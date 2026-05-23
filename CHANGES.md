# Değişiklik Geçmişi

Bu dosya projedeki **mimari kararları ve büyük refactor'ları** tarihli notlarla tutar.
Küçük bug fix'ler ve günlük commit'ler için `git log` yeterli — buraya sadece sonraki geliştiricilerin (insan veya AI) "buradaki yapı neden böyle?" sorusunun cevabını bilmesi gereken değişiklikler yazılır.

Yeni notları **en üste** ekle. Format: `## YYYY-MM-DD — kısa başlık`.

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
