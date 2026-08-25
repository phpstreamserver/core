<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Exception\ProcessIdentityException;
use PHPStreamServer\Core\LoggerInterface;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\WorkerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\DriverFactory;

/**
 * @internal
 */
final readonly class WorkerProcessRunner
{
    public function __construct(
        private ContainerInterface $workerContainer,
        private int $masterPid,
    ) {
    }

    public function run(WorkerInterface $worker): int
    {
        // Some command-line SAPIs (e.g., phpdbg) do not provide this function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: %s', Server::NAME, $worker->getName()));
        }

        EventLoop::setDriver((new DriverFactory())->create());

        $logger = $this->workerContainer->getService(LoggerInterface::class);

        ErrorHandler::register($logger);
        EventLoop::setErrorHandler(static function (\Throwable $exception): void {
            ErrorHandler::handleException($exception);
        });

        try {
            ProcessIdentity::switchTo($worker->getUser(), $worker->getGroup());
        } catch (ProcessIdentityException $e) {
            $logger->error(\sprintf('Worker "%s" failed to change process identity: %s', $worker->getName(), $e->getMessage()));
            EventLoop::run();

            return 1;
        }

        try {
            ParentDeathSignal::set(SIGTERM, $this->masterPid);
        } catch (\Throwable $e) {
            $logger->warning(\sprintf('Worker "%s": %s', $worker->getName(), $e->getMessage()), ['exception' => $e]);
        }

        return $worker->run($this->workerContainer);
    }
}
