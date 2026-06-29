<?php

namespace App\Jobs\Concerns;

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;

/**
 * Sipariş satırı (bayi) → ana mağaza Varyasyon.ID çözümleyici.
 *
 * EŞLEŞME YALNIZCA TedarikciKodu (numeric + benzersiz) ile yapılır:
 * aynı stok_kodu/barkod farklı ürünlerde tekrar edebildiği için onlarla
 * eşleştirmek yanlış ürünü seçebilir. Ted kodu BOŞSA son çare olarak
 * stok_kodu denenir (gerçek kodu olmayan eski siparişler için).
 *
 * PullBayiOrdersJob + TransferSingleBayiOrderJob ortak kullanır.
 */
trait ResolvesAnaVariant
{
    /**
     * @param  array  $line  Bayi sipariş satırı (WebSiparisUrun) — TedarikciKodu + StokKodu içerir
     * @param  array<string,int|null>  $cache  çağrılar arası önbellek (referansla)
     * @return int|null ana mağaza Varyasyon.ID (UrunID), bulunamazsa null
     */
    protected function resolveAnaVariantId(array $line, ProductService $anaProduct, array &$cache): ?int
    {
        $ted = trim((string) ($line['TedarikciKodu'] ?? ''));
        $stokKodu = trim((string) ($line['StokKodu'] ?? ''));
        $cacheKey = $ted !== '' ? 'ted:'.$ted : 'stok:'.$stokKodu;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // 1) LOKAL mapping — tedarikçi kodu (SOAP yok)
        if ($ted !== '') {
            $m = ProductMapping::where('tedarikci_kodu', $ted)->first();
            if ($m && $m->ana_variant_id) {
                return $cache[$cacheKey] = (int) $m->ana_variant_id;
            }
        }

        // 2) SOAP — ana'da tedarikçi kodu ile probe (aktarım sırasında meşru canlı okuma)
        if ($ted !== '') {
            $anaKart = $anaProduct->getProductByTedarikciKodu($ted);
            if ($anaKart) {
                $anaUrunId = (int) ($anaKart['ID'] ?? 0);
                foreach ($this->variantsOf($anaKart) as $v) {
                    if (trim((string) ($v['TedarikciKodu'] ?? '')) === $ted) {
                        $vid = (int) ($v['ID'] ?? 0);
                        if ($vid > 0) {
                            ProductMapping::updateOrCreate(
                                ['tedarikci_kodu' => $ted],
                                [
                                    'stok_kodu' => $stokKodu ?: null,
                                    'barcode' => (string) ($v['Barkod'] ?? '') ?: null,
                                    'ana_product_id' => (string) $anaUrunId,
                                    'ana_variant_id' => (string) $vid,
                                    'status' => 'synced',
                                    'last_synced_at' => now(),
                                ]
                            );

                            return $cache[$cacheKey] = $vid;
                        }
                    }
                }
            }
        }

        // 3) SON ÇARE — ted kodu yoksa stok_kodu (yalnızca ted boşken)
        if ($ted === '' && $stokKodu !== '') {
            $m = ProductMapping::where('stok_kodu', $stokKodu)->first();
            if ($m && $m->ana_variant_id) {
                return $cache[$cacheKey] = (int) $m->ana_variant_id;
            }
            $anaKart = $anaProduct->getProductByStokKodu($stokKodu);
            if ($anaKart) {
                foreach ($this->variantsOf($anaKart) as $v) {
                    if ((string) ($v['StokKodu'] ?? '') === $stokKodu) {
                        $vid = (int) ($v['ID'] ?? 0);
                        if ($vid > 0) {
                            return $cache[$cacheKey] = $vid;
                        }
                    }
                }
            }
        }

        return $cache[$cacheKey] = null;
    }

    /** UrunKarti'dan varyasyon listesini normalize et (tek=obje, çok=array). */
    protected function variantsOf(array $kart): array
    {
        $vars = $kart['Varyasyonlar']['Varyasyon'] ?? $kart['Varyasyonlar'] ?? [];
        if (is_array($vars) && ! array_is_list($vars)) {
            $vars = [$vars];
        }

        return is_array($vars) ? $vars : [];
    }
}
