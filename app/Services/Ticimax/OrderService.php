<?php

namespace App\Services\Ticimax;

use Illuminate\Support\Carbon;

class OrderService
{
    public function __construct(protected TicimaxClient $client)
    {
    }

    public static function for(string $storeKey): self
    {
        return new self(TicimaxClient::for($storeKey));
    }

    public function getNewOrders(?Carbon $since = null, int $page = 1, int $perPage = 50): array
    {
        $params = $this->client->getAuth() + [
            'f' => [
                'OnayDurumu' => -1,
                'SiparisDurumu' => -1,
                'Sayfa' => $page,
                'SayfadakiKayitSayisi' => $perPage,
                'BaslangicTarihi' => $since?->format('Y-m-d H:i:s') ?? '',
                'BitisTarihi' => '',
                'Aktarildi' => 0, // sadece henüz aktarılmamışlar
            ],
        ];
        $resp = $this->client->call('order', $this->method('select'), $params);
        return $this->normalizeList($resp, $this->method('select'));
    }

    /**
     * Ticimax'te 'SetSiparisAktarildi' var — admin notu yerine bunu kullanıyoruz.
     * Sipariş bayi'de "aktarıldı" olarak işaretlenir, bir daha çekilmez.
     */
    public function markOrderTransferred(string $orderId, ?string $externalRef = null): array
    {
        $params = $this->client->getAuth() + [
            'siparisId' => $orderId,
            'aktarilanSiparisKodu' => $externalRef ?? '',
        ];
        $resp = $this->client->call('order', $this->method('mark_transferred'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    public function createOrder(array $payload): array
    {
        $params = $this->client->getAuth() + ['siparis' => $payload];
        $resp = $this->client->call('order', $this->method('save'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    protected function method(string $key): string
    {
        return (string) config("ticimax.methods.order.{$key}");
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
        if (isset($resp['Siparis'])) {
            $resp = is_array($resp['Siparis']) && array_is_list($resp['Siparis']) ? $resp['Siparis'] : [$resp['Siparis']];
        } elseif (! array_is_list($resp)) {
            $resp = [$resp];
        }
        return array_map(fn ($r) => $this->toArray($r), $resp);
    }

    protected function normalizeOne(mixed $resp): ?array
    {
        return $resp ? $this->toArray($resp) : null;
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
