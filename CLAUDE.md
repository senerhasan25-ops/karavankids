# Karavankids ↔ Bayi.Karavankids Ticimax Senkronizasyon Paneli

> Bu dosya, projeyi geliştirecek yapay zeka asistanları için kapsamlı bağlamdır. Projeye katılan her geliştirici (insan veya AI) önce burayı okumalı.

## 1. Proje Amacı

Müşterimiz **Karavankids** (oyuncak satan e-ticaret + IT danışmanlığı) iki ayrı **Ticimax** e-ticaret altyapısı işletiyor:

- `karavankids.com` — Ana mağaza (B2C, son tüketici)
- `bayi.karavankids.com` — Bayi mağazası (B2B, bayilere satış)

Şu anda iki sitedeki ürünler ve siparişler elle senkronize ediliyor. Bu proje, **iki Ticimax mağazası arasında otomatik senkronizasyon yapan, web panelinden yönetilebilen bir entegrasyon servisi** geliştirmeyi hedefliyor.

## 2. Fonksiyonel Gereksinimler

### 2.1 Ürün Aktarımı (Ana → Bayi)
- Ana mağazada eklenen yeni ürünler bayi mağazasına otomatik aktarılmalı.
- Aktarılacak alanlar:
  - **Temel:** ad, açıklama, kategori, marka, stok kodu
  - **Görseller:** ana görsel + galeri (URL referansı, re-upload yok)
  - **Varyasyonlar:** renk, beden vb. alt ürünler tam aktarılır
  - **SEO:** URL, meta title, meta description
- **Eşleştirme anahtarı:** Lokal `product_mappings.stok_kodu` (UNIQUE) — primary lookup. **TedarikciKodu** (`SUP2026|{stokKodu}|{anaVaryasyonID}`) sadece audit/iz takibi içindir; Ticimax-native upsert flag'i (`TedarikciKodunaGoreGuncelle`) **kapatıldı**. Eşleştirmeyi biz lokalden bayi_product_id + bayi_variant_id ile garantiliyoruz.

### 2.2 Stok & Fiyat Güncellemesi (Ana → Bayi)
- Mevcut eşleşmiş ürünlerde **stok** ve **fiyat** ana mağazadan bayi mağazasına itilir.
- **Fiyat politikası:** Birebir eşleme. Ana fiyat = Bayi fiyat. Hiçbir kâr/iskonto formülü yok.
- İdempotent: aynı değer tekrar yazılabilir, hata vermemeli.

### 2.3 Sipariş Aktarımı (Bayi → Ana)
- Bayi mağazasına gelen siparişler ana mağazaya kopyalanır.
- Ana mağazada sipariş oluşturulunca Ticimax stoğu otomatik düşürür — ekstra stok düşme çağrısı **yok**.
- Aktarılan sipariş bayi tarafında `aktarıldı` olarak işaretlenir (tekrar gönderilmemesi için).
- Başarısız aktarımlar log'a düşer + panelde "Tekrar Dene" butonu olur.

### 2.4 Yönetim Paneli
- Kullanıcı girişi (Laravel Breeze).
- **API Ayarları sayfası:** İki mağaza için endpoint URL, kullanıcı adı, şifre/token; "Bağlantıyı Test Et" butonu.
- **Sync Ayarları sayfası:** `interval_minutes` (otomatik sync sıklığı), otomatik aç/kapa toggle'ı, son çalışma zamanı.
- **Manuel Aktarım sayfası:** Ana mağaza ürün listesi (barkod, ad, fiyat, stok, durum: yeni/eşleşti/hata); tekli "Aktar" butonu + toplu seçim + "Seçilenleri Aktar".
- **Sipariş Aktarımları sayfası:** Aktarım listesi, başarısızlar için "Tekrar Dene".
- **Log sayfası:** `sync_logs` ve `sync_jobs` görünümü, tarih/durum/tip filtreleri.

## 3. Teknoloji Yığını

| Katman | Seçim | Neden |
|---|---|---|
| Backend | PHP 8.2 + Laravel 11 | Ticimax entegrasyonlarında yaygın, paylaşımlı hosting'te çalışır |
| DB | MySQL 8 | Müşteri ortamına uygun |
| Frontend | Blade + Livewire 3 + Tailwind | Ayrı SPA gereksiz, panel yeterli |
| Auth | Laravel Breeze | E-posta/şifre, hızlı |
| Queue | Laravel Queue (database driver) | Paylaşımlı hosting dostu |
| Scheduler | Laravel Scheduler (`schedule:run` cron, her dakika) | Standart |
| Ticimax | SOAP (PHP `ext-soap`) | Ticimax resmi web servisleri |

## 4. Veri Modeli (Migrations)

```
users                    # Breeze default
api_credentials          # store_key (ana|bayi), endpoint_url, username, password (encrypted), is_active
sync_settings            # key, value (interval_minutes, otomatik_aktif, last_run_at vb.)
product_mappings         # LOKAL-ÖNCELİKLİ EŞLEŞTİRME ANAHTAR TABLOSU
                         # Bir satır = bir VARYASYON (ürün değil)
                         # stok_kodu UNIQUE — primary lookup
                         # barcode (index), ana_product_id, ana_variant_id,
                         # bayi_product_id, bayi_variant_id, tedarikci_kodu,
                         # last_synced_at, last_price, last_stock,
                         # status (synced|pending|error), last_error.
                         # SyncNewProductsJob/SyncStockPriceJob/PullBayiOrdersJob
                         # önce buradan okur, sonra SOAP'a düşer.
sync_jobs                # type (product_create|stock_price_update|order_pull), status,
                         # started_at, finished_at, total, success_count, error_count
sync_logs                # job_id FK, barcode, action, direction, status, message, created_at
order_transfers          # bayi_order_id UNIQUE, ana_order_id, status (pending|transferred|failed),
                         # retry_count, last_error, transferred_at
```

**İdempotency anahtarları:**
- **Ürün aktarımı:** `product_mappings.stok_kodu` (lokal UNIQUE). Job önce burada arar; varsa `bayi_product_id` + `bayi_variant_id` ile direkt SaveUrun (hızlı yol). Yoksa SOAP probe yapıp bulunan veya yeni oluşturulan ürünün ID'lerini mapping'e kaydeder (bir kerelik). `TedarikciKodunaGoreGuncelle: false` — Ticimax-native upsert güvenlik kemeri olarak KULLANILMIYOR (eski formatlı duplikatları yanlış yakalamasın).
- **Sipariş aktarımı:** `order_transfers.bayi_order_id` UNIQUE → tekrar güvenli (lokal tablo).
- **Sipariş satırı eşleştirme:** Her satırın `StokKodu`'su için `PullBayiOrdersJob` resolver'ı önce `product_mappings`'te arar → varsa `ana_variant_id` döner (SOAP yok). Yoksa `SelectUrun(StokKodu)` ile probe, sonucu mapping'e kaydeder. İkinci sync'te lookup tamamen lokaldir.

## 5. Mimari (Klasör Yapısı)

```
app/
  Services/Ticimax/
    TicimaxClient.php       # SOAP wrapper; constructor: 'ana'|'bayi' credential set'i
    ProductService.php      # getNewProducts, getProductByBarcode, createProduct, updateStockAndPrice
    OrderService.php        # getNewOrders, markOrderTransferred, createOrder
    ProductMapper.php       # Ana mağaza ürün payload → bayi SaveUrun formatı
                            # (görseller, varyasyonlar, SEO dahil)
  Jobs/
    SyncNewProductsJob.php       # Yeni ürünleri ana'dan çek, bayi'de yoksa oluştur
    SyncStockPriceJob.php        # Eşleşmiş ürünler için stok/fiyat güncelle
    PullBayiOrdersJob.php        # Bayi siparişlerini ana'ya aktar
    RetryFailedOrderTransferJob.php  # Başarısız sipariş aktarımlarını yeniden dene
  Livewire/
    ApiSettings.php
    SyncSettings.php
    ProductManualSync.php
    OrderTransfers.php
    SyncLogs.php
  Console/
    Kernel.php              # Scheduler kapısı: interval kontrolü + 3 job dispatch
config/
  ticimax.php               # Varsayılan endpoint, timeout, retry sayısı
database/migrations/        # Yukarıdaki 6 tablo
resources/views/livewire/   # 5 Blade view
routes/web.php
```

## 6. Kilit Akış Notları (AI'ların ENİYİ bilmesi gereken)

### 6.1 Ürün Aktarımı İdempotency (LOKAL-ÖNCELİKLİ)

> **DİKKAT (2026-05-24 değişikliği):** Önceki Ticimax-native upsert (`TedarikciKodunaGoreGuncelle:true`) **kapatıldı**. Eşleştirmeyi biz lokal mapping tablosundan yapıyoruz. Sebep: Hasan'ın eski sync'i farklı prefix (`SUPBIL|...`) kullanmıştı, yeni format (`SUP2026|...`) eşleştiremediğinde **duplikat açıyordu**. Detay: `CHANGES.md` 2026-05-24 (Akşam) notu.

`SyncNewProductsJob::processOne()` 6 aşamalı akış:

1. **LOKAL LOOKUP:** `ProductMapping::where('stok_kodu', X)->first()`
2. **YOKSA SOAP PROBE:** `bayi->getProductByStokKodu(X)` → bulunursa hedef ID'lerini al
3. **VARYASYON ID MAP'İ:** lokal mapping'ten veya SOAP'tan toplanan `Barkod → bayi.Varyasyon.ID` tablosu
4. **PAYLOAD'A ID YAZ:** `$payload['ID'] = $bayiProductId` + her varyasyona `bayi_variant_id`
5. **SAVE:** `bayi->createProduct($payload)` — Ticimax kesin ID match ile günceller (yeni duplikat açmaz)
6. **MAPPING UPSERT:** SaveUrun cevabındaki ID'lerle veya elimizdeki ID'lerle her varyasyon için `product_mappings` upsert

**TedarikciKodu** artık sadece audit/iz takibi içindir, format: `SUP2026|{stokKodu}|{anaVaryasyonID}`. Her varyasyonun kendi ID'si gömülür (UrunKartiID değil).

Bir sonraki sync'te SOAP probe (aşama 2) atlanır → 3x hızlanma.

### 6.2 Görseller
Ticimax `SaveUrun` çağrısında resimler URL listesi olarak gönderilir. **Ana mağaza CDN URL'leri doğrudan bayi'ye iletilir** — dosya indirme/yeniden yükleme yok. Opsiyonel HEAD kontrolü (URL 200 dönüyor mu) eklenebilir.

### 6.3 Varyasyonlar
Ana ürünün `Varyasyonlar` koleksiyonu Mapper'da bayi formatına çevrilir. Her varyasyonun **kendi ID'si + kendi StokKodu'su** ile eşsiz `TedarikciKodu`'su olur (örn. `SUP2026|225981|10780`). Eski `|renk|beden` suffix mekaniği kaldırıldı — variant ID zaten eşsiz olduğu için gereksiz.

`product_mappings` tablosuna **her varyasyon için ayrı satır** yazılır (`stok_kodu` UNIQUE). `bayi_variant_id` saklanır → bir sonraki stok/fiyat update'i SOAP'ta SelectUrun yapmadan direkt UpdateStock çağrısı atar.

### 6.4 Stok Düşmesi
Ana mağazada sipariş `SaveSiparis` ile oluşturulunca Ticimax stoğu **kendi içinde** düşürür. Bizim ayrıca `updateStockAndPrice` çağırmamıza gerek yok. Bir sonraki sync döngüsünde yeni stok zaten bayi'ye iter.

### 6.5 Şifre Güvenliği
`api_credentials.password` Eloquent `encrypted` cast ile saklanır. Panelde gösterilmez (sadece "Değiştir" formu).

### 6.6 Scheduler

`bootstrap/app.php`'deki `withSchedule()` her dakika tetiklenir → `App\Console\SyncTick::run()`. İçinde:

1. `sync_settings.otomatik_aktif` false → çık (master kapalı)
2. Cache'de **global stop flag** (`QueueControl::STOP_FLAG_KEY`) varsa → çık (kullanıcı durdurdu, 1 dk sonra yeniden açma)
3. `now() - last_run_at >= interval_minutes` değilse → çık (interval kilidi)
4. **Alt-toggle'lar** kontrol edilir, sadece açık olanlar dispatch:
   - `otomatik_urunler` → `SyncNewProductsJob`
   - `otomatik_stok_fiyat` → `SyncStockPriceJob`
   - `otomatik_siparis` → `PullBayiOrdersJob`
5. En az bir dispatch olduysa `last_run_at = now()` (hiç dispatch olmazsa last_run_at bozulmaz)

**Manuel tetik** (Sync Ayarları sayfasında): master KAPALIYKEN "Kaydet" tıklanırsa işaretli alt-toggle'lar tek seferlik dispatch edilir (eski "Şimdi Çalıştır" butonu kaldırıldı, bu davranışa entegre).

### 6.7 Kuyruk Denetimi (per-job stop)

Nav bar'daki `QueueControl` dropdown:
- **Çalışan** listesi → her satırın yanında **⛔ Durdur** (per-job flag + DB failed işaretleme)
- **Bekleyen** listesi → her satırın yanında **✕ Sil** (jobs tablosundan tek satır)
- **Hepsini Durdur** → toplu (global flag + jobs delete + tüm running→failed)

Job'lar loop içinde `QueueControl::isStopRequested($syncJobId)` çağırır. True dönerse worker stdout/STDERR'e `🛑 [HH:MM:SS] STOP signal alındı...` yazılır (CMD penceresinde görünür). Worker process'i ÖLDÜRÜLMEZ — sadece in-job flag mekanizması.

Cache driver `database` olduğundan flag web request'leri ile worker process arasında paylaşılır.

### 6.8 Front-end altyapı — DOKUNULMAYACAKLAR ⚠️

**`resources/js/app.js`**: Alpine.js manuel başlatma YOK. Livewire 3 kendi Alpine'ini içerir ve otomatik start eder. İkinci `Alpine.start()` çağırırsan **iki Alpine instance çakışır** → tüm `wire:click` handler'ları sessizce ölür. Backend log'larda hiçbir şey görünmez, butonlar sadece tıklanmaz.

**`resources/views/layouts/app.blade.php`**: `@livewireStyles` head'de, `@livewireScripts` body kapanışından önce. Eksik olursa wire:click hiç çalışmaz (auto-inject mekanizması bu kurulumda devreye girmiyor).

**Asset değişikliği sonrası ZORUNLU:** `npm run build` — vite manifest yenilenmezse tarayıcı eski bundle'ı kullanır.

## 7. Geliştirme Ortamı

### Gerekli Yazılımlar (Windows)
- **PHP 8.2+** with extensions: `soap`, `openssl`, `mbstring`, `pdo_mysql`, `curl`, `fileinfo`, `gd`, `tokenizer`, `xml`, `intl`, `bcmath`
- **Composer 2+**
- **MySQL 8+**
- **Node.js 20+** (Tailwind build için)

Önerilen kurulum:
- **Laravel Herd** (https://herd.laravel.com/windows) — PHP + nginx + node tek kurulumla
- **Laragon** (https://laragon.org) — MySQL için (veya MySQL Community Installer)
- **Composer Setup** (https://getcomposer.org/Composer-Setup.exe)

### Kurulum Adımları (clone sonrası)
```powershell
git clone https://github.com/senerhasan25-ops/karavankids.git
cd karavankids
composer install
npm install && npm run build
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
# Auto-push hook (her commit sonrası otomatik git push):
powershell -ExecutionPolicy Bypass -File scripts\install-hooks.ps1
# Linux/Mac için: sh scripts/install-hooks.sh
# Auto-pull (her 10dk arkadasının push'larını çeker, sadece Windows):
powershell -ExecutionPolicy Bypass -File scripts\install-autopull.ps1
php artisan serve
# Ayrı terminal: php artisan queue:work
```

### Sürekli Sync Akışı
- **Auto-push:** Her `git commit` sonrası post-commit hook otomatik `git push` yapar.
- **Auto-pull:** Windows Scheduled Task her 10 dakikada bir `git pull --ff-only` çalıştırır. Uncommitted değişiklik varsa pull atlanır (kullanıcının işine dokunmaz). Log: `storage/logs/auto-pull.log`.
- **Çatışma:** ff-only pull başarısız olursa log'a yazılır, commit/push akışını bozmaz. Manuel `git pull --rebase` ile çöz.

### Çalışma Komutları
```powershell
php artisan serve                  # Dev sunucu
php artisan queue:work             # Job worker
php artisan schedule:work          # Scheduler (dev için; prod'da cron)
php artisan migrate:fresh --seed   # DB sıfırla
npm run dev                        # Tailwind watch
```

### Prod Cron
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```
Ve `php artisan queue:work --daemon` supervisor altında.

## 8. Teslim Aşamaları (Sıralı)

1. **Faz 1** — Laravel iskeleti + Breeze auth + 6 migration + temel layout
2. **Faz 2** — `TicimaxClient` + `ProductService` + `OrderService` + API Ayarları ekranı + bağlantı testi
3. **Faz 3** — `ProductMapper` + `SyncNewProductsJob` + `SyncStockPriceJob` + scheduler + Sync Ayarları ekranı
4. **Faz 4** — Manuel Aktarım ekranı
5. **Faz 5** — `PullBayiOrdersJob` + Sipariş Aktarımları ekranı + retry
6. **Faz 6** — Log ekranı + filtreler + prod deploy talimatları

İki geliştirici paralel çalışırsa: **Faz 1** birlikte bitirilir; sonra **Faz 2 (Ticimax servisleri)** ve **Faz 1.5 (UI iskeletini / layout / menü)** ayrı çalışılabilir. Faz 3'ten sonra paralelleştirme zorlaşır (sıralı bağımlılık).

## 9. Doğrulama Planı

1. **Yerel kurulum:** Composer install + migrate + serve + queue:work + schedule:work.
2. **API testi:** Her iki mağazanın test/staging bilgilerini gir, "Bağlantıyı Test Et" → başarı dönmeli.
3. **Yeni ürün aktarımı:** Ana'da benzersiz barkodlu test ürünü oluştur → bir döngü sonra bayi'de oluşmalı (görsel/varyasyon/SEO dahil). `product_mappings` + `sync_logs` kontrol et.
4. **Stok/fiyat güncellemesi:** Ana'da fiyat/stok değiştir → bir döngü sonra bayi'de güncellenmiş olmalı.
5. **Sipariş aktarımı:** Bayi'de test siparişi oluştur → ana'da sipariş oluşmalı, ana stok düşmeli, `order_transfers.status = transferred` olmalı.
6. **Hata akışı:** Yanlış şifre ile bağlan → log'da okunabilir hata, retry mekanizması.
7. **Manuel aktarım:** Tek ürün "Aktar" butonu → tek atımda biter.
8. **Log filtreleri:** Tarih + durum + tip filtreleri çalışmalı.

## 10. Önemli Kurallar / Konvansiyonlar

- **Dil:** Kod, isimlendirme ve commit mesajları İngilizce. UI metinleri Türkçe.
- **Tarihler:** DB'de UTC; UI'da `Europe/Istanbul`.
- **Hata mesajları:** Ticimax SOAP fault'ları yakalanıp Türkçe'leştirilerek log'a yazılır.
- **Retry:** Job seviyesinde max 3 deneme + exponential backoff (10s, 30s, 60s).
- **Idempotency:** Her sync operasyonu tekrar çalıştırılabilir olmalı; mapping kayıtları unique constraint ile korunur.
- **Şifre:** Asla log'a yazma; `redact()` helper kullan.
- **Test:** Her servis için Pest unit + en az 1 happy-path feature testi.
- **PR boyutu:** Faz başına 1 PR (büyük olursa alt-faz'lara böl).

## 11. Üçüncü Taraf Bağımlılıkları

- Ticimax SOAP web servisleri — endpoint ve WSDL URL'leri müşteriden alınacak (panelde girilir).
- Müşterinin her iki Ticimax mağazasında **B2B servis paneli aktif** olmalı; API kullanıcısı oluşturulmuş olmalı.

## 12. Riskler & Açık Konular

- Ticimax API rate limit'i — büyük katalogda batch'lere bölmek gerekebilir (`->chunk(50)`).
- Görsel URL'leri Ticimax'in kendi CDN'inde — bayi mağazası başka CDN'den çekmeyi reddedebilir; test gerekir, gerekirse re-upload fallback.
- Varyasyon yapısı iki mağazada farklıysa (örn. ana'da renk-beden, bayi'de sadece beden) eşleme bozulabilir — Mapper'da varyasyon doğrulaması.
- Bayi siparişinde ürün barkodu mapping'de yoksa (ürün aktarımı yapılmamışsa) sipariş aktarımı başarısız olur → kullanıcı önce ürünü manuel aktarmalı; UI'da bu durum açıkça gösterilmeli.

---

**Proje sahibi:** Hasan
**Müşteri:** Karavankids
**Geliştirme:** 2 kişi paralel
**Plan dosyası:** `C:\Users\hasan\.claude\plans\bak-imdi-benim-ticimax-radiant-tiger.md`
