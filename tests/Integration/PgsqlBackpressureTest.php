<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Revolt\EventLoop;
use Throwable;

/**
 * A parameter too large for libpq's output buffer must leave the event
 * loop running while it is pushed out, not flush synchronously inside
 * the dispatch call.
 *
 * The backpressure is real: a throttling relay ({@see pg-relay.php})
 * stands between the client and a real Postgres server, moving
 * client-to-server bytes in small chunks with a pause between them, so
 * the client fills the pipe and has to wait for writable events. The
 * relay is a separate process, which is what makes a regression fail
 * this test rather than deadlock it — a synchronous flush still
 * completes, just with the loop stopped for its whole duration.
 *
 * That is the discriminator: a repeating callback samples the clock
 * while the statement runs, and the largest gap between its samples is
 * how long the loop was unable to run anything. Writable-event progress
 * leaves gaps in the milliseconds; a synchronous flush leaves one the
 * length of the whole transfer. The transfer being slow enough to tell
 * the two apart is asserted too, so a relay that failed to throttle
 * fails the test rather than passing it vacuously.
 *
 * The last test here covers the other direction of the same dispatch:
 * the server talking back while the statement is still going out. A
 * client watching only for writability never drains that traffic, so
 * the pipe toward it fills, the peer stops reading the statement, and
 * the flush never finishes. A watchdog bounds the wait and ends the
 * statement, so that regression fails this test in seconds rather than
 * hanging it.
 */
final class PgsqlBackpressureTest extends DriverCase
{
    /**
     * Enough to outgrow libpq's output buffer, the relay's receive
     * window and both kernel socket buffers several times over — half
     * this already leaves nothing for a writable event to do.
     */
    private const int PAYLOAD_BYTES = 8 * 1024 * 1024;

    private const int RELAY_CHUNK_BYTES = 32768;

    private const int RELAY_DELAY_MICROSECONDS = 2000;

    /** The relay's ceiling puts the transfer above this; a loop that stopped for it pays all of it in one gap. */
    private const float MINIMUM_TRANSFER_SECONDS = 0.3;

    /** Wide enough for an ordinary scheduling hiccup, far below a stopped transfer. */
    private const float MAXIMUM_LOOP_GAP_SECONDS = 0.2;

    /**
     * Reverse-direction traffic the relay sends while the statement is
     * still going out. Above the relay's own send buffer and the largest
     * receive buffer the client's kernel tunes itself to, so a client
     * that stops reading really does stall the relay rather than leaving
     * the traffic in a buffer nobody waits on.
     */
    private const int REVERSE_TRAFFIC_BYTES = 8 * 1024 * 1024;

    /** Far above the second or so the whole exchange takes, and decisive about a flush that stopped. */
    private const float WATCHDOG_SECONDS = 10.0;

    /** @var resource|null */
    private mixed $relay = null;

    /** @var array<int, resource> */
    private array $relayPipes = [];

    protected function tearDown(): void
    {
        foreach ($this->relayPipes as $pipe) {
            \fclose($pipe);
        }

        $this->relayPipes = [];

        if (\is_resource($this->relay)) {
            \proc_terminate($this->relay);
            \proc_close($this->relay);
        }

        $this->relay = null;
    }

    public function test_a_large_parameter_on_the_root_link_is_flushed_from_the_event_loop(): void
    {
        $this->assertTheLoopKeptRunning(
            static fn (PostgresLink $db, string $payload): int
                => (int) $db->execute('SELECT length(?) AS n', [$payload])->fetchRow()['n'],
        );
    }

    public function test_a_large_parameter_inside_a_transaction_is_flushed_from_the_event_loop(): void
    {
        $this->assertTheLoopKeptRunning(
            static function (PostgresLink $db, string $payload): int {
                $transaction = $db->beginTransaction();
                $length = (int) $transaction->execute('SELECT length(?) AS n', [$payload])->fetchRow()['n'];
                $transaction->commit();

                return $length;
            },
        );
    }

    public function test_server_traffic_during_a_large_dispatch_does_not_wedge_the_flush(): void
    {
        $db = new PgsqlAsyncClient(...[
            ...$this->throttledArgs(self::REVERSE_TRAFFIC_BYTES),
            new ConnectionOptions(maxConnections: 1),
        ]);
        $payload = \str_repeat('x', self::PAYLOAD_BYTES);
        $wedged = false;

        // The bound on the whole test: a dispatch that stopped has no
        // event left to wait for, so ending the statement is what makes
        // the failure an assertion rather than a hang.
        $watchdog = EventLoop::delay(self::WATCHDOG_SECONDS, static function () use ($db, &$wedged): void {
            $wedged = true;
            $db->close();
        });

        try {
            self::concurrently([
                static function () use ($db, $payload, $watchdog): void {
                    try {
                        self::assertSame(
                            self::PAYLOAD_BYTES,
                            (int) $db->execute('SELECT length(?) AS n', [$payload])->fetchRow()['n'],
                        );
                    } finally {
                        EventLoop::cancel($watchdog);
                    }
                },
            ]);
        } catch (Throwable $e) {
            if ($wedged) {
                self::fail(
                    'The dispatch made no further progress once the server sent traffic back: '
                    . $e->getMessage(),
                );
            }

            throw $e;
        } finally {
            EventLoop::cancel($watchdog);
            $db->close();
        }

        // Without this the test would pass on a relay that stayed
        // silent, which proves nothing about the reverse direction.
        self::assertGreaterThanOrEqual(self::REVERSE_TRAFFIC_BYTES, $this->reverseTrafficSent());
    }

    /**
     * How much reverse-direction traffic the relay reports having sent.
     * It announces the total as soon as the budget is spent, which is
     * before the statement it interrupted can have finished going out.
     */
    private function reverseTrafficSent(): int
    {
        $read = [$this->relayPipes[1]];
        $write = $except = [];

        if (\stream_select($read, $write, $except, 2) !== 1) {
            return 0;
        }

        $announced = \fgets($this->relayPipes[1]);

        return $announced === false ? 0 : (int) \trim($announced);
    }

    /**
     * Runs $write against a client behind the throttling relay while a
     * repeating callback samples the clock, and asserts both that the
     * transfer was slow enough to matter and that no single loop turn
     * swallowed it. The payload's length as the server measured it is
     * checked too: a flush that gave up halfway would otherwise satisfy
     * the timing.
     *
     * @param callable(PostgresLink, string): int $write
     */
    private function assertTheLoopKeptRunning(callable $write): void
    {
        $db = new PgsqlAsyncClient(...[...$this->throttledArgs(), new ConnectionOptions(maxConnections: 1)]);
        $payload = \str_repeat('x', self::PAYLOAD_BYTES);
        $samples = [\microtime(true)];

        $sampler = EventLoop::repeat(0.005, static function () use (&$samples): void {
            $samples[] = \microtime(true);
        });
        // Sampling must not be a reason for the loop to stay alive.
        EventLoop::unreference($sampler);

        try {
            self::concurrently([
                static function () use ($db, $payload, $write): void {
                    self::assertSame(self::PAYLOAD_BYTES, $write($db, $payload));
                },
            ]);
        } finally {
            EventLoop::cancel($sampler);
            $db->close();
        }

        $samples[] = \microtime(true);
        $elapsed = \end($samples) - $samples[0];

        self::assertGreaterThan(
            self::MINIMUM_TRANSFER_SECONDS,
            $elapsed,
            'The relay did not throttle, so this proves nothing about the dispatch.',
        );
        self::assertLessThan(self::MAXIMUM_LOOP_GAP_SECONDS, self::largestGap($samples));
    }

    /**
     * @param list<float> $samples
     */
    private static function largestGap(array $samples): float
    {
        $largest = 0.0;

        for ($i = 1; $i < \count($samples); $i++) {
            $largest = \max($largest, $samples[$i] - $samples[$i - 1]);
        }

        return $largest;
    }

    /**
     * The same Postgres server, reached through the relay. $reverseBytes
     * is how much traffic the relay sends back mid-upload, none by
     * default.
     *
     * @return array{string, string, string, string, int}
     */
    private function throttledArgs(int $reverseBytes = 0): array
    {
        [$host, $user, $password, $database, $port] = self::postgresArgs();

        $relay = \proc_open(
            [
                \PHP_BINARY,
                __DIR__ . '/pg-relay.php',
                $host,
                (string) $port,
                (string) self::RELAY_CHUNK_BYTES,
                (string) self::RELAY_DELAY_MICROSECONDS,
                (string) $reverseBytes,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($relay)) {
            self::fail('Could not start the relay process.');
        }

        $this->relay = $relay;
        $this->relayPipes = $pipes;
        $announced = \fgets($pipes[1]);

        if ($announced === false) {
            self::fail('The relay process did not announce a port: ' . (string) \stream_get_contents($pipes[2]));
        }

        return ['127.0.0.1', $user, $password, $database, (int) \trim($announced)];
    }
}
