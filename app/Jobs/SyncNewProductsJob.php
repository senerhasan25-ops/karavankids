<?php

namespace App\Jobs;

use App\Jobs\Concerns\BuffersSyncWrites;
use App\Livewire\QueueControl;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncSetting;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ana → Bayi ürün sync (LOKAL-ÖNCELİKLİ).
 *
 * Akış (varyasyon başına) — eşleme anahtarı: ana'nın GERÇEK TedarikciKodu'su:
 *   1. ProductMapping tablosunda tedarikci_kodu ara
 *   2a. VAR ise → bayi_product_id + bayi_variant_id biliniyor; payload'a yaz, SaveUrun (HIZLI YOL)
 *   2b. YOK ise → bayi'de SelectUrun(TedarikciKodu) ile tek seferlik probe (yedek: stok→barkod):
 *        - Hedefte zaten varsa → ID'leri al, mapping kaydet
 *        - Hedefte yoksa → SaveUrun ile yeni oluştur, dönen veriden ID'leri çıkar, mapping kaydet
 *   3. Bir sonraki sync'te o tedarikçi kodu artık 2a yolundan gider (SOAP probe yok).
 *
 * NOT: stok_kodu/barkod ana mağazada ÇOKLU olabildiği için anahtar değil; sadece yedek
 * lookup ve audit. TedarikciKodunaGoreGuncelle artık AÇIK (kodlar gerçek+unique) → Ticimax
 * tarafında da çift-kart açılmasına karşı güvenlik kemeri.
 */
class SyncNewProductsJob implements ShouldQueue
{
    use BuffersSyncWrites, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** 1000 yeni ürün ~100 dk sürebilir; 3 saat yeterli tampon. */
    public int $timeout = 10800;

    /**
     * sync_settings anahtarı — yeni ürün sync checkpoint.
     * SyncStockPriceJob ile aynı pattern: başarılı tamamlama tarihini saklarız,
     * bir sonraki çalışmada o tarihten sonra EKLENEN ürünleri çekeriz.
     */
    public const LAST_RUN_KEY = 'last_new_products_run_at';

    public function __construct(public ?Carbon $since = null, public ?Carbon $until = null) {}

    /**
     * Zaten çalışan VEYA kuyrukta bekleyen bir ürün-açma işi varsa YENİ dispatch ETME.
     * Buton iki kez tetiklense bile ikinci kopya oluşmaz (gereksiz ikinci tam pas önlenir).
     *
     * @return bool true → kuyruğa eklendi, false → zaten varolan iş yüzünden atlandı
     */
    public static function dispatchUnique(?Carbon $since = null, ?Carbon $until = null): bool
    {
        if (self::isQueuedOrRunning()) {
            return false;
        }
        self::dispatch($since, $until);

        return true;
    }

    /** Çalışan (sync_jobs) veya bekleyen (jobs kuyruğu) bir ürün-açma işi var mı? */
    public static function isQueuedOrRunning(): bool
    {
        if (SyncJob::where('type', 'product_create')->where('status', 'running')->exists()) {
            return true;
        }

        return DB::table('jobs')->where('payload', 'like', '%SyncNewProductsJob%')->exists();
    }

    /**
     * Aynı anda yalnızca BİR ürün-sync job'u çalışsın (overlap kilidi).
     * Bu job 3 saate kadar sürebiliyor; scheduler interval'i ~15 dk olduğundan
     * kilit olmadan üst üste binip Ticimax'ı paralel SOAP'la boğardı.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-new-products'))->dontRelease()->expireAfter(11100)];
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
            $mapper = new ProductMapper;

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

            // Kategori agaci mirror: ana mağazadaki kategori ağacının yolu bayide
            // findOrCreateCategoryByNameAndParent ile aynen kurulur (root → leaf).
            // Bayi getCategoryTree de bir kere cekilir; sonraki mirror cagrilari cache'lenir.
            $anaCategoryTree = $ana->getCategoryTree();
            $bayi->getCategoryTree(); // bayi cache'ini once isit (mevcut kategoriler eslesince yeniden olusturmasin)
            $mapper->setCategoryIdResolver(function (int $anaCatId) use ($bayi, $anaCategoryTree, $defaultCategoryId) {
                $bayiId = $bayi->mirrorCategoryFromAna($anaCatId, $anaCategoryTree);

                return $bayiId > 0 ? $bayiId : $defaultCategoryId;
            });

            // Ürünler menüsünde kaydedilen parametre seçimini oku.
            // Null = kaydedilmemiş veya boş → mevcut ürünler tam güncellenir.
            $selectedFields = $this->loadSavedSelectedFields();

            // ── Checkpoint belirle (delta sync) ──────────────────────────────
            // Constructor'a $since geçildiyse onu kullan (manuel /urunler ekranından
            // tarihli aktarım). Yoksa SyncSetting'ten checkpoint oku.
            // İlk çalışma (checkpoint yok) → son 7 günü güvenli başlangıç olarak al.
            $startedAt = now();
            if ($this->since) {
                $since = $this->since;
            } else {
                $raw = SyncSetting::get(self::LAST_RUN_KEY);
                $since = $raw ? Carbon::parse($raw) : now()->subDays(7);
            }

            $page = 1;
            $perPage = (int) config('ticimax.batch_size', 50);

            $stoppedEarly = false;
            $consecutiveBugPages = 0;     // ardışık bug sayfası (sonsuz döngü güvenliği)
            $maxConsecutiveBugPages = 10;  // bu kadar üst üste bug = gerçekten veri bitti varsay
            while (true) {
                if (QueueControl::isStopRequested($job->id)) {
                    $stoppedEarly = true;
                    break;
                }

                // EKLEME tarihi filtresi + recovery: bug sayfasına denk gelirse
                // sayfa küçük dilimlere bölünüp kurtarılır (#2, sıfır-kayıp hedefi).
                $result = $ana->fetchProductPageRecovering('created', $since, $page, $perPage);
                $products = $result['products'];

                if ($result['bug']) {
                    $consecutiveBugPages++;
                    // warning: action/direction NOT NULL — boş ctx ile bufferLog kullan.
                    $this->bufferLog(
                        $job,
                        [],
                        'create_product',
                        'warning',
                        "Ticimax SOAP page bug — sayfa #{$page}: {$result['recovered']} ürün kurtarıldı, ~{$result['lost']} ürün kaçırıldı. Devam ediliyor.",
                    );
                    // warning'i total sayma — sadece gerçek ürünler sayılsın.
                    $this->pendingTotal--;
                    if (empty($products) && $consecutiveBugPages >= $maxConsecutiveBugPages) {
                        break; // muhtemelen gerçek veri sonu
                    }
                } else {
                    $consecutiveBugPages = 0;
                    if (empty($products)) {
                        break; // gerçek veri sonu
                    }
                }

                foreach ($products as $urunKarti) {
                    if (QueueControl::isStopRequested($job->id)) {
                        $stoppedEarly = true;
                        break 2;
                    }
                    $this->processOne($job, $urunKarti, $bayi, $mapper, $selectedFields);
                }

                // Sayfa sonunda tamponu boşalt (bulk insert + tek sayaç update).
                $this->flushSyncBuffers($job);

                // Normal (bug olmayan) sayfada tam dolmamışsa son sayfadayız.
                // Bug sayfasında count<perPage normaldir (dilim kaybı) → devam et.
                if (! $result['bug'] && count($products) < $perPage) {
                    break;
                }
                $page++;
            }

            // Döngü break ile çıkmış olabilir (erken durdurma / son sayfa) —
            // kalan tamponu mutlaka yaz.
            $this->flushSyncBuffers($job);

            // ── EKSİK ÜRÜNLERİ TAMAMLA ──────────────────────────────────────
            // created-delta yalnızca checkpoint SONRASI eklenen ürünleri getirir;
            // "ana'da var ama bayide yok" (FullRemapProductsJob'un status=pending
            // işaretlediği) eski ürünler buraya hiç düşmez. Bu geçiş onları
            // stok_kodu ile ana'dan çekip bayide oluşturur. Oluşan ürün
            // status=synced olur → bir sonraki çalışmada tekrar denenmez.
            if (! $stoppedEarly) {
                $this->processPendingMissing($job, $ana, $bayi, $mapper, $selectedFields);
            }

            $job->update([
                'status' => $stoppedEarly ? 'failed' : 'completed',
                'finished_at' => now(),
                'last_error' => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
            ]);

            // Başarıyla tamamlandıysa ve constructor'dan ELLE tarih VERİLMEDİYSE
            // checkpoint'i güncelle. Manuel tarihli çağrıda (ProductManualSync)
            // bizim otomatik akışı kirletmesin diye dokunmuyoruz.
            if (! $stoppedEarly && ! $this->since) {
                SyncSetting::put(self::LAST_RUN_KEY, $startedAt->toIso8601String());
            }
        } catch (Throwable $e) {
            // Patlamadan önce biriken log'ları kaybetme.
            $this->flushSyncBuffers($job);
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function processOne(SyncJob $job, array $urunKarti, ProductService $bayi, ProductMapper $mapper, ?array $selectedFields = null): void
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
        // Eşleme/upsert anahtarı = ana'nın GERÇEK TedarikciKodu'su (unique + değişmez).
        $tedKodu = $mapper->resolveTedarikciKodu($urunKarti);

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
            // ========== AŞAMA 1: LOKAL LOOKUP (tedarikçi kodu) ==========
            $localMapping = $tedKodu
                ? ProductMapping::where('tedarikci_kodu', $tedKodu)->first()
                : null;

            $payload = $mapper->anaToBayiCreatePayload($urunKarti);
            $bayiProductId = $localMapping?->bayi_product_id ? (int) $localMapping->bayi_product_id : null;

            // ========== AŞAMA 2: LOKALDE YOKSA SOAP PROBE (tedarikçi kodu → stok → barkod) ==========
            $bayiProductSoapData = null;
            if (! $bayiProductId) {
                // Sadece ilk eşleştirmede SOAP'a düşeriz; sonra hep lokal.
                // Birincil anahtar tedarikçi kodu; stok/barkod yalnızca yedek.
                $bayiProductSoapData = $tedKodu
                    ? $bayi->getProductByTedarikciKodu($tedKodu)
                    : null;
                if (! $bayiProductSoapData && $primaryStokKodu) {
                    $bayiProductSoapData = $bayi->getProductByStokKodu($primaryStokKodu);
                }
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

            // ========== AŞAMA 4+5: KAYDET ==========
            // Mevcut ürün + kaydedilmiş parametre seçimi → sadece seçili alanları güncelle.
            // Mevcut ürün + seçim yok → tam güncelleme (geriye dönük uyumluluk).
            // Yeni ürün → her zaman tam oluşturma (seçim gözetilmez).
            if ($bayiProductId && ! empty($selectedFields)) {
                // updateProductSelective: seçili alanları günceller, geri kalanı bayi'den korur.
                // $bayiProductSoapData mevcutsa override koruması devreye girer;
                // lokal mapping yolundan geldiyse null — koruma atlanır (kabul edilebilir).
                $savedCard = $bayi->updateProductSelective(
                    $payload,
                    $bayiProductId,
                    $bayiVarIdByStokKodu,
                    $selectedFields,
                    $bayiProductSoapData
                );
                $msg = $localMapping
                    ? "Lokal eşleşme → seçili parametrelerle güncellendi (bayi #{$bayiProductId})"
                    : "SOAP eşleşmesi → seçili parametrelerle güncellendi (bayi #{$bayiProductId})";
            } else {
                // Tam kayıt (yeni ürün veya parametre seçimi yapılmamış)
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
                $savedList = $bayi->createProduct($payload);
                $savedCard = $savedList[0] ?? null;
                $msg = $localMapping
                    ? "Lokal eşleşme → tüm alanlar güncellendi (bayi #{$bayiProductId})"
                    : ($bayiProductId
                        ? "SOAP eşleşmesi bulundu → mapping kaydedildi (bayi #{$bayiProductId})"
                        : 'Yeni ürün oluşturuldu');
            }

            // ========== AŞAMA 6: LOKAL MAPPING KAYDET / GÜNCELLE ==========
            $this->upsertMappings(
                $anaUrunId,
                $anaVariants,
                $savedCard,
                $bayiProductId,
                $bayiVarIdByBarkod,
                $bayiVarIdByStokKodu,
                $tedKodu
            );
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
            if ($tedKodu) {
                ProductMapping::updateOrCreate(
                    ['tedarikci_kodu' => $tedKodu],
                    [
                        'stok_kodu' => $primaryStokKodu ?: null,
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
     * "Ana'da var, bayide yok" (status=pending, bayi_product_id NULL) ürünleri
     * stok_kodu ile ana'dan çekip bayide oluştur. FullRemapProductsJob bu satırları
     * işaretler; bu geçiş onları gerçekten aktarır. Aynı ana kartının birden çok
     * pending varyasyonu olabilir → ana_product_id'ye göre tekille, kart başına bir kez.
     */
    protected function processPendingMissing(SyncJob $job, ProductService $ana, ProductService $bayi, ProductMapper $mapper, ?array $selectedFields): void
    {
        $pending = ProductMapping::where('status', 'pending')
            ->whereNull('bayi_product_id')
            ->whereNotNull('tedarikci_kodu')
            ->get(['tedarikci_kodu', 'stok_kodu', 'barcode', 'ana_product_id']);

        if ($pending->isEmpty()) {
            return;
        }

        $seenCards = [];
        $processed = 0;
        foreach ($pending as $m) {
            if (QueueControl::isStopRequested($job->id)) {
                break;
            }
            // Aynı kartı iki kez işleme (processOne zaten tüm varyasyonları kapsar).
            $cardKey = $m->ana_product_id ?: ('ted:'.$m->tedarikci_kodu);
            if (isset($seenCards[$cardKey])) {
                continue;
            }
            $seenCards[$cardKey] = true;

            // Uzun süren eksik-tamamlama pası boyunca ilerleme görünür olsun:
            // her 20 kartta bir tamponu boşalt (log satırları + sayaçlar UI'da güncellensin).
            if ($processed > 0 && $processed % 20 === 0) {
                $this->flushSyncBuffers($job);
            }
            $processed++;

            $ctx = ['stok_kodu' => $m->stok_kodu, 'barcode' => $m->barcode, 'ana_id' => $m->ana_product_id];
            try {
                // Ana ürünü tedarikçi kodu ile çek (stok_kodu çoklu olabilir); yedek: stok_kodu.
                $anaCard = $ana->getProductByTedarikciKodu($m->tedarikci_kodu);
                if ((! $anaCard || (int) ($anaCard['ID'] ?? 0) === 0) && $m->stok_kodu) {
                    $anaCard = $ana->getProductByStokKodu($m->stok_kodu);
                }
                if (! $anaCard || (int) ($anaCard['ID'] ?? 0) === 0) {
                    $this->logError($job, $ctx, 'Eksik ürün: ana mağazada tedarikçi kodu ile bulunamadı (silinmiş olabilir)');

                    continue;
                }
                // processOne: lokal mapping (bayi_id NULL) → SOAP probe → bayide yoksa createProduct
                // → upsertMappings bayi ID'lerini yazıp status=synced yapar.
                $this->processOne($job, $anaCard, $bayi, $mapper, $selectedFields);
            } catch (Throwable $e) {
                $this->logError($job, $ctx, 'Eksik ürün oluşturma hatası: '.$e->getMessage());
            }
        }

        $this->flushSyncBuffers($job);
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
            // Varyasyonun GERÇEK tedarikçi kodu; boşsa kart kodu yedeği.
            $varTed = trim((string) ($av['TedarikciKodu'] ?? '')) ?: $tedKodu;
            if ($varTed === '' && $stokKodu === '' && $barkod === '') {
                continue;
            }
            $bayiVarId = $bayiVarIdByBarkod[$barkod]
                ?? $bayiVarIdByStokKodu[$stokKodu]
                ?? null;

            // Birincil anahtar tedarikçi kodu; yoksa stok_kodu, o da yoksa barkod.
            $key = $varTed !== ''
                ? ['tedarikci_kodu' => $varTed]
                : ($stokKodu !== '' ? ['stok_kodu' => $stokKodu] : ['barcode' => $barkod]);

            ProductMapping::updateOrCreate(
                $key,
                [
                    'stok_kodu' => $stokKodu ?: null,
                    'barcode' => $barkod ?: null,
                    'ana_product_id' => (string) $anaUrunId,
                    'ana_variant_id' => $anaVarId > 0 ? (string) $anaVarId : null,
                    'bayi_product_id' => $bayiProductId ? (string) $bayiProductId : null,
                    'bayi_variant_id' => $bayiVarId ? (string) $bayiVarId : null,
                    'tedarikci_kodu' => $varTed ?: null,
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
        // Tampona yaz — sayfa sonunda flushSyncBuffers() ile toplu insert/increment (#4).
        $this->bufferLog($job, $ctx, 'create_product', 'success', $msg);
    }

    protected function logError(SyncJob $job, array $ctx, string $msg, ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        $this->bufferLog($job, $ctx, 'create_product', 'error', $msg, 'ana_to_bayi', $rawRequest, $rawResponse);
    }

    /**
     * sync_settings.'product_sync_fields' anahtarından seçili güncelleme alanlarını oku.
     * Ürünler menüsünde yapılan seçim buraya kaydedilir (ProductPicker::persistFieldSettings).
     *
     * @return array|null null → kayıt yok / boş → tam güncelleme kullanılır.
     */
    protected function loadSavedSelectedFields(): ?array
    {
        $raw = SyncSetting::get('product_sync_fields', '');
        if (! $raw) {
            return null;
        }

        $saved = json_decode($raw, true);
        if (! is_array($saved)) {
            return null;
        }

        $out = array_keys(array_filter($saved['fields'] ?? []));
        foreach ($saved['uye_tipi'] ?? [] as $i => $on) {
            if ($on) {
                $out[] = 'uye_tipi_fiyat_'.$i;
            }
        }

        return ! empty($out) ? $out : null;
    }
}
