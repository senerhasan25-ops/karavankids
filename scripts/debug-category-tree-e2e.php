<?php

/**
 * Kategori agaci mirror E2E:
 *  - Ana magazadan kategori agacini cek
 *  - Ana'da derin yola sahip (PID > 0) urun bul
 *  - Bayide aynen mirror et, agacin her node'unu dogrula
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');

$anaTree = $ana->getCategoryTree();
echo 'Ana kategori agaci yuklendi: '.count($anaTree)." node\n";

// PID > 0 olan kategori sayisi
$deep = 0;
foreach ($anaTree as $k) {
    if ($k['PID'] > 0) {
        $deep++;
    }
}
echo "Derin (PID>0) node: $deep\n";

// Derin yola sahip bir ana urun bul: Kategoriler[] icindeki ID'lerden biri PID>0 olmali
$target = null;
$targetPath = null;
foreach ($ana->getNewProducts(null, 1, 50, 'DESC') as $p) {
    $kat = $p['Kategoriler']['int'] ?? $p['Kategoriler'] ?? [];
    if (! is_array($kat)) {
        $kat = [$kat];
    }
    foreach ($kat as $kid) {
        $kid = (int) $kid;
        if (! isset($anaTree[$kid])) {
            continue;
        }
        // Yolu cikar
        $path = [];
        $cur = $anaTree[$kid];
        while ($cur) {
            array_unshift($path, $cur);
            $pid = (int) $cur['PID'];
            $cur = $pid > 0 ? ($anaTree[$pid] ?? null) : null;
        }
        if (count($path) >= 2) { // en az 2 seviye
            $target = $p;
            $targetPath = $path;
            break 2;
        }
    }
}
// Sanitize-friendly bir urun bul: Aciklama'sinda script/iframe yok, basit metin
if (! $target) {
    foreach ($ana->getNewProducts(null, 1, 30, 'DESC') as $p) {
        $desc = (string) ($p['Aciklama'] ?? '');
        // Iframe/script/style/img-with-events yoksa sade kabul et
        if (! preg_match('/<script|<iframe|<style|expression\(|behavior:|on\w+\s*=/i', $desc)
            && str_contains($p['UrunAdi'] ?? '', 'Meri Meri')) {
            $target = $p;
            break;
        }
    }
}
if ($target) {
    $kat = $target['Kategoriler']['int'] ?? $target['Kategoriler'] ?? [];
    if (! is_array($kat)) {
        $kat = [$kat];
    }
    $kid = (int) ($kat[0] ?? 0);
    $targetPath = isset($anaTree[$kid]) ? [$anaTree[$kid]] : [['ID' => $kid, 'Tanim' => '?', 'PID' => 0]];
}
if (! $target) {
    echo "Sanitize-friendly aday bulunamadi\n";
    exit(1);
}

echo "\n=== Hedef ana urun ===\n";
echo '  '.$target['UrunAdi'].' (ID='.$target['ID'].")\n";
echo '  AnaKategoriID: '.($target['AnaKategoriID'] ?? '?')."\n";
$kat = $target['Kategoriler']['int'] ?? $target['Kategoriler'] ?? [];
if (! is_array($kat)) {
    $kat = [$kat];
}
echo '  Kategoriler: '.json_encode($kat)."\n";
echo '  Yol: '.implode(' > ', array_map(fn ($n) => $n['Tanim'].'(#'.$n['ID'].')', $targetPath))."\n";

// Sentetik barkod
$vList = $target['Varyasyonlar']['Varyasyon'] ?? $target['Varyasyonlar'];
if (isset($vList['Barkod'])) {
    $vList = [$vList];
}
$origBar = $vList[0]['Barkod'];
$vList[0]['Barkod'] = $origBar.'-T'.date('His');
$vList[0]['StokKodu'] = ($vList[0]['StokKodu'] ?? $origBar).'-T'.date('His');
$target['Varyasyonlar'] = $vList;
$target['Aktif'] = true;
// Sanitize-edge case'i izole etmek icin Aciklama'yi sade tut (asil hedef kategori dogrulamasi).
$target['Aciklama'] = '<p>Test urun aciklamasi (kategori agaci dogrulama).</p>';
$target['OnYazi'] = '';

// Mapper kur
$mapper = new ProductMapper;
$defaultBrandId = $bayi->getDefaultBrandId();
$defaultSupplierId = $bayi->getDefaultSupplierId();
$defaultCategoryId = $bayi->getDefaultCategoryId();
$mapper->setDefaultCategoryId($defaultCategoryId);
$mapper->setBrandResolver(fn ($n) => $bayi->findOrCreateBrandId($n) ?: $defaultBrandId);
$supMap = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function (int $aId) use ($supMap, $bayi, $defaultSupplierId) {
    $n = $supMap[$aId] ?? '';

    return ($n ? $bayi->findOrCreateSupplierId($n) : 0) ?: $defaultSupplierId;
});

// KATEGORI MIRROR
$mapper->setCategoryIdResolver(function (int $anaCatId) use ($bayi, $anaTree, $defaultCategoryId) {
    $bid = $bayi->mirrorCategoryFromAna($anaCatId, $anaTree);
    echo "  resolver: ana=$anaCatId → bayi=$bid\n";

    return $bid > 0 ? $bid : $defaultCategoryId;
});

echo "\n=== Mapper payload uretiliyor (kategori resolver aktif) ===\n";
$payload = $mapper->anaToBayiCreatePayload($target);
echo '  Payload.AnaKategoriID: '.$payload['AnaKategoriID']."\n";
echo '  Payload.Kategoriler:   '.json_encode($payload['Kategoriler'])."\n";

echo "\n=== Bayi'ye gonderiliyor ===\n";
$created = $bayi->createProduct($payload);
$newId = (int) ($created['ID'] ?? 0);
echo "  Bayi yeni ID: $newId\n";
if ($newId === 0) {
    echo "FAIL\n";
    exit(1);
}

sleep(3);
$bUrun = $bayi->getProductByBarcode($vList[0]['Barkod']);
if (! $bUrun) {
    echo "Bayi'den geri cekilemedi\n";
    exit(1);
}

echo "\n=== Bayi'deki urun ===\n";
echo '  UrunAdi: '.($bUrun['UrunAdi'] ?? '?')."\n";
echo '  AnaKategori: '.($bUrun['AnaKategori'] ?? '?')."\n";
echo '  AnaKategoriID: '.($bUrun['AnaKategoriID'] ?? '?')."\n";
$bk = $bUrun['Kategoriler']['int'] ?? $bUrun['Kategoriler'] ?? [];
if (! is_array($bk)) {
    $bk = [$bk];
}
echo '  Kategoriler: '.json_encode($bk)."\n";

// Bayi'deki kategori yolunu bul
$bTree = $bayi->getCategoryTree();
foreach ($bk as $kid) {
    $path = [];
    $cur = $bTree[(int) $kid] ?? null;
    while ($cur) {
        array_unshift($path, $cur);
        $pid = (int) $cur['PID'];
        $cur = $pid > 0 ? ($bTree[$pid] ?? null) : null;
    }
    echo '  Yol: '.implode(' > ', array_map(fn ($n) => $n['Tanim'].'(#'.$n['ID'].')', $path))."\n";
}
