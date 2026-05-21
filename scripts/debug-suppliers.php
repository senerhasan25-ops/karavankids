<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\TicimaxClient;

foreach (['ana', 'bayi'] as $store) {
    echo "=== {$store} tedarikciler ===\n";
    try {
        $c = new TicimaxClient($store);
        $resp = $c->call('product', 'SelectTedarikci', ['UyeKodu' => $c->getUyeKodu()]);
        $list = $resp->SelectTedarikciResult->Tedarikci ?? [];
        if (! is_array($list)) $list = [$list];
        echo "Toplam: " . count($list) . "\n";
        foreach (array_slice($list, 0, 10) as $t) {
            echo "  ID={$t->ID} | {$t->Tanim}\n";
        }
    } catch (\Throwable $e) {
        echo "HATA: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
