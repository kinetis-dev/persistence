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
 * libpq, mysqli_options()/set_charset() for mysqli, DSN keys and
 * attributes for PDO).
 *
 * A field the selected driver cannot honor is a construction-time
 * InvalidArgumentException naming the field and the driver — never a
 * silent ignore. The supported matrix lives in docs/persistence.md.
 *
 * $extraConnectionString is the raw escape hatch for backends whose
 * native configuration surface *is* a free-form key/value string (libpq)
 * — drivers without such a surface (mysqli, PDO MySQL) reject it loudly.
 */
final readonly class ConnectionOptions
{
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    /** libpq's sslmode vocabulary — the superset every driver validates against. */
    private const array SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public function __construct(
        public ?string $charset = null,
        public ?string $collation = null,
        public ?string $sslMode = null,
        public ?string $sslCa = null,
        public ?int $connectTimeout = null,
        public ?string $applicationName = null,
        public ?bool $compression = null,
        public int $maxConnections = 8,
        public string $extraConnectionString = '',
        public ?string $sslCert = null,
        public ?string $sslKey = null,
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

        if ($sslMode !== null && !\in_array($sslMode, self::SSL_MODES, true)) {
            throw new InvalidArgumentException(
                'ConnectionOptions $sslMode must be one of ' . \implode('|', self::SSL_MODES) . ", got \"{$sslMode}\".",
            );
        }

        foreach (['sslCa' => $sslCa, 'sslCert' => $sslCert, 'sslKey' => $sslKey] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("ConnectionOptions \${$name} must be a file path, not an empty string.");
            }
        }

        // A client certificate is only half of a keypair: mutual TLS
        // needs both, and one alone is always a misconfiguration rather
        // than a partial setup any driver could act on.
        if (($sslCert === null) !== ($sslKey === null)) {
            throw new InvalidArgumentException(
                'ConnectionOptions $sslCert and $sslKey must be set together — a client certificate is '
                . 'unusable without its private key, and vice versa.',
            );
        }

        // Client certificates are presented during the TLS handshake, so
        // without TLS they would be silently ignored.
        if ($sslCert !== null && ($sslMode === null || $sslMode === 'disable')) {
            throw new InvalidArgumentException(
                'ConnectionOptions $sslCert/$sslKey need TLS: set $sslMode to "require", "verify-ca", '
                . '"verify-full" (or libpq\'s "allow"/"prefer"), since a client certificate is only ever '
                . 'presented during a TLS handshake.',
            );
        }

        if ($connectTimeout !== null && $connectTimeout < 1) {
            throw new InvalidArgumentException('ConnectionOptions $connectTimeout must be a positive number of seconds.');
        }

        if ($maxConnections < 1) {
            throw new InvalidArgumentException('ConnectionOptions $maxConnections must be at least 1.');
        }
    }

    /**
     * Whether TLS verification modes are asked for at all — drivers use
     * this instead of re-deriving "disable means the same as unset".
     */
    public function wantsTls(): bool
    {
        return $this->sslMode !== null && $this->sslMode !== 'disable';
    }

    /**
     * The MySQL drivers' TLS profile check, at construction: MySQL clients
     * have no opportunistic TLS, verification needs a CA to verify
     * against, and a CA alongside a non-verifying mode would be silently
     * ignored — each of those is a loud error here rather than a
     * connection that quietly means something else.
     */
    public function validateMysqlSsl(string $driverLabel): void
    {
        if ($this->sslMode === 'allow' || $this->sslMode === 'prefer') {
            throw new InvalidArgumentException(
                "The {$driverLabel} driver has no opportunistic TLS: \$sslMode \"{$this->sslMode}\" "
                . 'is libpq-only. Use "disable", "require", "verify-ca", or "verify-full".',
            );
        }

        $verifying = $this->sslMode === 'verify-ca' || $this->sslMode === 'verify-full';

        if ($verifying && $this->sslCa === null) {
            throw new InvalidArgumentException(
                "The {$driverLabel} driver needs \$sslCa (a CA bundle path) for \$sslMode \"{$this->sslMode}\" — "
                . 'there is nothing to verify the server certificate against without one.',
            );
        }

        if (!$verifying && $this->sslCa !== null) {
            throw new InvalidArgumentException(
                "The {$driverLabel} driver would silently ignore \$sslCa under \$sslMode "
                . '"' . ($this->sslMode ?? 'unset') . '" — set $sslMode to "verify-ca" or "verify-full", or unset $sslCa.',
            );
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
                'sslCa' => $this->sslCa !== null,
                'sslCert' => $this->sslCert !== null,
                'sslKey' => $this->sslKey !== null,
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
