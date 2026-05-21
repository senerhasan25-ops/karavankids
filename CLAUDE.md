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
- **Eşleştirme anahtarı:** Barkod (her iki mağazada da unique kabul edilir).

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
product_mappings         # barcode UNIQUE, ana_product_id, bayi_product_id, last_synced_at,
                         # last_price, last_stock, status (synced|pending|error), last_error
sync_jobs                # type (product_create|stock_price_update|order_pull), status,
                         # started_at, finished_at, total, success_count, error_count
sync_logs                # job_id FK, barcode, action, direction, status, message, created_at
order_transfers          # bayi_order_id UNIQUE, ana_order_id, status (pending|transferred|failed),
                         # retry_count, last_error, transferred_at
```

**İdempotency anahtarları:**
- `product_mappings.barcode` UNIQUE → ürün aktarımı tekrar güvenli
- `order_transfers.bayi_order_id` UNIQUE → sipariş aktarımı tekrar güvenli

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

### 6.1 Ürün Aktarımı İdempotency
Yeni ürün oluşturmadan önce bayi'de `GetUrunByBarkod` ile kontrol edilir. Varsa sadece `product_mappings` kaydı yazılır, `SaveUrun` çağrılmaz. Bu sayede:
- İlk kez var olan ürünler eşleştirilir
- Manuel müdahaleyle eklenmiş ürünler bozulmaz
- Tekrar çalıştırma güvenli

### 6.2 Görseller
Ticimax `SaveUrun` çağrısında resimler URL listesi olarak gönderilir. **Ana mağaza CDN URL'leri doğrudan bayi'ye iletilir** — dosya indirme/yeniden yükleme yok. Opsiyonel HEAD kontrolü (URL 200 dönüyor mu) eklenebilir.

### 6.3 Varyasyonlar
Ana ürünün `Varyasyonlar` koleksiyonu Mapper'da bayi formatına çevrilir. **Her varyasyonun barkodu kendi `product_mappings` satırına yazılır.** Yani 1 ürün + 5 varyasyon = 6 mapping kaydı (ana + her varyasyon).

### 6.4 Stok Düşmesi
Ana mağazada sipariş `SaveSiparis` ile oluşturulunca Ticimax stoğu **kendi içinde** düşürür. Bizim ayrıca `updateStockAndPrice` çağırmamıza gerek yok. Bir sonraki sync döngüsünde yeni stok zaten bayi'ye iter.

### 6.5 Şifre Güvenliği
`api_credentials.password` Eloquent `encrypted` cast ile saklanır. Panelde gösterilmez (sadece "Değiştir" formu).

### 6.6 Scheduler
`app/Console/Kernel.php` her dakika tetiklenir. İçinde:
1. `sync_settings.otomatik_aktif` false ise çık.
2. `now() - sync_settings.last_run_at >= interval_minutes` değilse çık.
3. Sırayla `SyncNewProductsJob`, `SyncStockPriceJob`, `PullBayiOrdersJob` dispatch et.
4. `last_run_at = now()` yaz.

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
# Auto-push hook'u kur (her commit sonrası otomatik git push):
powershell -ExecutionPolicy Bypass -File scripts\install-hooks.ps1
# Linux/Mac için: sh scripts/install-hooks.sh
php artisan serve
# Ayrı terminal: php artisan queue:work
```

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
