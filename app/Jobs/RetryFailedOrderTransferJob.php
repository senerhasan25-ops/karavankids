<?php

namespace App\Jobs;

use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RetryFailedOrderTransferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public ?int $transferId = null)
    {
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'order_pull',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $query = OrderTransfer::where('status', 'failed');
            if ($this->transferId) {
                $query->where('id', $this->transferId);
            }

            $bayi = OrderService::for('bayi');
            $ana = OrderService::for('ana');
            $mapper = new ProductMapper();

            $query->chunkById(50, function ($transfers) use ($job, $bayi, $ana, $mapper) {
                foreach ($transfers as $transfer) {
                    $job->increment('total');
                    $o = $transfer->payload_snapshot ?? [];
                    try {
                        $barcodes = collect($o['Urunler'] ?? [])->pluck('Barkod')->filter()->unique()->all();
                        $map = ProductMapping::whereIn('barcode', $barcodes)->pluck('ana_product_id', 'barcode')->all();
                        $missing = array_diff($barcodes, array_keys($map));
                        if (! empty($missing)) {
                            throw new \RuntimeException('Ana eşleşmesi yok: ' . implode(',', $missing));
                        }

                        $anaPayload = $mapper->bayiOrderToAnaCreatePayload($o, $map);
                        $created = $ana->createOrder($anaPayload);
                        $anaOrderId = (string) ($created['SiparisID'] ?? $created['Id'] ?? '');

                        $bayi->markOrderTransferred($transfer->bayi_order_id, "Ana #{$anaOrderId} olarak aktarıldı");

                        $transfer->update([
                            'ana_order_id' => $anaOrderId,
                            'status' => 'transferred',
                            'transferred_at' => now(),
                            'last_error' => null,
                        ]);
                        $job->increment('success_count');
                        SyncLog::create([
                            'job_id' => $job->id,
                            'barcode' => $transfer->bayi_order_id,
                            'action' => 'transfer_order',
                            'direction' => 'bayi_to_ana',
                            'status' => 'success',
                            'message' => "Retry ok: Ana #{$anaOrderId}",
                        ]);
                    } catch (Throwable $e) {
                        $transfer->increment('retry_count');
                        $transfer->update(['last_error' => $e->getMessage()]);
                        $job->increment('error_count');
                        SyncLog::create([
                            'job_id' => $job->id,
                            'barcode' => $transfer->bayi_order_id,
                            'action' => 'transfer_order',
                            'direction' => 'bayi_to_ana',
                            'status' => 'error',
                            'message' => 'Retry failed: ' . $e->getMessage(),
                        ]);
                    }
                }
            });

            $job->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
