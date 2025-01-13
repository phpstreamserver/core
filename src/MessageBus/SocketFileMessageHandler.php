<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\ByteStream\StreamException;
use Amp\Future;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\UnixAddress;
use PHPStreamServer\Core\Message\CompositeMessage;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\weakClosure;

final class SocketFileMessageHandler implements MessageHandlerInterface, MessageBusInterface
{
    public const CHUNK_SIZE = 65536;

    private ResourceServerSocket $socket;

    /**
     * @var array<class-string, array<int, \Closure>>
     */
    private array $subscribers = [];

    private string $callbackId;

    public function __construct(string $socketFile)
    {
        $this->socket = (new ResourceServerSocketFactory(chunkSize: self::CHUNK_SIZE))->listen(new UnixAddress($socketFile));
        $server = &$this->socket;
        $subscribers = &$this->subscribers;

        \chmod($socketFile, 0666);

        $this->callbackId = EventLoop::defer(static function () use (&$server, &$subscribers) {
            while ($socket = $server->accept()) {
                $data = $socket->read(limit: self::CHUNK_SIZE);

                // if socket is not readable anymore
                if ($data === null) {
                    continue;
                }

                ['size' => $size, 'data' => $data] = \unpack('Vsize/a*data', $data);

                while (\strlen($data) < $size) {
                    $data .= $socket->read(limit: self::CHUNK_SIZE);
                }

                $message = \unserialize($data);
                \assert($message instanceof MessageInterface);
                $return = null;

                foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                    if (null !== $subscriberReturn = $subscriber($message)) {
                        $return = $subscriberReturn;
                        break;
                    }
                }

                try {
                    $serializedWriteData = \serialize($return);
                    $socket->write(\pack('Va*', \strlen($serializedWriteData), $serializedWriteData));
                } catch (StreamException) {
                    // if socket is not writable anymore
                    continue;
                }

                $socket->end();
            }
        });

        $this->subscribe(CompositeMessage::class, weakClosure(function (CompositeMessage $event) {
            foreach ($event->messages as $message) {
                $this->dispatch($message);
            }
        }));
    }

    public function __destruct()
    {
        EventLoop::cancel($this->callbackId);
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
