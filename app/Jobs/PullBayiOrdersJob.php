<?php

namespace App\Jobs;

use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Models\SyncSetting;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class PullBayiOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'order_pull',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $bayi = OrderService::for('bayi');
            $ana = OrderService::for('ana');
            $mapper = new ProductMapper();

            $sinceRaw = SyncSetting::get('last_order_pull_at');
            $since = $sinceRaw ? Carbon::parse($sinceRaw) : Carbon::now()->subDay();

            $page = 1;
            $perPage = (int) config('ticimax.batch_size', 50);

            while (true) {
                $orders = $bayi->getNewOrders($since, $page, $perPage);
                if (empty($orders)) {
                    break;
                }

                foreach ($orders as $o) {
                    $job->increment('total');
                    $bayiOrderId = (string) ($o['SiparisID'] ?? $o['Id'] ?? '');
                    if (! $bayiOrderId) {
                        continue;
                    }

                    $existing = OrderTransfer::where('bayi_order_id', $bayiOrderId)->first();
                    if ($existing && $existing->status === 'transferred') {
                        continue;
                    }

                    $transfer = $existing ?? new OrderTransfer(['bayi_order_id' => $bayiOrderId]);
                    $transfer->payload_snapshot = $o;

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

                        $bayi->markOrderTransferred($bayiOrderId, "Ana #{$anaOrderId} olarak aktarıldı");

                        $transfer->fill([
                            'ana_order_id' => $anaOrderId,
                            'status' => 'transferred',
                            'transferred_at' => now(),
                            'last_error' => null,
                        ])->save();

                        $this->log($job, $bayiOrderId, 'success', "Ana #{$anaOrderId}");
                    } catch (Throwable $e) {
                        $transfer->fill([
                            'status' => 'failed',
                            'retry_count' => ($transfer->retry_count ?? 0) + 1,
                            'last_error' => $e->getMessage(),
                        ])->save();
                        $this->log($job, $bayiOrderId, 'error', $e->getMessage());
                    }
                }

                if (count($orders) < $perPage) {
                    break;
                }
                $page++;
            }

            SyncSetting::put('last_order_pull_at', now()->toDateTimeString());
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

    protected function log(SyncJob $job, string $bayiOrderId, string $status, string $msg): void
    {
        if ($status === 'success') {
            $job->increment('success_count');
        } else {
            $job->increment('error_count');
        }
        SyncLog::create([
            'job_id' => $job->id,
            'barcode' => $bayiOrderId,
            'action' => 'transfer_order',
            'direction' => 'bayi_to_ana',
            'status' => $status,
            'message' => $msg,
        ]);
    }
}
