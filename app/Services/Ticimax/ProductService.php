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
        $resp = $this->client->call('product', 'SelectUrunler', $params);
        return $this->normalizeList($resp);
    }

    public function getProductByBarcode(string $barcode): ?array
    {
        $params = $this->client->getAuth() + ['Barkod' => $barcode];
        $resp = $this->client->call('product', 'GetUrunByBarkod', $params);
        return $this->normalizeOne($resp);
    }

    public function createProduct(array $payload): array
    {
        $params = $this->client->getAuth() + ['urun' => $payload];
        $resp = $this->client->call('product', 'SaveUrun', $params);
        return $this->normalizeOne($resp) ?? [];
    }

    public function updateStockAndPrice(string $productId, int $stock, float $price): array
    {
        $params = $this->client->getAuth() + [
            'urunId' => $productId,
            'StokAdedi' => $stock,
            'SatisFiyati' => $price,
        ];
        $resp = $this->client->call('product', 'SetUrunStokFiyat', $params);
        return $this->normalizeOne($resp) ?? [];
    }

    protected function normalizeList(mixed $resp): array
    {
        if (is_object($resp) && isset($resp->{'SelectUrunlerResult'})) {
            $resp = $resp->SelectUrunlerResult;
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        return is_array($resp) ? array_map(fn ($r) => $this->toArray($r), $resp) : [];
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
