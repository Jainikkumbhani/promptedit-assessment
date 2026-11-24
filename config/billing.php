<?php

return [

    /*
    | Users pay Pixelforge's cost plus this markup, in whole percent.
    */
    'markup_percent' => 25,

    /*
    | Credits (US dollars, as an exact decimal string) granted on sign-up.
    */
    'signup_credits_usd' => env('SIGNUP_CREDITS_USD', '2.00'),

    /*
    | Amounts users can pick on the fake "Buy credits" page, in whole dollars.
    */
    'credit_packs_usd' => [5, 10, 25],

    /*
    | Emails allowed to open the operator report.
    */
    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

];
