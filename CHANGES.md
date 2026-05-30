# Değişiklik Geçmişi

Bu dosya projedeki **mimari kararları ve büyük refactor'ları** tarihli notlarla tutar.
Küçük bug fix'ler ve günlük commit'ler için `git log` yeterli — buraya sadece sonraki geliştiricilerin (insan veya AI) "buradaki yapı neden böyle?" sorusunun cevabını bilmesi gereken değişiklikler yazılır.

Yeni notları **en üste** ekle. Format: `## YYYY-MM-DD — kısa başlık`.

---

## 2026-05-30 — Eşleştirme anahtarı StokKodu → TedarikciKodu'ya geçti

**Commits:** `c3a1812`, `8a42beb`, `52143c1`, `1c39c06`, `f668db9`, `ae33d48`

### Neden
StokKodu ve Barkod bir üründe **çoklu/tekrarlı** olabiliyor ve sonradan
değişebiliyor — eşleştirme anahtarı olarak güvenilmez. **TedarikciKodu ise
unique ve immutable.** Bu yüzden hem ürün haritalama, hem yeni ürün açma, hem
de stok/fiyat delta eşleştirmesi artık **TedarikciKodu'yu birincil anahtar**
kabul ediyor (StokKodu → Barkod sadece fallback).

> Karar gerekçesi (kullanıcı): "tedarikçi kodları unique ve değiştirilemez …
> her yeni ürünü açarken aynı tedarikçi kodu olmasına dikkat edeceğiz ve
> ürünleri tedarikçi kodu üzerinden eşleyeceğiz."

### Ana TedarikciKodu formatı
- **Gerçek:** `SUP26|{anaVariantId}|{stokKodu}` (örn `SUP26|1880|HEB-134`) —
  ana mağazadan birebir kopyalanır.
- **Sentetik yedek** (ana'da TedarikciKodu boşsa): `SUP2026|{stokKodu}|{anaVariantId}`.
- `ProductMapper::resolveTedarikciKodu()` / `resolveVariantTedarikciKodu()`
  önce gerçeği dener, yoksa sentetik üretir.

### Şema
`2026_05_30_120000_make_tedarikci_kodu_unique_key.php`: `stok_kodu`
UNIQUE → düz index'e düşürüldü, `tedarikci_kodu` UNIQUE yapıldı (SQLite için
try/catch sarmalı).

### Akış değişiklikleri
- **`ProductService`**: yeni `getProductByTedarikciKodu()` (SelectUrun +
  `TedarikciKodu` filtresi). `fullCreateAyarlari`'da hem ukAyar hem vAyar
  `TedarikciKodunaGoreGuncelle = true` (Ticimax-native upsert geri açıldı,
  artık TedarikciKodu güvenilir).
- **`SyncNewProductsJob`**: lokal lookup `ProductMapping::where('tedarikci_kodu')`,
  SOAP probe önce TedarikciKodu → stok → barcode sırasıyla. `upsertMappings`
  varyasyon başına `tedarikci_kodu` anahtarıyla yazar.
- **`FullRemapProductsJob`**: eşleştirme önceliği TedarikciKodu → stok →
  barkod; mapping `tedarikci_kodu` anahtarıyla yazılır.
- **`SyncStockPriceJob`**: delta eşleştirme `byTed` → `byStok` sırası.

### Yan düzeltmeler (aynı tur)
- **Varyasyonsuz delta kartı kurtarma** (`ae33d48`): Ticimax'in
  `StokGuncellemeTarihi` filtresi bazı kartları **varyasyonsuz** (Varyasyon
  sayısı 0) döndürüyor → stok güncellemesi sessizce kaçıyordu. `refetchVariants()`
  o kartı `ana_product_id` üzerinden tam haliyle yeniden çeker. (Test:
  `testurunnnnnDSD`/12623 stok 121→120 doğrulandı ✅.)
- **Timezone** UTC → `Europe/Istanbul` (`config/app.php` + `.env`) — loglardaki
  saatler artık 3 saat geri değil.
- **Çift dispatch koruması** (`1c39c06`): `SyncNewProductsJob::dispatchUnique()`
  + `isQueuedOrRunning()` — aynı iş hem `jobs` tablosunda hem `sync_jobs`'ta
  açıkken yeniden dispatch edilmez. SyncSettings / ProductManualSync / SyncTick
  hepsi bunu kullanır.
- **Log görünürlüğü** (`52143c1`): eksik-ürün tamamlama pasında her 20 kartta
  `flushSyncBuffers` → ürün açılırken loglar canlı akar.
- **Sipariş paneli toplu seçim** (`8a42beb`): her satır (sadece uygun olanlar
  değil) seçilebilir; "Seçilenleri Aktar" tüm sayfayı kapsar.
- **Test/KLON filtresi YOK**: create akışı `testurun`/`test1233` gibi tüm
  stok kodlarını da aktarır (kullanıcı kararı).

---

## 2026-05-28 — Üçüncü tur: kapsamlı iyileştirme taraması (10 madde)

Tüm kod tabanı taranıp 10 maddelik iyileştirme uygulandı. **Tararken kritik bir
bug yakalandı:** bir önceki turda eklenen pagination skip-and-continue
`warning` logları `action`/`direction` (NOT NULL) alanlarını atlıyordu →
insert patlıyor, job `failed` oluyordu, yani **2000 ürün sorunu aslında hâlâ
çözülmemişti** (`5b25489` ile düzeltildi + UI'da `⚠ Atlandı` rozeti).

### 1) Eşzamanlı job koruması — `WithoutOverlapping` (`3440a8f`)
SyncTick ~15 dk'da bir dispatch ediyor ama job'lar 3 saate kadar sürebiliyor →
önceki bitmeden yenisi başlıyor, Ticimax'a paralel SOAP + çift yazma. Üç
scheduled job'a (`SyncNewProductsJob`, `SyncStockPriceJob`, `PullBayiOrdersJob`)
`WithoutOverlapping(...)->dontRelease()->expireAfter(...)` middleware eklendi.

### 2) Sıfıra yakın kayıplı pagination recovery (`cd6e6dd`)
Ticimax SOAP'ı belirli `BaslangicIndex` aralığında (~1940-2030, sabit pencere)
`Value cannot be null. Parameter name: source` fırlatıyor — bu **veri sonu
DEĞİL**, ötesinde binlerce ürün var (test: offset 5000'de gerçek son). Eski
"tüm sayfayı atla" yaklaşımı 2040+ binlerce ürünü kaçırıyordu. Yeni
`ProductService::fetchProductPageRecovering()`: bug yakalanınca sayfayı
`subStep`'lik (10) dilimlere bölüp her dilimi ayrı offset ile çeker. Canlı test:
offset 2000'de 100 yerine sadece 10 ürün kayıp. Bug-handling artık iki job'da
duplike değil, tek serviste. Dönüş DTO: `{products, bug, recovered, lost}`.

### 3) `api_credentials.username` şifreleme (`824333e`)
Yeni Ticimax B2B'de `username` = "Web Servis Yetki Kodu" = asıl gizli anahtar,
ama düz metin saklanıyordu (sadece `password` encrypted'di). Modele
`username => 'encrypted'` cast + mevcut satırları şifreleyen idempotent
migration (`2026_05_28_120000`).

### 4) Satır-başına DB write → topluya (`4cd52e5`)
Her üründe 3 sorgu (increment×2 + SyncLog::create) → 5000 ürün ≈ 15.000 sorgu.
Yeni `app/Jobs/Concerns/BuffersSyncWrites` trait'i log'ları + sayaçları bellekte
biriktirip sayfa başına tek bulk INSERT + tek increment yapar → ~300x az yazma.

### 5) WSDL disk cache (`a4e43f6`)
`cache_wsdl=WSDL_CACHE_NONE` her process'te WSDL'i yeniden indiriyordu (~1sn).
`WSDL_CACHE_DISK` + yazılabilir `storage/framework/cache/soap` dizini (PHP
varsayılanı Windows'ta `/tmp`, yazılamaz). Ölçüm: 993ms → 22ms (~45x).

### 6) `SyncStockPriceJob` docblock güncellendi (`a5141cd`)
Yorum hâlâ "DuzenlemeTarihi / 24 saat / batch_size=50" diyordu; gerçek akış
(FiyatStokGuncelleme + recovery + buffer) yansıtıldı.

### 7) Çekirdek iş mantığına testler (`dd066bf`)
Önce sadece Breeze auth + 2 mapper testi vardı. Eklenenler (11 yeni, 47→58→59):
`PaginationRecoveryTest` (bug tanıma + dilimleme kurtarma + dedupe),
`SyncBufferingTest` (toplu yazım + sayaçlar), `ApiCredentialEncryptionTest`
(şifreleme + forStore cache).

### 8) Laravel Pint (`2a35482`)
Pint hiç çalıştırılmamıştı; tüm kod tabanı formatlandı (Laravel preset).

### 9) Debug scriptleri düzenlendi + Pint muafiyeti (`04a51ad`)
19 `debug-*.php` → `scripts/debug/`. **Önemli:** #8'deki Pint, `scripts/`
altındaki one-off scriptleri bozmuştu (`fully_qualified_strict_types` inline
`Kernel::class`'ı require'dan sonra gelen bir `use`'a çevirince
`make('Kernel')` patlıyordu). Tüm scriptler geri yüklendi, `pint.json` ile
`scripts/` exclude edildi.

### 10) `ApiCredential::forStore()` process-içi cache (`13e88d2`)
Her TicimaxClient kurulumunda DB sorgusu yapılıyordu; static cache +
`forgetCache()` (ApiSettings save'de ve TestCase setUp'ta temizlenir).

---

## 2026-05-28 — İkinci tur: cache, toplu aktarım, eşleşmeyen ürün raporu + canlı SOAP adet geri çekildi

**Commits:** `d2d2b83`, `556fddf`, `4b6dff4`, `343d686`, `7b7c4f1`

### Geri çekildi: SetSiparisUrunDurum (canlıda adet değiştirme)

`a2b2ac8`'de eklenen "🔄 Bayi'de/Ana'da Uygula" özelliği **`d2d2b83` ile revert** edildi. Sebep: sipariş içeriğini Ticimax tarafında canlı manipüle etmek istemiyoruz; sadece **yerel override** (aktarımda uygulanır) bırakıldı. Ürünler modal'ında artık tek "💾 Kaydet" butonu var. `OrderService::updateSiparisUrunAdet()` ve UI'daki `applyLive()` metodları silindi.

### Performans: Hepsi modu + ana durumları cache'lendi (`4b6dff4`)

- `getOrdersByFilter()` Hepsi (-1) seçildiğinde 23 ayrı SOAP çağrısı yapan loop artık **60 saniye TTL cache**'lendi. Aynı filtre + sayfa kombinasyonuyla peş peşe tıklamalarda anında dönüyor.
- `getOrderByIdCached(int, int=30)` eklendi. `OrderTransferPicker::listele()` içinde her aktarılmış sipariş için yapılan ana durum fetch çağrıları artık bu cached versiyonu kullanıyor (30s TTL). Modal'lar (durum/ürün düzenleme) **kasıtlı olarak** taze veri için `getOrderById`'yi doğrudan çağırmaya devam ediyor.

### Yeni özellik: Toplu (bulk) sipariş aktarımı (`343d686`)

Listede her satırın başında checkbox. Başlıkta `☑` toggle (sadece aktarılmamış olanları tümünü seç/temizle). Seçim yapılınca üstte indigo banner:
- **🚀 Seçilenleri Aktar** — sırayla `TransferSingleBayiOrderJob::dispatch`
- **⚠️ Force Aktar** — zaten aktarılmış olsa bile yeniden
- **Seçimi Temizle**

Sadece `local_status ∈ {null, failed, pending}` olan satırlarda checkbox aktif. Force'suz toplu aktarımda `transferred/queued` olanlar atlanır + skip sayısı bildirilir. Dispatch sonrası UI'da hemen `queued` görünür.

### Yeni özellik: Eşleşmeyen Ürünler Raporu (`7b7c4f1`)

Yeni route: `/eslesmeyen-urunler` (`UnmatchedProductsReport` Livewire). Nav'da "Eşleşmeyen Ürünler" linki.

**Veri kaynağı:** zaten var olan `order_transfers.last_error` kolonu. **Yeni migration yok.** ProductMapper'ın throw ettiği sabit-formatlı hata `"Ana'da bu StokKodu ile aktif ürün bulunamadı: <SK>"` regex ile parse edilip stok koduna göre gruplanıyor.

Tablo: Stok Kodu · Etkilenen Sipariş · İlk/Son Görüldü · 🔁 Tekrar Dene. Satır expand → tüm etkilenen bayi sipariş ID'leri görünür. "Tekrar Dene" tıklanınca o stok koduna ait tüm `failed` siparişler `TransferSingleBayiOrderJob`'a re-dispatch edilir.

**Tipik akış:** Aktarım hata verir → buraya birikir → kullanıcı eksik ürünü ana panelden oluşturur → "🔁 Tekrar Dene" → etkilenen siparişler otomatik geçer.

### Yapılmayacaklar listesinden

- ❌ Satır iptal/iade (`SetSiparisUrunDurum` Islem=1/2) — **pas geçildi**, kullanıcı kararı.
- ❌ Sipariş durumu modal'ı (`SetSiparisDurum`, `SetSiparisOdemeDurum`, `SetSiparisPaketlemeDurum`) — **KORUNDU**, sadece adet değiştirme geri çekildi.

---

## 2026-05-28 — Sipariş Aktarım Paneli (`/siparisler`) genişletildi: ürün düzenleme, durum güncelleme, canlı SOAP, dinamik enum, diagnostic

Bu seansın tüm işleri **`OrderTransferPicker` ekranı** etrafında. 12 commit, 4 yeni özellik + 4 kritik bug fix. **Hasan tarafına etki sıfır** — sadece `app/Livewire/OrderTransferPicker.php`, `app/Jobs/TransferSingleBayiOrderJob.php`, `app/Jobs/PullBayiOrdersJob.php`, `app/Services/Ticimax/OrderService.php` ve `app/Services/Ticimax/ProductMapper.php` dokunuldu.

### A) Yeni özellik: Sipariş içi ürün düzenleme (`product_overrides`)

**Commits:** `a321b53` (canlı SOAP adet uygulama özelliği `a2b2ac8` eklendi, sonra `d2d2b83` ile geri çekildi — bkz. aşağıdaki not)
**Migration:** `2026_05_28_100000_add_product_overrides_to_order_transfers.php` — `order_transfers.product_overrides` JSON nullable kolon.

`📦 Ürünler` butonu → modal açılır → her satırın adetini değiştir veya sil → tek kaydetme yolu:

- **`💾 Kaydet`** — `order_transfers.product_overrides` JSON kolonuna yazar. `TransferSingleBayiOrderJob` aktarımdan ÖNCE bayi snapshot'ına bunu uygular (silinmiş satırları çıkarır, adetleri değiştirir, `ToplamTutar`/`ToplamKdv`/`OdenenTutar`'ı yeniden hesaplar). **Bayi'deki orijinal siparişe DOKUNMAZ, sadece ana'ya aktarılan kopyayı etkiler.**

> **Not (revert `d2d2b83`):** `SetSiparisUrunDurum` (Islem=0) ile bayi/ana'da satır adetini canlıda değiştiren özellik geri çekildi. Karar: sipariş içeriğini Ticimax tarafında manipüle etmek istemediğimiz için sadece **yerel override** (aktarımda uygulanır) bırakıldı.

### B) Yeni özellik: Sipariş/Ödeme/Paketleme durumu güncelleme

**Commits:** `881bea9`, `88cc6db`, `5d41677`

`✎ Durumlar` butonu → modal'da Bayi/Ana tab toggle → 3 dropdown (Sipariş Durumu / Ödeme Durumu / Paketleme Durumu) → "— Değiştirme —" opsiyonuyla sadece istediğini gönder.

**Yeni SOAP wrapper'ları (`OrderService`):**
- `updateSiparisDurum(int $siparisId, int $durumKodu, string $siparisNo)` → `SetSiparisDurum`
- `updateOdemeDurum(int $siparisId, int $odemeDurumKodu, ?int $odemeId)` → `SetSiparisOdemeDurum`. `$odemeId` verilmediyse önce `getOrderById` ile snapshot'tan `Odemeler.WebSiparisOdeme.ID` çekilir, bulunamazsa `SelectSiparisOdeme` fallback.
- `updatePaketlemeDurum(int $siparisId, int $paketlemeKodu)` → `SetSiparisPaketlemeDurum`

**Kritik öğrendiklerimiz:**
- `WebSiparisDurumlari` ve `WebOdemeDurumlari` **WSDL enum tipidir** — sayısal değil string ister (`"Onaylandi"`, `"KargoyaVerildi"`). Sayı gönderirsen `Invalid enum value '2' cannot be deserialized` hatası.
- Mapping XSD'den birebir çekildi → const `OrderService::SIPARIS_DURUM_ENUM` (0-18) ve `OrderService::ODEME_DURUM_ENUM` (0-4) fallback olarak kalıyor.
- **Bayi ile ana WSDL farklı**: bayi 19 sipariş durumu (`OnSiparis..TeslimEdilemedi`), ana 23 (`+MagazayaGonderildi, MagazayaUlasti, MagazadaTeslimBekliyor, CuzdanaIade`). Ödeme durumu her ikisinde de 5 (`OnayBekliyor..IptalEdilmis`).
- Bu yüzden **dinamik enum çekme**: `getSupportedSiparisDurumEnums()` ve `getSupportedOdemeDurumEnums()` → XSD'yi `?xsd=xsd0..9` üzerinden parse, Laravel `Cache::remember` 1 günlük. Modal seçili tab'a (bayi/ana) göre dropdown opsiyonlarını disabled yapar (`X durumu (bu mağazada yok)`).
- Ödeme Durumu kodları **5 (Ödeme Bekliyor)** ve **6 (Ödeme Talep Edildi)** Ticimax web panelinde var ama **SOAP enum'da yok** (her iki mağazada da). Bu sebeple bu iki kod ile API'den güncelleme yapılamaz — modal'da `(SOAP'ta yok)` etiketiyle disabled.

### C) "Bu Sipariş Numarasına Ait Kayıt Bulunmaktadır" → otomatik eşleştirme

**Commits:** `8f8f087`, `2c98dc3`

Ana'da aynı `SiparisNo` ile sipariş zaten varsa `SaveSiparis` bunu hata olarak döner. Önceden `failed` damgalayıp kullanıcıyı yanıltıyorduk (aslında ana'da sipariş VARDI).

**Yeni davranış (hem `TransferSingleBayiOrderJob` hem `PullBayiOrdersJob`):**
1. Hata mesajında `"Bu Sipariş Numarasına Ait Kayıt"` görürse:
2. Ana'da `getOrdersByFilter(['siparis_no' => ...])` ile arar
3. Bulduğu `ana_order_id`'yi `OrderTransfer` kaydına yazar → `status='transferred'`
4. Bayi'de `SetSiparisAktarildi` ile damgalar
5. Log'a `Ana #X olarak eşleştirildi` yazar

**Retroaktif eşleştirme yapıldı (tek seferlik):**
| Bayi | Ana | SiparisNo |
|---|---|---|
| #83 | #72 | 675HO2985M |
| #84 | #69 | 939GE9612N |
| #85 | #70 | 541WC8426P |
| #86 | #71 | 969CE2944V |

### D) Display bug fix: Ticimax SOAP iki ayrı alan dönüyor (METİN + SAYI)

**Commit:** `24eb591`

`SelectSiparis` yanıtında:
- `SiparisDurumu` = METİN (`"Onaylandı"`, `"Siparişiniz Alındı"`)
- `Durum` = SAYI (2, 0)
- `PaketlemeDurumu` = METİN, `PaketlemeDurumuID` = SAYI

Biz metin alanını okuyup `(int) "Onaylandı" = 0` yapıyorduk → her sipariş "Sipariş Alındı" görünüyordu. Aslında güncellemeler doğru çalışıyordu, sadece liste yanlış gösteriyordu.

**Fix:** `OrderTransferPicker::listele()` artık `Durum` (sayı) öncelikli okur, `SiparisDurumu` (metin) fallback. `PaketlemeDurumuID` için aynı düzeltme. Aynı sorun benzer projelerde tekrarlanabilir — METİN alanına `(int)` cast yapmadan önce `is_numeric()` kontrol et.

### E) Display bug fix: Ödeme tipi yanlış path → "Kredi Kartı" her satırda

**Commit:** `8b9642f`

Ticimax SOAP ödeme verisi gerçek path: `$o['Odemeler']['WebSiparisOdeme']` (tek ödeme = obje, çoklu = array). Biz `$o['Odeme']['OdemeTipi']` veya `$o['OdemeTipi']` okuyorduk → her ikisi de `null` → default `''` → `(int) '' = 0` → `odemeTipleri[0] = "Kredi Kartı"` her satırda yanlış görünüyordu.

**Aynı bug `ProductMapper`'da da vardı**: ana'ya `OdemeTipi='10'` (Diğer) gönderiyorduk, bayi'de aslında 2 (Kapıda Nakit Ödeme) idi.

**Fix:**
- `OrderTransferPicker::extractFirstOdeme()` helper — tek/çoklu obje handle eder
- Hem panel hem ana aktarımı doğru path'tan okur (`OdemeTipi`, `OdemeSecenekID`, `KapidaOdemeTutari`, `TaksitSayisi` hepsi)
- `OdemeDurumu` metni gelmiyor — Ticimax `Onaylandi: 0/1` bool döner. `Onaylandi=1` → "Onaylandı" göster (kod 1), aksi takdirde boş. Sipariş durumlarının aksine ödeme durumu metni SOAP yanıtında yok.

### F) Bayi/Ana ayrı görüntü + Bayi ID gösterimi

**Commits:** `24eb591`, `df03402`

"Ödeme / Durumlar" kolonu artık iki blok gösterir:
```
🏪 BAYI #86 (mavi sol bar)
  💳 Kapıda Nakit Ödeme
  📋 Onaylandı
  📦 Beklemede

🏢 ANA #71 (yeşil sol bar, sadece aktarılmışsa)
  💳 Kapıda Nakit Ödeme
  📋 Sipariş Alındı
  📦 Beklemede
```

Aktarılmış siparişler için ana mağazadan durumlar `listele()` içinde N ekstra SOAP çağrısıyla çekilir (`getOrderById($ana_order_id)` per row, try/catch per-row). Performans: 4 aktarılmış sipariş için ~3-5 saniye ekleyebilir.

### G) Yeni: Diagnostic (Aktarım Detayı) Modal'ı

**Commit:** `436bc72`

`✓ Aktarıldı` / `✗ Başarısız` badge'ler **tıklanabilir** — modal'da son 5 `SyncLog` kaydı (action: `transfer_order`, `transfer_order_manual`, `mark_order`):
- Status badge (yeşil/kırmızı), zaman, mesaj/hata + stack trace
- Expandable `<details>`: SOAP Request (bayi raw JSON + ana payload JSON + SOAP XML)
- Expandable `<details>`: SOAP Response (Ticimax cevabı XML)
- "Tüm logları görüntüle" → `/loglar?search=<bayiId>` link

**ÖNEMLİ:** `TransferSingleBayiOrderJob` artık **başarılı path'i de** `raw_request`/`raw_response` yazar (önceden sadece error path). Audit + "ne gönderdik?" sorusunun cevabı.

### H) `OrderTransferPicker` mevcut state (referans)

10 filtre (alıcı adı, e-mail, telefon, sipariş no, tarih aralığı, ödeme tipi, ödeme durumu, sipariş durumu, paketleme durumu, aktarılma durumu) + sayfalama + 4 işlem butonu per row:

| Buton | Ne yapar |
|---|---|
| `🚀 Aktar` / `↻ Zorla` | Tek sipariş aktarımı (`TransferSingleBayiOrderJob`) |
| `📦 Ürünler` | Ürün düzenleme modal'ı (override + canlı SOAP) |
| `✎ Durumlar` | Durum güncelleme modal'ı (bayi/ana tab) |
| `✓/✗ badge` | Diagnostic modal (SOAP request/response) |

### I) Hasan tarafına etki

**Sıfır.** Bu seansta `ProductService`, `ProductMapper` (sadece `Odeme` bloğu), `ProductPicker`, `ProductManualSync`, `SyncNewProductsJob`, `SyncStockPriceJob`, `ProductMapping` dosyalarına dokunulmadı.

**ProductMapper'daki tek değişiklik:** Bayi'den ana'ya sipariş aktarırken `Odeme` payload'ı artık `$bayiOrder['Odemeler']['WebSiparisOdeme']` path'inden çekiliyor (önceden `$bayiOrder['Odeme']`'den çekiyor ama orası boştu → her zaman default fallback değerleri gidiyordu). Bu Hasan'ın test ettiği ürün senkronu/sipariş aktarımı testlerini etkilemez, sadece sipariş aktarımının doğruluğunu artırır.

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
