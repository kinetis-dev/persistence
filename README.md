<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/persistence</strong>
  <br>
  <strong>Runtime-matched MySQL and Postgres clients with a transaction safety net</strong>
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
Usable standalone: it depends on no Kinetis package.

MySQL and Postgres through runtime-matched drivers — native
`ext-mysqli`/`ext-pgsql` async clients under a persistent worker, PDO
under boot-and-die — all presenting this package's own
`Contract\SqlLink`/`Contract\SqlTransaction` abstraction, so nothing
above the driver needs to know which one it's talking to.

```php
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Persistence\TransactionGuard;

$db = SqlConnectionFactory::create(new ConnectionDefinition(
    dialect: 'mysql',
    host: 'db.internal',
    database: 'shop',
    user: 'shop',
    password: $password,
));

// One guard per unit of work: a request, a job, a command.
$guard = new TransactionGuard($logger);

try {
    $guard->transaction($db, static function (SqlTransaction $tx): void {
        $tx->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', ['SKU-1']);
    });
} finally {
    $guard->rollbackDangling();
}
```

`ConnectionDefinition` holds the dialect (`mysql` or `pgsql`), the
driver selection, host, port (the dialect's own by default), database,
credentials, `ConnectionOptions` — charset, TLS, connect timeout, pool
width — and how many connections to open at construction.
`SqlConnectionFactory::create()` builds the client its driver selection
names: `native` (mysqli or ext-pgsql async), `pdo`, or `auto` — native
under FrankenPHP worker mode or RoadRunner, PDO everywhere else.
`SqlConnectionFactory::singleSession()` builds a PDO client pinned to
the first session it opens, which never opens a replacement: the client
for work that lives in the session, such as a session-scoped advisory
lock. An ordinary PDO client gives up its session after a failed
statement or `beginTransaction()` and opens a fresh one on its next
call, without sending the failed statement again.

## What the host owns

- **A guard per unit of work.** `TransactionGuard::transaction()`
  commits on success and rolls back on any throw. A transaction begun
  with the guard's own `beginTransaction()` and held open across calls is
  tracked until the unit of work ends, where the host calls
  `rollbackDangling()` — from a `finally`, so it runs whether the work
  returned or threw. It closes every tracked transaction still open,
  logs a warning for each, and rethrows the first cleanup failure once
  all have been attempted. A guard is never shared between units of
  work.
- **A client per connection for the process's lifetime.** The async
  clients are connection pools: build each once at startup and reuse it
  across units of work. Under a persistent worker, warm the mysqli pool
  at startup (`warmConnections`); see the documentation for why.
- **Closing clients at shutdown.** `close()` takes a client out of
  service and ends the connections it holds; every later call throws
  `Exception\ConnectionException`. Call it when the process stops using
  the client.

## Failures

Every persistence failure is an `Exception\SqlException`:

- **`Exception\ConnectionException`** — connecting failed, or an
  established connection died.
- **`Exception\QueryException`** — a statement was rejected or failed.
  `getQuery()` is its SQL text, `getCode()` the server's own error number
  (`0` where none was reported), and `getSqlState()` the SQLSTATE the
  server or driver reported, or `null`.
- **`Exception\TransactionException`** — transaction misuse: a statement
  on a transaction the server or the application already ended, nesting
  the driver refuses, or a failed `COMMIT`/`ROLLBACK`.

`QueryException::isUniqueViolation()` is the portable unique-conflict
classifier, true on every driver for PostgreSQL's SQLSTATE `23505` and
for the MySQL family's error 1062, `ER_DUP_ENTRY`. MySQL and MariaDB
report SQLSTATE `23000` for every integrity violation, a `NOT NULL` one
included, so there the error number decides and the SQLSTATE cannot.
Classifying a failure changes nothing about it: the statement is not
retried and the error is not suppressed.

Let a unique key settle a race instead of reading before the write, since
two requests can both find a value free, and catch the violation outside
`TransactionGuard::transaction()`, which has already rolled the
transaction back by then.

## Instrumentation

Pass a `Contract\SqlInstrumentation` as either factory method's second
argument to receive what every client and transaction reports: a query
dispatched, sent to the server, and reaped; a transaction started, and
ended as `commit`, `rollback` or `unknown`. A transaction's own `BEGIN`,
`COMMIT` and `ROLLBACK` are not query moments — the started/ended pair is
what reports them — while every statement run through the transaction
reports all three. A client contains any failure its instrumentation
throws, so instrumentation cannot change a query result, a transaction
outcome or the release of a connection.

Every moment runs inline on the query's Fiber: an implementation must not
suspend, must stay bounded, and must perform no blocking I/O — anything
it exports goes to separately owned, bounded infrastructure. A client
keeps its instrumentation for its whole lifetime, so the implementation
holds no mutable request or unit-of-work state. `queryDispatched()`
receives the complete SQL text; bound parameter values are never passed,
but the text can carry literals, so never log or export it verbatim.

## With Kinetis

```sh
composer require kinetis/database-bridge
```

[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
builds the clients from `DB_*` configuration, binds the default
connection, provides lazy request-scoped `TransactionGuard` cleanup, and
reports through Kinetis telemetry.

## Installation

```sh
composer require kinetis/persistence
```

Requires PHP 8.4+, plus the extension for the driver you use:
`ext-mysqli`, `ext-pgsql` (with `ext-sockets`), `ext-pdo_mysql` or
`ext-pdo_pgsql`. Full documentation:
[kinetis.dev/docs/persistence.html](https://kinetis.dev/docs/persistence.html).

## License

MIT — see [LICENSE](LICENSE).
