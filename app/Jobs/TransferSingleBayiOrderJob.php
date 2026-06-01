<?php

namespace App\Jobs;

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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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

    public function __construct(public string $bayiOrderId, public bool $forceReTransfer = false) {}

    /**
     * Aynı bayiOrderId için iki paralel transfer job'u çalışmasın — kullanıcı
     * butonu çift tıklarsa veya farklı tab'lardan tetiklenirse race olur,
     * ana'da çift sipariş oluşturma riski var. Per-order kilit yeterli.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('transfer-order-'.$this->bayiOrderId))
                ->dontRelease()
                ->expireAfter(600),
        ];
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
            $mapper = new ProductMapper;

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

            // === KULLANICI DÜZENLEMELERİ (product_overrides) ===
            // Eğer kullanıcı modal'dan ürünleri düzenlediyse, bayi'den gelen Urunler listesini
            // override mantığına göre değiştir:
            //  - removed=true → satır komple çıkar
            //  - adet değişmiş → satırın Adet'ini güncelle
            // Bu sadece ana'ya aktarımı etkiler, bayi'deki orijinal sipariş el değmez.
            $overrides = $existing?->product_overrides ?? null;
            if (is_array($overrides) && ! empty($overrides['lines'] ?? [])) {
                $ovrMap = [];
                foreach ($overrides['lines'] as $ovr) {
                    $sk = (string) ($ovr['stok_kodu'] ?? '');
                    if ($sk !== '') {
                        $ovrMap[$sk] = $ovr;
                    }
                }

                // Bayi ürünlerini normalize et — ProductMapper.flattenOrderLines ile aynı mantık
                $rawUrunler = $o['Urunler'] ?? [];
                if (isset($rawUrunler['WebSiparisUrun'])) {
                    $rawUrunler = is_array($rawUrunler['WebSiparisUrun']) && array_is_list($rawUrunler['WebSiparisUrun'])
                        ? $rawUrunler['WebSiparisUrun']
                        : [$rawUrunler['WebSiparisUrun']];
                } elseif (! array_is_list((array) $rawUrunler) && ! empty($rawUrunler)) {
                    $rawUrunler = [$rawUrunler];
                }

                $filtered = [];
                foreach ((array) $rawUrunler as $line) {
                    $sk = (string) ($line['StokKodu'] ?? '');
                    $ovr = $ovrMap[$sk] ?? null;
                    if ($ovr && ! empty($ovr['removed'])) {
                        continue; // kullanıcı bu ürünü sildi
                    }
                    if ($ovr && isset($ovr['adet'])) {
                        $line['Adet'] = max(1, (int) $ovr['adet']);
                    }
                    $filtered[] = $line;
                }

                // ProductMapper hem WebSiparisUrun anahtarını hem düz listeyi kabul ediyor,
                // tutarlı olmak için WebSiparisUrun altına koy
                $o['Urunler'] = ['WebSiparisUrun' => $filtered];

                // ToplamTutar / ToplamKdv değişebilir — yeniden hesapla (basit yaklaşım: silinen satırların
                // brüt fiyatlarını çıkar). Mapper zaten satırlardan da fallback hesaplıyor, ama UrunTutariKdvDahil
                // = ToplamTutar - KargoTutari kullandığından ToplamTutar doğru olmak zorunda.
                $yeniBrut = 0.0;
                $yeniKdv = 0.0;
                foreach ($filtered as $line) {
                    $bf = (float) ($line['Tutar'] ?? $line['BirimFiyat'] ?? $line['SatisFiyati'] ?? 0);
                    $adet = (int) ($line['Adet'] ?? 1);
                    $kdvO = (float) ($line['KdvOrani'] ?? 20);
                    $satirBrut = $bf * $adet;
                    $satirKdv = isset($line['KdvTutari'])
                        ? (float) $line['KdvTutari']
                        : round($satirBrut * ($kdvO / (100 + $kdvO)), 4);
                    $yeniBrut += $satirBrut;
                    $yeniKdv += $satirKdv;
                }
                $kargoBrut = (float) ($o['KargoTutari'] ?? 0);
                $kargoKdv = (float) ($o['KargoKdvTutari'] ?? 0);
                $o['ToplamTutar'] = round($yeniBrut + $kargoBrut, 4);
                $o['ToplamKdv'] = round($yeniKdv + $kargoKdv, 4);
                $o['OdenenTutar'] = $o['ToplamTutar']; // ödenen = yeni toplam (kullanıcı bilerek düzenledi)
            }

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
                        'message' => 'markOrderTransferred patladı (ana #'.$anaOrderId.' zaten oluştu): '.$markEx->getMessage(),
                    ]);
                }

                // Başarılı aktarımda da SOAP diagnostic'i logla — kullanıcı detay modal'ından
                // ne gönderdiğimizi / ne aldığımızı görebilsin (debug + audit).
                $client = $ana->getClient();
                $successDiagRequest = "=== BAYI ORDER (ham) ===\n"
                    .json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    ."\n\n=== ANA PAYLOAD (mapper çıktısı) ===\n"
                    .json_encode($anaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $lastReqOk = $client->getLastRequestXml();
                if ($lastReqOk) {
                    $successDiagRequest .= "\n\n=== SOAP REQUEST XML ===\n".$lastReqOk;
                }
                $this->log($job, $context, 'success', "Ana #{$anaOrderId} (manuel aktarım)",
                    $successDiagRequest,
                    $client->getLastResponseXml());
                $job->update(['status' => 'completed', 'finished_at' => now()]);
            } catch (Throwable $e) {
                // ÖZEL DURUM: "Bu Sipariş Numarasına Ait Kayıt Bulunmaktadır" hatası
                // → Sipariş ana sitede ZATEN var demektir. Aktarım başarısız değil, sadece
                //    bizim yerel kaydımız güncel değil. Ana'dan SiparisNo ile arayıp bulup
                //    local kaydı "transferred" olarak işaretle. Bayi'yi de mark et.
                $errMsg = $e->getMessage();
                $zatenVar = stripos($errMsg, 'Bu Sipariş Numarasına Ait Kayıt') !== false
                    || stripos($errMsg, 'Bu Siparis Numarasina Ait Kayit') !== false;

                if ($zatenVar) {
                    $bayiSiparisNo = (string) ($o['SiparisNo'] ?? $o['SiparisKodu'] ?? '');
                    $existingAnaId = null;
                    try {
                        // Ana sitede SiparisNo ile ara — son 1 yıl içinde
                        $hits = $ana->getOrdersByFilter([
                            'siparis_no' => $bayiSiparisNo,
                            'date_from' => Carbon::now()->subYear()->format('Y-m-d\T00:00:00'),
                            'date_to' => Carbon::now()->format('Y-m-d\T23:59:59'),
                        ], 1, 5);
                        if (! empty($hits)) {
                            $existingAnaId = (string) ($hits[0]['ID'] ?? $hits[0]['SiparisID'] ?? '');
                        }
                    } catch (Throwable $lookupEx) {
                        // arama patlarsa sessiz geç — ana_order_id null kalır ama yine de "transferred" yaparız
                    }

                    $context['ana_id'] = $existingAnaId ?: null;
                    $transfer->fill([
                        'ana_order_id' => $existingAnaId ?: ($transfer->ana_order_id ?? null),
                        'status' => 'transferred',
                        'transferred_at' => now(),
                        'last_error' => null,
                    ])->save();

                    // Bayi'yi de "aktarıldı" olarak işaretle ki listede tekrar gözükmesin
                    try {
                        $bayi->markOrderTransferred(
                            $this->bayiOrderId,
                            $existingAnaId ? "Ana #{$existingAnaId} (zaten mevcuttu, eşleştirildi)" : 'Ana sitede zaten mevcut'
                        );
                    } catch (Throwable $markEx) {
                        // sessiz geç
                    }

                    $msg = $existingAnaId
                        ? "Sipariş ana sitede zaten mevcut → Ana #{$existingAnaId} olarak eşleştirildi"
                        : 'Sipariş ana sitede zaten mevcut (SiparisNo ile bulunamadı ama "transferred" işaretlendi)';
                    $this->log($job, $context, 'success', $msg);
                    $job->update(['status' => 'completed', 'finished_at' => now()]);

                    return;
                }

                // Diğer hatalar → failed
                $transfer->fill([
                    'status' => 'failed',
                    'retry_count' => ($transfer->retry_count ?? 0) + 1,
                    'last_error' => $e->getMessage(),
                ])->save();

                $client = $ana->getClient();
                $traceLines = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
                $fullMsg = $e->getMessage()
                    ."\n  ↳ ".$e->getFile().':'.$e->getLine()
                    ."\n  trace:\n    ".implode("\n    ", $traceLines);

                $diagRequest = "=== BAYI ORDER (ham) ===\n"
                    .json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($anaPayload !== null) {
                    $diagRequest .= "\n\n=== ANA PAYLOAD (mapper çıktısı) ===\n"
                        .json_encode($anaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                $lastReq = $client->getLastRequestXml();
                if ($lastReq) {
                    $diagRequest .= "\n\n=== SOAP REQUEST XML ===\n".$lastReq;
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
