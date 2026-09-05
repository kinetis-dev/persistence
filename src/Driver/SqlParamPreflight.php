<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * The parameter pre-flight every driver runs as the first thing
 * `execute()` does — ahead of its telemetry span, its connection pool,
 * the connection itself, the prepared-statement memo and any session or
 * server state. Keying, count and value kind are all decided here
 * ({@see SqlParamInterpolator::assertPositionalKeys()},
 * {@see SqlParamInterpolator::assertParameterCount()},
 * {@see SqlParamInterpolator::assertBindableValues()}, in that order),
 * so an argument list outside the contract costs a caller one
 * {@see \Kinetis\Persistence\Exception\QueryException} and nothing else:
 * no dispatch event, no connection attempt against a cold or unreachable
 * server, no charset or collation change, no prepare, no bound position.
 *
 * That ordering is the contract, not an optimization. A driver that
 * validated on the way *through* execution would report an unreachable
 * server for a query it was never going to send, and would report it
 * differently depending on whether its pool happened to be warm — so
 * the same mistake would read as `QueryException` on one call and
 * `ConnectionException` on the next.
 *
 * One instance per client and per transaction, each holding its own
 * dialect: the two disagree on enough lexical detail that which "?" is
 * a placeholder is a dialect question ({@see SqlParamInterpolator}).
 * What comes out is a {@see PreflightedQuery} the execution layer
 * consumes — the later layer may read that decision, and never makes it.
 *
 * @internal
 */
final class SqlParamPreflight
{
    /**
     * Beyond this many distinct SQL strings the whole memo is dropped.
     * A workload that interpolates values into its SQL instead of
     * binding would otherwise grow it without limit; a full reset is
     * crude but keeps the steady state — a bounded set of parameterized
     * statements — at exactly one scan each.
     */
    private const int MAX_ENTRIES = 256;

    /**
     * The placeholder split memoized per SQL string. It is a pure
     * function of the text and the dialect, so one scan per distinct
     * query keeps the per-execution cost of running the pre-flight this
     * early down to an array lookup and two integer comparisons.
     *
     * @var array<string, list<string>>
     */
    private array $segments = [];

    public function __construct(private readonly SqlDialect $dialect) {}

    /**
     * Settles $params against $sql, or throws.
     *
     * @param array<array-key, mixed> $params
     */
    public function run(string $sql, array $params): PreflightedQuery
    {
        // Ahead of the count, which only means anything once the keys
        // are known to be the positions.
        SqlParamInterpolator::assertPositionalKeys($params, $sql);

        $segments = $this->segments[$sql] ?? null;

        if ($segments === null) {
            // Scanned before the memo is touched, so a query carrying
            // a "?" the scan refuses outright — one inside a MySQL
            // executable comment — throws here rather than becoming an
            // entry a later call would trust.
            $segments = SqlParamInterpolator::split($sql, $this->dialect);

            if (\count($this->segments) >= self::MAX_ENTRIES) {
                $this->segments = [];
            }

            $this->segments[$sql] = $segments;
        }

        SqlParamInterpolator::assertParameterCount(\count($segments) - 1, \count($params), $sql);

        return new PreflightedQuery($sql, $segments, SqlParamInterpolator::assertBindableValues($params, $sql));
    }
}
