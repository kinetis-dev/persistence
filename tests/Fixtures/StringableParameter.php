<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Stringable;

/**
 * An object that casts to a string — the one parameter kind the four
 * drivers would otherwise disagree about, refused with every other
 * object by the shared value contract.
 */
final class StringableParameter implements Stringable
{
    #[\Override]
    public function __toString(): string
    {
        return 'stringable';
    }
}
