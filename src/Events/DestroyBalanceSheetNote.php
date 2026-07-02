<?php

namespace Zerp\DoubleEntry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zerp\DoubleEntry\Models\BalanceSheetNote;

class DestroyBalanceSheetNote
{
    use Dispatchable;

    public function __construct(
        public BalanceSheetNote $balanceSheetNote
    ) {}
}
