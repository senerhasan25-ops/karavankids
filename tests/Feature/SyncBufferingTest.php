<?php

namespace Tests\Feature;

use App\Jobs\Concerns\BuffersSyncWrites;
use App\Models\SyncJob;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BuffersSyncWrites trait'inin toplu yazımını doğrular (#4).
 */
class SyncBufferingTest extends TestCase
{
    use RefreshDatabase;

    /** Trait'i test edebilmek için public sarmalayıcılarla anonim sınıf. */
    private function harness(): object
    {
        return new class
        {
            use BuffersSyncWrites;

            public function add(SyncJob $job, array $ctx, string $status, string $msg): void
            {
                $this->bufferLog($job, $ctx, 'create_product', $status, $msg);
            }

            public function flush(SyncJob $job): void
            {
                $this->flushSyncBuffers($job);
            }

            public function dropOneTotal(): void
            {
                $this->pendingTotal--;
            }
        };
    }

    public function test_buffered_loglar_tek_seferde_yazilir_ve_sayaclar_artar(): void
    {
        $job = SyncJob::create(['type' => 'test', 'status' => 'running', 'started_at' => now()]);
        $h = $this->harness();

        $h->add($job, ['stok_kodu' => 'A'], 'success', 'ok-A');
        $h->add($job, ['stok_kodu' => 'B'], 'success', 'ok-B');
        $h->add($job, ['stok_kodu' => 'C'], 'error', 'fail-C');

        // Flush'tan ÖNCE hiçbir şey DB'de olmamalı (gerçekten tamponlanıyor mu?)
        $this->assertSame(0, SyncLog::where('job_id', $job->id)->count());

        $h->flush($job);
        $job->refresh();

        $this->assertSame(3, SyncLog::where('job_id', $job->id)->count());
        $this->assertSame(3, $job->total);
        $this->assertSame(2, $job->success_count);
        $this->assertSame(1, $job->error_count);

        // Insert edilen satırlarda zorunlu alanlar + timestamp dolu mu?
        $row = SyncLog::where('job_id', $job->id)->where('stok_kodu', 'A')->first();
        $this->assertSame('create_product', $row->action);
        $this->assertSame('ana_to_bayi', $row->direction);
        $this->assertNotNull($row->created_at);
    }

    public function test_warning_total_disinda_birakilabilir(): void
    {
        $job = SyncJob::create(['type' => 'test', 'status' => 'running', 'started_at' => now()]);
        $h = $this->harness();

        $h->add($job, [], 'warning', 'sayfa atlandı');
        $h->dropOneTotal(); // job'lar warning'i ürün saymaz
        $h->add($job, ['stok_kodu' => 'X'], 'success', 'ok');
        $h->flush($job);
        $job->refresh();

        $this->assertSame(2, SyncLog::where('job_id', $job->id)->count()); // warning yine loglanır
        $this->assertSame(1, $job->total); // ama total'a sayılmaz
        $this->assertSame(1, $job->success_count);
        $this->assertSame(0, $job->error_count);
    }

    public function test_bos_tampon_flush_sorunsuz(): void
    {
        $job = SyncJob::create(['type' => 'test', 'status' => 'running', 'started_at' => now()]);
        $h = $this->harness();

        $h->flush($job); // hiç buffer yok → patlamamalı
        $job->refresh();

        $this->assertSame(0, $job->total);
        $this->assertSame(0, SyncLog::where('job_id', $job->id)->count());
    }
}
