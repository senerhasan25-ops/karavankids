<?php

namespace App\Jobs;

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Livewire\QueueControl;
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
 * Ana → Bayi ürün sync (LOKAL-ÖNCELİKLİ).
 *
 * Akış (varyasyon başına):
 *   1. ProductMapping tablosunda stok_kodu ara
 *   2a. VAR ise → bayi_product_id + bayi_variant_id biliniyor; payload'a yaz, SaveUrun (HIZLI YOL)
 *   2b. YOK ise → bayi'de SelectUrun(StokKodu) ile tek seferlik probe:
 *        - Hedefte zaten varsa → ID'leri al, TedarikciKodu'muzu yaz, mapping kaydet
 *        - Hedefte yoksa → SaveUrun ile yeni oluştur, dönen veriden ID'leri çıkar, mapping kaydet
 *   3. Bir sonraki sync'te o stok_kodu artık 2a yolundan gider (SOAP probe yok).
 *
 * NOT: TedarikciKodunaGoreGuncelle bayrağı KALDIRILDI. Ticimax'ın TedarikciKodu
 * tabanlı match'i artık güvenlik kemeri değil — biz lokal ID match'i ile garanti
 * altına alıyoruz.
 */
class SyncNewProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public ?Carbon $since = null, public ?Carbon $until = null)
    {
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'product_create',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $ana = ProductService::for('ana');
            $bayi = ProductService::for('bayi');
            $mapper = new ProductMapper();

            $defaultBrandId = $bayi->getDefaultBrandId();
            $defaultSupplierId = $bayi->getDefaultSupplierId();
            $defaultCategoryId = $bayi->getDefaultCategoryId();
            $mapper->setDefaultCategoryId($defaultCategoryId);

            $mapper->setBrandResolver(function (string $name) use ($bayi, $defaultBrandId) {
                $id = $bayi->findOrCreateBrandId($name);
                return $id > 0 ? $id : $defaultBrandId;
            });

            $anaSupplierIdToName = array_flip($ana->getSupplierMap());
            $mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi, $defaultSupplierId) {
                $name = $anaSupplierIdToName[$anaId] ?? '';
                $id = $name ? $bayi->findOrCreateSupplierId($name) : 0;
                return $id > 0 ? $id : $defaultSupplierId;
            });

            $since = $this->since;
            $page = 1;
            $perPage = (int) config('ticimax.batch_size', 50);

            $stoppedEarly = false;
            while (true) {
                if (QueueControl::isStopRequested($job->id)) {
                    $stoppedEarly = true;
                    break;
                }
                $products = $ana->getNewProducts($since, $page, $perPage);
                if (empty($products)) {
                    break;
                }

                foreach ($products as $urunKarti) {
                    if (QueueControl::isStopRequested($job->id)) {
                        $stoppedEarly = true;
                        break 2;
                    }
                    $job->increment('total');
                    $this->processOne($job, $urunKarti, $bayi, $mapper);
                }

                if (count($products) < $perPage) {
                    break;
                }
                $page++;
            }

            $job->update([
                'status' => $stoppedEarly ? 'failed' : 'completed',
                'finished_at' => now(),
                'last_error' => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function processOne(SyncJob $job, array $urunKarti, ProductService $bayi, ProductMapper $mapper): void
    {
        if (QueueControl::isStopRequested($job->id)) {
            return;
        }

        $anaUrunId = (int) ($urunKarti['ID'] ?? 0);
        $anaVariants = $this->extractVariants($urunKarti);
        $primaryVariant = $anaVariants[0] ?? null;
        $primaryStokKodu = $mapper->resolveStokKodu($urunKarti);
        $primaryBarkod = (string) ($primaryVariant['Barkod'] ?? '');
        $urunAdi = (string) ($urunKarti['UrunAdi'] ?? '');
        // TedarikciKodu birincil VaryasyonID kullanır (UrunKartiID değil)
        $primaryVariantId = $mapper->resolvePrimaryVariantId($urunKarti);
        $tedKodu = $mapper->buildTedarikciKodu($primaryVariantId, $primaryStokKodu);

        $context = [
            'barcode' => $primaryBarkod ?: null,
            'stok_kodu' => $primaryStokKodu ?: null,
            'urun_adi' => $urunAdi ?: null,
            'ana_id' => $anaUrunId ?: null,
        ];

        if ($primaryStokKodu === '' && $primaryBarkod === '') {
            $this->logError($job, $context, "Üründe ne StokKodu ne Barkod var (ID={$anaUrunId})");
            return;
        }

        try {
            // ========== AŞAMA 1: LOKAL LOOKUP ==========
            $localMapping = $primaryStokKodu
                ? ProductMapping::where('stok_kodu', $primaryStokKodu)->first()
                : null;

            $payload = $mapper->anaToBayiCreatePayload($urunKarti);
            $bayiProductId = $localMapping?->bayi_product_id ? (int) $localMapping->bayi_product_id : null;

            // ========== AŞAMA 2: LOKALDE YOKSA SOAP PROBE ==========
            $bayiProductSoapData = null;
            if (! $bayiProductId) {
                // Sadece ilk eşleştirmede SOAP'a düşeriz; sonra hep lokal
                $bayiProductSoapData = $primaryStokKodu
                    ? $bayi->getProductByStokKodu($primaryStokKodu)
                    : null;
                if (! $bayiProductSoapData && $primaryBarkod) {
                    $bayiProductSoapData = $bayi->getProductByBarcode($primaryBarkod);
                }
                $bayiProductId = $bayiProductSoapData
                    ? (int) ($bayiProductSoapData['ID'] ?? 0)
                    : null;
            }

            // ========== AŞAMA 3: VARYASYON ID MAP'İ KUR ==========
            // bayi'deki Varyasyon.ID'leri (Barkod → ID) eşle. Lokal mapping varsa
            // lokalden, yoksa SOAP'tan gelen veriden topla.
            $bayiVarIdByBarkod = [];
            $bayiVarIdByStokKodu = [];

            if ($localMapping && $bayiProductId) {
                // Lokal mapping tablosundan o bayi_product_id'ye bağlı diğer varyasyonları topla
                ProductMapping::where('bayi_product_id', $bayiProductId)
                    ->get()
                    ->each(function ($m) use (&$bayiVarIdByBarkod, &$bayiVarIdByStokKodu) {
                        if ($m->bayi_variant_id && $m->barcode) {
                            $bayiVarIdByBarkod[$m->barcode] = (int) $m->bayi_variant_id;
                        }
                        if ($m->bayi_variant_id && $m->stok_kodu) {
                            $bayiVarIdByStokKodu[$m->stok_kodu] = (int) $m->bayi_variant_id;
                        }
                    });
            } elseif ($bayiProductSoapData) {
                foreach ($this->extractVariants($bayiProductSoapData) as $bv) {
                    $bvId = (int) ($bv['ID'] ?? 0);
                    if ($bvId <= 0) {
                        continue;
                    }
                    $bk = (string) ($bv['Barkod'] ?? '');
                    $sk = (string) ($bv['StokKodu'] ?? '');
                    if ($bk !== '') {
                        $bayiVarIdByBarkod[$bk] = $bvId;
                    }
                    if ($sk !== '') {
                        $bayiVarIdByStokKodu[$sk] = $bvId;
                    }
                }
            }

            // ========== AŞAMA 4: PAYLOAD'A ID'LERİ YAZ ==========
            if ($bayiProductId) {
                $payload['ID'] = $bayiProductId;
                foreach ($payload['Varyasyonlar'] as $i => $v) {
                    $vbk = (string) ($v['Barkod'] ?? '');
                    $vsk = (string) ($v['StokKodu'] ?? '');
                    $matchId = $bayiVarIdByBarkod[$vbk] ?? $bayiVarIdByStokKodu[$vsk] ?? null;
                    if ($matchId) {
                        $payload['Varyasyonlar'][$i]['ID'] = $matchId;
                        $payload['Varyasyonlar'][$i]['UrunKartiID'] = $bayiProductId;
                    }
                }
            }

            // ========== AŞAMA 5: SAVE ==========
            $savedList = $bayi->createProduct($payload);
            $savedCard = $savedList[0] ?? null;

            // ========== AŞAMA 6: LOKAL MAPPING KAYDET / GÜNCELLE ==========
            // SaveUrun cevabında Ticimax atanan ID'leri echo eder (varsa).
            // Yoksa yukarıda topladığımız ID map'i kullanırız.
            $this->upsertMappings(
                $anaUrunId,
                $anaVariants,
                $savedCard,
                $bayiProductId,
                $bayiVarIdByBarkod,
                $bayiVarIdByStokKodu,
                $tedKodu
            );

            $msg = $localMapping
                ? "Lokal eşleşme → güncellendi (bayi #{$bayiProductId})"
                : ($bayiProductId
                    ? "SOAP eşleşmesi bulundu → mapping kaydedildi (bayi #{$bayiProductId})"
                    : "Yeni ürün oluşturuldu");
            $this->logSuccess($job, $context, $msg);
        } catch (Throwable $e) {
            $client = $bayi->getClient();
            $this->logError(
                $job,
                $context,
                $e->getMessage(),
                $client->getLastRequestXml(),
                $client->getLastResponseXml()
            );
            if ($primaryStokKodu) {
                ProductMapping::updateOrCreate(
                    ['stok_kodu' => $primaryStokKodu],
                    [
                        'barcode' => $primaryBarkod ?: null,
                        'ana_product_id' => (string) $anaUrunId,
                        'status' => 'error',
                        'last_error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Her varyasyon için mapping satırı upsert et — her iki taraf ID'leriyle birlikte.
     */
    protected function upsertMappings(
        int $anaUrunId,
        array $anaVariants,
        ?array $savedCard,
        ?int $bayiProductId,
        array $bayiVarIdByBarkod,
        array $bayiVarIdByStokKodu,
        string $tedKodu,
    ): void {
        // Eğer SaveUrun cevabında yeni ID'ler döndüyse onları da ekleyelim
        if ($savedCard) {
            $bayiProductId = $bayiProductId ?: (int) ($savedCard['ID'] ?? 0);
            foreach ($this->extractVariants($savedCard) as $sv) {
                $svId = (int) ($sv['ID'] ?? 0);
                if ($svId <= 0) {
                    continue;
                }
                $bk = (string) ($sv['Barkod'] ?? '');
                $sk = (string) ($sv['StokKodu'] ?? '');
                if ($bk !== '') {
                    $bayiVarIdByBarkod[$bk] = $svId;
                }
                if ($sk !== '') {
                    $bayiVarIdByStokKodu[$sk] = $svId;
                }
            }
        }

        foreach ($anaVariants as $av) {
            $anaVarId = (int) ($av['ID'] ?? 0);
            $stokKodu = (string) ($av['StokKodu'] ?? '');
            $barkod = (string) ($av['Barkod'] ?? '');
            if ($stokKodu === '' && $barkod === '') {
                continue;
            }
            $bayiVarId = $bayiVarIdByBarkod[$barkod]
                ?? $bayiVarIdByStokKodu[$stokKodu]
                ?? null;

            $key = $stokKodu !== ''
                ? ['stok_kodu' => $stokKodu]
                : ['barcode' => $barkod];

            ProductMapping::updateOrCreate(
                $key,
                [
                    'stok_kodu' => $stokKodu ?: null,
                    'barcode' => $barkod ?: null,
                    'ana_product_id' => (string) $anaUrunId,
                    'ana_variant_id' => $anaVarId > 0 ? (string) $anaVarId : null,
                    'bayi_product_id' => $bayiProductId ? (string) $bayiProductId : null,
                    'bayi_variant_id' => $bayiVarId ? (string) $bayiVarId : null,
                    'tedarikci_kodu' => $tedKodu,
                    'last_price' => (float) ($av['SatisFiyati'] ?? 0),
                    'last_stock' => (int) ($av['StokAdedi'] ?? 0),
                    'status' => 'synced',
                    'last_error' => null,
                    'last_synced_at' => now(),
                ]
            );
        }
    }

    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }
        return is_array($v) ? array_values($v) : [];
    }

    protected function logSuccess(SyncJob $job, array $ctx, string $msg): void
    {
        $job->increment('success_count');
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'success',
            'message' => $msg,
        ]));
    }

    protected function logError(SyncJob $job, array $ctx, string $msg, ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        $job->increment('error_count');
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'error',
            'message' => $msg,
            'raw_request' => $rawRequest,
            'raw_response' => $rawResponse,
        ]));
    }
}
