<?php

namespace App\Console\Commands;

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Console\Command;

/**
 * Hedef (bayi) mağazada aynı StokKodu'na sahip ÇOKLU ürünleri tespit eder.
 *
 * SİLME: Ticimax SOAP'ta DeleteUrun method'u YOK. Silinecek ürünleri kullanıcı
 * Ticimax panelinden manuel siler. Bu komut sadece raporlar ve --apply ile
 * korunan ürünleri lokal mapping'e yazar — böylece manuel silme sonrası yeni
 * sync hızlı yoldan eski ürünü günceller, yeni duplikat açılmaz.
 *
 * Kullanım:
 *   php artisan sync:dedupe-bayi-products             # sadece raporla (DRY RUN)
 *   php artisan sync:dedupe-bayi-products --apply     # raporla + lokal mapping yaz
 *
 * BELLEK GÜVENLİĞİ: Hiçbir veri biriktirilmez — her ürün için lookup yapar,
 * sonucu hemen ekrana yazar / mapping'e işler, sonra unset eder. Bin binlerce
 * ürünle bile 128MB sınırına takılmaz.
 */
class DedupeBayiProductsCommand extends Command
{
    protected $signature = 'sync:dedupe-bayi-products
                            {--apply : Korunan (eski) ürünleri lokal mapping tablosuna yaz}';

    protected $description = 'Hedef mağazadaki StokKodu duplikatlarını raporlar; --apply ile lokal eşleşmeyi günceller.';

    public function handle(): int
    {
        // Güvenlik şemsiyesi — sürpriz bellek kullanımına karşı
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? '⚠  APPLY modu: korunan ürünler lokal mapping\'e yazılacak. Silme YOK (Ticimax SOAP delete desteklemiyor — panelden manuel sil).'
            : 'ℹ  DRY-RUN modu: sadece rapor. --apply ile mapping yazılır.');
        $this->newLine();

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');

        $page = 1;
        $perPage = 50;

        $scanned = 0;
        $duplicateCount = 0;
        $mappedCount = 0;

        while (true) {
            $products = $ana->getNewProducts(null, $page, $perPage);
            if (empty($products)) {
                break;
            }

            foreach ($products as $anaUrunKarti) {
                $anaUrunId = (int) ($anaUrunKarti['ID'] ?? 0);
                $variants = $this->extractVariants($anaUrunKarti);

                foreach ($variants as $av) {
                    $stokKodu = trim((string) ($av['StokKodu'] ?? ''));
                    if ($stokKodu === '') {
                        continue;
                    }
                    $scanned++;

                    // SOAP probe — sonucu hemen işle, biriktirme
                    $bayiMatches = $bayi->findAllProductsByStokKodu($stokKodu, 10);

                    if (count($bayiMatches) > 1) {
                        $duplicateCount++;
                        $this->processOneDuplicate(
                            $stokKodu,
                            $anaUrunId,
                            $av,
                            $bayiMatches,
                            $apply,
                            $mappedCount
                        );
                    }

                    // Bellekten boşalt
                    unset($bayiMatches);
                }

                unset($variants, $anaUrunKarti);
            }

            $count = count($products);
            unset($products); // büyük SOAP response — hemen at

            // İlerleme satırı (her sayfada)
            $this->line("  [sayfa {$page}] taranan toplam varyasyon: {$scanned}, duplikat: {$duplicateCount}");

            if ($count < $perPage) {
                break;
            }
            $page++;

            // PHP'nin gc'sini zorla
            gc_collect_cycles();
        }

        $this->newLine();
        $this->info("✓ Toplam taranan varyasyon: {$scanned}");
        $this->info("✓ Duplikat grup sayısı: {$duplicateCount}");
        if ($apply) {
            $this->info("✓ Lokal mapping yazılan: {$mappedCount}");
            $this->info('🎉 Mapping güncel — Ticimax panelinden duplikatları silebilirsin. Bir sonraki sync yeni duplikat açmaz.');
        } else {
            $this->warn('DRY-RUN — hiçbir değişiklik yapılmadı. --apply ile mapping yaz.');
        }

        return self::SUCCESS;
    }

    /**
     * Tek bir duplikat grubunu işler: konsola yazar, --apply ise mapping kaydeder.
     * Bellekte hiçbir şey biriktirmez — fonksiyondan çıkar çıkmaz lokal değişkenler boşalır.
     */
    protected function processOneDuplicate(
        string $stokKodu,
        int $anaUrunId,
        array $anaVariant,
        array $bayiMatches,
        bool $apply,
        int &$mappedCount
    ): void {
        // Canonical seç: TedarikciKodu SUP2026 ile başlamayan = eski format, korunur.
        // Hiçbiri eski değilse en küçük ID'li olanı koru.
        $keep = null;
        foreach ($bayiMatches as $bm) {
            if (! str_starts_with((string) ($bm['TedarikciKodu'] ?? ''), 'SUP2026')) {
                $keep = $bm;
                break;
            }
        }
        if ($keep === null) {
            usort($bayiMatches, fn ($a, $b) => (int) ($a['ID'] ?? 0) <=> (int) ($b['ID'] ?? 0));
            $keep = $bayiMatches[0];
        }

        $keepId = (int) ($keep['ID'] ?? 0);
        $keepTk = substr((string) ($keep['TedarikciKodu'] ?? '?'), 0, 30);

        $removeStrs = [];
        foreach ($bayiMatches as $bm) {
            $bmId = (int) ($bm['ID'] ?? 0);
            if ($bmId !== $keepId) {
                $removeStrs[] = '#'.$bmId.' ('.substr((string) ($bm['TedarikciKodu'] ?? '?'), 0, 30).')';
            }
        }

        $this->line("  StokKodu={$stokKodu}: KEEP #{$keepId} ({$keepTk}) | REMOVE ".implode(', ', $removeStrs));

        if (! $apply) {
            return;
        }

        // Mapping kaydet — keep'in varyasyonu içinden stok_kodu eşleşenini bul
        $keepVariants = $this->extractVariants($keep);
        $matchingKeepVar = null;
        foreach ($keepVariants as $kv) {
            if ((string) ($kv['StokKodu'] ?? '') === $stokKodu) {
                $matchingKeepVar = $kv;
                break;
            }
        }
        $matchingKeepVar ??= $keepVariants[0] ?? null;
        $keepVariantId = $matchingKeepVar ? (int) ($matchingKeepVar['ID'] ?? 0) : 0;
        $barkod = (string) ($matchingKeepVar['Barkod'] ?? $anaVariant['Barkod'] ?? '');

        try {
            ProductMapping::updateOrCreate(
                ['stok_kodu' => $stokKodu],
                [
                    'barcode' => $barkod ?: null,
                    'ana_product_id' => (string) $anaUrunId,
                    'ana_variant_id' => isset($anaVariant['ID']) ? (string) $anaVariant['ID'] : null,
                    'bayi_product_id' => $keepId > 0 ? (string) $keepId : null,
                    'bayi_variant_id' => $keepVariantId > 0 ? (string) $keepVariantId : null,
                    // TedarikciKodu kaynak VaryasyonID'sini gömer (UrunKartiID değil)
                    'tedarikci_kodu' => "SUP2026|{$stokKodu}|".(isset($anaVariant['ID']) ? (int) $anaVariant['ID'] : 0),
                    'status' => 'synced',
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]
            );
            $mappedCount++;
        } catch (\Throwable $e) {
            $this->error('    ✗ Mapping kaydı hatası: '.$e->getMessage());
        }
    }

    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }

        return is_array($v) ? array_values($v) : [];
    }
}
