<?php

declare(strict_types=1);

/**
 * A throttling TCP relay in front of a real Postgres server, for
 * PgsqlBackpressureTest.
 *
 * It runs as its own process on purpose. The point of the tests is what
 * a large outbound parameter does to the event loop, so the peer that
 * applies the backpressure must keep moving whether or not the loop
 * under test is running — otherwise a driver that flushed synchronously
 * would deadlock the test instead of failing it.
 *
 * Usage: php pg-relay.php <host> <port> <chunkBytes> <delayMicroseconds> [<injectBytes>]
 * It prints the port it bound to, then relays until either side closes.
 * Client-to-server traffic moves at most <chunkBytes> per
 * <delayMicroseconds>, and the accepted socket is given a small buffer
 * in each direction, so a client writing megabytes fills the pipe and
 * has to wait. The server-to-client direction is not throttled.
 *
 * One chunk is in flight across the whole relay, and nothing is read
 * from either side until it has been handed over. That is the shape of
 * the peer this stands in for: one Postgres backend is a single process,
 * and while it is blocked writing to a client it is not reading that
 * client's query. So a client that stops reading stalls its own
 * outbound traffic too.
 *
 * <injectBytes>, when given, is how many bytes of NoticeResponse the
 * relay sends toward the client mid-upload — the reverse-direction
 * traffic a real server produces from NOTICE and NOTIFY while a
 * statement is still coming in. It is emitted only once the client is
 * well into an upload the server has not answered yet, which is a
 * message boundary in this direction: the server sends nothing while it
 * is still reading. The relay announces the total on its stdout once the
 * budget is spent, so a test can tell a client that drained the traffic
 * from one that never had any.
 */

[, $host, $port, $chunk, $delay] = $argv;
$inject = (int) ($argv[5] ?? 0);

$server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    \fwrite(\STDERR, "relay: cannot listen: {$errstr}\n");

    exit(1);
}

// On the listening socket, so accepted connections inherit it and the
// small window is negotiated during the handshake. Shrinking it after
// accept instead leaves the peer probing a zero window on a multi-second
// backoff, which throttles far harder than intended.
$listener = \socket_import_stream($server);

if ($listener instanceof Socket) {
    \socket_set_option($listener, \SOL_SOCKET, \SO_RCVBUF, 16384);
}

$name = (string) \stream_socket_get_name($server, false);
echo \substr($name, \strrpos($name, ':') + 1), "\n";

$client = \stream_socket_accept($server, 30);

if ($client === false) {
    exit(1);
}

$upstream = \stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);

if ($upstream === false) {
    \fwrite(\STDERR, "relay: cannot reach {$host}:{$port}: {$errstr}\n");

    exit(1);
}

// Keeps the relay's own share of the server-to-client pipe small, so a
// client that stops reading stalls this process rather than absorbing
// the injected traffic into a kernel buffer nobody waits on.
$accepted = \socket_import_stream($client);

if ($accepted instanceof Socket) {
    \socket_set_option($accepted, \SOL_SOCKET, \SO_SNDBUF, 16384);
}

\stream_set_blocking($client, false);
\stream_set_blocking($upstream, false);

/** @var list<array{from: resource, to: resource, throttled: bool, pending: string}> $directions */
$directions = [
    ['from' => $client, 'to' => $upstream, 'throttled' => true, 'pending' => ''],
    ['from' => $upstream, 'to' => $client, 'throttled' => false, 'pending' => ''],
];

/** One NoticeResponse of $bytes padding: type byte, length (itself included), then the fields libpq reads. */
$notice = static function (int $bytes): string {
    $fields = "SNOTICE\0VNOTICE\0C00000\0M" . \str_repeat('x', $bytes) . "\0\0";

    return 'N' . \pack('N', 4 + \strlen($fields)) . $fields;
};

// Bytes the client has sent since the server last said anything, which
// is how the relay recognizes an upload still in progress, and the
// injected total so far.
$uploaded = 0;
$injected = 0;
$injectAfter = 4 * (int) $chunk;
$forwarded = 0;

while (true) {
    $read = $write = $except = [];

    foreach ($directions as $direction) {
        if ($direction['pending'] !== '') {
            $write[] = $direction['to'];
        }
    }

    if ($write === []) {
        foreach ($directions as $direction) {
            $read[] = $direction['from'];
        }
    }

    if (\stream_select($read, $write, $except, 0, 200000) === false) {
        exit(0);
    }

    foreach ($directions as $i => $direction) {
        if (!\in_array($direction['from'], $read, true)) {
            continue;
        }

        $data = \fread($direction['from'], 65536);

        if ($data === false || $data === '') {
            if (\feof($direction['from'])) {
                exit(0);
            }

            continue;
        }

        $directions[$i]['pending'] = $data;

        if (!$direction['throttled']) {
            // Anything from the server restarts the count, so the relay
            // only ever speaks again after a further stretch of an
            // upload the server stayed silent through.
            $uploaded = 0;
        }
    }

    if ($inject > 0 && $injected < $inject && $uploaded >= $injectAfter && $directions[1]['pending'] === '') {
        $directions[1]['pending'] = $notice(65536);
        $injected += \strlen($directions[1]['pending']);

        if ($injected >= $inject) {
            echo $injected, "\n";
        }
    }

    foreach ($directions as $i => $direction) {
        if ($directions[$i]['pending'] === '') {
            continue;
        }

        $written = \fwrite($direction['to'], $directions[$i]['pending']);

        if ($written === false) {
            exit(0);
        }

        $directions[$i]['pending'] = \substr($directions[$i]['pending'], $written);

        if (!$direction['throttled']) {
            continue;
        }

        $uploaded += $written;
        $forwarded += $written;

        if ($forwarded >= (int) $chunk) {
            $forwarded = 0;
            \usleep((int) $delay);
        }
    }
}
