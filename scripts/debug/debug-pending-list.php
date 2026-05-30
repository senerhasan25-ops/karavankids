<?php

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$pending = ProductMapping::where('status', 'pending')->get(['stok_kodu', 'barcode', 'ana_product_id', 'last_price', 'last_stock']);
echo "Bayide olmayan (status=pending) {$pending->count()} ürün:\n";
foreach ($pending as $p) {
    echo "  stok={$p->stok_kodu} barkod={$p->barcode} ana_id={$p->ana_product_id} fiyat={$p->last_price} stok={$p->last_stock}\n";
}
