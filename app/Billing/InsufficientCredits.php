<?php

namespace App\Billing;

use App\Support\Money;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly Money $required, public readonly Money $available)
    {
        parent::__construct('Not enough credits for this generation.');
    }
}
