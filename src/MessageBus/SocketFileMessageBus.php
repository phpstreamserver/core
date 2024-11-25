<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Amp\Socket\StaticSocketConnector;
use Amp\Socket\UnixAddress;

use function Amp\async;
use function Amp\delay;

final class SocketFileMessageBus implements MessageBusInterface
{
    private SocketConnector $connector;

    public function __construct(string $socketFile)
    {
        $this->connector = new StaticSocketConnector(new UnixAddress($socketFile), new DnsSocketConnector());
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     * @psalm-suppress PossiblyUndefinedVariable
     */
    public function dispatch(MessageInterface $message): Future
    {
        $connector = &$this->connector;

        return async(static function () use (&$connector, &$message): mixed {
            while (true) {
                try {
                    $socket = $connector->connect('');
                    break;
                } catch (ConnectException) {
                    delay(0.01);
                }
            }

            $serializedWriteData = \serialize($message);
            $socket->write(\pack('Va*', \strlen($serializedWriteData), $serializedWriteData));

            $data = $socket->read(limit: SocketFileMessageHandler::CHUNK_SIZE);
            \assert(\is_string($data));

            ['size' => $size, 'data' => $data] = \unpack('Vsize/a*data', $data);

            while (\strlen($data) < $size) {
                $data .= $socket->read(limit: SocketFileMessageHandler::CHUNK_SIZE);
            }

            return \unserialize($data);
        });
    }
}
