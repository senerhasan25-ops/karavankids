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

    /*
    | Gerçek Ticimax WSDL'inden doğrulanmış method isimleri (digitalsupport.ticimaxtest.com).
    */
    'methods' => [
        'product' => [
            'select' => 'SelectUrun',
            'select_stock_price' => 'SelectUrunStokFiyat',
            'save' => 'SaveUrun',
            'update_stock' => 'StokAdediGuncelle',
            'update_price' => 'UpdateUrunFiyat',
            'save_variant' => 'SaveVaryasyon',
            'select_variant' => 'SelectVaryasyon',
        ],
        'order' => [
            'select' => 'SelectSiparis',
            'save' => 'SaveSiparis',
            'mark_transferred' => 'SetSiparisAktarildi',
            'set_status' => 'SetSiparisDurum',
        ],
    ],
];
