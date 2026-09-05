<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * One `execute()` call after {@see SqlParamPreflight} has settled it and
 * before any driver has acted on it: the SQL text, the literal segments
 * the placeholder scan split it into, and the argument list held to the
 * value contract.
 *
 * Carrying the outcome of the pre-flight rather than re-deriving it is
 * what lets the pre-flight run first. A driver reaches its telemetry
 * span, its pool, its connection and its prepare with this object
 * already in hand, so the only work left at dispatch is encoding values
 * a check has already accepted — never deciding whether to accept them.
 *
 * @internal
 */
final class PreflightedQuery
{
    /**
     * @param list<string> $segments The literal text between the
     *     recognized "?" placeholders, one segment more than there are
     *     placeholders, so joining them around the encoded values
     *     reproduces $sql.
     * @param list<null|bool|int|float|string> $values One argument per
     *     placeholder, in placeholder order.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $segments,
        public readonly array $values,
    ) {}
}
