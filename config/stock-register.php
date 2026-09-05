<?php

return [
    'path' => env('STOCK_REGISTER_PATH', base_path('registru-produse-kymco.xlsx')),
    'default_exchange_rate' => env('STOCK_REGISTER_EXCHANGE_RATE', '5.31'),
    'sync_enabled' => env('STOCK_REGISTER_SYNC_ENABLED', true),
];
