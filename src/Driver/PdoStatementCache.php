<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;
use PDO;
use PDOStatement;

/**
 * The prepared-statement memo both PDO execution paths keep — a
 * client's, living as long as its connection ({@see PdoExecutionTrait}),
 * and a transaction's own, living as long as it owns the handle
 * ({@see PdoTransaction}).
 *
 * Reuse is what makes binding the faster option on these drivers: both
 * run native (non-emulated) prepares, where every prepare() is its own
 * server round trip, so a loop issuing one SQL string N times costs
 * N+1 round trips instead of 2N. MySQL and Postgres both scope a
 * prepared statement to its connection, which is exactly how long an
 * instance of this cache lives.
 *
 * Reuse is also why nothing reaches this class unchecked. A PDOStatement
 * keeps whatever was last bound to it, and {@see PdoParamBinder} binds
 * only the values it is given — so on a reused statement the leftovers
 * from an earlier execution would stand in for whatever the current one
 * left unbound, running "SELECT ?, ?" with [3] as if it were [3, 2]
 * where a freshly prepared statement would have been rejected outright.
 * {@see SqlParamPreflight} settles keying, count and value kind before
 * the caller's connection is even resolved, and hands the outcome down
 * as a {@see PreflightedQuery}; this class prepares and binds it, and
 * decides nothing about it.
 *
 * @internal
 */
final class PdoStatementCache
{
    /**
     * Beyond this many distinct SQL strings the whole memo is dropped.
     * A workload that interpolates values into its SQL instead of
     * binding would otherwise grow it without limit; a full reset is
     * crude but keeps the steady state — a bounded set of parameterized
     * statements — at exactly one prepare each.
     */
    private const int MAX_ENTRIES = 256;

    /** @var array<string, PDOStatement> */
    private array $entries = [];

    /**
     * Binds and executes $query — preparing its SQL, or reusing the
     * statement already prepared for that exact text — and returns the
     * executed statement for the caller's own result construction.
     */
    public function execute(PDO $pdo, PreflightedQuery $query): PDOStatement
    {
        $statement = $this->entries[$query->sql] ?? null;

        if ($statement === null) {
            if (\count($this->entries) >= self::MAX_ENTRIES) {
                $this->entries = [];
            }

            $prepared = $pdo->prepare($query->sql);

            if ($prepared === false) {
                throw new QueryException('Failed to prepare query', $query->sql);
            }

            $statement = $this->entries[$query->sql] = $prepared;
        }

        PdoParamBinder::bind($statement, $query->values);
        $statement->execute();

        return $statement;
    }
}
