<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

final class Connection
{
    public function __construct(
        public readonly int $pid,
        public readonly \DateTimeImmutable $connectedAt,
        public readonly string $localIp,
        public readonly string $localPort,
        public readonly string $remoteIp,
        public readonly string $remotePort,
        public int $rx = 0,
        public int $tx = 0,
    ) {
    }

    public function __serialize(): array
    {
        return [
            0 => $this->pid,
            1 => $this->connectedAt->getTimestamp(),
            2 => $this->localIp,
            3 => $this->localPort,
            4 => $this->remoteIp,
            5 => $this->remotePort,
            6 => $this->rx,
            7 => $this->tx,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pid = $data[0];
        /** @psalm-suppress PossiblyFalsePropertyAssignmentValue */
        $this->connectedAt = \DateTimeImmutable::createFromFormat('U', (string) $data[1]);
        $this->localIp = $data[2];
        $this->localPort = $data[3];
        $this->remoteIp = $data[4];
        $this->remotePort = $data[5];
        $this->rx = $data[6];
        $this->tx = $data[7];
    }
}
