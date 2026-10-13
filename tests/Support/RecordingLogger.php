<?php

declare(strict_types=1);

namespace Tests\Support;

use dry\log\logger\LoggerInterFace;
use dry\log\logger\MonologTrait;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * A dry logger that keeps what it is told in memory, for the tests that
 * read what the gateway logged.
 */
final class RecordingLogger implements LoggerInterFace
{
    use MonologTrait;

    private TestHandler $handler;

    public function __construct()
    {
        $this->handler = new TestHandler();
    }

    /**
     * @param string $name
     * @return static
     */
    public function register(string $name): static
    {
        $this->logger = new Logger($name, [$this->handler]);

        return $this;
    }

    /**
     * @return list<LogRecord>
     */
    public function records(): array
    {
        return array_values($this->handler->getRecords());
    }

    /**
     * The one record with this message, or null.
     *
     * @param string $message
     * @return LogRecord|null
     */
    public function record(string $message): ?LogRecord
    {
        foreach ($this->records() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        return null;
    }
}
