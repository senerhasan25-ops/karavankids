<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\TicimaxClient;

foreach (['ana', 'bayi'] as $store) {
    echo "=== {$store} markalar ===\n";
    try {
        $c = new TicimaxClient($store);
        $resp = $c->call('product', 'SelectMarka', ['UyeKodu' => $c->getUyeKodu()]);
        $brands = $resp->SelectMarkaResult->Marka ?? [];
        if (! is_array($brands)) $brands = [$brands];
        echo "Toplam marka: " . count($brands) . "\n";
        foreach (array_slice($brands, 0, 10) as $b) {
            echo "  ID={$b->ID} | {$b->Tanim} | Aktif=" . ($b->Aktif ? 'evet' : 'hayır') . "\n";
        }
        echo "\n";
    } catch (\Throwable $e) {
        echo "HATA: " . $e->getMessage() . "\n\n";
    }
}
