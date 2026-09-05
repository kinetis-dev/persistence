<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/persistence</strong>
  <br>
  <strong>Request-scoped SQL transaction safety net and connection factory for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/persistence"><img src="https://img.shields.io/packagist/v/kinetis/persistence?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/persistence"><img src="https://img.shields.io/packagist/dt/kinetis/persistence" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/persistence"><img src="https://img.shields.io/packagist/php-v/kinetis/persistence" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/persistence"><img src="https://img.shields.io/packagist/l/kinetis/persistence" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

MySQL and Postgres through runtime-matched drivers — native
`ext-mysqli`/`ext-pgsql` async clients under a persistent worker, PDO
under boot-and-die — all presenting this package's own
`Contract\SqlLink`/`Contract\SqlTransaction` abstraction, so nothing
above the driver needs to know which one it's talking to.

```php
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Persistence\TransactionGuard;

$db = SqlConnectionFactory::fromConfig($config);

$guard->transaction($db, function ($tx) {
    $tx->execute('INSERT INTO orders (...) VALUES (...)');
});
```

`SqlConnectionFactory::fromConfig()` builds a runtime-matched driver
client from `Kinetis\Config` (the `DB_*` keys below, or their
`DB_{NAME}_*` named-connection equivalents), bound under
`Contract\MysqlLink`/`Contract\PostgresLink` automatically once
`DB_CONNECTION` is set — this package's bootstrap registers it before
the application's own `bootstrap.php`, which wins on the same binding.

`TransactionGuard` is the genuinely Kinetis-specific piece: request-scoped, autowired fresh
per `RequestScope` like any other unregistered class, tracking every
transaction it starts so a request that throws before committing or
rolling back doesn't leak an open transaction into whatever the pooled
connection is reused for next. `Kinetis\Http\Kernel` wires
`rollbackDangling()` into `RequestScope::onDispose()` automatically
whenever this package is installed — a genuine no-op for a request that
never opens one.

Optional: an application with no database at all can skip this package
entirely — `Kinetis\Http\Kernel` degrades gracefully (`class_exists()`
check, no dispose hook registered) when it isn't installed.

## Provides

Installing this package is what opts it in — it registers the
following automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[kinetis.dev/docs/cli.html](https://kinetis.dev/docs/cli.html)):

- **Service binding**: with `DB_CONNECTION` set, the default connection
  is built and bound under its dialect contract
  (`Kinetis\Persistence\Contract\MysqlLink` or
  `Contract\PostgresLink`) before your own `bootstrap.php` runs — your
  registration wins on the same binding. Inert when `DB_CONNECTION` is
  unset.

Nothing else — no commands, routes, middleware, event listeners, or
MCP tools.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`. Every key
is scoped.

| Key | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | *(required)* | `mysql` or `pgsql`. |
| `DB_HOST` | `127.0.0.1` | Server host. |
| `DB_PORT` | `3306` / `5432` | Per dialect. |
| `DB_NAME` | `app` | Database name. |
| `DB_USER` | `app` | User. |
| `DB_PASSWORD` | *(required)* | Password. |
| `DB_DRIVER` | `auto` | `auto` (native under FrankenPHP worker mode or RoadRunner, PDO otherwise), `native`, or `pdo`. |
| `DB_CHARSET` | `utf8mb4` (MySQL) | Connection charset. |
| `DB_COLLATION` | — | MySQL collation (`SET NAMES ... COLLATE`). |
| `DB_SSLMODE` | — | `disable`/`require`/`verify-ca`/`verify-full` on every driver; libpq additionally accepts `allow`/`prefer`. |
| `DB_SSL_CA` | — | CA bundle path for the verify modes. |
| `DB_SSL_CERT` | — | Client certificate for mutual TLS; requires `DB_SSL_KEY`. |
| `DB_SSL_KEY` | — | Client private key; requires `DB_SSL_CERT`. Postgres requires `0600` permissions. |
| `DB_CONNECT_TIMEOUT` | — | Seconds. |
| `DB_APP_NAME` | — | Postgres `application_name`. |
| `DB_COMPRESSION` | — | MySQL protocol compression. |
| `DB_MAX_CONNECTIONS` | `8` | Async drivers' pool width — per worker thread under FrankenPHP, per worker process under RoadRunner. |
| `DB_WARM_CONNECTIONS` | `0` | Connections opened at boot instead of first use — load-bearing for the mysqli driver under worker mode. |

Scoped keys follow the named-connection convention — the connection
name inserts after the first segment: `DB_HOST` + `reporting` → `DB_REPORTING_HOST`.
Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/persistence
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework). Full documentation:
[kinetis.dev/docs/persistence.html](https://kinetis.dev/docs/persistence.html).

## License

MIT — see [LICENSE](LICENSE).
