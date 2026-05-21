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
    | WSDL'den doğrulanması gereken method isimleri.
    | Ticimax sürümleri arasında farklılık olabilir; gerçek WSDL'e bakıp burayı düzeltmek yeterli.
    */
    'methods' => [
        'product' => [
            'select' => env('TICIMAX_METHOD_SELECT_URUNLER', 'SelectUrunler'),
            'get_by_barcode' => env('TICIMAX_METHOD_GET_URUN_BY_BARKOD', 'GetUrunByBarkod'),
            'save' => env('TICIMAX_METHOD_SAVE_URUN', 'SaveUrun'),
            'update_stock_price' => env('TICIMAX_METHOD_SET_STOK_FIYAT', 'SetUrunStokFiyat'),
            'set_active' => env('TICIMAX_METHOD_SET_URUN_AKTIF', 'SetUrunAktif'),
        ],
        'order' => [
            'select' => env('TICIMAX_METHOD_SELECT_SIPARISLER', 'SelectSiparisler'),
            'save' => env('TICIMAX_METHOD_SAVE_SIPARIS', 'SaveSiparis'),
            'mark_note' => env('TICIMAX_METHOD_SET_ADMIN_NOTU', 'SetSiparisAdminNotu'),
        ],
    ],
];
