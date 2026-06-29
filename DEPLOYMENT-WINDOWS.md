# Windows Server'a Kurulum (Karavankids Sync)

Bu sistem zaten Windows'ta çalışıyor (Herd + Windows Scheduled Task). Bir
Windows sanal sunucuya taşımak, lokaldekinin **neredeyse aynısıdır**.

## İzolasyon — mevcut uygulamaları bozar mı?

**Hayır.** Eklediğimiz her parça izoledir ve sunucudaki diğer `.exe`'lere dokunmaz:

| Eklenen | İzolasyon |
|---|---|
| PHP 8.4 | Kendi klasöründe, sadece PATH'e eklenir |
| Caddy (web) | Tek `.exe`, kendi servisi — **sadece 80 + 443 portlarını** kullanır |
| php-cgi | Yalnızca `127.0.0.1:9123` (lokal) dinler, dışarı açılmaz |
| 2 Scheduled Task | Kendi adlarıyla (Karavankids*), diğer görevlere karışmaz |
| SQLite | Tek dosya (`database/database.sqlite`) |

> ⚠️ **Tek çakışma riski: 80/443 portları.** Sunucuda başka bir uygulama bu
> portları kullanıyorsa aşağıdaki **"Port çakışması"** bölümüne bak.

---

## 0) Ön kontrol — portlar boş mu?

PowerShell'de:

```powershell
netstat -ano | findstr ":80 :443"
```

- **Çıktı boşsa** → 80/443 serbest, Caddy'yi olduğu gibi kullan.
- **Doluysa** → o portu hangi uygulamanın tuttuğunu not et (PID), "Port çakışması" bölümüne bak.

---

## 1) PHP 8.4 + gerekli eklentiler

1. **PHP 8.4 Non-Thread-Safe (NTS) x64** indir — Caddy + php-cgi (FastCGI) için
   doğru olan NTS'tir (Thread Safe, Apache mod_php gibi gömülü kullanım için):
   - **İndir:** `https://windows.php.net/downloads/releases/php-8.4.22-nts-Win32-vs17-x64.zip`
   - `C:\php`'ye aç.
   - ⚠️ **Visual C++ Redistributable (VS 2015-2022 x64) gerekir**, yoksa PHP açılmaz:
     `https://aka.ms/vs/17/release/vc_redist.x64.exe`
2. `C:\php\php.ini` oluştur (`php.ini-production`'ı kopyala) ve şu satırların başındaki `;`'i kaldır:

```ini
extension_dir = "ext"
extension=soap        ; ← Ticimax için ZORUNLU
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=curl
extension=fileinfo
extension=intl
extension=gd
```

3. `C:\php`'yi **sistem PATH**'ine ekle. Doğrula:

```powershell
php -v
php -m | findstr soap     # "soap" yazmalı
```

---

## 2) Kod + bağımlılıklar

```powershell
# Repoyu sunucuya al (git veya zip)
git clone https://github.com/senerhasan25-ops/karavankids.git C:\karavankids-sync
cd C:\karavankids-sync

composer install --no-dev --optimize-autoloader
npm install
npm run build
```

> Composer yoksa: [getcomposer.org](https://getcomposer.org). Node yoksa:
> [nodejs.org](https://nodejs.org) (sadece `npm run build` için; build sonrası gerekmez).

---

## 3) .env + APP_KEY + veritabanı

```powershell
copy .env.production.example .env
```

`.env`'i düzenle:

- 🔴 **`APP_KEY`** — Lokaldeki `.env`'deki **APP_KEY'i BİREBİR kopyala.** (Şifreli
  API yetki kodları bu anahtara bağlı; farklı key → "The payload is invalid" → tüm
  SOAP patlar.) Sıfırdan kuruyorsan: `php artisan key:generate` + sonra API
  Ayarları'nı panelden yeniden gir.
- `APP_URL=https://panel.senin-domainin.com`

**Veritabanı:**

```powershell
# Mevcut veriyi taşıyorsan: lokaldeki database\database.sqlite dosyasını
#   C:\karavankids-sync\database\database.sqlite olarak kopyala.
# Sıfırdan kuruyorsan dosyayı oluştur:
if (-not (Test-Path database\database.sqlite)) { New-Item -ItemType File database\database.sqlite }

php artisan migrate --force
```

**Yazma izinleri:** `storage\` ve `bootstrap\cache\` klasörleri yazılabilir olmalı
(IIS/servis hesabı kullanıyorsan o hesaba yazma izni ver). Çoğu kurulumda sorun çıkmaz.

---

## 4) Web sunucu — Caddy (otomatik HTTPS)

### 4a) Domaini yönlendir
(Alt)domainin **A kaydını** sunucunun genel IP'sine yönlendir
(örn. `panel.karavankids.com → 11.22.33.44`). HTTPS için bu şart.

### 4b) php-cgi'yi kalıcı çalıştır (NSSM ile servis)
Caddy, PHP'yi FastCGI üzerinden konuşur; php-cgi'nin sürekli açık olması gerekir.
En temizi onu bir Windows **servisi** yapmak — [nssm.cc](https://nssm.cc) indir:

```powershell
# php-cgi'yi servis olarak kur (127.0.0.1:9123 dinler)
nssm install KaravankidsPhpCgi "C:\php\php-cgi.exe" "-b 127.0.0.1:9123"
# php-cgi varsayılan 500 istekte bir kapanır — sonsuz yap:
nssm set KaravankidsPhpCgi AppEnvironmentExtra PHP_FCGI_MAX_REQUESTS=0
nssm start KaravankidsPhpCgi
```

### 4c) Caddy'yi kur ve çalıştır
[caddyserver.com/download](https://caddyserver.com/download) → `caddy.exe` indir
(örn. `C:\caddy\caddy.exe`).

- Depodaki **`Caddyfile`**'da `PANEL_DOMAIN`'i kendi domaininle, `root` yolunu
  repo konumuyla değiştir.
- Caddy'yi servis olarak çalıştır:

```powershell
cd C:\karavankids-sync
nssm install KaravankidsCaddy "C:\caddy\caddy.exe" "run --config C:\karavankids-sync\Caddyfile"
nssm start KaravankidsCaddy
```

Caddy, ilk istekte Let's Encrypt sertifikasını **otomatik alır ve yeniler**.
Artık `https://panel.senin-domainin.com` çalışır.

### Port çakışması (80/443 doluysa)
Başka bir uygulama 80/443'ü kullanıyorsa iki seçenek:
- **DNS challenge + özel port:** Caddy'yi `panel.domain:8443` gibi bir portta çalıştır
  ve Let's Encrypt için DNS doğrulaması kullan (Cloudflare vb.). Kullanıcılar
  `https://panel.domain:8443` ile girer.
- **Mevcut web sunucusunu kullan:** Sunucuda zaten IIS/nginx 443'te yayın yapıyorsa,
  Caddy yerine ona bir site/reverse-proxy ekleyip `public\` klasörünü gösterebilirsin.
  Bu durumda haber ver, sana o sunucuya uygun config üreteyim.

> **Alternatif (Caddy istemiyorsan):** IIS + PHP (FastCGI) + URL Rewrite + win-acme
> ile SSL de tam destekli bir yoldur. Tercih edersen IIS rehberi de hazırlayabilirim.

---

## 5) Otomatik çalışma — 2 Scheduled Task

Yönetici PowerShell'de:

```powershell
cd C:\karavankids-sync
powershell -ExecutionPolicy Bypass -File scripts\install-queue-worker.ps1
powershell -ExecutionPolicy Bypass -File scripts\install-scheduler.ps1
```

- **KaravankidsQueueWorker** — her dakika `queue:work --stop-when-empty` (job'ları işler)
- **KaravankidsScheduler** — her dakika `schedule:run` (otomatik sync tetikleyici)

> Bu görevler bir kullanıcı oturumuna bağlıdır (`LogonType Interactive`). Sunucu
> kimse giriş yapmadan da çalışsın istiyorsan, görevleri "kullanıcı oturum açmasa da
> çalıştır" (Run whether user is logged on or not) olarak Task Scheduler'dan
> güncelle, ya da php-cgi gibi NSSM servisine çevir (istersen yardımcı olurum).

---

## 6) Doğrulama

1. `https://panel.senin-domainin.com` → giriş ekranı (kilit/HTTPS yeşil).
2. Giriş yap → **API Ayarları → "Bağlantıyı Test Et"** her iki mağaza için
   **✓ yeşil** dönmeli (gerçek yetki doğrulaması yapar).
3. **Dashboard** → Eşleştirme Sağlık Raporu verisi geliyor.
4. Loglar / Kuyruk durumundan job'ların işlendiğini gör.

---

## 7) Güncelleme (yeni kod çektiğinde)

```powershell
cd C:\karavankids-sync
git pull
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
# Cache kullanmıyoruz (config:cache yok), o yüzden ekstra adım gerekmez.
# php-cgi servisini yenile ki yeni kod yüklensin:
nssm restart KaravankidsPhpCgi
```

> Queue worker zaten her dakika yeni process açtığı için yeni kodu otomatik alır.

---

## 8) Sık sorun giderme

| Belirti | Sebep / Çözüm |
|---|---|
| "The payload is invalid" (her sayfa) | APP_KEY yanlış. Lokaldeki APP_KEY'i koy **veya** API Ayarları'nı yeniden gir. |
| SOAP "Class SoapClient not found" | `extension=soap` açık değil. php.ini'yi düzelt, php-cgi servisini restart et. |
| HTTPS sertifikası gelmiyor | Domain A kaydı sunucuya gelmiyor **ya da** 80/443 başka uygulamada. Port çakışması bölümüne bak. |
| Job'lar işlenmiyor | KaravankidsQueueWorker görevi çalışıyor mu? `storage\logs\queue-worker.log`'a bak. |
| Otomatik sync tetiklenmiyor | KaravankidsScheduler görevi + Otomatik Güncelleme'de "Otomatik aktif" açık mı? `storage\logs\scheduler.log`. |
| 500 hatası ama detay yok | `APP_DEBUG=false` (doğru). Hata `storage\logs\laravel.log`'ta. |

---

**Özet:** PHP+soap → kod → .env (APP_KEY!) → SQLite+migrate → Caddy+php-cgi (NSSM
servisleri) → 2 scheduled task → doğrula. Hepsi izole; mevcut `.exe`'lerine
dokunmaz (tek dikkat: 80/443 portları).
