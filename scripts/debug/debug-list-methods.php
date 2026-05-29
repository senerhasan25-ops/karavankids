<?php

use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$c = TicimaxClient::for('ana')->client('product');
foreach ($c->__getFunctions() as $f) {
    if (stripos($f, 'Response') !== false) {
        continue;
    }
    if (preg_match('/Urun|Stok|Marka|Tedarikci|Get|Select/i', $f)) {
        echo $f.PHP_EOL;
    }
}
