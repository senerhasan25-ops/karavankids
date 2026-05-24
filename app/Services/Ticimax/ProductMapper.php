<?php

namespace App\Services\Ticimax;

/**
 * Ticimax UrunKarti/Varyasyon yapısı ile çalışır.
 *
 * İki yönü var:
 *  1) Ana → Bayi (Hasan'ın akışı): anaToBayiCreatePayload()
 *     - Yeni ürünleri bayi'de TedarikciKodu = "SUP|{ana_urun_id}|{stok_kodu}" ile oluşturur.
 *     - Varyasyonlara renk/beden ekiyle "SUP|{ana_urun_id}|{stok_kodu}|{renk}|{beden}" yazar.
 *     - Bayi tarafında SaveUrun çağrılırken `TedarikciKodunaGoreGuncelle: true` ile
 *       upsert yapılır — Ticimax mevcutsa günceller, yoksa oluşturur. Lokal mapping yok.
 *
 *  2) Bayi → Ana (Ali'nin akışı): bayiOrderToAnaCreatePayload()
 *     - Bayi'den çekilen siparişi ana'da SaveSiparis için gerçek WebSiparis envelope'una uygun
 *       hale getirir.
 *     - Sipariş satırlarındaki StokKodu ile ana'da lookup yapılır (callback'le sağlanır).
 *       Bulunan ana varyasyon ID'si line'da `UrunID` olarak yazılır.
 */
class ProductMapper
{
    /** TedarikciKodu prefix'i — gelecekte ayrı versiyonlar için tek noktadan değiştirilebilsin. */
    public const TED_KODU_PREFIX = 'SUP2026';

    /** @var callable|null  Marka adı → bayi marka ID'si çözümleyici (opsiyonel) */
    protected $brandResolver = null;

    /** @var callable|null  Tedarikçi (ana ID) → bayi tedarikçi ID'si çözümleyici */
    protected $supplierResolver = null;

    /** Bayi'nin default kategori ID'si — Kategoriler boş kalmasın diye fallback */
    protected int $defaultCategoryId = 0;

    public function setBrandResolver(?callable $resolver): void
    {
        $this->brandResolver = $resolver;
    }

    public function setSupplierResolver(?callable $resolver): void
    {
        $this->supplierResolver = $resolver;
    }

    public function setDefaultCategoryId(int $id): void
    {
        $this->defaultCategoryId = $id;
    }

    // ============================================================
    //  ANA → BAYİ  (ürün oluşturma / upsert)
    // ============================================================

    /**
     * Ana mağazadan gelen UrunKarti objesini bayi'ye SaveUrun payload'una çevirir.
     * TedarikciKodu gömülür → Ticimax kendi upsert'ünü TedarikciKodunaGoreGuncelle ile yapar.
     */
    public function anaToBayiCreatePayload(array $ana): array
    {
        $stokKodu = $this->resolveStokKodu($ana);
        $primaryVariantId = $this->resolvePrimaryVariantId($ana);
        $kartTedKodu = $this->buildTedarikciKodu($primaryVariantId, $stokKodu);

        $kart = [
            'ID' => 0, // upsert için 0 — TedarikciKodu eşleşirse Ticimax günceller, yoksa yeni oluşturur
            'Aktif' => (bool) ($ana['Aktif'] ?? true),
            'UrunAdi' => $this->sanitizeText((string) ($ana['UrunAdi'] ?? '')),
            // Ticimax'in guvenlik filtresi <script>, <style>, <iframe>, on* event'leri,
            // CSS expression/behavior'leri reddediyor — sanitize zorunlu.
            'Aciklama' => $this->sanitizeHtml((string) ($ana['Aciklama'] ?? '')),
            'OnYazi' => $this->sanitizeHtml((string) ($ana['OnYazi'] ?? '')),
            'AramaAnahtarKelime' => $this->sanitizeText((string) ($ana['AramaAnahtarKelime'] ?? '')),

            'AnaKategori' => (string) ($ana['AnaKategori'] ?? ''),
            'AnaKategoriID' => $this->defaultCategoryId, // 0 ise Ticimax 'kategori bayi'de yok' diye sessizce reddediyor
            'Kategoriler' => $this->defaultCategoryId > 0 ? ['int' => [$this->defaultCategoryId]] : [],

            'Marka' => (string) ($ana['Marka'] ?? ''),
            'MarkaID' => $this->resolveBrandId((string) ($ana['Marka'] ?? '')),

            'SeoSayfaBaslik' => $this->sanitizeText((string) ($ana['SeoSayfaBaslik'] ?? $ana['UrunAdi'] ?? '')),
            'SeoSayfaAciklama' => $this->sanitizeText((string) ($ana['SeoSayfaAciklama'] ?? ($ana['OnYazi'] ?? ''))),
            'SeoAnahtarKelime' => $this->sanitizeText((string) ($ana['SeoAnahtarKelime'] ?? '')),
            // Ana'nın slug'ı bayi'de çakışabilir; boş bırak, Ticimax üretsin
            'UrunSayfaAdresi' => '',

            'ListedeGoster' => (bool) ($ana['ListedeGoster'] ?? true),
            'Vitrin' => (bool) ($ana['Vitrin'] ?? false),
            'YeniUrun' => (bool) ($ana['YeniUrun'] ?? false),

            'TedarikciID' => $this->resolveSupplierId((int) ($ana['TedarikciID'] ?? 0)),
            'TedarikciKodu' => $kartTedKodu, // ← Ticimax upsert anahtarı
            'TedarikciKodu2' => '',

            'Resimler' => $this->mapImages($ana['Resimler'] ?? []),

            'Varyasyonlar' => $this->mapVariations($ana['Varyasyonlar'] ?? [], $kartTedKodu),
        ];

        // Üst seviyede tek varyasyonsuz ürün geldiyse fallback
        if (empty($kart['Varyasyonlar']) && ($ana['Barkod'] ?? null || $ana['StokKodu'] ?? null)) {
            $kart['Varyasyonlar'] = [$this->fallbackVariantFromTopLevel($ana, $kartTedKodu)];
        }

        return $kart;
    }

    /**
     * "SUP2026|{stokKodu}|{anaVariantId}" — kaynak mağazadaki VARYASYON ID'sini
     * gömer (UrunKartiID değil). Aynı StokKodu farklı varyasyonlarda olamayacağı için
     * tek başına eşsiz; varyasyon ID eklenmesi sadece izleme/audit içindir.
     */
    public function buildTedarikciKodu(int $anaVariantId, string $stokKodu): string
    {
        return self::TED_KODU_PREFIX . '|' . trim($stokKodu) . '|' . $anaVariantId;
    }

    /**
     * Üründen birincil VaryasyonID'yi çıkarır — en küçük ID'li varyasyon.
     * resolveStokKodu ile aynı varyasyonu seçer (deterministik).
     */
    public function resolvePrimaryVariantId(array $ana): int
    {
        $variants = $this->flattenVariants($ana['Varyasyonlar'] ?? []);
        usort($variants, fn ($a, $b) => ((int) ($a['ID'] ?? 0)) <=> ((int) ($b['ID'] ?? 0)));
        foreach ($variants as $v) {
            $id = (int) ($v['ID'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Üründen StokKodu çıkarır; üstte yoksa varyasyon listesinden en düşük ID'li olanı seçer
     * (deterministik kalsın, API yanıt sıralaması değişse bile aynı kod üretilsin).
     */
    public function resolveStokKodu(array $ana): string
    {
        $top = trim((string) ($ana['StokKodu'] ?? ''));
        if ($top !== '') {
            return $top;
        }
        $variants = $this->flattenVariants($ana['Varyasyonlar'] ?? []);
        usort($variants, fn ($a, $b) => ((int) ($a['ID'] ?? 0)) <=> ((int) ($b['ID'] ?? 0)));
        foreach ($variants as $v) {
            $sk = trim((string) ($v['StokKodu'] ?? ''));
            if ($sk !== '') {
                return $sk;
            }
        }
        return '';
    }

    protected function flattenVariants(mixed $variations): array
    {
        if (! is_array($variations)) {
            return [];
        }
        if (isset($variations['Varyasyon'])) {
            $variations = is_array($variations['Varyasyon']) && array_is_list($variations['Varyasyon'])
                ? $variations['Varyasyon']
                : [$variations['Varyasyon']];
        }
        return is_array($variations) ? array_values($variations) : [];
    }

    // ============================================================
    //  BAYİ → ANA  (sipariş aktarımı)
    // ============================================================

    /**
     * Bayi siparişini ana mağazada SaveSiparis için gerçek WebSiparis envelope'una uygun
     * payload'a çevirir.
     *
     * @param  array     $bayiOrder  Bayi'den dönen WebSiparis (Urunler altında WebSiparisUrun listesi)
     * @param  callable  $resolveAnaUrunIdByStokKodu  fn(string $stokKodu): ?int — ana'daki Varyasyon.ID
     *
     * @throws \RuntimeException StokKodu ana'da bulunamazsa
     */
    public function bayiOrderToAnaCreatePayload(array $bayiOrder, callable $resolveAnaUrunIdByStokKodu): array
    {
        // Sipariş satırlarını (WebSiparisUrun) WebSiparisSaveUrun haline getir
        $bayiUrunler = $this->flattenOrderLines($bayiOrder['Urunler'] ?? []);
        $satirlar = [];
        $kdvToplam = 0.0;
        $araToplam = 0.0;

        foreach ($bayiUrunler as $line) {
            $stokKodu = trim((string) ($line['StokKodu'] ?? ''));
            if ($stokKodu === '') {
                throw new \RuntimeException('Bayi sipariş satırında StokKodu yok — eşleşme yapılamaz.');
            }
            $anaUrunId = $resolveAnaUrunIdByStokKodu($stokKodu);
            if (! $anaUrunId) {
                throw new \RuntimeException("Ana'da bu StokKodu ile aktif ürün bulunamadı: {$stokKodu}");
            }

            $adet = (int) ($line['Adet'] ?? 1);
            $birimFiyat = (float) ($line['Tutar'] ?? $line['BirimFiyat'] ?? $line['SatisFiyati'] ?? 0);
            $kdvOrani = (float) ($line['KdvOrani'] ?? 20);
            $kdvTutari = isset($line['KdvTutari'])
                ? (float) $line['KdvTutari']
                : round($birimFiyat * $adet * ($kdvOrani / (100 + $kdvOrani)), 4);

            $satirlar[] = [
                'Adet' => $adet,
                'KdvOrani' => $kdvOrani,
                'KdvTutari' => $kdvTutari,
                'MagazaId' => 0,
                'MagazaStokKontrolEt' => false,
                'Maliyet' => 0,
                'MarketPlaceOdemeAlindi' => true,
                'Tutar' => $birimFiyat,
                'UrunID' => $anaUrunId,
                'UrunIndirimsizFiyati' => 0,
            ];

            $araToplam += $birimFiyat * $adet;
            $kdvToplam += $kdvTutari;
        }

        // === FİYAT HESABI — Hasan'ın çalışan Python kodundan birebir port ===
        // siparis_aktar.py::save_to_target() referans.
        //
        // KRİTİK: Ticimax `UrunTutari` alanında "KDV DAHİL ürün toplamı"nı bekler
        // (KDV hariç değil!). Yanlış göndersek 333.33 fiyat ana'da 266.66 görünür
        // (Ticimax "KDV dahil bu" sanıp tekrar KDV ayırır).
        //
        // Doğru formül (Python'dan):
        //   ToplamTutar  = bayi'deki ürün+kargo KDV DAHİL toplam
        //   ToplamKdv    = bayi'deki toplam KDV
        //   UrunTutari   = ToplamTutar − KargoTutari   (KDV DAHİL ürün toplamı)
        //   UrunTutariKdv= ToplamKdv − KargoKdv        (sadece ürün KDV'si)
        $bayiToplamTutar = (float) ($bayiOrder['ToplamTutar'] ?? 0);
        $bayiToplamKdv   = (float) ($bayiOrder['ToplamKdv'] ?? 0);

        $kargoBrut = (float) ($bayiOrder['KargoTutari'] ?? 0);
        if ($kargoBrut <= 0) {
            $kargoBrut = 5.0; // kullanıcı isteği: minimum kargo
        }

        // Kargo KDV: bayi'den gelirse onu kullan, yoksa %20 varsayımıyla brüt'ten ayır
        $kargoKdvSrc = (float) ($bayiOrder['KargoKdvTutari'] ?? 0);
        $kargoKdv = $kargoKdvSrc > 0
            ? $kargoKdvSrc
            : ($kargoBrut > 0 ? round($kargoBrut - ($kargoBrut / 1.20), 4) : 0.0);

        // Bayi ToplamTutar / ToplamKdv gelmezse satır hesabından üret (fallback).
        // araToplam = Σ(birimFiyat × adet) → birimFiyat KDV DAHİL geliyorsa bu KDV dahil toplamdır
        if ($bayiToplamTutar <= 0) {
            $bayiToplamTutar = $araToplam + $kargoBrut;
        }
        if ($bayiToplamKdv <= 0) {
            $bayiToplamKdv = $kdvToplam + $kargoKdv;
        }

        // Hedef için ürün tutarları (kargo hariç) — Python ile birebir
        $urunTutariKdvDahil = round($bayiToplamTutar - $kargoBrut, 4);
        $urunKdv            = round($bayiToplamKdv - $kargoKdv, 4);

        // Genel toplam = OdenenTutar (Python öncelik sırası)
        $genelToplam = (float) ($bayiOrder['OdenenTutar'] ?? $bayiOrder['SiparisToplamTutari'] ?? ($araToplam + $kargoBrut));
        if ($genelToplam <= 0) {
            $genelToplam = $bayiToplamTutar;
        }

        // NOT: Bu alanlar SOAP'tan boş gelirse [] olur — s() helper'ı array/null/object güvenli.
        $alici = $this->s($bayiOrder['AdiSoyadi'] ?? $bayiOrder['UyeAdi'] ?? null);
        $mail = trim($this->s($bayiOrder['Mail'] ?? $bayiOrder['UyeMail'] ?? null));
        if (strlen($mail) <= 5) {
            $mail = 'destek@karavankids.com';
        }
        $telefon = $this->s($bayiOrder['Telefon']
            ?? $bayiOrder['UyeCep']
            ?? ($bayiOrder['TeslimatAdresi']['AliciTelefon'] ?? null)
            ?? null, '5555555555');

        $faturaSrc = $bayiOrder['FaturaAdresi'] ?? [];
        $teslimatSrc = $bayiOrder['TeslimatAdresi'] ?? [];
        $tarih = now()->format('Y-m-d\TH:i:s');

        return [
            'BNPLNo' => '',
            'FaturaAdres' => $this->buildFaturaAdres($faturaSrc),
            'FaturaAdresId' => 0,
            'HediyePaketiVar' => false,
            'IndirimTutari' => 0,
            'IsMarketplace' => true,
            'KargoAdresId' => 0,
            'KargoDesi' => 0,
            'KargoFirmaId' => (int) ($bayiOrder['KargoFirmaID'] ?? $bayiOrder['KargoFirmaId'] ?? 1),
            'KargoKatkiPayi' => 0,
            'KargoTutari' => $kargoBrut,
            // Python'da 0 → Ticimax kargo KDV'sini UrunTutariKdv hesabından zaten almıyor,
            // tekrar yapay KDV ayırmaması için 0 bırak (kargo brütü olduğu gibi kayıt edilir).
            'KargoKdvOrani' => 0,
            'KargoyaSonVerilmeTarihiGuncelle' => false,
            // Python kodunda false → çalışıyordu. Asıl sorun UrunTutari'nın KDV dahil mi hariç mi
            // olduğuydu (yukarıdaki blokta düzeltildi). Bu flag false kalsın.
            'KdvOraniniSiparisUrundenAl' => false,
            'MarketPlaceOdemeAlindi' => true,
            'MarketplaceKampanyaKodu' => '',
            'Odeme' => [
                'BankaKomisyonu' => 0,
                'HavaleHesapID' => 0,
                'KapidaOdemeTutari' => 0,
                'OdemeDurumu' => $this->s($bayiOrder['Odeme']['OdemeDurumu'] ?? $bayiOrder['OdemeDurumu'] ?? null, '1'),
                'OdemeIndirimi' => 0,
                'OdemeSecenekID' => 0,
                'OdemeTipi' => $this->s($bayiOrder['Odeme']['OdemeTipi'] ?? $bayiOrder['OdemeTipi'] ?? null, '10'),
                'TaksitSayisi' => 0,
                'Tarih' => $tarih,
                'Tutar' => $genelToplam,
            ],
            'OzelAlan3' => '',
            'ParaBirimi' => 'TRY',
            'PazaryeriButikId' => 0,
            'PazaryeriIhracat' => 0,
            'SiparisKaynagi' => 'KaravanKids',
            'SiparisNo' => $this->s($bayiOrder['SiparisNo'] ?? $bayiOrder['SiparisKodu'] ?? null),
            'SiparisNotu' => '',
            'SmsGonder' => false,
            'TeslimatAdres' => $this->buildTeslimatAdres($teslimatSrc, $alici, $telefon),
            'TeslimatTarihi' => $tarih,
            // Python'dan: UrunTutari = ToplamTutar − KargoTutari (KDV DAHİL ürün tutarı)
            //             UrunTutariKdv = ToplamKdv − KargoKdv  (sadece ürün KDV'si)
            'UrunTutari' => $urunTutariKdvDahil,
            'UrunTutariKdv' => $urunKdv,
            'Urunler' => ['WebSiparisSaveUrun' => $satirlar],
            'UyeAdi' => $alici,
            'UyeCep' => $telefon,
            'UyeId' => 0,
            'UyeKazanimAktif' => false,
            'UyeMail' => $mail,
        ];
    }

    /**
     * Ticimax SOAP boş XML elementlerini ('<Ulke/>') null yerine boş array [] olarak
     * deserialize eder → (string) [] "Array to string conversion" fırlatır.
     * Tüm string cast'leri bu helper'dan geçir: array/null/object → fallback.
     */
    protected function s(mixed $v, string $default = ''): string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return $default;
        }
        return (string) $v;
    }

    protected function buildFaturaAdres(array $src): array
    {
        return [
            'Adres' => $this->s($src['Adres'] ?? null),
            'AdresTarifi' => '',
            'FirmaAdi' => $this->s($src['FirmaAdi'] ?? null),
            'Il' => $this->s($src['Il'] ?? null),
            'Ilce' => $this->s($src['Ilce'] ?? null),
            'Mahalle' => $this->s($src['Mahalle'] ?? null),
            'PostaKodu' => $this->s($src['PostaKodu'] ?? null),
            'Semt' => $this->s($src['Semt'] ?? null),
            'Ulke' => $this->s($src['Ulke'] ?? null, 'Türkiye'),
            'VergiDairesi' => $this->s($src['VergiDairesi'] ?? null),
            'VergiNo' => $this->s($src['VergiNo'] ?? null),
            'isKurumsal' => (bool) ($src['isKurumsal'] ?? false),
        ];
    }

    protected function buildTeslimatAdres(array $src, string $alici, string $telefon): array
    {
        return [
            'Adres' => $this->s($src['Adres'] ?? null),
            'AdresTarifi' => '',
            'AliciAdi' => $this->s($src['AliciAdi'] ?? null, $alici),
            'AliciTelefon' => $this->s($src['AliciTelefon'] ?? null, $telefon),
            'Il' => $this->s($src['Il'] ?? null),
            'Ilce' => $this->s($src['Ilce'] ?? null),
            'Mahalle' => $this->s($src['Mahalle'] ?? null),
            'Semt' => $this->s($src['Semt'] ?? null),
            'Ulke' => $this->s($src['Ulke'] ?? null, 'Türkiye'),
        ];
    }

    /**
     * Bayi'den dönen Urunler — bazen array (list), bazen `WebSiparisUrun` anahtarı altında, bazen tek obje.
     */
    protected function flattenOrderLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }
        if (isset($lines['WebSiparisUrun'])) {
            $lines = is_array($lines['WebSiparisUrun']) && array_is_list($lines['WebSiparisUrun'])
                ? $lines['WebSiparisUrun']
                : [$lines['WebSiparisUrun']];
        } elseif (! array_is_list($lines)) {
            $lines = [$lines];
        }
        return is_array($lines) ? $lines : [];
    }

    // ============================================================
    //  ORTAK / Yardımcılar
    // ============================================================

    public function deactivatePayload(): array
    {
        return ['Aktif' => false];
    }

    // ============================================================
    //  Sanitize — Ticimax güvenlik filtresine takılmamak için
    // ============================================================

    /**
     * Ticimax `Aciklama` ve `OnYazi` gibi rich-text alanları için HTML temizleme.
     * - <script>, <style>, <iframe>, <embed>, <object>, <link>, <meta>, <form> tamamen kaldırılır
     * - on* (onclick, onload, ...) attribute'ları kaldırılır
     * - javascript:, data: URL şemaları kaldırılır
     * - CSS expression() / behavior: / @import kaldırılır
     * - Geri kalan: <p>, <br>, <b>, <i>, <u>, <a>, <img>, <ul>, <ol>, <li>, <h1>..<h6>, <table>, <div>, <span> KALIR
     */
    public function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // 1) Tehlikeli tag'leri içerikleriyle birlikte sil
        $dangerous = ['script', 'style', 'iframe', 'embed', 'object', 'link', 'meta', 'form', 'input', 'button', 'svg', 'noscript', 'frame', 'frameset', 'applet'];
        foreach ($dangerous as $tag) {
            $html = preg_replace('#<\s*' . $tag . '\b[^>]*>.*?<\s*/\s*' . $tag . '\s*>#is', '', $html) ?? $html;
            $html = preg_replace('#<\s*' . $tag . '\b[^>]*/?\s*>#i', '', $html) ?? $html;
        }
        // 2) on* event attribute'larını sök (onclick, onload, onerror, ...)
        $html = preg_replace('#\s*on[a-z]+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
        $html = preg_replace("#\s*on[a-z]+\s*=\s*'[^']*'#i", '', $html) ?? $html;
        $html = preg_replace('#\s*on[a-z]+\s*=\s*[^\s>]+#i', '', $html) ?? $html;
        // 3) javascript: ve data: URL şemalarını sök
        $html = preg_replace('#\b(href|src|action|formaction)\s*=\s*"\s*(?:javascript|data|vbscript)\s*:[^"]*"#i', '$1="#"', $html) ?? $html;
        $html = preg_replace("#\b(href|src|action|formaction)\s*=\s*'\s*(?:javascript|data|vbscript)\s*:[^']*'#i", '$1="#"', $html) ?? $html;
        // 4) CSS expression / behavior / @import (inline style içinde olabilir)
        $html = preg_replace('#expression\s*\([^)]*\)#i', '', $html) ?? $html;
        $html = preg_replace('#behavior\s*:[^;"\']+#i', '', $html) ?? $html;
        $html = preg_replace('#@import[^;]+;?#i', '', $html) ?? $html;
        // 5) HTML yorumları (içinde CDATA/script gizleme olabilir)
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * Düz metin alanları (UrunAdi, SEO alanları, anahtar kelimeler) için.
     * Hiçbir HTML/tag kabul edilmez — sadece düz metin.
     */
    public function sanitizeText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // HTML entity decode + tag sök + tekrar entity encode için: önce tag'leri çıkar
        $text = strip_tags($text);
        // \r\n → space (tek satır metinler için)
        $text = preg_replace('#\s+#', ' ', $text) ?? $text;
        return trim($text);
    }

    protected function mapImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }
        if (isset($images['string'])) {
            $images = is_array($images['string']) && array_is_list($images['string']) ? $images['string'] : [$images['string']];
        }
        return array_values(array_filter(array_map(function ($img) {
            if (is_string($img)) {
                return $img;
            }
            if (is_array($img)) {
                return $img['ResimUrl'] ?? $img['Url'] ?? $img['ResimAdi'] ?? null;
            }
            return null;
        }, $images)));
    }

    /**
     * Varyasyon listesini Ticimax Varyasyon şemasına göre üretir ve her birine
     * "{baseTedKodu}|{renk}|{beden}" formatında TedarikciKodu yazar.
     */
    protected function mapVariations(mixed $variations, string $baseTedKodu): array
    {
        $variations = $this->flattenVariants($variations);
        if (empty($variations)) {
            return [];
        }

        return array_map(function ($v) use ($baseTedKodu) {
            $ozellikler = $this->mapVariantProps($v['Ozellikler'] ?? []);
            // Her varyasyonun KENDİ TedarikciKodu'su = "SUP2026|varyasyonStokKodu|varyasyonId"
            // Üst seviye baseTedKodu birincil varyasyondan üretildi; ikincil varyasyonlar
            // için kendi ID/StokKodu'larıyla üretmek zorundayız (eşsiz olsun diye).
            $vId = (int) ($v['ID'] ?? 0);
            $vStokKodu = trim((string) ($v['StokKodu'] ?? ''));
            $tedKodu = ($vId > 0 && $vStokKodu !== '')
                ? $this->buildTedarikciKodu($vId, $vStokKodu)
                : $baseTedKodu;

            return [
                'ID' => 0,
                'UrunKartiID' => 0,
                'Aktif' => (bool) ($v['Aktif'] ?? true),
                'Barkod' => (string) ($v['Barkod'] ?? ''),
                'StokKodu' => (string) ($v['StokKodu'] ?? ''),
                'StokAdedi' => (float) ($v['StokAdedi'] ?? 0),
                'SatisFiyati' => (float) ($v['SatisFiyati'] ?? 0),
                'IndirimliFiyati' => (float) ($v['IndirimliFiyati'] ?? 0),
                'AlisFiyati' => (float) ($v['AlisFiyati'] ?? 0),
                'PiyasaFiyati' => (float) ($v['PiyasaFiyati'] ?? 0),
                'KdvOrani' => (float) ($v['KdvOrani'] ?? 20),
                'KdvDahil' => (bool) ($v['KdvDahil'] ?? true),
                'ParaBirimi' => (string) ($v['ParaBirimi'] ?? 'TL'),
                'ParaBirimiID' => (int) ($v['ParaBirimiID'] ?? 1),
                'Desi' => (float) ($v['Desi'] ?? 0),
                'UrunAgirligi' => (float) ($v['UrunAgirligi'] ?? 0),
                'TedarikciKodu' => $tedKodu, // ← varyasyon seviyesi upsert anahtarı
                'TedarikciKodu2' => '',
                'Ozellikler' => $ozellikler,
                'Resimler' => $this->mapImages($v['Resimler'] ?? []),
            ];
        }, $variations);
    }

    protected function mapVariantProps(mixed $props): array
    {
        if (! is_array($props)) {
            return [];
        }
        if (isset($props['VaryasyonOzellik'])) {
            $props = is_array($props['VaryasyonOzellik']) && array_is_list($props['VaryasyonOzellik']) ? $props['VaryasyonOzellik'] : [$props['VaryasyonOzellik']];
        }
        return array_map(fn ($p) => [
            'Tanim' => (string) ($p['Tanim'] ?? ''),
            'Deger' => (string) ($p['Deger'] ?? ''),
            'RenkKodu' => (string) ($p['RenkKodu'] ?? ''),
        ], $props);
    }

    protected function fallbackVariantFromTopLevel(array $ana, string $baseTedKodu): array
    {
        return [
            'ID' => 0,
            'UrunKartiID' => 0,
            'Aktif' => true,
            'Barkod' => (string) ($ana['Barkod'] ?? ''),
            'StokKodu' => (string) ($ana['StokKodu'] ?? $ana['Barkod'] ?? ''),
            'StokAdedi' => (float) ($ana['StokAdedi'] ?? $ana['ToplamStokAdedi'] ?? 0),
            'SatisFiyati' => (float) ($ana['SatisFiyati'] ?? 0),
            'IndirimliFiyati' => (float) ($ana['IndirimliFiyati'] ?? 0),
            'AlisFiyati' => (float) ($ana['AlisFiyati'] ?? 0),
            'KdvOrani' => (float) ($ana['KdvOrani'] ?? 20),
            'KdvDahil' => (bool) ($ana['KdvDahil'] ?? true),
            'ParaBirimi' => (string) ($ana['ParaBirimi'] ?? 'TL'),
            'ParaBirimiID' => (int) ($ana['ParaBirimiID'] ?? 1),
            'Desi' => (float) ($ana['Desi'] ?? 0),
            'TedarikciKodu' => $baseTedKodu,
            'TedarikciKodu2' => '',
            'Ozellikler' => [],
            'Resimler' => [],
        ];
    }

    protected function resolveBrandId(string $name): int
    {
        if ($name === '' || $this->brandResolver === null) {
            return 0;
        }
        try {
            return (int) call_user_func($this->brandResolver, $name);
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function resolveSupplierId(int $anaSupplierId): int
    {
        if ($anaSupplierId === 0 || $this->supplierResolver === null) {
            return 0;
        }
        try {
            return (int) call_user_func($this->supplierResolver, $anaSupplierId);
        } catch (\Throwable) {
            return 0;
        }
    }
}
