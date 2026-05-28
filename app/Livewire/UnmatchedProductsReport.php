<?php

namespace App\Livewire;

use App\Jobs\TransferSingleBayiOrderJob;
use App\Models\OrderTransfer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Eşleşmeyen Ürünler Raporu
 *
 * Sipariş aktarımı sırasında ProductMapper'da fırlatılan
 * "Ana'da bu StokKodu ile aktif ürün bulunamadı: XXX" hatasını yakalayıp
 * bunları stok koduna göre gruplayan rapor ekranı.
 *
 * Veri kaynağı: zaten var olan `order_transfers.last_error` kolonu —
 * yeni tablo / migration yok. Sadece okuma raporu.
 *
 * Kullanım: kullanıcı eşleşmeyen ürünleri ana sitede oluşturduktan sonra
 * "Tekrar Dene" ile aynı bayi siparişlerini tekrar kuyruğa alır.
 */
#[Title('Eşleşmeyen Ürünler')]
#[Layout('layouts.app')]
class UnmatchedProductsReport extends Component
{
    public string $search = '';
    public ?string $expandedStokKodu = null;

    /** Tek bir stok kodu için etkilenen tüm bayi siparişlerini yeniden kuyruğa al. */
    public function retryStokKodu(string $stokKodu): void
    {
        $affected = $this->affectedOrdersForStokKodu($stokKodu);
        $count = 0;
        foreach ($affected as $bayiOrderId) {
            TransferSingleBayiOrderJob::dispatch((string) $bayiOrderId, false);
            $count++;
        }
        session()->flash('status', "'{$stokKodu}' stok kodu için {$count} sipariş yeniden aktarım kuyruğuna alındı.");
    }

    public function toggleExpand(string $stokKodu): void
    {
        $this->expandedStokKodu = $this->expandedStokKodu === $stokKodu ? null : $stokKodu;
    }

    /**
     * `last_error` metninden stok kodlarını çıkar ve gruplandır.
     * ProductMapper'ın throw ettiği mesajın formatı (sabit):
     *   "Ana'da bu StokKodu ile aktif ürün bulunamadı: <STOK_KODU>"
     */
    public function render()
    {
        $rows = OrderTransfer::query()
            ->where('status', 'failed')
            ->where('last_error', 'like', "%StokKodu ile aktif ürün bulunamadı%")
            ->orderByDesc('updated_at')
            ->get(['id', 'bayi_order_id', 'ana_order_id', 'status', 'last_error', 'updated_at', 'created_at']);

        // Stok koduna göre grupla
        $groups = [];
        foreach ($rows as $r) {
            if (preg_match('/bulunamadı:\s*([^\s\.\n]+)/u', (string) $r->last_error, $m)) {
                $sk = trim($m[1]);
                if ($sk === '') {
                    continue;
                }
                // Eğer search varsa filtrele
                if ($this->search !== '' && mb_stripos($sk, $this->search) === false) {
                    continue;
                }
                if (! isset($groups[$sk])) {
                    $groups[$sk] = [
                        'stok_kodu' => $sk,
                        'count' => 0,
                        'orders' => [],
                        'first_seen' => $r->updated_at,
                        'last_seen' => $r->updated_at,
                    ];
                }
                $groups[$sk]['count']++;
                $groups[$sk]['orders'][] = [
                    'transfer_id' => $r->id,
                    'bayi_order_id' => $r->bayi_order_id,
                    'updated_at' => $r->updated_at,
                ];
                if ($r->updated_at < $groups[$sk]['first_seen']) {
                    $groups[$sk]['first_seen'] = $r->updated_at;
                }
                if ($r->updated_at > $groups[$sk]['last_seen']) {
                    $groups[$sk]['last_seen'] = $r->updated_at;
                }
            }
        }

        // Etkilenen sipariş sayısına göre azalan
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return view('livewire.unmatched-products-report', [
            'groups' => $groups,
            'totalAffectedOrders' => array_sum(array_column($groups, 'count')),
            'totalDistinctSku' => count($groups),
        ]);
    }

    /** Bir stok kodu için etkilenen distinct bayi sipariş ID'leri. */
    protected function affectedOrdersForStokKodu(string $stokKodu): array
    {
        $rows = OrderTransfer::query()
            ->where('status', 'failed')
            ->where('last_error', 'like', "%bulunamadı: {$stokKodu}%")
            ->pluck('bayi_order_id')
            ->unique()
            ->values()
            ->all();
        return $rows;
    }
}
