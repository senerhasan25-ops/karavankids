<?php

namespace App\Console\Commands;

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Console\Command;

/**
 * Hedef (bayi) mağazada aynı StokKodu'na sahip ÇOKLU ürünleri tespit eder ve isterseniz
 * "yenisini" (TedarikciKodu prefix'i SUP3005|... ile başlayan, kategori boş olan) pasifleştirir.
 *
 * NEDEN: Hasan'ın eski sync'i SUPBIL|... TedarikciKodu prefix'i kullanmış. Bizim yeni sync
 * (SUP3005|...) bunları eşleştiremeyince sıfırdan kopya açtı. Eski ürünler kategorili ve
 * sipariş geçmişli; korumamız gerek. Yenileri pasifleştirip lokal mapping'i eski ürünlere
 * yönlendiriyoruz — bir sonraki sync hızlı yoldan eski ürünü günceller.
 *
 * Kullanım:
 *   php artisan sync:dedupe-bayi-products             # sadece raporla (DRY RUN — varsayılan)
 *   php artisan sync:dedupe-bayi-products --apply     # pasifleştir + mapping yaz
 */
class DedupeBayiProductsCommand extends Command
{
    protected $signature = 'sync:dedupe-bayi-products
                            {--apply : Sadece raporlamak yerine yeni duplikatları pasifleştir ve lokal mapping yaz}';

    protected $description = 'Hedef mağazadaki StokKodu duplikatlarını tespit eder, isteğe bağlı temizler.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? '⚠  APPLY modu: yeni duplikatlar pasifleştirilecek ve mapping güncellenecek.'
            : 'ℹ  DRY-RUN modu: sadece rapor üretilecek. --apply ile çalıştır gerçek temizlik için.');
        $this->newLine();

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');

        $page = 1;
        $perPage = 100;
        $totalScanned = 0;
        $duplicateGroups = []; // stok_kodu => [{ana_*, bayi_keep, bayi_remove[]}]

        $this->info('Kaynaktaki ürünler taranıyor (sayfa sayfa)...');
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
                    $totalScanned++;

                    // Hedefte aynı StokKodu'lu ürünleri ara
                    $bayiMatches = $bayi->findAllProductsByStokKodu($stokKodu, 10);
                    if (count($bayiMatches) <= 1) {
                        continue; // duplikat yok
                    }

                    // Canonical = TedarikciKodu SUP3005 ile başlamayan VE/VEYA en küçük ID
                    $keep = null;
                    $remove = [];
                    foreach ($bayiMatches as $bm) {
                        $isNewFormat = str_starts_with((string) ($bm['TedarikciKodu'] ?? ''), 'SUP3005');
                        if (! $isNewFormat && $keep === null) {
                            $keep = $bm; // eski format → koru
                        }
                    }
                    // Hiçbiri eski değilse en küçük ID'li olanı koru
                    if ($keep === null) {
                        usort($bayiMatches, fn ($a, $b) => (int) ($a['ID'] ?? 0) <=> (int) ($b['ID'] ?? 0));
                        $keep = $bayiMatches[0];
                    }
                    foreach ($bayiMatches as $bm) {
                        if ((int) ($bm['ID'] ?? 0) !== (int) ($keep['ID'] ?? 0)) {
                            $remove[] = $bm;
                        }
                    }

                    $duplicateGroups[$stokKodu] = [
                        'ana_urun_id' => $anaUrunId,
                        'ana_variant' => $av,
                        'keep' => $keep,
                        'remove' => $remove,
                    ];

                    $keepLabel = '#' . ($keep['ID'] ?? '?') . ' (' . substr((string) ($keep['TedarikciKodu'] ?? '?'), 0, 30) . ')';
                    $removeLabel = implode(', ', array_map(
                        fn ($r) => '#' . ($r['ID'] ?? '?') . ' (' . substr((string) ($r['TedarikciKodu'] ?? '?'), 0, 30) . ')',
                        $remove
                    ));
                    $this->line("  StokKodu={$stokKodu}: KEEP {$keepLabel} | REMOVE {$removeLabel}");
                }
            }

            if (count($products) < $perPage) {
                break;
            }
            $page++;
        }

        $this->newLine();
        $this->info("Toplam taranan varyasyon: {$totalScanned}");
        $this->info('Duplikat grup sayısı: ' . count($duplicateGroups));

        if (empty($duplicateGroups)) {
            $this->info('🎉 Hiç duplikat bulunamadı.');
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN — hiçbir değişiklik yapılmadı. Uygulamak için --apply ekle.');
            return self::SUCCESS;
        }

        // ========== APPLY ==========
        $this->newLine();
        $this->info('Temizlik başlıyor...');

        $deactivated = 0;
        $mapped = 0;
        $failed = 0;

        foreach ($duplicateGroups as $stokKodu => $g) {
            $keep = $g['keep'];
            $anaUrunId = (int) $g['ana_urun_id'];
            $anaVariant = $g['ana_variant'];

            // 1) Yeni duplikatları pasifleştir
            foreach ($g['remove'] as $rm) {
                $removeId = (int) ($rm['ID'] ?? 0);
                if ($removeId <= 0) {
                    continue;
                }
                try {
                    $bayi->setActive((string) $removeId, false);
                    $this->line("  ✓ Pasifleştirildi: bayi #{$removeId} (StokKodu={$stokKodu})");
                    $deactivated++;
                } catch (\Throwable $e) {
                    $this->error("  ✗ #{$removeId} pasifleştirme hatası: " . $e->getMessage());
                    $failed++;
                }
            }

            // 2) Korunan ürünü local mapping'e yaz
            try {
                $keepProductId = (int) ($keep['ID'] ?? 0);
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

                ProductMapping::updateOrCreate(
                    ['stok_kodu' => $stokKodu],
                    [
                        'barcode' => $barkod ?: null,
                        'ana_product_id' => (string) $anaUrunId,
                        'ana_variant_id' => isset($anaVariant['ID']) ? (string) $anaVariant['ID'] : null,
                        'bayi_product_id' => $keepProductId > 0 ? (string) $keepProductId : null,
                        'bayi_variant_id' => $keepVariantId > 0 ? (string) $keepVariantId : null,
                        'tedarikci_kodu' => "SUP3005|{$stokKodu}|{$anaUrunId}",
                        'status' => 'synced',
                        'last_synced_at' => now(),
                        'last_error' => null,
                    ]
                );
                $mapped++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Mapping kaydı hatası (StokKodu={$stokKodu}): " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("✓ Pasifleştirilen: {$deactivated}");
        $this->info("✓ Lokal mapping yazılan: {$mapped}");
        if ($failed > 0) {
            $this->warn("✗ Hata: {$failed}");
        }
        $this->info('🎉 Tamamlandı. Bir sonraki sync hızlı yoldan eski (korunan) ürünleri günceller.');

        return self::SUCCESS;
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
