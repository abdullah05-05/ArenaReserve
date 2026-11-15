<?php
/**
 * config_constants.php — Centralized constants for ArenaReserve
 * Include this file wherever you need shared config values.
 */

return [
    'support' => [
        'phone' => '03137970801',
        'phone_formatted' => '+92-313-797-0801',
        'email' => 'abdullahtariq0505@gmail.com',
        'hours' => 'Mon–Sun 9 AM–11 PM',
        'address' => '17-km Sheikhupura Road, Shah Zaman Park near Mughal Steel, Lahore',
    ],
    'platform' => [
        'name' => 'ArenaReserve',
        'fee_percent' => 5,
        'currency' => 'PKR',
        'currency_symbol' => '₨',
    ],
    'booking' => [
        'direct_advance_percent' => 50,
        'challenge_advance_percent' => 25,
        'cancellation_full_refund_hours' => 24,
        'cancellation_partial_refund_percent' => 50,
        'hold_minutes' => 10,
    ],
    'verification' => [
        'token_expiry_hours' => 24,
        'reset_token_expiry_hours' => 1,
    ],
];