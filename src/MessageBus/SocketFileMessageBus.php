<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Amp\Socket\StaticSocketConnector;
use Amp\Socket\UnixAddress;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

final class SocketFileMessageBus implements GracefulMessageBusInterface
{
    private SocketConnector $connector;
    private int $queue = 0;

    public function __construct(string $socketFile)
    {
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
        $this->queue++;
        $connector = $this->connector;
        $queue = &$this->queue;

        return async(static function () use ($message, $connector): mixed {
            while (true) {
                try {
                    $socket = $connector->connect('');
                    break;
                } catch (ConnectException) {
                    delay(0.01);
                }
            }

            $serializedMessage = \serialize($message);
            $compressMessage = \extension_loaded('zlib') && \strlen($serializedMessage) > SocketFileMessageHandler::COMPRESS_FROM;

            if ($compressMessage) {
                $serializedMessage = \gzdeflate($serializedMessage, 1);
            }

            $payload = \pack('Vva*', \strlen($serializedMessage), (int) $compressMessage, $serializedMessage);

            $socket->write($payload);
            $data = $socket->read(limit: SocketFileMessageHandler::CHUNK_SIZE);
            \assert(\is_string($data));

            ['size' => $size, 'gzip' => $compressed, 'data' => $data] = \unpack('Vsize/vgzip/a*data', $data);

            $i = 0;
            while (\strlen($data) < $size && $i++ < 5000) {
                $data .= $socket->read(limit: SocketFileMessageHandler::CHUNK_SIZE);
            }

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
