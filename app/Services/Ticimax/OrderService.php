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
            ],
        ];
        $resp = $this->client->call('order', 'SelectSiparisler', $params);
        return $this->normalizeList($resp);
    }

    public function markOrderTransferred(string $orderId, string $note = 'Bayi siparişi aktarıldı'): array
    {
        $params = $this->client->getAuth() + [
            'siparisId' => $orderId,
            'AdminNotu' => $note,
        ];
        $resp = $this->client->call('order', 'SetSiparisAdminNotu', $params);
        return $this->normalizeOne($resp) ?? [];
    }

    public function createOrder(array $payload): array
    {
        $params = $this->client->getAuth() + ['siparis' => $payload];
        $resp = $this->client->call('order', 'SaveSiparis', $params);
        return $this->normalizeOne($resp) ?? [];
    }

    protected function normalizeList(mixed $resp): array
    {
        if (is_object($resp) && isset($resp->{'SelectSiparislerResult'})) {
            $resp = $resp->SelectSiparislerResult;
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        return is_array($resp) ? array_map(fn ($r) => $this->toArray($r), $resp) : [];
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
