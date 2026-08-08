<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Functional Currency
    |--------------------------------------------------------------------------
    |
    | The currency the books are kept in. Every journal line carries its own
    | transaction-currency amount plus an amount converted into this currency,
    | and it is the functional amounts that must net to zero — a mixed-currency
    | entry cannot balance in any other terms.
    |
    | Changing this on a populated ledger is not a configuration change; it is a
    | re-translation of every historical entry at the rates prevailing on each
    | entry date. Treat it as fixed for the life of the books.
    |
    */

    'functional' => env('APP_FUNCTIONAL_CURRENCY', 'NGN'),

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    |
    | Amounts are stored as integers in each currency's minor unit, so `scale`
    | is what a stored value means: at scale 2 a stored 500 is 5.00, and at
    | scale 0 it is 500. Scale is a property of the currency, not of the
    | application — JPY has no minor unit, KWD has three.
    |
    | Do not change a currency's scale once any amount is stored in it; that
    | would silently rescale every existing row.
    |
    */

    'currencies' => [
        'NGN' => ['symbol' => '₦', 'scale' => 2, 'name' => 'Nigerian Naira'],
        'USD' => ['symbol' => '$', 'scale' => 2, 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'scale' => 2, 'name' => 'Euro'],
        'GBP' => ['symbol' => '£', 'scale' => 2, 'name' => 'Pound Sterling'],
    ],

];
