<?php

namespace App\Jobs\Concerns;

use App\Models\SyncJob;
use App\Models\SyncLog;
use Illuminate\Support\Carbon;

/**
 * Sync job'larında satır-başına DB yazımını topluya çevirir (#4).
 *
 * ÖNCE: her ürün için $job->increment() (UPDATE) + SyncLog::create() (INSERT)
 *       → 5000 ürün ≈ 15.000 sorgu.
 * SONRA: log'lar bellekte biriktirilip sayfa başına tek bulk INSERT,
 *        sayaçlar bellekte toplanıp sayfa başına tek UPDATE.
 *        → 5000 ürün ≈ 50 sorgu (sayfa sayısı × 2).
 *
 * Kullanan job: bufferLog() ile log ekler, sayfa sonunda flushSyncBuffers()
 * çağırır. İş bitiminde / erken çıkışta da flush ETMELİ (kalan tampon yazılsın).
 */
trait BuffersSyncWrites
{
    /** @var array<int,array<string,mixed>> Bekleyen sync_logs satırları (bulk insert için). */
    protected array $logBuffer = [];

    protected int $pendingTotal = 0;

    protected int $pendingSuccess = 0;

    protected int $pendingError = 0;

    /**
     * Bir log satırını tampona ekle. action/direction/status NOT NULL olduğundan
     * varsayılan değerlerle garanti altına alınır; created_at/updated_at bulk
     * insert'te elle verilmeli (Eloquent otomatik eklemez).
     *
     * @param  string  $kind  'success' | 'error' | 'warning' → sayaçları da günceller
     */
    protected function bufferLog(SyncJob $job, array $ctx, string $action, string $status, string $message, string $direction = 'ana_to_bayi', ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        $now = Carbon::now();
        $this->logBuffer[] = array_merge(
            [
                'job_id' => $job->id,
                'barcode' => null,
                'stok_kodu' => null,
                'urun_adi' => null,
                'ana_id' => null,
                'bayi_id' => null,
                'raw_request' => $rawRequest,
                'raw_response' => $rawResponse,
            ],
            $ctx,
            [
                'action' => $action,
                'direction' => $direction,
                'status' => $status,
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->pendingTotal++;
        if ($status === 'success') {
            $this->pendingSuccess++;
        } elseif ($status === 'error') {
            $this->pendingError++;
        }
    }

    /**
     * Tamponu DB'ye boşalt: tek bulk INSERT + tek sayaç UPDATE.
     * Hiç bekleyen yoksa hiçbir sorgu yapmaz.
     */
    protected function flushSyncBuffers(SyncJob $job): void
    {
        if (! empty($this->logBuffer)) {
            // Büyük raw_request/response'lar olabilir → makul parçalara böl.
            foreach (array_chunk($this->logBuffer, 200) as $chunk) {
                SyncLog::insert($chunk);
            }
            $this->logBuffer = [];
        }

        if ($this->pendingTotal !== 0 || $this->pendingSuccess !== 0 || $this->pendingError !== 0) {
            $job->increment('total', $this->pendingTotal);
            if ($this->pendingSuccess !== 0) {
                $job->increment('success_count', $this->pendingSuccess);
            }
            if ($this->pendingError !== 0) {
                $job->increment('error_count', $this->pendingError);
            }
            $this->pendingTotal = 0;
            $this->pendingSuccess = 0;
            $this->pendingError = 0;
        }
    }
}
