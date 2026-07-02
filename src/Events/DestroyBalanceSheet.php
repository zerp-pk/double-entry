<?php

namespace Zerp\DoubleEntry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zerp\DoubleEntry\Models\BalanceSheet;

class DestroyBalanceSheet
{
    use Dispatchable;

    public function __construct(
        public BalanceSheet $balanceSheet
    ) {}
}
