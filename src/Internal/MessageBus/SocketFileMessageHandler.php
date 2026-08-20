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
use PHPStreamServer\Core\Command\GetProcessesCommand;
use PHPStreamServer\Core\Internal\PeerCredentials;
use PHPStreamServer\Core\MessageBus\AllowedClassesProviderInterface;
use PHPStreamServer\Core\MessageBus\CompositeMessage;
use PHPStreamServer\Core\MessageBus\Context;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Runtime\ProcessIdentity;
use Revolt\EventLoop;

use function Amp\async;

final class SocketFileMessageHandler implements MessageHandlerInterface, MessageBusInterface
{
    use MessageBusTrait;

    private const DEFAULT_ALLOWED_CLASSES = [
        \DateTimeImmutable::class,
        \DateTime::class,
        \DateTimeZone::class,
        \DateInterval::class,
        \DatePeriod::class,
        \stdClass::class,
    ];

    private ResourceServerSocket $socket;

    /**
     * @var array<class-string<MessageInterface>, array<int, \Closure(MessageInterface, Context): mixed>>
     */
    private array $subscribers = [];

    private array $allowedClasses = self::DEFAULT_ALLOWED_CLASSES;
    private string $recomputeClassesCallbackId = '';

    public function __construct(string $socketFile)
    {
        $this->socket = (new ResourceServerSocketFactory(chunkSize: self::CHUNK_SIZE))->listen(
            address: new UnixAddress($socketFile),
            bindContext: (new BindContext())->withBacklog(self::BACKLOG),
        );

        \chmod($socketFile, 0666);

        $subscribers = &$this->subscribers;
        $allowedClasses = &$this->allowedClasses;

        EventLoop::queue(function () use (&$subscribers, &$allowedClasses): void {
            while ($socket = $this->socket->accept()) {
                $cred = PeerCredentials::get($socket->getResource());

                $processes = $this->dispatch(new GetProcessesCommand())->await();
                $processPids = \array_map(static fn(ProcessInfo $p): int => $p->pid, $processes);
                $masterPid = \posix_getpid();
                $context = new Context(
                    source: match (true) {
                        $cred->pid === $masterPid => MessageSource::MASTER,
                        \in_array( $cred->pid, $processPids, true) => MessageSource::CHILD,
                        default => MessageSource::EXTERNAL,
                    },
                    pid: $cred->pid,
                    uid: $cred->uid,
                    gid: $cred->gid,
                    user: ProcessIdentity::getUserBuUid($cred->uid),
                    group: ProcessIdentity::getGroupBuGid($cred->gid),
                );

                async(static function () use ($socket, &$subscribers, $masterPid, $context, &$allowedClasses): void {
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

                            $message = \unserialize($data, ['allowed_classes' => $allowedClasses]);

                            if ($message instanceof \__PHP_Incomplete_Class) {
                                $className = ((array) $message)['__PHP_Incomplete_Class_Name'] ?? 'unknown';
                                throw new \RuntimeException(\sprintf('Received untrusted message class "%s"', $className));
                            }

                            if (!$message instanceof MessageInterface) {
                                break;
                            }

                            $return = null;

                            foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                                if (null !== $subscriberReturn = $subscriber($message, $context)) {
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
                        if (\posix_getpid() === $masterPid) {
                            $socket->end();
                        }
                    }
                });
            }
        });

        $this->subscribe(CompositeMessage::class, function (CompositeMessage $event): void {
            foreach ($event->messages as $message) {
                $this->dispatch($message)->await();
            }
        });
    }

    public function stop(): void
    {
        if ($this->recomputeClassesCallbackId !== '') {
            EventLoop::cancel($this->recomputeClassesCallbackId);
            $this->recomputeClassesCallbackId = '';
        }
        $this->subscribers = [];
        $this->allowedClasses = self::DEFAULT_ALLOWED_CLASSES;
        $this->socket->close();
    }

    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T, Context): mixed $closure
     */
    public function subscribe(string $class, \Closure $closure): void
    {
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $this->subscribers[$class][\spl_object_id($closure)] = $closure;
        $this->recomputeAllowedClasses();
    }

    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T, Context): mixed $closure
     */
    public function unsubscribe(string $class, \Closure $closure): void
    {
        unset($this->subscribers[$class][\spl_object_id($closure)]);
        if ($this->subscribers[$class] === []) {
            unset($this->subscribers[$class]);
            $this->recomputeAllowedClasses();
        }
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     */
    public function dispatch(MessageInterface $message): Future
    {
        $subscribers = &$this->subscribers;

        $pid = \posix_getpid();
        $uid = \posix_geteuid();
        $gid = \posix_getegid();
        $context = new Context(
            source: MessageSource::MASTER,
            pid: $pid,
            uid: $uid,
            gid: $gid,
            user: ProcessIdentity::getUserBuUid($uid),
            group: ProcessIdentity::getGroupBuGid($gid),
        );

        return async(static function () use (&$subscribers, &$message, $context): mixed {
            foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                if (null !== $subscriberReturn = $subscriber($message, $context)) {
                    return $subscriberReturn;
                }
            }

            return null;
        });
    }

    private function recomputeAllowedClasses(): void
    {
        if ($this->recomputeClassesCallbackId !== '') {
            return;
        }

        $this->recomputeClassesCallbackId = EventLoop::defer(function () {
            $allowed = self::DEFAULT_ALLOWED_CLASSES;
            foreach ($this->subscribers as $class => $_) {
                $allowed[] = $class;
                if (\is_subclass_of($class, AllowedClassesProviderInterface::class)) {
                    foreach ($class::getAllowedClasses() as $nestedClass) {
                        $allowed[] = $nestedClass;
                    }
                }
            }
            $this->allowedClasses = \array_unique($allowed);
            $this->recomputeClassesCallbackId = '';
        });
    }
}
