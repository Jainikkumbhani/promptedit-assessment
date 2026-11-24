<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case SignupBonus = 'signup_bonus';
    case Purchase = 'purchase';
    /** Worst-case cost reserved when a generation is submitted. */
    case Hold = 'hold';
    /** The hold given back once the generation finished (either way). */
    case HoldRelease = 'hold_release';
    /** The actual cost of a succeeded generation. */
    case Charge = 'charge';
}
