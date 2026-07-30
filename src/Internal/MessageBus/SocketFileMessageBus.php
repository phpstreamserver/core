<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Amp\Socket\StaticSocketConnector;
use Amp\Socket\UnixAddress;
use Amp\TimeoutCancellation;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;
use function PHPStreamServer\Core\readExactly;

final class SocketFileMessageBus implements GracefulMessageBusInterface
{
    private const RETRY_DELAY = 0.01;

    private readonly SocketConnector $connector;
    private int $queue = 0;
    private bool $stopping = false;

    public function __construct(string $socketFile, private readonly float $connectTimeout = 1.0)
    {
        if ($connectTimeout <= 0) {
            throw new \InvalidArgumentException('Connection timeout must be greater than zero');
        }

        $this->connector = new StaticSocketConnector(new UnixAddress($socketFile), new DnsSocketConnector());
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     * @psalm-suppress PossiblyUndefinedVariable
     */
    public function dispatch(MessageInterface $message): Future
    {
        if ($this->stopping) {
            return Future::error(new ConnectException('The master message bus is stopping'));
        }

        $this->queue++;
        $connector = $this->connector;
        $connectTimeout = $this->connectTimeout;
        $queue = &$this->queue;

        return async(static function () use ($message, $connector, $connectTimeout): mixed {
            $cancellation = new TimeoutCancellation($connectTimeout);

            try {
                while (true) {
                    try {
                        $socket = $connector->connect(uri: '', cancellation: $cancellation);
                        break;
                    } catch (ConnectException) {
                        delay(timeout: self::RETRY_DELAY, cancellation: $cancellation);
                    }
                }
            } catch (CancelledException $e) {
                throw new ConnectException(message: \sprintf('Timed out connecting to the master message bus after %.2f seconds', $connectTimeout), previous: $e);
            }

            unset($cancellation);

            $serializedMessage = \serialize($message);
            $compressMessage = \extension_loaded('zlib') && \strlen($serializedMessage) > SocketFileMessageHandler::COMPRESS_FROM;

            if ($compressMessage) {
                $serializedMessage = \gzdeflate($serializedMessage, 1);
            }

            $payload = \pack('Vva*', \strlen($serializedMessage), (int) $compressMessage, $serializedMessage);

            $socket->write($payload);
            $header = readExactly($socket, 6);
            ['size' => $size, 'gzip' => $compressed] = \unpack('Vsize/vgzip', $header);
            $data = readExactly($socket, $size);

            if ($compressed) {
                $data = \gzinflate($data);
            }

            return \unserialize($data);
        })->finally(static function () use (&$queue): void {
            $queue--;
        });
    }

    public function stop(): Future
    {
        $this->stopping = true;
        $queue = &$this->queue;
        $deferred = new DeferredFuture();
        EventLoop::defer($deferred->complete(...));

        return async(static function () use (&$queue, $deferred): void {
            $deferred->getFuture()->await();
            while ($queue > 0) {
                delay(0.001);
            }
        });
    }
}
