<?php

namespace App\Jobs;

use App\Models\OrderTransfer;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Models\SyncSetting;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Bayi'den yeni siparişleri çek → ana mağazada SaveSiparis ile oluştur → bayi'de "aktarıldı" işaretle.
 *
 * EŞLEŞTİRME: Her sipariş satırının StokKodu'su ana mağazada SelectUrun ile aranır,
 * bulunan Varyasyon.ID line'da UrunID olarak yazılır. Lokal product_mappings'e bağımlılık YOK.
 */
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
            $anaProduct = ProductService::for('ana');
            $mapper = new ProductMapper();

            // StokKodu → ana Varyasyon.ID cache (tek job içinde, tekrar tekrar SOAP atma)
            $variantCache = [];
            $resolver = function (string $stokKodu) use ($anaProduct, &$variantCache): ?int {
                if (array_key_exists($stokKodu, $variantCache)) {
                    return $variantCache[$stokKodu];
                }
                $id = $anaProduct->getVariantIdByStokKodu($stokKodu);
                $variantCache[$stokKodu] = $id;
                return $id;
            };

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
                    $bayiOrderId = (string) ($o['ID'] ?? $o['SiparisID'] ?? '');
                    if (! $bayiOrderId) {
                        continue;
                    }

                    $existing = OrderTransfer::where('bayi_order_id', $bayiOrderId)->first();
                    if ($existing && $existing->status === 'transferred') {
                        continue;
                    }

                    $transfer = $existing ?? new OrderTransfer(['bayi_order_id' => $bayiOrderId]);
                    $transfer->payload_snapshot = $o;

                    // Sipariş üzerinden context bilgisi
                    $musteri = (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? '');
                    $ilkUrunStok = (string) ($o['Urunler'][0]['StokKodu']
                        ?? $o['Urunler']['WebSiparisUrun'][0]['StokKodu']
                        ?? $o['Urunler']['WebSiparisUrun']['StokKodu']
                        ?? '');
                    $context = [
                        'barcode' => $bayiOrderId,
                        'stok_kodu' => $ilkUrunStok ?: null,
                        'urun_adi' => $musteri ?: null, // sipariş için müşteri adını burada gösterelim
                        'ana_id' => null,
                        'bayi_id' => $bayiOrderId,
                    ];

                    try {
                        $anaPayload = $mapper->bayiOrderToAnaCreatePayload($o, $resolver);
                        $created = $ana->createOrder($anaPayload);
                        $anaOrderId = (string) ($created['SiparisID'] ?? $created['ID'] ?? $created['SaveSiparisResult'] ?? '');
                        $context['ana_id'] = $anaOrderId ?: null;

                        // CRITICAL: aktarılan durumu önce kaydet — markOrderTransferred patlarsa
                        // yeniden deneme ana'da duplicate oluşturmasın.
                        $transfer->fill([
                            'ana_order_id' => $anaOrderId,
                            'status' => 'transferred',
                            'transferred_at' => now(),
                            'last_error' => null,
                        ])->save();

                        try {
                            $bayi->markOrderTransferred($bayiOrderId, "Ana #{$anaOrderId} olarak aktarıldı");
                        } catch (Throwable $markEx) {
                            SyncLog::create([
                                'job_id' => $job->id,
                                'barcode' => $bayiOrderId,
                                'action' => 'mark_order',
                                'direction' => 'bayi_to_ana',
                                'status' => 'error',
                                'message' => 'markOrderTransferred patladı (ana #' . $anaOrderId . ' zaten oluştu): ' . $markEx->getMessage(),
                            ]);
                        }

                        $this->log($job, $context, 'success', "Ana #{$anaOrderId}");
                    } catch (Throwable $e) {
                        $transfer->fill([
                            'status' => 'failed',
                            'retry_count' => ($transfer->retry_count ?? 0) + 1,
                            'last_error' => $e->getMessage(),
                        ])->save();
                        // Hangi servis patladıysa raw XML'i oradan al
                        $client = $ana->getClient();
                        $this->log($job, $context, 'error', $e->getMessage(),
                            $client->getLastRequestXml(),
                            $client->getLastResponseXml());
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

    protected function log(SyncJob $job, array $ctx, string $status, string $msg, ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        if ($status === 'success') {
            $job->increment('success_count');
        } else {
            $job->increment('error_count');
        }
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'transfer_order',
            'direction' => 'bayi_to_ana',
            'status' => $status,
            'message' => $msg,
            'raw_request' => $rawRequest,
            'raw_response' => $rawResponse,
        ]));
    }
}
