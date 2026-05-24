<?php

namespace App\Livewire;

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Aktarım Parametreleri')]
#[Layout('layouts.app')]
class ProductPickerTransfer extends Component
{
    /** Session'dan gelen seçili ürün satırları */
    public array $products = [];

    /**
     * Hangi alanlar güncellensin? Anahtarlar buildSelectiveAyarlari ile aynı.
     * Varsayılan: hepsi açık (kullanıcı kapatabilir).
     */
    public array $fields = [
        'urun_adi'        => true,
        'aciklama'        => true,
        'on_yazi'         => true,
        'kategori'        => true,
        'marka'           => true,
        'tedarikci'       => true,
        'satis_fiyati'    => true,
        'indirimli_fiyat' => true,
        'stok_adedi'      => true,
        'kdv_dahil'       => true,
        'kdv_orani'       => true,
        'seo'             => true,
        'uye_tipi_fiyat'  => true,
        'resimler'        => true,
        'aktif'           => true,
    ];

    /** Aktarım sonuçları (her ürün için tek satır) */
    public array $results = [];
    public bool $running = false;
    public ?string $globalError = null;

    public function mount(): void
    {
        $this->products = session()->get('picker.products', []);
        if (empty($this->products)) {
            $this->globalError = 'Aktarılacak ürün yok. Önce listele sayfasından seçim yapın.';
        }
    }

    public function toggleAll(bool $value): void
    {
        foreach (array_keys($this->fields) as $k) {
            $this->fields[$k] = $value;
        }
    }

    public function aktar(): void
    {
        if (empty($this->products)) {
            $this->globalError = 'Aktarılacak ürün yok.';
            return;
        }
        $selectedFields = array_keys(array_filter($this->fields));
        if (empty($selectedFields)) {
            $this->globalError = 'En az bir parametre seçin.';
            return;
        }

        $this->running = true;
        $this->results = [];
        $this->globalError = null;

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        foreach ($this->products as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';
            $raw      = $row['_raw'] ?? null;

            if (! is_array($raw) || $stokKodu === '') {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'Ham payload yok'];
                continue;
            }

            try {
                // Bayide var mı?
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                $payload = $mapper->anaToBayiCreatePayload($raw);

                if ($bayiMevcut && (int) ($bayiMevcut['ID'] ?? 0) > 0) {
                    // GÜNCELLE: seçili alan grubu üzerinden
                    $bayiId = (int) $bayiMevcut['ID'];
                    $varMap = $this->mapBayiVariantIds($bayiMevcut);
                    $result = $bayi->updateProductSelective($payload, $bayiId, $varMap, $selectedFields);
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'guncellendi', 'mesaj' => "bayi ID={$bayiId}"];
                    $this->upsertMapping($row, $bayiId, $varMap);
                } else {
                    // YENİ OLUŞTUR
                    $created = $bayi->createProduct($payload);
                    $bayiId = (int) ($created['ID'] ?? 0);
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'olusturuldu', 'mesaj' => "bayi ID={$bayiId}"];
                    $this->upsertMapping($row, $bayiId, []);
                }
            } catch (\Throwable $e) {
                $msg = substr($e->getMessage(), 0, 200);
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'hata', 'mesaj' => $msg];
            }
        }

        $this->running = false;
        // Sonraki ziyarette session boşalsın
        session()->forget('picker.products');
    }

    /**
     * Bayi UrunKarti içindeki Varyasyonlardan StokKodu → bayi VaryasyonID map'i çıkarır.
     */
    protected function mapBayiVariantIds(array $bayiKart): array
    {
        $vars = $bayiKart['Varyasyonlar']['Varyasyon'] ?? $bayiKart['Varyasyonlar'] ?? [];
        if (isset($vars['Barkod'])) $vars = [$vars];
        $out = [];
        if (is_array($vars)) {
            foreach ($vars as $v) {
                $sk = (string) ($v['StokKodu'] ?? '');
                $id = (int) ($v['ID'] ?? 0);
                if ($sk !== '' && $id > 0) $out[$sk] = $id;
            }
        }
        return $out;
    }

    /**
     * Lokal mapping kayıtlarını tazele (Ali'nin lokal-öncelikli akışına uyumlu).
     */
    protected function upsertMapping(array $row, int $bayiProductId, array $bayiVarMap): void
    {
        $stokKodu = $row['stok_kodu'] ?? '';
        if ($stokKodu === '') return;
        $bayiVariantId = $bayiVarMap[$stokKodu] ?? null;
        ProductMapping::updateOrCreate(
            ['stok_kodu' => $stokKodu],
            [
                'barcode' => $row['barkod'] ?? null,
                'ana_variant_id' => $row['variant_id'] ?? null,
                'bayi_product_id' => $bayiProductId ?: null,
                'bayi_variant_id' => $bayiVariantId,
                'last_price' => $row['satis_fiyati'] ?? null,
                'last_stock' => $row['stok_adedi'] ?? null,
                'status' => 'synced',
                'last_error' => null,
                'last_synced_at' => now(),
            ]
        );
    }

    /**
     * SyncNewProductsJob ile aynı resolver setup'unu kur.
     */
    protected function buildMapper(ProductService $ana, ProductService $bayi): ProductMapper
    {
        $mapper = new ProductMapper();
        $defaultBrandId = $bayi->getDefaultBrandId();
        $defaultSupplierId = $bayi->getDefaultSupplierId();
        $defaultCategoryId = $bayi->getDefaultCategoryId();
        $mapper->setDefaultCategoryId($defaultCategoryId);

        $mapper->setBrandResolver(function (string $name) use ($bayi, $defaultBrandId) {
            $id = $bayi->findOrCreateBrandId($name);
            return $id > 0 ? $id : $defaultBrandId;
        });
        $anaSupplierIdToName = array_flip($ana->getSupplierMap());
        $mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi, $defaultSupplierId) {
            $name = $anaSupplierIdToName[$anaId] ?? '';
            $id = $name ? $bayi->findOrCreateSupplierId($name) : 0;
            return $id > 0 ? $id : $defaultSupplierId;
        });

        // Kategori ağacı mirror
        $anaTree = $ana->getCategoryTree();
        $bayi->getCategoryTree();
        $mapper->setCategoryIdResolver(function (int $anaCatId) use ($bayi, $anaTree, $defaultCategoryId) {
            $bid = $bayi->mirrorCategoryFromAna($anaCatId, $anaTree);
            return $bid > 0 ? $bid : $defaultCategoryId;
        });

        return $mapper;
    }

    public function render()
    {
        return view('livewire.product-picker-transfer');
    }
}
