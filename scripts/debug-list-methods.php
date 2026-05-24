<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = App\Services\Ticimax\TicimaxClient::for('ana')->client('product');
foreach ($c->__getFunctions() as $f) {
    if (stripos($f, 'Response') !== false) continue;
    if (preg_match('/Urun|Stok|Marka|Tedarikci|Get|Select/i', $f)) {
        echo $f . PHP_EOL;
    }
}
