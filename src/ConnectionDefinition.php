<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use InvalidArgumentException;

/**
 * Everything {@see SqlConnectionFactory} needs to build a client: the
 * dialect, which driver to select, where the server is, the credentials,
 * the canonical {@see ConnectionOptions}, and how many connections to
 * open at construction.
 *
 * $port defaults to the dialect's own (3306 for `mysql`, 5432 for
 * `pgsql`). $driver is `auto`, `native` or `pdo` — see
 * {@see SqlConnectionFactory} for what each selects. $warmConnections
 * above zero connects while the client is built instead of on first use.
 */
final readonly class ConnectionDefinition
{
    public int $port;

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'auto'|'native'|'pdo' $driver
     */
    public function __construct(
        public string $dialect,
        public string $host,
        public string $database,
        public string $user,
        #[\SensitiveParameter] public string $password,
        ?int $port = null,
        public string $driver = 'auto',
        public ConnectionOptions $options = new ConnectionOptions(),
        public int $warmConnections = 0,
    ) {
        // The @param types narrow what analysed callers pass; construction
        // still refuses whatever arrives at runtime.
        // @phpstan-ignore function.alreadyNarrowedType
        if (!\in_array($dialect, ['mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException("ConnectionDefinition \$dialect must be \"mysql\" or \"pgsql\", got \"{$dialect}\".");
        }

        // @phpstan-ignore function.alreadyNarrowedType
        if (!\in_array($driver, ['auto', 'native', 'pdo'], true)) {
            throw new InvalidArgumentException(
                "ConnectionDefinition \$driver must be \"auto\", \"native\", or \"pdo\", got \"{$driver}\".",
            );
        }

        $port ??= $dialect === 'mysql' ? 3306 : 5432;

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("ConnectionDefinition \$port must be a valid TCP port (1-65535), got {$port}.");
        }

        if ($warmConnections < 0) {
            throw new InvalidArgumentException("ConnectionDefinition \$warmConnections must not be negative, got {$warmConnections}.");
        }

        $this->port = $port;
    }
}
