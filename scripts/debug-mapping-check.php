<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\ProductMapping;
use Illuminate\Contracts\Console\Kernel;

$total = ProductMapping::count();
$synced = ProductMapping::where('status', 'synced')->count();
$error = ProductMapping::where('status', 'error')->count();
echo "Toplam mapping: {$total} | synced: {$synced} | error: {$error}\n\n";

echo "İlk 5 synced mapping:\n";
foreach (ProductMapping::where('status', 'synced')->limit(5)->get() as $m) {
    echo "  {$m->barcode} | ana={$m->ana_product_id} | bayi={$m->bayi_product_id} | stok={$m->last_stock} | fiyat={$m->last_price}\n";
}
