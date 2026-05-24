<?php

namespace App\Jobs;

use App\Livewire\QueueControl;
use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Manuel sipariş aktarım panelinden tetiklenir — tek bir bayi siparişi
 * için aktarım yapar. PullBayiOrdersJob'un tek-sipariş versiyonu.
 *
 * Akış:
 *  1) Bayi'den siparişi taze çek (panel'deki snapshot eski olabilir)
 *  2) ProductMapper ile ana payload üret
 *  3) Ana'ya SaveSiparis
 *  4) OrderTransfer kaydet + bayi'yi "aktarıldı" işaretle
 */
class TransferSingleBayiOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $bayiOrderId, public bool $forceReTransfer = false)
    {
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'order_pull_single',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $bayi = OrderService::for('bayi');
            $ana = OrderService::for('ana');
            $anaProduct = ProductService::for('ana');
            $mapper = new ProductMapper();

            // 1) Bayi'den siparişi taze çek
            $o = $bayi->getOrderById((int) $this->bayiOrderId);
            if (! $o) {
                throw new \RuntimeException("Bayi'de #{$this->bayiOrderId} ID'li sipariş bulunamadı.");
            }

            $job->increment('total');

            // 2) Local DB kontrol — zaten aktarılmışsa ve force değilse atla
            $existing = OrderTransfer::where('bayi_order_id', $this->bayiOrderId)->first();
            if ($existing && $existing->status === 'transferred' && ! $this->forceReTransfer) {
                $this->log($job, [
                    'bayi_id' => $this->bayiOrderId,
                    'ana_id' => $existing->ana_order_id,
                ], 'skipped', "Zaten aktarılmış (Ana #{$existing->ana_order_id})");
                $job->update(['status' => 'completed', 'finished_at' => now()]);
                return;
            }

            $transfer = $existing ?? new OrderTransfer(['bayi_order_id' => $this->bayiOrderId]);
            $transfer->payload_snapshot = $o;

            // StokKodu → ana variant resolver (PullBayiOrdersJob ile aynı mantık)
            $variantCache = [];
            $resolver = function (string $stokKodu) use ($anaProduct, &$variantCache): ?int {
                if (array_key_exists($stokKodu, $variantCache)) {
                    return $variantCache[$stokKodu];
                }
                $m = ProductMapping::where('stok_kodu', $stokKodu)->first();
                if ($m && $m->ana_variant_id) {
                    return $variantCache[$stokKodu] = (int) $m->ana_variant_id;
                }
                $anaUrunKarti = $anaProduct->getProductByStokKodu($stokKodu);
                if (! $anaUrunKarti) {
                    return $variantCache[$stokKodu] = null;
                }
                $anaUrunId = (int) ($anaUrunKarti['ID'] ?? 0);
                $vars = $anaUrunKarti['Varyasyonlar']['Varyasyon'] ?? [];
                if (is_array($vars) && ! array_is_list($vars)) {
                    $vars = [$vars];
                }
                foreach ((array) $vars as $v) {
                    if ((string) ($v['StokKodu'] ?? '') === $stokKodu) {
                        $varId = (int) ($v['ID'] ?? 0);
                        if ($varId) {
                            ProductMapping::updateOrCreate(
                                ['stok_kodu' => $stokKodu],
                                [
                                    'barcode' => (string) ($v['Barkod'] ?? '') ?: null,
                                    'ana_product_id' => (string) $anaUrunId,
                                    'ana_variant_id' => (string) $varId,
                                    'status' => 'synced',
                                    'last_synced_at' => now(),
                                ]
                            );
                            return $variantCache[$stokKodu] = $varId;
                        }
                    }
                }
                return $variantCache[$stokKodu] = null;
            };

            $musteri = (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? '');
            $context = [
                'barcode' => $this->bayiOrderId,
                'urun_adi' => $musteri ?: null,
                'bayi_id' => $this->bayiOrderId,
                'ana_id' => null,
            ];

            $anaPayload = null;
            try {
                $anaPayload = $mapper->bayiOrderToAnaCreatePayload($o, $resolver);
                $created = $ana->createOrder($anaPayload);
                $anaOrderId = (string) ($created['SiparisID'] ?? $created['ID'] ?? '');
                $context['ana_id'] = $anaOrderId ?: null;

                $transfer->fill([
                    'ana_order_id' => $anaOrderId,
                    'status' => 'transferred',
                    'transferred_at' => now(),
                    'last_error' => null,
                ])->save();

                try {
                    $bayi->markOrderTransferred($this->bayiOrderId, "Ana #{$anaOrderId} olarak aktarıldı (manuel)");
                } catch (Throwable $markEx) {
                    SyncLog::create([
                        'job_id' => $job->id,
                        'barcode' => $this->bayiOrderId,
                        'action' => 'mark_order',
                        'direction' => 'bayi_to_ana',
                        'status' => 'error',
                        'message' => 'markOrderTransferred patladı (ana #' . $anaOrderId . ' zaten oluştu): ' . $markEx->getMessage(),
                    ]);
                }

                $this->log($job, $context, 'success', "Ana #{$anaOrderId} (manuel aktarım)");
                $job->update(['status' => 'completed', 'finished_at' => now()]);
            } catch (Throwable $e) {
                $transfer->fill([
                    'status' => 'failed',
                    'retry_count' => ($transfer->retry_count ?? 0) + 1,
                    'last_error' => $e->getMessage(),
                ])->save();

                $client = $ana->getClient();
                $traceLines = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
                $fullMsg = $e->getMessage()
                    . "\n  ↳ " . $e->getFile() . ':' . $e->getLine()
                    . "\n  trace:\n    " . implode("\n    ", $traceLines);

                $diagRequest = "=== BAYI ORDER (ham) ===\n"
                    . json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($anaPayload !== null) {
                    $diagRequest .= "\n\n=== ANA PAYLOAD (mapper çıktısı) ===\n"
                        . json_encode($anaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                $lastReq = $client->getLastRequestXml();
                if ($lastReq) {
                    $diagRequest .= "\n\n=== SOAP REQUEST XML ===\n" . $lastReq;
                }

                $this->log($job, $context, 'error', $fullMsg, $diagRequest, $client->getLastResponseXml());
                $job->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'last_error' => $e->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function log(SyncJob $job, array $ctx, string $status, string $msg, ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        if ($status === 'success') {
            $job->increment('success_count');
        } elseif ($status === 'error') {
            $job->increment('error_count');
        }
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'transfer_order_manual',
            'direction' => 'bayi_to_ana',
            'status' => $status === 'skipped' ? 'success' : $status,
            'message' => $msg,
            'raw_request' => $rawRequest,
            'raw_response' => $rawResponse,
        ]));
    }
}
