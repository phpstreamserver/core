<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Revolt\EventLoop;
use Revolt\EventLoop\FiberLocal;

/**
 * Runs the callback once on the first read and captures its echoed output.
 * There is no backpressure, writes do not wait for readers, so queued output can grow.
 *
 * @internal
 * @implements ConcurrentIterator<string>
 */
final class CallbackOutputIterator implements ConcurrentIterator
{
    private const BATCH_SIZE = 65536;
    private const DIRECT_WRITE_SIZE = 16384;

    /** @var FiberLocal<null|\Closure(string, bool): void> */
    private static FiberLocal $writer;

    /** @var Queue<string>|null */
    private Queue|null $pendingQueue;

    /** @var ConcurrentIterator<string> */
    private readonly ConcurrentIterator $iterator;

    public function __construct(private readonly \Closure $callback)
    {
        /** @var Queue<string> $queue */
        $queue = new Queue();
        $this->pendingQueue = $queue;
        $this->iterator = $queue->iterate();

        if (isset(self::$writer)) {
            return;
        }

        /** @var FiberLocal<null|\Closure(string, bool): void> */
        self::$writer = new FiberLocal(static fn(): null => null);

        \ob_start(static function (string $chunk, int $phase): string {
            $write = self::$writer->get();

            if ($write === null) {
                return $chunk;
            }

            $forceFlush = ($phase & \PHP_OUTPUT_HANDLER_FLUSH) !== 0;
            if (($phase & \PHP_OUTPUT_HANDLER_CLEAN) === 0 && ($chunk !== '' || $forceFlush)) {
                $write($chunk, $forceFlush);
            }

            return '';
        }, 1, \PHP_OUTPUT_HANDLER_CLEANABLE | \PHP_OUTPUT_HANDLER_FLUSHABLE);
    }

    public function continue(Cancellation|null $cancellation = null): bool
    {
        if (($queue = $this->pendingQueue) !== null) {
            $this->pendingQueue = null;
            EventLoop::queue(self::runCallback(...), $queue, $this->callback);
        }

        return $this->iterator->continue($cancellation);
    }

    public function getValue(): string
    {
        return $this->iterator->getValue();
    }

    public function getPosition(): int
    {
        return $this->iterator->getPosition();
    }

    public function isComplete(): bool
    {
        return $this->iterator->isComplete();
    }

    public function dispose(): void
    {
        $this->pendingQueue = null;
        $this->iterator->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }

    /**
     * @param Queue<string> $queue
     */
    private static function runCallback(Queue $queue, \Closure $callback): void
    {
        if ($queue->isDisposed()) {
            return;
        }

        /** @var string $buffer */
        $buffer = '';
        $flushQueued = false;

        $flush = static function () use ($queue, &$buffer): void {
            if ($buffer === '') {
                return;
            }

            $chunk = $buffer;
            $buffer = '';
            if (!$queue->isDisposed()) {
                $queue->pushAsync($chunk)->ignore();
            }
        };

        self::$writer->set(static function (string $chunk, bool $forceFlush) use ($queue, &$buffer, &$flushQueued, $flush): void {
            if ($queue->isDisposed()) {
                return;
            }

            if (\strlen($chunk) >= self::DIRECT_WRITE_SIZE) {
                $flush();
                $queue->pushAsync($chunk)->ignore();
                return;
            }

            $buffer .= $chunk;
            if ($forceFlush || \strlen($buffer) >= self::BATCH_SIZE) {
                $flush();
            } elseif (!$flushQueued) {
                $flushQueued = true;
                EventLoop::queue(static function () use (&$flushQueued, $flush): void {
                    $flushQueued = false;
                    $flush();
                });
            }
        });

        try {
            $callback();
            $flush();
            $queue->complete();
        } catch (\Throwable $exception) {
            $flush();
            $queue->error($exception);
        } finally {
            self::$writer->unset();
        }
    }
}
