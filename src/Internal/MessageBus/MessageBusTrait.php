<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;

/**
 * @internal
 */
trait MessageBusTrait
{
    private const CHUNK_SIZE = 65536;
    private const COMPRESS_FROM = 8192;
    private const BACKLOG = 2048;
    private const HANDSHAKE = "PHSS\x01";
    private const CONNECT_TIMEOUT = 1.0;
    private const PROTOCOL_READ_TIMEOUT = 1.0;
    private const PAYLOAD_READ_TIMEOUT = 5.0;

    /**
     * @throws SocketException
     * @throws CancelledException
     */
    private static function readExactly(Socket $socket, int $length, Cancellation|null $cancellation = null): string
    {
        $data = '';
        while (\strlen($data) < $length) {
            /** @psalm-suppress InvalidArgument */
            $chunk = $socket->read(limit: $length - \strlen($data), cancellation: $cancellation);
            if ($chunk === null) {
                throw new SocketException(\sprintf('Socket closed after receiving %d of %d expected bytes', \strlen($data), $length));
            }
            $data .= $chunk;
        }

        return $data;
    }
}
