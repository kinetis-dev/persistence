<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Declares that this link is faster binding a value than reading it as a
 * literal in the SQL text — so a caller that could safely emit either
 * keeps binding on this link.
 *
 * Which way round that goes is a property of the driver, not the
 * dialect. The PDO drivers carry it because they run native prepared
 * statements and memoize them; the native mysqli and pgsql links do
 * not, so on those there is no prepared statement for a bound value to
 * reuse.
 *
 * A marker rather than a method: there is nothing to call, only a fact
 * about the implementation for a caller to branch on.
 * Kinetis\QueryBuilder\Query is the caller that does.
 */
interface PrefersPreparedStatements {}
