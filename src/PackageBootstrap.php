<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

/**
 * Declared via extra.kinetis in this package's composer.json and run by
 * the framework ahead of the application's own bootstrap.php: with
 * DB_CONNECTION configured, the default connection is built and bound
 * under its dialect contract, so application code constructor-injects
 * MysqlLink/PostgresLink with zero bootstrap code of its own. Without
 * DB_CONNECTION this stays inert — "no database" is a configuration,
 * not an error.
 *
 * The application's bootstrap.php runs after this and wins on a shared
 * binding — an app that wants different pool options simply registers
 * its own SqlConnectionFactory::fromConfig() result as before. Named
 * (non-default) connections stay explicit app-side wiring; only the
 * default DB_* block is bound here.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->get('DB_CONNECTION') === null) {
            return;
        }

        $link = SqlConnectionFactory::fromConfig($config);

        $app->instance($link instanceof MysqlLink ? MysqlLink::class : PostgresLink::class, $link);
    }
}
