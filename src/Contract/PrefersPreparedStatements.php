<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Declares that this link is faster binding a value than reading it as a
 * literal in the SQL text — so a caller that could do either should bind.
 *
 * Which way round that goes is a property of the driver, not the dialect.
 * The native mysqli and pgsql drivers reach the server once for a query
 * carrying no parameters and twice for a prepared one, so writing a safe
 * value straight into the SQL saves a round trip. The PDO drivers run
 * with native prepares and memoize the prepared statement, so binding
 * costs one round trip after the first and keeps the binary protocol,
 * while an unparameterized query drops to the text protocol and measures
 * around half again as expensive per query.
 *
 * A marker rather than a method: there is nothing to call, only a fact
 * about the implementation for a caller to branch on.
 * Kinetis\QueryBuilder\Query is the caller that does.
 */
interface PrefersPreparedStatements {}
