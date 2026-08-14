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
        $this->expectExceptionMessage('ConnectionOptions $charset must match');
        $this->expectExceptionMessage('got "bad charset"');
        new ConnectionOptions(charset: 'bad charset');
    }

    public function test_a_non_identifier_collation_is_rejected_naming_field_and_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $collation must match');
        $this->expectExceptionMessage('got "utf8mb4_unicode_ci; DROP"');
        new ConnectionOptions(collation: 'utf8mb4_unicode_ci; DROP');
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
        $this->expectExceptionMessage("The test-driver driver does not support the \"{$field}\" connection option.");
        $options->rejectUnsupported('test-driver', [$field]);
    }

    #[DataProvider('eachFieldSet')]
    public function test_reject_unsupported_passes_when_the_field_is_not_listed(ConnectionOptions $options): void
    {
        $field = $this->dataName();
        \assert(\is_string($field));

        $others = \array_values(\array_diff(
            ['charset', 'collation', 'sslMode', 'connectTimeout', 'applicationName', 'compression', 'extraConnectionString'],
            [$field],
        ));

        $options->rejectUnsupported('test-driver', $others);
        $this->addToAssertionCount(1); // No exception: the set field wasn't in the rejected list.
    }

    public function test_reject_unsupported_ignores_unset_fields(): void
    {
        new ConnectionOptions()->rejectUnsupported(
            'test-driver',
            ['charset', 'collation', 'sslMode', 'connectTimeout', 'applicationName', 'compression', 'extraConnectionString'],
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
