<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/persistence</strong>
  <br>
  <strong>Request-scoped SQL transaction safety net and connection factory for Kinetis</strong>
</p>

---

MySQL and Postgres via `amphp/mysql`/`amphp/postgres`, both sharing the
`Amp\Sql\SqlLink`/`SqlTransaction` abstraction — this package doesn't
need to know which one it's talking to.

```php
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Persistence\TransactionGuard;

$db = SqlConnectionFactory::fromConfig($config);

$guard->transaction($db, function ($tx) {
    $tx->execute('INSERT INTO orders (...) VALUES (...)');
});
```

`SqlConnectionFactory::fromConfig()` builds a
`MysqlConnectionPool`/`PostgresConnectionPool` from `Kinetis\Config`
(`DB_CONNECTION`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_PORT`,
or their `DB_{NAME}_*` named-connection equivalents). `TransactionGuard`
is the genuinely Kinetis-specific piece: request-scoped, autowired fresh
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

## Installation

```sh
composer require kinetis/persistence
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/persistence.html](https://docs.kinetis.dev/persistence.html).

## License

MIT — see [LICENSE](../../LICENSE).
