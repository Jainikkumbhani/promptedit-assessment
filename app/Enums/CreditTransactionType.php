<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case SignupBonus = 'signup_bonus';
    case Purchase = 'purchase';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
