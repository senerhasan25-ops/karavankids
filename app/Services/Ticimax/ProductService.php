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
     * SelectUrun çağrısı — filtreli ürün listesi döner.
     * Ticimax'in gerçek imzası: SelectUrun(UyeKodu, UyeSifre, f: WebUrunFiltre).
     */
    public function getNewProducts(?Carbon $since = null, int $page = 1, int $perPage = 50): array
    {
        $params = $this->client->getAuth() + [
            'f' => [
                'Aktif' => -1,
                'Firsat' => -1,
                'Indirimli' => -1,
                'Sayfa' => $page,
                'SayfadakiUrunSayisi' => $perPage,
                'TarihGuncellemeBaslangic' => $since?->format('Y-m-d H:i:s') ?? '',
                'TarihGuncellemeBitis' => '',
            ],
        ];
        $resp = $this->client->call('product', $this->method('select'), $params);
        return $this->normalizeList($resp, $this->method('select'));
    }

    /**
     * Barkoda göre tek ürün. SelectUrun'a Barkod filtresi vererek arıyoruz
     * (Ticimax'te ayrı GetUrunByBarkod yok).
     */
    public function getProductByBarcode(string $barcode): ?array
    {
        $params = $this->client->getAuth() + [
            'f' => [
                'Aktif' => -1,
                'Barkod' => $barcode,
                'Sayfa' => 1,
                'SayfadakiUrunSayisi' => 1,
            ],
        ];
        $resp = $this->client->call('product', $this->method('select'), $params);
        $list = $this->normalizeList($resp, $this->method('select'));
        return $list[0] ?? null;
    }

    public function createProduct(array $payload): array
    {
        $params = $this->client->getAuth() + ['urun' => $payload];
        $resp = $this->client->call('product', $this->method('save'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    public function updateProduct(string $productId, array $payload): array
    {
        $params = $this->client->getAuth() + ['urun' => array_merge($payload, ['UrunKartiID' => $productId])];
        $resp = $this->client->call('product', $this->method('save'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    /**
     * Stok ve fiyat güncellemesi. Ticimax'te tek call yok — iki ayrı SOAP çağrısı.
     * Hata olursa exception fırlatır (her ikisi de bağımsız).
     */
    public function updateStockAndPrice(string $productId, int $stock, float $price): array
    {
        $auth = $this->client->getAuth();

        $stockResp = $this->client->call('product', $this->method('update_stock'), $auth + [
            'urunId' => $productId,
            'StokAdedi' => $stock,
        ]);

        $priceResp = $this->client->call('product', $this->method('update_price'), $auth + [
            'urunId' => $productId,
            'SatisFiyati' => $price,
        ]);

        return [
            'stock' => $this->normalizeOne($stockResp),
            'price' => $this->normalizeOne($priceResp),
        ];
    }

    /**
     * Ticimax'te ayrı SetUrunAktif yok — SaveUrun ile Aktif alanı güncellenir.
     */
    public function setActive(string $productId, bool $active): array
    {
        return $this->updateProduct($productId, ['Aktif' => $active]);
    }

    protected function method(string $key): string
    {
        return (string) config("ticimax.methods.product.{$key}");
    }

    protected function normalizeList(mixed $resp, string $method = ''): array
    {
        $resultKey = $method . 'Result';
        if (is_object($resp) && isset($resp->{$resultKey})) {
            $resp = $resp->{$resultKey};
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        if (! is_array($resp)) {
            return [];
        }
        // Ticimax çoğu liste için tek elemanı obje, çoklu elemanları array döner.
        // Wrapper olarak Urun anahtarı kullanır.
        if (isset($resp['Urun'])) {
            $resp = is_array($resp['Urun']) && array_is_list($resp['Urun']) ? $resp['Urun'] : [$resp['Urun']];
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
