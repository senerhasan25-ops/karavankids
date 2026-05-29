<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

foreach (['ana', 'bayi'] as $store) {
    echo "=== {$store} ===\n";
    try {
        $c = new TicimaxClient($store);
        $resp = $c->call('product', 'SelectParaBirimi', ['UyeKodu' => $c->getUyeKodu()]);
        echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n";
    } catch (Throwable $e) {
        echo 'HATA: '.$e->getMessage()."\n\n";
    }
}
