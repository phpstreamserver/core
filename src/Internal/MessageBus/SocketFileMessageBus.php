<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Amp\Socket\StaticSocketConnector;
use Amp\Socket\UnixAddress;
use Amp\TimeoutCancellation;
use PHPStreamServer\Core\MessageBus\MessageInterface;

use function Amp\async;

final class SocketFileMessageBus implements GracefulMessageBusInterface
{
    use MessageBusTrait;

    private readonly SocketConnector $connector;
    private Socket|null $socket = null;
    private Future $dispatchTail;
    private bool $stopping = false;

    public function __construct(string $socketFile)
    {
        $this->connector = new StaticSocketConnector(new UnixAddress($socketFile), new DnsSocketConnector());
        $this->dispatchTail = Future::complete();
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

        $previousDispatch = $this->dispatchTail;
        $nextDispatch = new DeferredFuture();
        $this->dispatchTail = $nextDispatch->getFuture();

        return async(function () use ($message, $previousDispatch, $nextDispatch): mixed {
            try {
                $previousDispatch->await();
                $socket = $this->getSocket();

                $serializedMessage = \serialize($message);
                $compressMessage = \extension_loaded('zlib') && \strlen($serializedMessage) > self::COMPRESS_FROM;

                if ($compressMessage) {
                    $serializedMessage = \gzdeflate($serializedMessage, 1);
                }

                $payload = \pack('Vva*', \strlen($serializedMessage), (int) $compressMessage, $serializedMessage);
                $socket->write($payload);

                $header = self::readExactly($socket, 6, new TimeoutCancellation(self::PAYLOAD_READ_TIMEOUT, 'Message header timed out'));
                ['size' => $size, 'gzip' => $compressed] = \unpack('Vsize/vgzip', $header);
                $data = self::readExactly($socket, $size, new TimeoutCancellation(self::PAYLOAD_READ_TIMEOUT, 'Message frame timed out'));

                if ($compressed) {
                    $data = \gzinflate($data);
                }

                return \unserialize($data);
            } catch (CancelledException|StreamException $e) {
                $this->socket?->close();
                $this->socket = null;
                throw $e;
            } finally {
                $nextDispatch->complete();
            }
        });
    }

    /**
     * @throws ConnectException
     */
    private function getSocket(): Socket
    {
        if ($this->socket !== null && $this->socket->isReadable() && $this->socket->isWritable()) {
            return $this->socket;
        }

        $this->socket?->close();
        $this->socket = null;

        try {
            $socket = $this->connector->connect(uri: '', cancellation: new TimeoutCancellation(self::CONNECT_TIMEOUT, 'The master message bus connection timed out'));
            $socket->write(self::HANDSHAKE);
            $handshake = self::readExactly($socket, \strlen(self::HANDSHAKE), new TimeoutCancellation(self::PROTOCOL_READ_TIMEOUT, 'Message header timed out'));

            if ($handshake !== self::HANDSHAKE) {
                throw new ConnectException('The master message bus returned an invalid protocol handshake');
            }

            return $this->socket = $socket;
        } catch (CancelledException $e) {
            throw new ConnectException(message: $e->getMessage(), previous: $e);
        }
    }

    public function stop(): Future
    {
        $this->stopping = true;
        $dispatchTail = $this->dispatchTail;
        $socket = &$this->socket;

        return async(static function () use ($dispatchTail, &$socket): void {
            $dispatchTail->await();
            $socket?->close();
            $socket = null;
        });
    }
}
