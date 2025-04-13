<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Amp\Socket\StaticSocketConnector;
use Amp\Socket\UnixAddress;

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
        return async(function () use ($message): mixed {
            while (true) {
                try {
                    $socket = $this->connector->connect('');
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

            $socket->write(\pack('Vva*', \strlen($serializedMessage), (int) $compressMessage, $serializedMessage));

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
        })->finally(function (): void {
            $this->queue--;
        });
    }

    public function stop(): Future
    {
        return async(function (): void {
            while ($this->queue > 0) {
                delay(0.001);
            }
        });
    }
}
