<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;

/**
 * @internal
 */
trait MessageBusTrait
{
    private const CHUNK_SIZE = 65536;
    private const COMPRESS_FROM = 8192;
    private const MAX_PAYLOAD_SIZE = 8 * 1024 * 1024;
    private const BACKLOG = 2048;
    private const HANDSHAKE = "PHSS\x01";
    private const CONNECT_TIMEOUT = 1.0;
    private const PROTOCOL_READ_TIMEOUT = 1.0;
    private const PAYLOAD_READ_TIMEOUT = 10.0;

    private static function encodeFrame(string $data): string
    {
        $compress = \extension_loaded('zlib') && \strlen($data) > self::COMPRESS_FROM;

        if ($compress) {
            $data = @\gzdeflate($data, 1);
        }

        return \pack('Vv', \strlen($data), (int) $compress) . $data;
    }

    /**
     * @throws StreamException
     * @throws CancelledException
     */
    private static function readFrame(Socket $socket, Cancellation|null $firstByteCancellation = null): string|null
    {
        $firstByte = $socket->read(limit: 1, cancellation: $firstByteCancellation);
        if ($firstByte === null) {
            return null;
        }

        $header = $firstByte . self::readExactly($socket, 5, new TimeoutCancellation(self::PROTOCOL_READ_TIMEOUT, 'Message header timed out'));
        ['size' => $size, 'gzip' => $compressed] = \unpack('Vsize/vgzip', $header);

        if ($size < 1 || $size > self::MAX_PAYLOAD_SIZE || ($compressed !== 0 && $compressed !== 1)) {
            throw new StreamException('Received invalid message bus frame');
        }

        $data = self::readExactly($socket, $size, new TimeoutCancellation(self::PAYLOAD_READ_TIMEOUT, 'Message frame timed out'));

        if ($compressed) {
            $data = @\gzinflate($data, self::MAX_PAYLOAD_SIZE);
            if ($data === false) {
                throw new StreamException('Received invalid compressed message bus frame');
            }
        }

        return $data;
    }

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
