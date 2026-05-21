# İş Bölümü — Karavankids Sync Paneli

İki geliştirici **paralel** çalışıyor. İskelet hazır (tüm dosyalar oluşturuldu, migrate çalışıyor, panel ayağa kalkıyor). Bu doküman: **kim hangi alana sahip, hangi dosyalara dokunabilir, nasıl koordine olunur.**

İlk önce: [CLAUDE.md](CLAUDE.md) → projenin tamamını anlat.

---

## 1. Bölünme Mantığı

İş **yön bazında** bölündü:

```
karavankids.com (Ana mağaza)  ──────►  bayi.karavankids.com (Bayi)
                                          ⇣ ürün oluştur, stok/fiyat güncelle
                                          
bayi.karavankids.com  ──────►  karavankids.com
   ⇡ sipariş aktarım + ana'da stok düşür
```

- **Hasan** → Ana → Bayi (ürün/stok/fiyat akışı)
- **Arkadaş** → Bayi → Ana (sipariş aktarım) + Log/Dashboard

---

## 2. Hasan'ın Sorumluluğu (Ana → Bayi)

### Dosyalar (sahip)
```
app/Services/Ticimax/ProductService.php
app/Services/Ticimax/ProductMapper.php          ← ana→bayi ürün payload dönüşümü
app/Jobs/SyncNewProductsJob.php
app/Jobs/SyncStockPriceJob.php
app/Livewire/ProductManualSync.php
resources/views/livewire/product-manual-sync.blade.php
```

### Yapılacaklar
1. **Ticimax SOAP method isimlerini doğrula** — Ticimax B2B servis dokümantasyonundan:
   - `SelectUrunler` parametre yapısı ve filtre alanları doğru mu?
   - `GetUrunByBarkod` gerçekten var mı, yoksa farklı bir method mu? (örn. `SelectUrunByBarkod`)
   - `SaveUrun` payload alan isimleri WSDL ile eşleşiyor mu?
   - `SetUrunStokFiyat` doğru method ismi mi? (`UpdateUrunStokFiyat` da olabilir)
2. **Görsel akışı test et** — ana mağaza CDN URL'leri bayi'de çalışıyor mu? Çalışmıyorsa fallback (re-upload) ekle.
3. **Varyasyonları test et** — renkli/bedenli bir ürün gerçekten doğru aktarılıyor mu?
4. **Edge case'ler:**
   - Aynı barkod farklı ürünlere atanmışsa ne olur?
   - Bayi'de zaten manuel oluşturulmuş ürün varsa (idempotency)?
   - Ana'da silinen ürün — bayi'de ne yapılır? (Aktif=false bayrağı?)
5. **`ProductManualSync` ekranı:**
   - "Yeni Ürünleri Çek" butonu için tarih aralığı seçici ekle
   - Toplu seçimde "Hepsini Seç" checkbox'ı
   - Hata durumunda satırın tam hata mesajını gösteren modal
6. **Tests:** `tests/Feature/ProductSyncTest.php` — SOAP mock ile en az happy-path testi.

### Branch
```
feat/ana-to-bayi
```

---

## 3. Arkadaşın Sorumluluğu (Bayi → Ana + Log/Dashboard)

### Dosyalar (sahip)
```
app/Services/Ticimax/OrderService.php
app/Services/Ticimax/ProductMapper.php          ← bayiOrderToAnaCreatePayload metodu
                                                  (Hasan ile koordine — aynı dosya)
app/Jobs/PullBayiOrdersJob.php
app/Jobs/RetryFailedOrderTransferJob.php
app/Livewire/OrderTransfers.php
app/Livewire/SyncLogs.php
resources/views/livewire/order-transfers.blade.php
resources/views/livewire/sync-logs.blade.php
resources/views/dashboard.blade.php             ← özet kartlar (Hasan'la koordine değil, baştan yaz)
```

### Yapılacaklar
1. **Ticimax SOAP method isimlerini doğrula:**
   - `SelectSiparisler` filtreleri (durum, tarih aralığı) doğru mu?
   - `SaveSiparis` payload alanları — `Uye`, `Urunler`, `TeslimatAdresi`, `FaturaAdresi` yapısı WSDL ile eşleşiyor mu?
   - `SetSiparisAdminNotu` doğru method mu? (Alternatif: `SetSiparisDurum` + kendi durum kodu)
2. **Sipariş aktarma akışı test et:**
   - Bayi'de eksik ürün (barkod ana'da eşleşmemiş) → kullanıcıya net hata göster, sipariş başarısız listesinde dursun
   - Müşteri bilgileri (ad/telefon/email) ana mağazaya doğru gidiyor mu?
   - Adres yapısı — il/ilçe/posta kodu Ticimax formatına uyuyor mu?
3. **`OrderTransfers` ekranı:**
   - Filtre: tarih aralığı + müşteri arama
   - Sipariş satırını tıklayınca **detay modal** — bayi sipariş JSON snapshot + ana sipariş ID
   - "Eksik Ürün" durumu için özel badge + "Önce Ürünü Aktar" linki (`/urunler?barcode=XYZ`)
4. **`SyncLogs` ekranı:**
   - Satıra tıklayınca log detayı (job + tüm log satırları)
   - "Son 24 saatte hata" özet kartı
5. **Dashboard:**
   - Bugün aktarılan ürün/sipariş sayısı
   - Son 7 gün sync grafiği (Chart.js veya basit tablo)
   - Başarısız aktarımların hızlı erişim listesi
   - Son scheduler çalışma zamanı + sıradaki çalışma
6. **Tests:** `tests/Feature/OrderTransferTest.php` — SOAP mock ile sipariş aktarım testi.

### Branch
```
feat/bayi-to-ana
```

---

## 4. Ortak (İkisi de değiştirebilir, koordine et)

| Dosya | Neden ortak |
|---|---|
| `app/Services/Ticimax/TicimaxClient.php` | İkisi de SOAP wrapper kullanıyor |
| `app/Models/*` | Veri modeli |
| `app/Livewire/ApiSettings.php` + view | Credential ekranı |
| `app/Livewire/SyncSettings.php` + view | Interval ayarı |
| `app/Console/SyncTick.php` | Scheduler kapısı |
| `routes/web.php` | Yeni route eklerken |
| `database/migrations/*` | Şema değişikliği |
| `CLAUDE.md`, `README.md`, `WORKSPLIT.md` | Doküman |

**Kural:** Bu dosyalarda değişiklik yapacaksan, **commit etmeden önce Slack/WhatsApp'tan haber ver.** Ya da küçük bir PR aç, diğeri 5 dakika içinde merge etsin. Aynı dosyaya aynı anda dokunmayı önle.

Ortak `ProductMapper.php`: Hasan `anaToBayiCreatePayload()`, arkadaş `bayiOrderToAnaCreatePayload()` — farklı method'lar, çatışma olmaz ama dosya aynı.

---

## 5. Git Akışı

```powershell
# Yeni feature'a başla
git checkout main
git pull
git checkout -b feat/<senin-alanın>

# Çalış, commit at (auto-push hook varsa kendi branch'ine push'lar)
git add .
git commit -m "feat: ..."

# Tamamlanınca PR aç
gh pr create --base main --title "..." --body "..."
# Veya github.com'dan elle

# Karşı taraf review eder, merge eder.
# Sen sonra:
git checkout main
git pull
git checkout -b feat/<sonraki>
```

**Kurallar:**
- `main`'e doğrudan push **yok** (auto-push hook ile yanlışlıkla gitmesin diye dikkat et — sadece feature branch'te commit at).
- PR açmadan önce kendi branch'inde `git pull --rebase origin main` çek, çatışmaları çöz.
- Küçük PR'lar daha iyi (her PR ~200-400 satır). Bir Faz = bir PR olabilir.

---

## 6. Commit Mesajı Konvansiyonu

```
feat: ana mağazadan ürün çekme job'u
fix: barkod boş gelince null pointer
chore: deploy script güncellemesi
docs: WORKSPLIT.md detayları
test: ProductMapper unit testleri
refactor: TicimaxClient retry mantığı sadeleştirildi
```

Türkçe açıklama OK, başında İngilizce tip (`feat`/`fix`/`chore`/...) olsun.

---

## 7. Senkronizasyon Noktası (Faz Sonları)

Her iki kişi de kendi Faz'ını bitirince **birlikte** bir test seansı yapın:
1. Test Ticimax credentials'ları ile API ayarlarını gir
2. Yeni ürün aktar (Hasan'ın akışı)
3. Bayi'de o ürüne sipariş ver (manuel test)
4. Sipariş aktarımını çalıştır (arkadaşın akışı)
5. Ana'da sipariş + stok düşmesi doğrula
6. Log'larda her şeyin görünür olduğunu kontrol et

End-to-end çalışmadıysa hangi noktada koptu, onu fix et, tekrar dene.

---

## 8. Hızlı Referans

| Şey | Yer |
|---|---|
| Proje bağlamı | [CLAUDE.md](CLAUDE.md) |
| Setup / komutlar | [README.md](README.md) |
| Mimari plan | bkz. CLAUDE.md Bölüm 5, 8 |
| Auto-push hook | `scripts/install-hooks.ps1` |
| Repo | https://github.com/senerhasan25-ops/karavankids |
