<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\GetProcessNetworkInfoCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Plugin\System\Connection;

use function PHPStreamServer\Core\formatDuration;
use function PHPStreamServer\Core\formatFileSize;

class ConnectionsCommand extends Command
{
    final public static function getName(): string
    {
        return 'connections';
    }

    final public static function getDescription(): string
    {
        return 'Show active connections';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);

        $processNetworkInfos = $bus->dispatch(new GetProcessNetworkInfoCommand())->await();

        $processNamesByPids = [];
        $connections = [];

        foreach ($processNetworkInfos as $processNetworkInfo) {
            $processNamesByPids[$processNetworkInfo->pid] = $processNetworkInfo->name;
            foreach ($processNetworkInfo->connections as $connection) {
                $connections[] = $connection;
            }
        }

        echo "❯ Connections\n";

        if (\count($connections) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'PID',
                    'Worker',
                    'Local address',
                    'Remote address',
                    'Age',
                    'Bytes (RX / TX)',
                ])
                ->addRows(\array_map(array: $connections, callback: static function (Connection $connection) use ($processNamesByPids): array {
                    return [
                        $connection->pid,
                        $processNamesByPids[$connection->pid],
                        self::formatAddress($connection->localIp, $connection->localPort),
                        self::formatAddress($connection->remoteIp, $connection->remotePort),
                        formatDuration($connection->connectedAt),
                        \sprintf('(%s / %s)', formatFileSize($connection->rx), formatFileSize($connection->tx)),
                    ];
                }));
        } else {
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no active connections</>\n";
        }

        return 0;
    }

    private static function formatAddress(string $ip, string $port): string
    {
        return \str_contains($ip, ':') ? \sprintf('[%s]:%s', $ip, $port) : \sprintf('%s:%s', $ip, $port);
    }
}
