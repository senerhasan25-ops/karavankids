<?php

namespace Tests\Feature;

use App\Jobs\TransferSingleBayiOrderJob;
use App\Livewire\OrderTransferPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sipariş aktarım panelindeki çoklu seçim + toplu aktarım davranışı.
 *
 * - toggleSelectAll: sayfadaki TÜM siparişleri seçer/temizler (aktarılmış dahil)
 * - topluAktar(): aktarılmış/kuyruktaki siparişleri ATLAR (duplicate guard)
 * - topluAktar(force): hepsini gönderir
 */
class OrderTransferSelectionTest extends TestCase
{
    use RefreshDatabase;

    private array $orders = [
        ['id' => '83', 'local_status' => null],          // yeni
        ['id' => '84', 'local_status' => 'failed'],      // başarısız
        ['id' => '85', 'local_status' => 'transferred'], // aktarılmış
        ['id' => '86', 'local_status' => 'queued'],      // kuyrukta
    ];

    public function test_tumunu_sec_tum_siparisleri_secer_ve_temizler(): void
    {
        $c = Livewire::test(OrderTransferPicker::class)
            ->set('orders', $this->orders);

        // İlk tıklama: tümü seçili
        $c->call('toggleSelectAll')
            ->assertSet('selectedBayiIds', ['83', '84', '85', '86']);

        // İkinci tıklama: temizlenir
        $c->call('toggleSelectAll')
            ->assertSet('selectedBayiIds', []);
    }

    public function test_toplu_aktar_aktarilanlari_atlar(): void
    {
        Queue::fake();

        Livewire::test(OrderTransferPicker::class)
            ->set('orders', $this->orders)
            ->set('selectedBayiIds', ['83', '84', '85', '86'])
            ->call('topluAktar');

        // Sadece yeni (83) + başarısız (84) kuyruğa alınır; 85/86 atlanır.
        Queue::assertPushed(TransferSingleBayiOrderJob::class, 2);
    }

    public function test_force_toplu_aktar_hepsini_gonderir(): void
    {
        Queue::fake();

        Livewire::test(OrderTransferPicker::class)
            ->set('orders', $this->orders)
            ->set('selectedBayiIds', ['83', '84', '85', '86'])
            ->call('topluAktar', true);

        Queue::assertPushed(TransferSingleBayiOrderJob::class, 4);
    }
}
