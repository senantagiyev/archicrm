<?php

namespace App\Enums;

/**
 * Access levels from the TZ §5.4 permission matrix.
 * Ordered: None < View < Edit < Full — atLeast() compares by that order.
 */
enum AccessLevel: int
{
    case None = 0;
    case View = 1;
    case Edit = 2;
    case Full = 3;

    public function atLeast(self $level): bool
    {
        return $this->value >= $level->value;
    }
}
