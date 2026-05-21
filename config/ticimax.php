<?php

return [
    'timeout' => env('TICIMAX_TIMEOUT', 60),
    'connection_timeout' => env('TICIMAX_CONNECTION_TIMEOUT', 15),
    'retry_attempts' => env('TICIMAX_RETRY_ATTEMPTS', 3),
    'retry_delay_seconds' => env('TICIMAX_RETRY_DELAY', 5),
    'batch_size' => env('TICIMAX_BATCH_SIZE', 50),

    'wsdl_paths' => [
        'product' => env('TICIMAX_WSDL_PRODUCT', '/Servis/UrunServis.svc?wsdl'),
        'order' => env('TICIMAX_WSDL_ORDER', '/Servis/SiparisServis.svc?wsdl'),
    ],
];
