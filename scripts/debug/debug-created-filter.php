<?php

/**
 * created (EklemeTarihiBaslangic) filtresi gerçekten filtreliyor mu?
 * Gelecek bir tarih verince ~0 dönmeli; binlerce dönerse filtre ÇALIŞMIYOR.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ana = ProductService::for('ana');

$cases = [
    'created / gelecek (2099)' => ['created', Carbon::parse('2099-01-01')],
    'created / bugün' => ['created', Carbon::now()->startOfDay()],
    'created / 30 gün önce' => ['created', Carbon::now()->subDays(30)],
    'stock_price / gelecek (2099)' => ['stock_price', Carbon::parse('2099-01-01')],
];

foreach ($cases as $label => [$type, $since]) {
    $res = $ana->fetchProductPageRecovering($type, $since, 1, 100, 'ASC');
    echo str_pad($label, 32).' → '.count($res['products'])." ürün (sayfa 1/100)\n";
}

echo "\nBeklenti: 'gelecek (2099)' satırları ~0 olmalı. Değilse o filtre Ticimax'ta uygulanmıyor.\n";
