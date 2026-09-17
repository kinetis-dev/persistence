<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Closure;
use Kinetis\Persistence\Driver\PdoParamBinder;
use Kinetis\Persistence\Driver\SqlParamInterpolator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A finite float reaches every driver as text that reads back as the
 * exact binary float the caller bound.
 *
 * Every case here runs with `precision` and `serialize_precision` set to
 * 14 — the value a default PHP install ships `precision` at, and the one
 * that made a `(string)` cast or a `json_encode()` the wrong tool for
 * this. An encoding that depended on either setting fails here and
 * passes on a host that happens to have raised them.
 */
final class FloatParameterEncodingTest extends TestCase
{
    /**
     * Runs $case with both float-formatting settings truncating, and
     * puts the process back the way it was whatever the case does: these
     * are process-global, and this package's own suite must not decide
     * how the rest of a run formats a float.
     */
    private static function truncating(Closure $case): void
    {
        $precision = (string) \ini_get('precision');
        $serialize = (string) \ini_get('serialize_precision');
        \ini_set('precision', '14');
        \ini_set('serialize_precision', '14');

        try {
            $case();
        } finally {
            \ini_set('precision', $precision);
            \ini_set('serialize_precision', $serialize);
        }
    }

    /**
     * The values a 14-significant-digit spelling gets wrong, plus the
     * three shapes the encoding itself has to keep: an exponent, a whole
     * number, and the sign of a zero.
     *
     * @return iterable<string, array{float}>
     */
    public static function difficultFloats(): iterable
    {
        yield 'a sum with no exact binary form' => [0.1 + 0.2];
        yield 'a microsecond timestamp' => [1726480000.123456];
        yield '17 significant digits' => [1.2345678901234567];
        yield 'exponent form' => [1.0E+25];
        yield 'a whole number' => [1.0];
        yield 'negative zero' => [-0.0];
    }

    #[DataProvider('difficultFloats')]
    public function test_encoding_round_trips_the_exact_binary_float(float $value): void
    {
        self::truncating(static function () use ($value): void {
            $decoded = (float) SqlParamInterpolator::encodeFloat($value);

            self::assertSame($value, $decoded);
            // -0.0 === 0.0 in PHP, so a zero's sign needs its own read:
            // 1/-0.0 is -INF and 1/0.0 is INF.
            self::assertSame(\fdiv(1.0, $value), \fdiv(1.0, $decoded));
        });
    }

    #[DataProvider('difficultFloats')]
    public function test_every_encoding_is_plain_numeric_text(float $value): void
    {
        self::truncating(static function () use ($value): void {
            // What both dialects accept as a numeric literal and as
            // parameter text: digits, one optional fraction, one
            // optional exponent, and nothing else — no locale separator,
            // no thousands grouping, no quoting.
            self::assertMatchesRegularExpression(
                '/^-?[0-9]+(\.[0-9]+)?(E[+-][0-9]+)?$/',
                SqlParamInterpolator::encodeFloat($value),
            );
        });
    }

    /**
     * The failure the encoder exists for. Both of these spellings are
     * what the drivers used to put on the wire, and both are a different
     * number than the caller bound.
     */
    public function test_the_casts_this_replaces_lose_the_same_values(): void
    {
        self::truncating(static function (): void {
            self::assertSame('0.3', (string) (0.1 + 0.2));
            self::assertSame('0.30000000000000004', SqlParamInterpolator::encodeFloat(0.1 + 0.2));

            // json_encode() only moves the truncation onto
            // serialize_precision, which is why it is not the fix.
            self::assertSame('1726480000.1235', \json_encode(1726480000.123456));
            self::assertSame('1726480000.123456', SqlParamInterpolator::encodeFloat(1726480000.123456));
        });
    }

    /**
     * printf spells the decimal separator the way LC_NUMERIC does, and a
     * comma would turn one bound value into two SQL expressions. Skipped
     * where no comma-decimal locale is installed — musl builds carry
     * none at all.
     */
    public function test_a_comma_decimal_locale_still_encodes_a_dot(): void
    {
        $previous = (string) \setlocale(LC_NUMERIC, '0');

        try {
            \setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');

            if (\localeconv()['decimal_point'] === '.') {
                self::markTestSkipped('No comma-decimal locale is installed here.');
            }

            self::assertSame('0.30000000000000004', SqlParamInterpolator::encodeFloat(0.1 + 0.2));
        } finally {
            \setlocale(LC_NUMERIC, $previous);
        }
    }

    /**
     * The PDO binder's half of the same contract: a float is bound as
     * the encoder's text, a string is bound exactly as it was given, and
     * the three typed kinds keep their PDO::PARAM_* type.
     */
    public function test_the_pdo_binder_binds_encoded_float_text_and_unchanged_strings(): void
    {
        self::truncating(static function (): void {
            $bound = [];
            // A stub, not a mock: what is asserted is the arguments the
            // binder passed, which the callback below collects.
            $statement = self::createStub(PDOStatement::class);
            $statement->method('bindValue')->willReturnCallback(
                static function (mixed $position, mixed $value, int $type) use (&$bound): bool {
                    $bound[] = [$position, $value, $type];

                    return true;
                },
            );

            PdoParamBinder::bind($statement, [0.1 + 0.2, '0.1 + 0.2', 1.0, 7, true, null]);

            self::assertSame([
                [1, '0.30000000000000004', PDO::PARAM_STR],
                [2, '0.1 + 0.2', PDO::PARAM_STR],
                [3, '1', PDO::PARAM_STR],
                [4, 7, PDO::PARAM_INT],
                [5, true, PDO::PARAM_BOOL],
                [6, null, PDO::PARAM_NULL],
            ], $bound);
        });
    }
}
