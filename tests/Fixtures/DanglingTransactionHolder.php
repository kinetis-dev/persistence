<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

/**
 * A static handoff so the test can inspect the transaction
 * DanglingTransactionController opened inside Kernel::handle() after that
 * call has returned — the controller has no other way to hand it back
 * out, since Dispatcher only sees its declared return value.
 */
final class DanglingTransactionHolder
{
    public static ?FakeSqlLink $link = null;
}
