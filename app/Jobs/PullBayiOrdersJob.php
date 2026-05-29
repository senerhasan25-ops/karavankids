<?php

namespace App\Jobs;

use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Models\SyncSetting;
use App\Livewire\QueueControl;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
    /** Sipariş aktarımı için 1 saat yeterli (çok sayıda sipariş gelse de). */
    public int $timeout = 3600;

    /**
     * Aynı anda yalnızca BİR PullBayiOrdersJob çalışsın. Scheduler (SyncTick) job
     * hâlâ çalışırken interval dolunca yenisini dispatch edebiliyor — bu da Ticimax'a
     * paralel SOAP + çift sipariş aktarımı riski yaratıyor. Overlap kilidi bunu önler.
     *
     * dontRelease(): zaten çalışan varken gelen kopya kuyruğa geri atılmaz, sessizce
     * düşürülür (bir sonraki tick zaten yenisini dener). expireAfter: kilit, timeout +
     * tampon kadar sonra otomatik düşer (job çökerse kilit takılı kalmasın).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('pull-bayi-orders'))->dontRelease()->expireAfter(3900)];
    }

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

            // StokKodu → ana Varyasyon.ID resolver — LOKAL ÖNCELİKLİ.
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
                $foundVarId = null;
                $foundBarkod = null;
                foreach ((array) $vars as $v) {
                    if ((string) ($v['StokKodu'] ?? '') === $stokKodu) {
                        $foundVarId = (int) ($v['ID'] ?? 0);
                        $foundBarkod = (string) ($v['Barkod'] ?? '');
                        break;
                    }
                }
                if ($foundVarId) {
                    ProductMapping::updateOrCreate(
                        ['stok_kodu' => $stokKodu],
                        [
                            'barcode' => $foundBarkod ?: null,
                            'ana_product_id' => (string) $anaUrunId,
                            'ana_variant_id' => (string) $foundVarId,
                            'status' => 'synced',
                            'last_synced_at' => now(),
                        ]
                    );
                }
                return $variantCache[$stokKodu] = $foundVarId ?: null;
            };

            // Ayarlardan saat aralığı ve seçili sipariş durumlarını oku
            $saatAralik = (int) SyncSetting::get('siparis_saat_aralik', 24);
            $since = Carbon::now()->subHours($saatAralik);

            $seciliRaw = SyncSetting::get('secili_siparis_durumlari', '');
            $seciliDurumlar = ($seciliRaw && $seciliRaw !== '[]')
                ? json_decode($seciliRaw, true)
                : [];
            // Boş = tümü (SiparisDurumu=0), dolu = seçili her durum için ayrı SOAP çağrısı
            $durumList = empty($seciliDurumlar) ? [0] : $seciliDurumlar;

            $perPage = (int) config('ticimax.batch_size', 50);

            // Her durum için sayfalı çekme, bayi_order_id ile dedup
            $seenOrderIds = [];
            $allOrders = [];
            foreach ($durumList as $durum) {
                $page = 1;
                while (true) {
                    $batch = $bayi->getNewOrders($since, $page, $perPage, (int) $durum);
                    foreach ($batch as $o) {
                        $oid = (string) ($o['ID'] ?? $o['SiparisID'] ?? '');
                        if ($oid && ! isset($seenOrderIds[$oid])) {
                            $seenOrderIds[$oid] = true;
                            $allOrders[] = $o;
                        }
                    }
                    if (count($batch) < $perPage) {
                        break;
                    }
                    $page++;
                }
            }

            $stoppedEarly = false;
            foreach ($allOrders as $o) {
                if (QueueControl::isStopRequested($job->id)) {
                    $stoppedEarly = true;
                    break;
                }

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

                $musteri = (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? '');
                $ilkUrunStok = (string) ($o['Urunler'][0]['StokKodu']
                    ?? $o['Urunler']['WebSiparisUrun'][0]['StokKodu']
                    ?? $o['Urunler']['WebSiparisUrun']['StokKodu']
                    ?? '');
                $context = [
                    'barcode'   => $bayiOrderId,
                    'stok_kodu' => $ilkUrunStok ?: null,
                    'urun_adi'  => $musteri ?: null,
                    'ana_id'    => null,
                    'bayi_id'   => $bayiOrderId,
                ];

                $anaPayload = null;
                try {
                    $anaPayload = $mapper->bayiOrderToAnaCreatePayload($o, $resolver);
                    $created = $ana->createOrder($anaPayload);
                    $anaOrderId = (string) ($created['SiparisID'] ?? $created['ID'] ?? $created['SaveSiparisResult'] ?? '');
                    $context['ana_id'] = $anaOrderId ?: null;

                    // CRITICAL: aktarılan durumu önce kaydet — markOrderTransferred patlarsa
                    // yeniden deneme ana'da duplicate oluşturmasın.
                    $transfer->fill([
                        'ana_order_id'   => $anaOrderId,
                        'status'         => 'transferred',
                        'transferred_at' => now(),
                        'last_error'     => null,
                    ])->save();

                    try {
                        $bayi->markOrderTransferred($bayiOrderId, "Ana #{$anaOrderId} olarak aktarıldı");
                    } catch (Throwable $markEx) {
                        SyncLog::create([
                            'job_id'    => $job->id,
                            'barcode'   => $bayiOrderId,
                            'action'    => 'mark_order',
                            'direction' => 'bayi_to_ana',
                            'status'    => 'error',
                            'message'   => 'markOrderTransferred patladı (ana #' . $anaOrderId . ' zaten oluştu): ' . $markEx->getMessage(),
                        ]);
                    }

                    $this->log($job, $context, 'success', "Ana #{$anaOrderId}");
                } catch (Throwable $e) {
                    // ÖZEL DURUM: "Bu Sipariş Numarasına Ait Kayıt Bulunmaktadır" → sipariş ana'da zaten var.
                    // Bayi+ana eşleştirmesini otomatik yap; failed yapma, başarı say.
                    $errMsg = $e->getMessage();
                    $zatenVar = stripos($errMsg, 'Bu Sipariş Numarasına Ait Kayıt') !== false
                        || stripos($errMsg, 'Bu Siparis Numarasina Ait Kayit') !== false;

                    if ($zatenVar) {
                        $bayiSiparisNo = (string) ($o['SiparisNo'] ?? $o['SiparisKodu'] ?? '');
                        $existingAnaId = null;
                        try {
                            $hits = $ana->getOrdersByFilter([
                                'siparis_no' => $bayiSiparisNo,
                                'date_from'  => \Illuminate\Support\Carbon::now()->subYear()->format('Y-m-d\T00:00:00'),
                                'date_to'    => \Illuminate\Support\Carbon::now()->format('Y-m-d\T23:59:59'),
                            ], 1, 5);
                            if (! empty($hits)) {
                                $existingAnaId = (string) ($hits[0]['ID'] ?? $hits[0]['SiparisID'] ?? '');
                            }
                        } catch (Throwable $lookupEx) {
                            // sessiz geç — yine de transferred işaretle
                        }

                        $context['ana_id'] = $existingAnaId ?: null;
                        $transfer->fill([
                            'ana_order_id'   => $existingAnaId ?: ($transfer->ana_order_id ?? null),
                            'status'         => 'transferred',
                            'transferred_at' => now(),
                            'last_error'     => null,
                        ])->save();

                        try {
                            $bayi->markOrderTransferred(
                                $bayiOrderId,
                                $existingAnaId ? "Ana #{$existingAnaId} (zaten mevcuttu, otomatik eşleştirildi)" : 'Ana sitede zaten mevcut'
                            );
                        } catch (Throwable $markEx) {
                            // sessiz
                        }

                        $msg = $existingAnaId
                            ? "Sipariş ana sitede zaten mevcut → Ana #{$existingAnaId} olarak eşleştirildi (SiparisNo={$bayiSiparisNo})"
                            : 'Sipariş ana sitede zaten mevcut (SiparisNo ile bulunamadı, "transferred" işaretlendi)';
                        $this->log($job, $context, 'success', $msg);
                        continue; // success path
                    }

                    $transfer->fill([
                        'status'      => 'failed',
                        'retry_count' => ($transfer->retry_count ?? 0) + 1,
                        'last_error'  => $e->getMessage(),
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

                    $this->log($job, $context, 'error', $fullMsg,
                        $diagRequest,
                        $client->getLastResponseXml());
                }
            }

            $job->update([
                'status'     => $stoppedEarly ? 'failed' : 'completed',
                'finished_at' => now(),
                'last_error' => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'last_error'  => $e->getMessage(),
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
            'job_id'       => $job->id,
            'action'       => 'transfer_order',
            'direction'    => 'bayi_to_ana',
            'status'       => $status,
            'message'      => $msg,
            'raw_request'  => $rawRequest,
            'raw_response' => $rawResponse,
        ]));
    }
}
