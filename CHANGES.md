# Değişiklik Geçmişi

Bu dosya projedeki **mimari kararları ve büyük refactor'ları** tarihli notlarla tutar.
Küçük bug fix'ler ve günlük commit'ler için `git log` yeterli — buraya sadece sonraki geliştiricilerin (insan veya AI) "buradaki yapı neden böyle?" sorusunun cevabını bilmesi gereken değişiklikler yazılır.

Yeni notları **en üste** ekle. Format: `## YYYY-MM-DD — kısa başlık`.

---

## 2026-05-24 (Akşam) — Lokal-öncelikli mapping + per-job kuyruk denetimi + UI sadeleştirmesi

Bu seansta birbirine bağlı 9 büyük değişiklik yapıldı. Aşağıda ilgili commit'ler ve sebepleriyle. **Hasan tarafı için yapılan değişikliklerden hangileri tarafımı etkiler diye baktığında bu bölümü okumak yeterli.**

### A) Eşleştirme akışı: SOAP-probe → lokal-öncelikli (3x hızlandı)

**Commit:** `4044d59 feat(sync): lokal-öncelikli ürün eşleştirme — SOAP probe sayısı 3x düştü`

Önce her sync'te her ürün için 2-3 SOAP çağrısı yapılıyordu (StokKodu lookup + Barkod lookup + SaveUrun). Şimdi:

1. `ProductMapping` tablosunda `stok_kodu` ara — **VAR**: doğrudan `bayi_product_id` + `bayi_variant_id` ile SaveUrun (hızlı yol, SOAP probe yok)
2. **YOK**: `bayi.getProductByStokKodu()` ile bir kerelik probe, sonucu mapping'e kaydet → bir sonraki sync'te artık 1. yoldan gider

**`product_mappings` tablosu yeniden tasarlandı** (`2026_05_24_300000_extend_product_mappings_for_local_first.php`):
- Bir satır = bir **VARYASYON** (eskiden ürün başınaydı)
- Yeni kolonlar: `stok_kodu` (UNIQUE), `ana_variant_id`, `bayi_variant_id`, `tedarikci_kodu`
- Eski `barcode` UNIQUE kısıtı kaldırıldı (varyasyonlar arası çakışma olabilir), index'e çevrildi
- Eski satırlar `stok_kodu=null` olduğu için yeni akış onları görmez, paralel birikir

**`TedarikciKodunaGoreGuncelle: false`** — eşleştirmeyi biz lokal mapping ile garantiliyoruz. Ticimax-native upsert artık güvenlik kemeri değil; Hasan'ın eski `SUPBIL|...` formatlı ürünleri yanlış yakalamasın diye kapatıldı.

**Performans:**
| Senaryo | Önce | Şimdi |
|---|---|---|
| 100 mevcut ürünü stock güncelle | 300 SOAP | **100 SOAP** |
| Yeni ürün ekle | 3 SOAP | 2 SOAP (sadece ilk kez) |
| Sipariş aktarımı (variant lookup) | N SOAP | **0 SOAP** (mapping varsa) |

### B) TedarikciKodu format değişti — VaryasyonID + yeni prefix

**Commits:** `3a8d76a chore: TedarikciKodu prefix'i SUP3005 → SUP2026`, `9b0fb36 refactor(tedarikci-kodu): UrunKartiID yerine VaryasyonID kullan`

**Önce:** `SUP3005|stokKodu|anaUrunKartiID` (örn `SUP3005|225981|10615`) — tüm varyasyonlarda aynı UrunKartiID
**Şimdi:** `SUP2026|stokKodu|anaVaryasyonID` (örn `SUP2026|225981|10780`) — her varyasyonun KENDİ ID'si

Faydası: aynı ürünün birden fazla varyasyonu olduğunda her birinin **eşsiz** TedarikciKodu'su olur. Eski kod varyasyonlara `|renk|beden` suffix ekliyordu; artık variant ID zaten eşsiz olduğu için suffix yok.

`ProductMapper::resolvePrimaryVariantId()` eklendi — kart seviyesinde birincil varyasyonu seçer (en küçük ID'li).

### C) Eski format duplikatları temizleme komutu

**Commit:** `e93bdc8 fix(dedupe): bellek dolması — biriktirme yerine streaming + GC` (önceki `3df2418`)

```bash
php artisan sync:dedupe-bayi-products          # DRY-RUN raporu
php artisan sync:dedupe-bayi-products --apply  # rapor + lokal mapping yaz
```

Hedef mağazada aynı StokKodu'na sahip duplikat ürünleri bulur. **Canonical seçimi**: TedarikciKodu SUP2026 ile başlamayan (eski format) korunur — kategorisi, sipariş geçmişi var. SUP2026 yenisi silinmesi gereken.

**SİLME YOK** — Ticimax SOAP'ta `DeleteUrun` method'u yok (WSDL doğrulandı). Kullanıcı çıktıyı görüp panelden manuel siler. `--apply` sadece lokal mapping'i canonical'a yönlendirir → bir sonraki sync hızlı yoldan eski ürünü günceller, yeni duplikat açılmaz.

Bellek güvenliği: hiçbir veri biriktirilmez, her duplikat anında işlenir, `unset()` + `gc_collect_cycles()` ile temizlik. `memory_limit=512M` şemsiye.

### D) Kuyruk denetimi: per-job durdurma + scheduler stop saygısı

**Commits:** `1a6a351 feat(queue): bekleyen/çalışan job'ları ayrı ayrı listele ve durdur`, `71fc4c4 fix(queue): scheduler global stop'a saygı duysun`, `7efaca6 fix(queue-stop): worker process'i öldürme`, `ee3abcc feat(queue): stop sinyali tespit edildiğinde worker stdout'una görünür yaz`

Nav bar'daki `QueueControl` dropdown tamamen yeniden tasarlandı:

```
Çalışan (2)
  #15 product_create  [⛔ Durdur]
  #16 order_pull      [⛔ Durdur]
Bekleyen (3)
  #142 SyncNewProductsJob  [✕ Sil]
  ...
─────────────────────────
[⛔ Hepsini Durdur]
```

- **`QueueControl::isStopRequested(?int $syncJobId)`** — job'ların loop'unda çağrılır. Global `STOP_FLAG_KEY` VEYA per-job `STOP_FLAG_JOB_PREFIX . $id` flag'ini kontrol eder. Stop tespit edildiğinde STDERR'e `🛑 [HH:MM:SS] STOP signal alındı...` yazar (worker CMD penceresinde görünür).
- **`cancelRunning($syncJobId)`** — per-job flag yazar + DB'de o satırı failed işaretler
- **`cancelPending($jobId)`** — jobs tablosundan tek satır siler
- **`stopAll()`** — toplu: global flag + jobs delete + tüm running→failed
- **Worker process'i ÖLDÜRÜLMEZ** artık (eski versiyondaki `Stop-Process` kaldırıldı). Sadece in-job flag + DB temizliği yapılır.
- **`SyncTick::run()`** global stop flag aktifse hiç dispatch ETMEZ (scheduler 1 dk sonra yenisini açmasın diye).

### E) Otomatik sync per-type toggle

**Commit:** `1e2c37b feat(scheduler): otomatik sync'i tip bazında aç/kapat — 3 alt-toggle`, `11ea8e3 feat(sync-settings): 'Şimdi Çalıştır' kartını kaldır`

`SyncSetting`'e 3 yeni anahtar: `otomatik_urunler`, `otomatik_stok_fiyat`, `otomatik_siparis` (default `true`).

`SyncTick.run()` artık alt-toggle'ları okur, sadece açık olanları dispatch eder.

**UI mantığı** (Sync Ayarları ekranı):
- Master AÇIK + alt-toggle'lar → seçilenler her N dk scheduler ile çalışır
- Master KAPALI + alt-toggle'lar → **Kaydet tıklandığında seçilenler TEK SEFERLİK dispatch** (eski "Şimdi Çalıştır" butonu bu mantığa gömüldü, alt panel kaldırıldı)

### F) Front-end altyapı düzeltmeleri (kritik!)

İki gizli bug bulundu, ikisi de wire:click'in sessizce çalışmamasına yol açıyordu:

**Commit:** `f982638 fix(layout): @livewireScripts/@livewireStyles ekle` — `layouts/app.blade.php`'de Livewire script tag'leri eksikti. Auto-inject mekanizması bu kurulumda devreye girmemiş.

**Commit:** `7d9fb40 fix(js): Alpine.js manuel başlatmayı kaldır — Livewire 3 ile çakışıyordu` — `resources/js/app.js` manuel `import Alpine; Alpine.start()` yapıyordu. Livewire 3 kendi Alpine'ini getirip otomatik başlatır; **iki Alpine instance çakışınca** wire:click event handler'ları DOM'a bağlanmıyordu. Backend kusursuz çalışıyordu, sorun sessizce front-end'de ölüyordu.

**HER İKİSİ DE PROD'A DEPLOY EDİLİRKEN KRİTİK**: yeni layout dosyası deploy edildikten sonra **`npm run build` ZORUNLU** — bundle yenilenmezse Alpine çakışması devam eder.

### G) Diğer küçük ama önemli fix'ler

- **`069c6dc fix(product-sync): SaveUrunResult=0 sadece urunKartlari boşsa hata say`** — Ticimax SaveUrun'da `Result=0` "0 yeni ürün oluştu" demek (mevcut güncellendi). Önceki kod bunu hata sayıyordu, oysa ürünler hedef sitede oluşmuş/güncellenmiş oluyordu. Şimdi: response'ta `urunKartlari` echo varsa başarı, yoksa gerçek hata.
- **`00fccfd fix(scheduler): otomatik_aktif kapaliyken scheduler hic tetiklenmesin`** — `bootstrap/app.php` scheduler'a `->when()` koşulu eklendi.

### Etkilenen dosyalar (özet)

| Dosya | Değişiklik özeti |
|---|---|
| `database/migrations/2026_05_24_300000_extend_product_mappings_for_local_first.php` | YENİ — schema değişikliği |
| `app/Models/ProductMapping.php` | yeni fillable alanlar |
| `app/Services/Ticimax/ProductMapper.php` | buildTedarikciKodu yeni format + resolvePrimaryVariantId() |
| `app/Services/Ticimax/ProductService.php` | getProductByStokKodu, findAllProductsByStokKodu, TedarikciKodunaGoreGuncelle=false |
| `app/Jobs/SyncNewProductsJob.php` | 6 aşamalı lokal-öncelikli akış + upsertMappings() |
| `app/Jobs/SyncStockPriceJob.php` | lokal mapping üzerinden, SOAP probe yok |
| `app/Jobs/PullBayiOrdersJob.php` | resolver lokalden okur, SOAP fallback |
| `app/Livewire/QueueControl.php` | per-job stop + isStopRequested helper |
| `app/Livewire/SyncSettings.php` | per-task toggle'lar + save() içinde fire-once |
| `app/Console/SyncTick.php` | alt-toggle saygısı + global stop kontrolü |
| `app/Console/Commands/DedupeBayiProductsCommand.php` | YENİ — duplikat raporu/mapping fix |
| `resources/views/layouts/app.blade.php` | @livewireStyles + @livewireScripts |
| `resources/js/app.js` | Alpine manuel start kaldırıldı |
| `resources/views/livewire/queue-control.blade.php` | per-job liste + sub-toggle UI |
| `resources/views/livewire/sync-settings.blade.php` | unified panel |
| `tests/Unit/ProductMapperTest.php` | TedarikciKodu yeni format assertion'ları |

### Hasan'ın AI'sına okuma listesi (sırayla)

1. **Bu CHANGES.md notu** (mimari karar bağlamı)
2. **`CLAUDE.md` §6.1 / §6.6** (eşleştirme + scheduler akışı — bu seansta güncellendi)
3. **`app/Jobs/SyncNewProductsJob.php` üst yorum** (6 aşamalı akış belgelenmiş)
4. **`app/Livewire/QueueControl.php` üst yorum** (per-job stop mekaniği)
5. `php artisan sync:dedupe-bayi-products` — duplikat varsa önce DRY-RUN, sonra `--apply`
6. **Front-end:** `layouts/app.blade.php` ve `resources/js/app.js`'e dokunma — Alpine çakışması geri gelir

---

## 2026-05-24 — Eşleştirme TedarikciKodu + StokKodu lookup'a geçti

**Commit:** `4763796 refactor(sync): Ticimax-native TedarikciKodu upsert + StokKodu lookup (ticibot pattern)`

### Sorun
Eski mantık `product_mappings` tablosuna `barcode → ana_product_id` eşleşmesi yazıyordu.

- **Sipariş aktarımında** bayi siparişinin her satırı için DB lookup yapılıyordu → mapping yoksa "Ana eşleşmesi yok: X" hatası → sipariş başarısız.
- **Ürün sync'inde** Ticimax test ortamı `SaveUrun`'a yeni ürünü `SaveUrunResult=0` ile sessizce reddediyordu (mevcut ürünler güncellenemiyordu çünkü lokal lookup `getProductByBarcode` Ticimax bayi servisindeki "Value cannot be null (source)" bug'ı yüzünden yanlış davranıyordu).
- Test ortamına geçişlerde mapping tablosu boş kalıyor, gerçek aktarım denemesi yapılamıyordu.

### Çözüm
Ali'nin daha önce Gemini ile yazdığı çalışan Python sync bot'unun (`C:\ticibot\urun_aktar.py` + `siparis_aktar.py`) mantığı port edildi:

**Ürün sync (ana → bayi):**
- `ProductMapper::anaToBayiCreatePayload` her ürüne `TedarikciKodu = SUP|{anaUrunId}|{stokKodu}` yazar.
- Varyasyonlara `|renk|beden` ekiyle uzatılır (`SUP|555|ABC|Kırmızı|L`).
- `ProductService::SaveUrun` çağrısında `ukAyar.TedarikciKodunaGoreGuncelle = true` + `vAyar.TedarikciKodunaGoreGuncelle = true` flag'leri ile **Ticimax kendisi upsert yapar** (mevcudu günceller veya yeni oluşturur).
- Lokal `product_mappings` tablosu sadece audit/dashboard sayaçları için yazılmaya devam — eşleştirme için artık okunmuyor.

**Sipariş aktarım (bayi → ana):**
- `ProductMapper::bayiOrderToAnaCreatePayload` artık bir resolver callback alır: `fn(string $stokKodu): ?int` → ana mağazadaki `Varyasyon.ID`.
- `PullBayiOrdersJob` ve `RetryFailedOrderTransferJob` resolver'ı in-memory cache'li bir lookup'a bağlar: `ProductService::getVariantIdByStokKodu` → `SelectUrun(f.StokKodu = X, Aktif = 1)` → ilk varyasyonun ID'sini döndürür.
- Sipariş satırı `WebSiparisSaveUrun` formatında: `Adet`, `KdvOrani`, `KdvTutari`, `Tutar`, `UrunID` (ana variant ID), `Maliyet=0`, `MarketPlaceOdemeAlindi=true`...
- SaveSiparis envelope'u gerçek Ticimax şemasına uyduruldu (`TeslimatAdres`, `FaturaAdres`, `Odeme` struct, `UyeAdi`/`UyeCep`/`UyeMail` flat, `KargoFirmaId` int).

**API değişiklikleri:**
- `bayiOrderToAnaCreatePayload($order, callable $resolver)` — eskiden `array $barcodeToAnaIdMap` alıyordu.
- `ProductService::getVariantIdByStokKodu(string $stokKodu): ?int` — yeni helper.
- `OrderService::markOrderTransferred` → `SetSiparisAktarildi` patlarsa `SetSiparisPaketlemeDurum(2)` fallback'i.

### Etkilenen dosyalar
- `app/Services/Ticimax/ProductMapper.php` (full rewrite)
- `app/Services/Ticimax/ProductService.php` (+ `getVariantIdByStokKodu`, ayarlara flag eklendi)
- `app/Services/Ticimax/OrderService.php` (full rewrite)
- `app/Jobs/SyncNewProductsJob.php` (DB matching kaldırıldı)
- `app/Jobs/PullBayiOrdersJob.php` (resolver callback)
- `app/Jobs/RetryFailedOrderTransferJob.php` (resolver callback)
- `tests/Unit/ProductMapperTest.php` (yeni şemaya göre rewrite, 8 test)

### Veritabanı şeması
Değiştirilmedi. `product_mappings` tablosu duruyor ama:
- ✗ Artık eşleştirme için okunmuyor
- ✓ Audit kaydı (sync geçmişi, hata takibi) için yazılmaya devam

Tablo gelecekte tamamen kaldırılabilir (Dashboard sayaçları başka kaynaktan da hesaplanabilir).

### Referans
- Python örneği: `C:\ticibot\urun_aktar.py` (TedarikciKodu format + upsert flag) ve `C:\ticibot\siparis_aktar.py` (StokKodu lookup).
- WSDL doğrulama: `php scripts/debug-order-wsdl.php bayi` — `SelectSiparis`, `SaveSiparis`, `SetSiparisAktarildi`, `SetSiparisDurum` hepsi gerçek WSDL ile eşleşiyor.

---

## 2026-05-24 — API ayarlarında tek "Web Servis Yetki Kodu" alanı

**Commit:** `2e29bf4 feat(api): yeni Ticimax B2B icin tek 'Web Servis Yetki Kodu' alani`

Yeni Ticimax B2B kurulumları (`karavankids.ticimaxtest.com` / `digitalsupport.ticimaxtest.com` dahil) `UyeKodu` + `UyeSifre` çifti yerine tek bir web servis yetki kodu kullanıyor. Form `Şifre` alanını opsiyonel yapıldı, `password` kolonu DB'de nullable. Eski kurulumlar hâlâ ikili kimlik desteklenir.
