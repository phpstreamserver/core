<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\Future;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\UnixAddress;
use Amp\TimeoutCancellation;
use PHPStreamServer\Core\MessageBus\CompositeMessage;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\weakClosure;

final class SocketFileMessageHandler implements MessageHandlerInterface, MessageBusInterface
{
    use MessageBusTrait;

    private ResourceServerSocket $socket;

    /**
     * @var array<class-string, array<int, \Closure>>
     */
    private array $subscribers = [];

    public function __construct(string $socketFile)
    {
        $this->socket = (new ResourceServerSocketFactory(chunkSize: self::CHUNK_SIZE))->listen(
            address: new UnixAddress($socketFile),
            bindContext: (new BindContext())->withBacklog(self::BACKLOG),
        );

        \chmod($socketFile, 0666);

        $server = &$this->socket;
        $subscribers = &$this->subscribers;

        EventLoop::queue(static function () use (&$server, &$subscribers) {
            while ($socket = $server->accept()) {
                $ownerPid = \posix_getpid();
                async(static function () use ($socket, &$subscribers, $ownerPid): void {
                    try {
                        $handshake = self::readExactly($socket, \strlen(self::HANDSHAKE), new TimeoutCancellation(self::PROTOCOL_READ_TIMEOUT, 'Message bus handshake timed out'));
                        if ($handshake !== self::HANDSHAKE) {
                            return;
                        }

                        $socket->write(self::HANDSHAKE);

                        while (true) {
                            $firstByte = $socket->read(limit: 1);
                            if ($firstByte === null) {
                                break;
                            }

                            $header = $firstByte . self::readExactly($socket, 5, new TimeoutCancellation(self::PROTOCOL_READ_TIMEOUT, 'Message header timed out'));
                            ['size' => $size, 'gzip' => $compressed] = \unpack('Vsize/vgzip', $header);
                            $data = self::readExactly($socket, $size, new TimeoutCancellation(self::PAYLOAD_READ_TIMEOUT, 'Message frame timed out'));

                            if ($compressed) {
                                $data = \gzinflate($data);
                            }

                            $message = \unserialize($data);
                            if (!$message instanceof MessageInterface) {
                                break;
                            }

                            $return = null;

                            foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                                if (null !== $subscriberReturn = $subscriber($message)) {
                                    $return = $subscriberReturn;
                                    break;
                                }
                            }

                            $serializedMessage = \serialize($return);
                            $compressMessage = \extension_loaded('zlib') && \strlen($serializedMessage) > self::COMPRESS_FROM;

                            if ($compressMessage) {
                                $serializedMessage = \gzdeflate($serializedMessage, 1);
                            }

                            $payload = \pack('Vva*', \strlen($serializedMessage), (int) $compressMessage, $serializedMessage);

                            $socket->write($payload);
                        }
                    } catch (CancelledException|StreamException) {
                        // The socket was closed
                    } finally {
                        if (\posix_getpid() === $ownerPid) {
                            $socket->end();
                        }
                    }
                });
            }
        });

        $this->subscribe(CompositeMessage::class, weakClosure(function (CompositeMessage $event) {
            foreach ($event->messages as $message) {
                $this->dispatch($message)->await();
            }
        }));
    }

    public function stop(): void
    {
        $this->subscribers = [];
        $this->socket->close();
    }

    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T): mixed $closure
     */
    public function subscribe(string $class, \Closure $closure): void
    {
        $this->subscribers[$class][\spl_object_id($closure)] = $closure;
    }

    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T): mixed $closure
     */
    public function unsubscribe(string $class, \Closure $closure): void
    {
        unset($this->subscribers[$class][\spl_object_id($closure)]);
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     */
    public function dispatch(MessageInterface $message): Future
    {
        $subscribers = &$this->subscribers;

        return async(static function () use (&$subscribers, &$message): mixed {
            foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                if (null !== $subscriberReturn = $subscriber($message)) {
                    return $subscriberReturn;
                }
            }

            return null;
        });
    }
}
