# Karavankids ↔ Bayi.Karavankids Senkronizasyon Paneli

İki Ticimax mağazası (`karavankids.com` ↔ `bayi.karavankids.com`) arasında ürün/stok/fiyat sync ve sipariş aktarımı yapan web paneli. Detay için bkz. `CLAUDE.md`.

## Hızlı Başlangıç (Dev)

```powershell
git clone https://github.com/senerhasan25-ops/karavankids.git
cd karavankids
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
# Auto-push hook (her commit sonrası git push otomatik):
powershell -ExecutionPolicy Bypass -File scripts\install-hooks.ps1
# Linux/Mac: sh scripts/install-hooks.sh
php artisan serve
# Ayrı terminalde:
php artisan queue:work
php artisan schedule:work    # her dakika scheduler tick
```

Tarayıcı: http://localhost:8000
Varsayılan giriş: **admin@karavankids.local** / **admin123**

## Sayfalar

| URL | İşlev |
|---|---|
| `/ayarlar/api` | Ana ve bayi Ticimax API bilgileri + bağlantı testi |
| `/ayarlar/sync` | Otomatik sync interval (dk) + aç/kapa |
| `/urunler` | Ürün eşleştirmeleri, manuel "Çek" / "Güncelle" butonları |
| `/siparisler` | Bayi → Ana sipariş aktarımları, retry |
| `/loglar` | Tüm sync job'ları ve sonuçları, filtreli |

## İlk Çalıştırma

1. Panele giriş yap.
2. `/ayarlar/api` → her iki mağazanın endpoint, kullanıcı kodu, şifresini gir → her biri için "Bağlantıyı Test Et".
3. `/ayarlar/sync` → interval (örn. 15 dk) belirle → otomatik aç.
4. `/urunler` → "Yeni Ürünleri Çek" → mapping tablosu dolar.
5. Bundan sonra otomatik. Manuel müdahale için ilgili sayfaların butonları kullanılır.

## Production Deploy

### Cron (her dakika scheduler tetikleyici)
```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

### Queue Worker (Supervisor)
```ini
[program:karavankids-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan queue:work --tries=3 --backoff=30 --sleep=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/karavankids-queue.log
```

### .env (prod)
```
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=karavankids_sync
DB_USERNAME=...
DB_PASSWORD=...
QUEUE_CONNECTION=database
TICIMAX_TIMEOUT=60
TICIMAX_BATCH_SIZE=50
```

`php artisan config:cache && php artisan view:cache && php artisan route:cache`

## Mimari

```
app/Services/Ticimax/
  TicimaxClient.php         # SOAP wrapper
  ProductService.php
  OrderService.php
  ProductMapper.php         # Ana payload → Bayi formatı + sipariş ters yön

app/Jobs/
  SyncNewProductsJob        # Ana → Bayi yeni ürün
  SyncStockPriceJob         # Ana → Bayi stok/fiyat
  PullBayiOrdersJob         # Bayi → Ana sipariş
  RetryFailedOrderTransferJob

app/Livewire/               # 5 panel ekranı
app/Console/SyncTick.php    # Scheduler kapısı (interval kontrolü)
```

## Geliştiriciler

İki kişi paralel çalışıyor. Bkz. `CLAUDE.md` → Bölüm 8 (Teslim Aşamaları) ve Bölüm 10 (Konvansiyonlar).
