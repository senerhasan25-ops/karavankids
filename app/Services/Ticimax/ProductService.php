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

    public function getProductByBarcode(string $barcode): ?array
    {
        $params = $this->client->getAuth() + ['Barkod' => $barcode];
        $resp = $this->client->call('product', $this->method('get_by_barcode'), $params);
        return $this->normalizeOne($resp);
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

    public function updateStockAndPrice(string $productId, int $stock, float $price): array
    {
        $params = $this->client->getAuth() + [
            'urunId' => $productId,
            'StokAdedi' => $stock,
            'SatisFiyati' => $price,
        ];
        $resp = $this->client->call('product', $this->method('update_stock_price'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    public function setActive(string $productId, bool $active): array
    {
        $params = $this->client->getAuth() + [
            'urunId' => $productId,
            'Aktif' => $active,
        ];
        $resp = $this->client->call('product', $this->method('set_active'), $params);
        return $this->normalizeOne($resp) ?? [];
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
        if (isset($resp['Urun'])) {
            $resp = is_array($resp['Urun']) && array_is_list($resp['Urun']) ? $resp['Urun'] : [$resp['Urun']];
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
