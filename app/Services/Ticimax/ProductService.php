<?php

namespace App\Services\Ticimax;

use Illuminate\Support\Carbon;

class ProductService
{
    public function __construct(protected TicimaxClient $client)
    {
    }

    public static function for(string $storeKey): self
    {
        return new self(TicimaxClient::for($storeKey));
    }

    public function getClient(): TicimaxClient
    {
        return $this->client;
    }

    /**
     * Ticimax SelectUrun(UyeKodu, f: UrunFiltre, s: UrunSayfalama).
     * f filtre / s sayfalama ayrı yapılar.
     */
    public function getNewProducts(?Carbon $since = null, int $page = 1, int $perPage = 50): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => [
                'Aktif' => -1,
                'Firsat' => -1,
                'Indirimli' => -1,
                'DuzenlemeTarihiBaslangic' => $since?->format('Y-m-d\TH:i:s') ?? '',
                'DuzenlemeTarihiBitis' => '',
            ],
            's' => [
                'BaslangicIndex' => $startIdx,
                'KayitSayisi' => $perPage,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
        ];
        $resp = $this->client->call('product', $this->method('select'), $params);
        return $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
    }

    /**
     * Barkoda göre tek ürün — SelectUrun'a Barkod filtresi verir.
     */
    public function getProductByBarcode(string $barcode): ?array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => [
                'Aktif' => -1,
                'Barkod' => $barcode,
            ],
            's' => [
                'BaslangicIndex' => 0,
                'KayitSayisi' => 1,
                'KayitSayisinaGoreGetir' => true,
            ],
        ];
        $resp = $this->client->call('product', $this->method('select'), $params);
        $list = $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
        return $list[0] ?? null;
    }

    /**
     * SaveUrun: UrunKarti listesi + UrunKartiAyar + VaryasyonAyar gönderilir.
     * Tek ürün için bile array'e sarıp gönderiyoruz.
     */
    public function createProduct(array $urunKarti): array
    {
        return $this->saveBatch([$urunKarti], $this->fullCreateAyarlari())[0] ?? [];
    }

    public function updateProduct(string $productId, array $urunKarti): array
    {
        $urunKarti['ID'] = (int) $productId;
        return $this->saveBatch([$urunKarti], $this->fullUpdateAyarlari())[0] ?? [];
    }

    /**
     * Birden fazla ürünü tek SaveUrun çağrısında gönderir.
     */
    public function saveBatch(array $urunKartlari, array $ayarlar = []): array
    {
        $ayarlar = array_replace_recursive($this->fullCreateAyarlari(), $ayarlar);
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunKartlari' => $urunKartlari,
            'ukAyar' => $ayarlar['ukAyar'],
            'vAyar' => $ayarlar['vAyar'],
        ];
        $resp = $this->client->call('product', $this->method('save'), $params);
        $result = $this->normalizeList($resp, $this->method('save'), 'UrunKarti');
        return $result;
    }

    /**
     * Sadece stok güncelle — StokAdediGuncelle (Varyasyon ID üzerinden çalışır).
     */
    public function updateStock(string $varyasyonId, int $stock): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunId' => (int) $varyasyonId,
            'StokAdedi' => $stock,
        ];
        $resp = $this->client->call('product', $this->method('update_stock'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    /**
     * Sadece fiyat güncelle — UpdateUrunFiyat.
     */
    public function updatePrice(string $varyasyonId, float $price): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunId' => (int) $varyasyonId,
            'SatisFiyati' => $price,
        ];
        $resp = $this->client->call('product', $this->method('update_price'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    /**
     * Stok + fiyat — iki ayrı SOAP çağrısı.
     */
    public function updateStockAndPrice(string $varyasyonId, int $stock, float $price): array
    {
        return [
            'stock' => $this->updateStock($varyasyonId, $stock),
            'price' => $this->updatePrice($varyasyonId, $price),
        ];
    }

    /**
     * UrunKarti.Aktif alanını güncellemek için SaveUrun ile sadece Aktif update.
     */
    public function setActive(string $productId, bool $active): array
    {
        return $this->updateProduct($productId, ['Aktif' => $active]);
    }

    /**
     * Tam ürün oluşturmada hangi alanların yazılacağı (UrunKartiAyar + VaryasyonAyar).
     * Hepsini true yaparak gönderdiğimiz tüm alanların yazılmasını söylüyoruz.
     */
    protected function fullCreateAyarlari(): array
    {
        return [
            'ukAyar' => [
                'AciklamaGuncelle' => true,
                'AktifGuncelle' => true,
                'AnaKategoriGuncelle' => true,
                'AramaAnahtarKelimeGuncelle' => true,
                'EtiketGuncelle' => true,
                'KategoriGuncelle' => true,
                'ListedeGosterGuncelle' => true,
                'MarkaGuncelle' => true,
                'OnYaziGuncelle' => true,
                'ResimOlmayanlaraResimEkle' => true,
                'SatisBirimiGuncelle' => true,
                'SeoAnahtarKelimeGuncelle' => true,
                'SeoSayfaAciklamaGuncelle' => true,
                'SeoSayfaBaslikGuncelle' => true,
                'OncekiResimleriSil' => false,
                'Base64Resim' => false,
                'ResimleriIndirme' => false,
            ],
            'vAyar' => [
                'AktifGuncelle' => true,
                'AlisFiyatiGuncelle' => true,
                'BarkodGuncelle' => true,
                'IndirimliFiyatiGuncelle' => true,
                'KdvDahilGuncelle' => true,
                'KdvOraniGuncelle' => true,
                'ParaBirimiGuncelle' => true,
                'PiyasaFiyatiGuncelle' => true,
                'SatisFiyatiGuncelle' => true,
                'StokAdediGuncelle' => true,
                'StokKoduGuncelle' => true,
                'UrunKartiAktifGuncelle' => true,
                'OncekiResimleriSil' => false,
            ],
        ];
    }

    protected function fullUpdateAyarlari(): array
    {
        return $this->fullCreateAyarlari();
    }

    protected function method(string $key): string
    {
        return (string) config("ticimax.methods.product.{$key}");
    }

    /**
     * Result'tan UrunKarti listesini çıkarır. Ticimax tek elemanı obje, çoklu elemanları array
     * dönebiliyor. ArrayOfUrunKarti wrapper'ı UrunKarti anahtarı ile geliyor.
     */
    protected function normalizeList(mixed $resp, string $method = '', string $itemKey = 'UrunKarti'): array
    {
        $resultKey = $method . 'Result';
        if (is_object($resp)) {
            // SaveUrun gibi method'larda Result alanı sadece status int olabilir.
            // Önce urunKartlari (gerçek payload) varsa onu kullan; yoksa Result alanına bak.
            if (isset($resp->urunKartlari)) {
                $resp = $resp->urunKartlari;
            } elseif (isset($resp->{$resultKey}) && (is_object($resp->{$resultKey}) || is_array($resp->{$resultKey}))) {
                $resp = $resp->{$resultKey};
            }
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        if (! is_array($resp)) {
            return [];
        }
        if (isset($resp[$itemKey])) {
            $resp = is_array($resp[$itemKey]) && array_is_list($resp[$itemKey]) ? $resp[$itemKey] : [$resp[$itemKey]];
        } elseif (! array_is_list($resp)) {
            $resp = [$resp];
        }
        return array_map(fn ($r) => $this->toArray($r), $resp);
    }

    protected function normalizeOne(mixed $resp): ?array
    {
        if (! $resp) {
            return null;
        }
        return $this->toArray($resp);
    }

    protected function toArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_map(fn ($x) => is_object($x) || is_array($x) ? $this->toArray($x) : $x, $v);
        }
        if (is_object($v)) {
            return $this->toArray((array) $v);
        }
        return [];
    }
}
