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
use PHPStreamServer\Core\Internal\PeerCredentials;
use PHPStreamServer\Core\Internal\ProcessIdentity;
use PHPStreamServer\Core\MessageBus\AllowedClassesProviderInterface;
use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\CompositeMessage;
use PHPStreamServer\Core\MessageBus\Context;
use PHPStreamServer\Core\MessageBus\MessageBusException;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;
use PHPStreamServer\Core\Runtime\ChildProcessRegistry;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Future\await;

final class SocketFileMessageHandler implements MessageHandlerInterface, MessageBusInterface
{
    use MessageBusTrait;

    private const MAX_EXTERNAL_CONNECTIONS = 32;

    private const DEFAULT_ALLOWED_CLASSES = [
        CompositeMessage::class,
        \DateTimeImmutable::class,
        \DateTime::class,
        \DateTimeZone::class,
        \DateInterval::class,
        \DatePeriod::class,
        \stdClass::class,
    ];

    private ResourceServerSocket $socket;
    private int $externalConnections = 0;

    /**
     * @var array<class-string<MessageInterface>, array<int, \Closure(MessageInterface, Context): mixed>>
     */
    private array $subscribers = [];

    /**
     * @var array<class-string<MessageInterface>, array<MessageSource>>
     */
    private array $authorizedSourcesForMessage = [];

    private array $allowedClasses = self::DEFAULT_ALLOWED_CLASSES;
    private string $recomputeClassesCallbackId = '';

    public function __construct(string $socketFile, ChildProcessRegistry $childProcessRegistry)
    {
        $this->socket = (new ResourceServerSocketFactory(chunkSize: self::CHUNK_SIZE))->listen(
            address: new UnixAddress($socketFile),
            bindContext: (new BindContext())->withBacklog(self::BACKLOG),
        );

        \chmod($socketFile, 0666);

        EventLoop::queue(function () use ($childProcessRegistry): void {
            while ($socket = $this->socket->accept()) {
                /** @psalm-suppress PossiblyInvalidArgument */
                $cred = PeerCredentials::get($socket->getResource());
                if ($cred === null) {
                    throw new \RuntimeException('Unable to retrieve message bus peer credentials');
                }

                $masterPid = \posix_getpid();
                $masterUid = \posix_geteuid();

                $context = new Context(
                    source: match (true) {
                        $cred->pid === $masterPid => MessageSource::MASTER,
                        $childProcessRegistry->contains($cred->pid) => MessageSource::CHILD,
                        $cred->uid === 0 || $cred->uid === $masterUid => MessageSource::MANAGER,
                        default => MessageSource::EXTERNAL,
                    },
                    pid: $cred->pid,
                    uid: $cred->uid,
                    gid: $cred->gid,
                    user: ProcessIdentity::getUserBuUid($cred->uid),
                    group: ProcessIdentity::getGroupBuGid($cred->gid),
                );

                $isExternal = $context->source === MessageSource::EXTERNAL;
                if ($isExternal && $this->externalConnections >= self::MAX_EXTERNAL_CONNECTIONS) {
                    $socket->close();
                    continue;
                }

                if ($isExternal) {
                    $this->externalConnections++;
                }

                async(function () use ($socket, $masterPid, $context, $isExternal): void {
                    try {
                        $handshake = self::readExactly($socket, \strlen(self::HANDSHAKE), new TimeoutCancellation(self::PROTOCOL_READ_TIMEOUT, 'Message bus handshake timed out'));
                        if ($handshake !== self::HANDSHAKE) {
                            return;
                        }

                        $socket->write(self::HANDSHAKE);

                        while (true) {
                            $data = self::readFrame($socket);
                            if ($data === null) {
                                break;
                            }

                            $message = \unserialize($data, ['allowed_classes' => $this->allowedClasses]);

                            if ($message instanceof \__PHP_Incomplete_Class) {
                                $className = ((array) $message)['__PHP_Incomplete_Class_Name'] ?? 'unknown';
                                throw new \RuntimeException(\sprintf('Received untrusted message class "%s"', $className));
                            }

                            if (!$message instanceof MessageInterface) {
                                break;
                            }

                            $response = $this->dispatchWithContext($message, $context)->await();
                            $serializedMessage = \serialize($response);

                            if (\strlen($serializedMessage) > self::MAX_PAYLOAD_SIZE) {
                                $serializedMessage = \serialize(new MessageBusResponse(error: 'Message bus response exceeds the maximum payload size'));
                            }

                            $socket->write(self::encodeFrame($serializedMessage));
                        }
                    } catch (CancelledException|StreamException) {
                        // The socket was closed
                    } finally {
                        if ($isExternal) {
                            $this->externalConnections--;
                        }

                        if (\posix_getpid() === $masterPid) {
                            $socket->end();
                        }
                    }
                });
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

        return async(function () use ($message, $context): mixed {
            $response = $this->dispatchWithContext($message, $context)->await();
            if ($response->error !== null) {
                throw new MessageBusException($response->error);
            }

            return $response->result;
        });
    }

    /**
     * @return Future<MessageBusResponse>
     */
    private function dispatchWithContext(MessageInterface $message, Context $context): Future
    {
        $subscribers = &$this->subscribers;

        $authorizedSources = $this->getAuthorizedSourcesForMessage($message);
        if ($authorizedSources !== [] && !\in_array($context->source, $authorizedSources, true)) {
            return Future::complete(new MessageBusResponse(error: 'Permission denied'));
        }

        if ($message instanceof CompositeMessage) {
            $futures = [];
            foreach ($message->messages as $nestedMessage) {
                $futures[] = $this->dispatchWithContext($nestedMessage, $context);
            }

            return async(static function () use ($futures): MessageBusResponse {
                foreach (await($futures) as $response) {
                    if ($response->error !== null) {
                        return $response;
                    }
                }

                return new MessageBusResponse(result: null);
            });
        }

        return async(static function () use (&$subscribers, &$message, $context): MessageBusResponse {
            foreach ($subscribers[$message::class] ?? [] as $subscriber) {
                if (null !== $subscriberReturn = $subscriber($message, $context)) {
                    return new MessageBusResponse(result: $subscriberReturn);
                }
            }

            return new MessageBusResponse(result: null);
        });
    }

    private function getAuthorizedSourcesForMessage(MessageInterface $message): array
    {
        if (isset($this->authorizedSourcesForMessage[$message::class])) {
            return $this->authorizedSourcesForMessage[$message::class];
        }

        $authorizedSourcesAttr = (new \ReflectionClass($message::class))->getAttributes(AuthorizedSources::class)[0] ?? null;
        $sources = $authorizedSourcesAttr?->newInstance()->sources ?? [];

        return $this->authorizedSourcesForMessage[$message::class] = $sources;
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
