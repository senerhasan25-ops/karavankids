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

    public function getClient(): TicimaxClient
    {
        return $this->client;
    }

    /**
     * Henüz aktarılmamış (EntegrasyonAktarildi=0), ödemesi alınmış, paketlenmemiş siparişleri çek.
     * siparis_aktar.py:get_source_orders ile aynı filtre.
     */
    public function getNewOrders(?Carbon $since = null, int $page = 1, int $perPage = 50): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);
        $bas = ($since ?? Carbon::now()->subDays(3))->format('Y-m-d\T00:00:00');
        $son = Carbon::now()->format('Y-m-d\T23:59:59');

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => [
                'DuzenlemeTarihiBas' => null,
                'DuzenlemeTarihiSon' => null,
                'EntegrasyonAktarildi' => 0,
                'EntegrasyonParams' => [
                    'EntegrasyonParamsAktif' => false,
                ],
                'IptalEdilmisUrunler' => false,
                'KampanyaGetir' => false,
                'KargoFirmaID' => 0,
                'OdemeDurumu' => 1,
                'OdemeTipi' => -1,
                'PaketlemeDurumu' => 1, // 1 = henüz paketlenmemiş (aktarım için uygun)
                'SiparisDurumu' => 0,
                'SiparisID' => 0,
                'SiparisKaynagi' => '',
                'SiparisTarihiBas' => $bas,
                'SiparisTarihiSon' => $son,
                'StrSiparisDurumu' => '',
                'TedarikciID' => -1,
                'UrunGetir' => true,
                'UyeID' => -1,
            ],
            's' => [
                'BaslangicIndex' => $startIdx,
                'KayitSayisi' => $perPage,
                'SiralamaYonu' => 'ASC',
            ],
        ];

        $resp = $this->client->call('order', $this->method('select'), $params);
        return $this->normalizeList($resp, $this->method('select'));
    }

    /**
     * Ana mağazada sipariş oluştur. Payload ProductMapper::bayiOrderToAnaCreatePayload
     * tarafından gerçek WebSiparis envelope şemasında hazırlanır.
     */
    public function createOrder(array $payload): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'siparis' => $payload,
        ];
        $resp = $this->client->call('order', $this->method('save'), $params);

        // Ticimax SaveSiparis "başarı sayma" mantığı:
        //  • Asıl gösterge SiparisDetayi'nin DOLU olmasıdır (yeni sipariş ID dönüyor)
        //  • IsError=true bazen yumuşak uyarı olarak gelir (örn. "stok hesabı geç güncellendi")
        //    ama sipariş yine de oluşmuş olur. O yüzden SiparisDetayi varsa başarı sayalım.
        //  • Sadece SiparisDetayi NIL ise gerçekten reddetmiş demektir → exception fırlat.
        $result = $resp;
        if (is_object($result) && isset($result->SaveSiparisResult)) {
            $result = $result->SaveSiparisResult;
        }
        $resultArr = is_object($result) ? json_decode(json_encode($result), true) : (array) $result;

        $siparisDetayi = $resultArr['SiparisDetayi'] ?? null;
        // SOAP nil → ['@attributes' => ['nil' => 'true']] veya boş array gelir
        $detayiBos = $siparisDetayi === null
            || (is_array($siparisDetayi) && (
                empty($siparisDetayi)
                || (isset($siparisDetayi['@attributes']['nil']) && filter_var($siparisDetayi['@attributes']['nil'], FILTER_VALIDATE_BOOLEAN))
                || (isset($siparisDetayi['nil']) && filter_var($siparisDetayi['nil'], FILTER_VALIDATE_BOOLEAN))
            ));

        if ($detayiBos) {
            // Gerçek reddedilme → Ticimax hata mesajını topla ve fırlat
            $msg = trim((string) ($resultArr['ErrorMessage'] ?? 'Bilinmeyen Ticimax hatası'));
            $details = $resultArr['Messages']['WebServisResponse'] ?? [];
            if (is_array($details) && ! array_is_list($details)) {
                $details = [$details];
            }
            $subMsgs = [];
            foreach ((array) $details as $d) {
                $code = $d['ErrorCode'] ?? '';
                $emsg = trim((string) ($d['ErrorMessage'] ?? ''));
                if ($emsg !== '') {
                    $subMsgs[] = "  • [{$code}] {$emsg}";
                }
            }
            $full = "Ticimax SaveSiparis reddetti: {$msg}"
                . ($subMsgs ? "\n" . implode("\n", $subMsgs) : '');
            throw new \RuntimeException($full);
        }

        // BAŞARI — SiparisDetayi dolu. PullBayiOrdersJob ana_order_id'yi okuyabilsin diye
        // SiparisDetayi'ndeki ID'yi üst seviyeye taşıyalım.
        $anaOrderId = null;
        if (is_array($siparisDetayi)) {
            $anaOrderId = $siparisDetayi['ID']
                ?? $siparisDetayi['SiparisID']
                ?? $siparisDetayi['SiparisId']
                ?? null;
        }

        $normalized = $this->normalizeOne($resp) ?? [];
        if ($anaOrderId !== null && ! isset($normalized['SiparisID'])) {
            $normalized['SiparisID'] = (string) $anaOrderId;
        }
        return $normalized;
    }

    /**
     * Aktarım sonrası bayi'deki siparişe "aktarıldı" damgası vur.
     * Eski API'de SetSiparisAktarildi vardı; siparis_aktar.py'de Hasan
     * SetSiparisPaketlemeDurum(2) kullanıyor (paketlendi → bir daha çekilmez).
     * Burada ikisini de destekliyoruz: önce SetSiparisAktarildi dene, yoksa paketleme.
     */
    public function markOrderTransferred(string $orderId, ?string $externalRef = null): array
    {
        try {
            $params = [
                'UyeKodu' => $this->client->getUyeKodu(),
                'siparisId' => (int) $orderId,
                'aktarilanSiparisKodu' => $externalRef ?? '',
            ];
            $resp = $this->client->call('order', $this->method('mark_transferred'), $params);
            return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisAktarildi'];
        } catch (\Throwable $e) {
            // Fallback: paketleme durumunu 2 yap → bir daha "yeni" filtresine girmez.
            $params = [
                'UyeKodu' => $this->client->getUyeKodu(),
                'SiparisId' => (int) $orderId,
                'PaketlemeDurumId' => 2,
            ];
            $resp = $this->client->call('order', 'SetSiparisPaketlemeDurum', $params);
            return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisPaketlemeDurum', 'fallback_reason' => $e->getMessage()];
        }
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
        // WebSiparis (yeni) veya Siparis (eski) wrapper'ı
        foreach (['WebSiparis', 'Siparis'] as $key) {
            if (isset($resp[$key])) {
                $resp = is_array($resp[$key]) && array_is_list($resp[$key]) ? $resp[$key] : [$resp[$key]];
                break;
            }
        }
        if (! array_is_list($resp)) {
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
