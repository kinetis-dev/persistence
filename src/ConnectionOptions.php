<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use InvalidArgumentException;

/**
 * The canonical, driver-neutral connection option set. One instance is
 * built by {@see SqlConnectionFactory} from Config (discrete DB_* keys,
 * plus a translated legacy DB_OPTIONS string) and handed to whichever
 * driver gets constructed — each driver owns the translation from these
 * canonical fields to its native mechanism (connection-string keys for
 * amphp/libpq, mysqli_options()/set_charset() for mysqli, DSN keys and
 * attributes for PDO).
 *
 * A field the selected driver cannot honor is a construction-time
 * InvalidArgumentException naming the field and the driver — never a
 * silent ignore. The supported matrix lives in docs/persistence.md.
 *
 * $extraConnectionString is the raw escape hatch for backends whose
 * native configuration surface *is* a free-form key/value string (amphp's
 * config parsers, libpq) — drivers without such a surface (mysqli, PDO
 * MySQL) reject it loudly.
 */
final readonly class ConnectionOptions
{
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    public function __construct(
        public ?string $charset = null,
        public ?string $collation = null,
        public ?string $sslMode = null,
        public ?int $connectTimeout = null,
        public ?string $applicationName = null,
        public ?bool $compression = null,
        public int $maxConnections = 8,
        public string $extraConnectionString = '',
    ) {
        // charset/collation reach drivers as SQL fragments (SET NAMES) or
        // API calls — constrain them to identifier characters so a value
        // from the environment can never smuggle SQL along.
        foreach (['charset' => $charset, 'collation' => $collation] as $name => $value) {
            if ($value !== null && \preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException(
                    "ConnectionOptions \${$name} must match " . self::IDENTIFIER_PATTERN . ", got \"{$value}\".",
                );
            }
        }

        if ($connectTimeout !== null && $connectTimeout < 1) {
            throw new InvalidArgumentException('ConnectionOptions $connectTimeout must be a positive number of seconds.');
        }

        if ($maxConnections < 1) {
            throw new InvalidArgumentException('ConnectionOptions $maxConnections must be at least 1.');
        }
    }

    /**
     * The loud-failure helper every driver funnels through: names both the
     * unsupported field and the driver, so a config that works on one
     * runtime fails with an actionable message on another instead of
     * silently meaning something different.
     *
     * @param list<string> $fields Field names this driver cannot honor.
     */
    public function rejectUnsupported(string $driverLabel, array $fields): void
    {
        foreach ($fields as $field) {
            $set = match ($field) {
                'charset' => $this->charset !== null,
                'collation' => $this->collation !== null,
                'sslMode' => $this->sslMode !== null,
                'connectTimeout' => $this->connectTimeout !== null,
                'applicationName' => $this->applicationName !== null,
                'compression' => $this->compression !== null,
                'extraConnectionString' => $this->extraConnectionString !== '',
                default => throw new InvalidArgumentException("Unknown ConnectionOptions field \"{$field}\"."),
            };

            if ($set) {
                throw new InvalidArgumentException(
                    "The {$driverLabel} driver does not support the \"{$field}\" connection option. "
                    . 'Unset it, or select a driver that supports it (see docs/persistence.md for the matrix).',
                );
            }
        }
    }
}
