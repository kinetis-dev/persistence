<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\ConnectionOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionOptionsTest extends TestCase
{
    public function test_valid_identifier_charset_and_collation_are_accepted(): void
    {
        $options = new ConnectionOptions(charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci');

        self::assertSame('utf8mb4', $options->charset);
        self::assertSame('utf8mb4_unicode_ci', $options->collation);
    }

    public function test_a_non_identifier_charset_is_rejected_naming_field_and_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $charset must match /^[A-Za-z0-9_]+$/, got "bad charset".');
        new ConnectionOptions(charset: 'bad charset');
    }

    public function test_a_non_identifier_collation_is_rejected_naming_field_and_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $collation must match /^[A-Za-z0-9_]+$/, got "utf8mb4_unicode_ci; DROP".');
        new ConnectionOptions(collation: 'utf8mb4_unicode_ci; DROP');
    }

    public function test_an_unknown_ssl_mode_is_rejected_naming_the_vocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $sslMode must be one of disable|allow|prefer|require|verify-ca|verify-full, got "mandatory".');
        new ConnectionOptions(sslMode: 'mandatory');
    }

    public function test_an_empty_ssl_ca_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $sslCa must be a file path, not an empty string.');
        new ConnectionOptions(sslCa: '');
    }

    public function test_connect_timeout_boundary_one_second_is_accepted_zero_is_not(): void
    {
        self::assertSame(1, new ConnectionOptions(connectTimeout: 1)->connectTimeout);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('connectTimeout');
        new ConnectionOptions(connectTimeout: 0);
    }

    public function test_max_connections_boundary_one_is_accepted_zero_is_not(): void
    {
        self::assertSame(1, new ConnectionOptions(maxConnections: 1)->maxConnections);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxConnections');
        new ConnectionOptions(maxConnections: 0);
    }

    /**
     * @return iterable<string, array{ConnectionOptions}>
     */
    public static function eachFieldSet(): iterable
    {
        yield 'charset' => [new ConnectionOptions(charset: 'latin1')];
        yield 'collation' => [new ConnectionOptions(collation: 'latin1_swedish_ci')];
        yield 'sslMode' => [new ConnectionOptions(sslMode: 'require')];
        yield 'sslCa' => [new ConnectionOptions(sslCa: '/certs/ca.pem')];
        yield 'connectTimeout' => [new ConnectionOptions(connectTimeout: 5)];
        yield 'applicationName' => [new ConnectionOptions(applicationName: 'myapp')];
        yield 'compression' => [new ConnectionOptions(compression: true)];
        yield 'extraConnectionString' => [new ConnectionOptions(extraConnectionString: 'x=y')];
    }

    /**
     * Every field must be individually detectable by rejectUnsupported():
     * a match arm silently dropping out would turn a loud failure into a
     * silent ignore, which is the exact bug class this method exists to
     * prevent.
     */
    #[DataProvider('eachFieldSet')]
    public function test_reject_unsupported_detects_each_set_field(ConnectionOptions $options): void
    {
        $field = $this->dataName();
        \assert(\is_string($field));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The test-driver driver does not support the \"{$field}\" connection option. Unset it, or select a driver that supports it (see docs/persistence.md for the matrix).");
        $options->rejectUnsupported('test-driver', [$field]);
    }

    #[DataProvider('eachFieldSet')]
    public function test_reject_unsupported_passes_when_the_field_is_not_listed(ConnectionOptions $options): void
    {
        $field = $this->dataName();
        \assert(\is_string($field));

        $others = \array_values(\array_diff(
            ['charset', 'collation', 'sslMode', 'sslCa', 'connectTimeout', 'applicationName', 'compression', 'extraConnectionString'],
            [$field],
        ));

        $options->rejectUnsupported('test-driver', $others);
        $this->addToAssertionCount(1); // No exception: the set field wasn't in the rejected list.
    }

    public function test_reject_unsupported_ignores_unset_fields(): void
    {
        new ConnectionOptions()->rejectUnsupported(
            'test-driver',
            ['charset', 'collation', 'sslMode', 'sslCa', 'connectTimeout', 'applicationName', 'compression', 'extraConnectionString'],
        );
        $this->addToAssertionCount(1);
    }

    public function test_reject_unsupported_throws_on_an_unknown_field_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ConnectionOptions field "bogus"');
        new ConnectionOptions()->rejectUnsupported('test-driver', ['bogus']);
    }
}
