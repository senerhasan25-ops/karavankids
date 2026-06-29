<?php

namespace Tests\Feature;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SyncStockPriceJob::dispatchUnique() ve PullBayiOrdersJob::dispatchUnique() —
 * scheduler kısa interval'de (5 dk) önceki kopya hâlâ çalışırken/kuyruktayken
 * ikinci kopya dispatch ETMEMELİ (Ticimax'a paralel SOAP / çift sipariş riski).
 */
class StockPriceAndOrderDispatchGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stok_fiyat_temizken_kuyruga_eklenir(): void
    {
        Queue::fake();

        $this->assertTrue(SyncStockPriceJob::dispatchUnique());
        Queue::assertPushed(SyncStockPriceJob::class, 1);
    }

    public function test_stok_fiyat_calisan_is_varken_atlanir(): void
    {
        Queue::fake();

        SyncJob::create([
            'type' => 'stock_price_update',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->assertFalse(SyncStockPriceJob::dispatchUnique());
        Queue::assertNothingPushed();
    }

    public function test_stok_fiyat_kuyrukta_bekleyen_is_varken_atlanir(): void
    {
        Queue::fake();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncStockPriceJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->assertFalse(SyncStockPriceJob::dispatchUnique());
        Queue::assertNothingPushed();
    }

    public function test_siparis_temizken_kuyruga_eklenir(): void
    {
        Queue::fake();

        $this->assertTrue(PullBayiOrdersJob::dispatchUnique());
        Queue::assertPushed(PullBayiOrdersJob::class, 1);
    }

    public function test_siparis_calisan_is_varken_atlanir(): void
    {
        Queue::fake();

        SyncJob::create([
            'type' => 'order_pull',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->assertFalse(PullBayiOrdersJob::dispatchUnique());
        Queue::assertNothingPushed();
    }

    public function test_siparis_kuyrukta_bekleyen_is_varken_atlanir(): void
    {
        Queue::fake();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\PullBayiOrdersJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->assertFalse(PullBayiOrdersJob::dispatchUnique());
        Queue::assertNothingPushed();
    }
}
