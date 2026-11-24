<?php

return [

    'markup_percent' => 25,

    'signup_credits_usd' => env('SIGNUP_CREDITS_USD', '2.00'),

    'credit_packs_usd' => [5, 10, 25],

    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

];
