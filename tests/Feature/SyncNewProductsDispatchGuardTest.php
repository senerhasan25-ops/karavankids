<?php

namespace Tests\Feature;

use App\Jobs\SyncNewProductsJob;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SyncNewProductsJob::dispatchUnique() — buton iki kez basılsa bile
 * çalışan/bekleyen bir ürün işi varken ikinci kopya oluşmaz.
 */
class SyncNewProductsDispatchGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_temizken_kuyruga_eklenir(): void
    {
        Queue::fake();

        $this->assertTrue(SyncNewProductsJob::dispatchUnique());
        Queue::assertPushed(SyncNewProductsJob::class, 1);
    }

    public function test_calisan_is_varken_atlanir(): void
    {
        Queue::fake();

        SyncJob::create([
            'type' => 'product_create',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->assertFalse(SyncNewProductsJob::dispatchUnique());
        Queue::assertNothingPushed();
    }

    public function test_kuyrukta_bekleyen_is_varken_atlanir(): void
    {
        Queue::fake();

        // jobs tablosuna bekleyen bir SyncNewProductsJob taklit et
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncNewProductsJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->assertFalse(SyncNewProductsJob::dispatchUnique());
        Queue::assertNothingPushed();
    }
}
