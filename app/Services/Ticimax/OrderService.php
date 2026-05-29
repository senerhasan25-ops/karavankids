<?php

namespace App\Services\Ticimax;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class OrderService
{
    public function __construct(protected TicimaxClient $client) {}

    public static function for(string $storeKey): self
    {
        return new self(TicimaxClient::for($storeKey));
    }

    public function getClient(): TicimaxClient
    {
        return $this->client;
    }

    /**
     * Henüz aktarılmamış (EntegrasyonAktarildi=0), ödemesi alınmış, paketlenmemiş siparişleri çek.
     * siparis_aktar.py:get_source_orders ile aynı filtre.
     */
    public function getNewOrders(?Carbon $since = null, int $page = 1, int $perPage = 50, int $siparisDurumu = 0): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);
        // Saat bazlı filtreleme için tam tarih-saat formatını kullan (gün başı değil)
        $bas = ($since ?? Carbon::now()->subDays(3))->format('Y-m-d\TH:i:s');
        $son = Carbon::now()->format('Y-m-d\TH:i:s');

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => [
                'DuzenlemeTarihiBas' => null,
                'DuzenlemeTarihiSon' => null,
                'EntegrasyonAktarildi' => 0,
                'EntegrasyonParams' => [
                    'EntegrasyonParamsAktif' => false,
                ],
                'IptalEdilmisUrunler' => false,
                'KampanyaGetir' => false,
                'KargoFirmaID' => 0,
                'OdemeDurumu' => 1,
                'OdemeTipi' => -1,
                'PaketlemeDurumu' => 1, // 1 = henüz paketlenmemiş (aktarım için uygun)
                'SiparisDurumu' => $siparisDurumu,
                'SiparisID' => 0,
                'SiparisKaynagi' => '',
                'SiparisTarihiBas' => $bas,
                'SiparisTarihiSon' => $son,
                'StrSiparisDurumu' => '',
                'TedarikciID' => -1,
                'UrunGetir' => true,
                'UyeID' => -1,
            ],
            's' => [
                'BaslangicIndex' => $startIdx,
                'KayitSayisi' => $perPage,
                'SiralamaYonu' => 'ASC',
            ],
        ];

        $resp = $this->client->call('order', $this->method('select'), $params);

        return $this->normalizeList($resp, $this->method('select'));
    }

    /**
     * Ticimax'taki sipariş durumlarını döner: [['id' => 1, 'ad' => 'Yeni Sipariş'], ...]
     * Ekran yükleme sırasında SyncSettings'te gösterilmek üzere çekilir.
     */
    public function getOrderStatuses(): array
    {
        $params = ['UyeKodu' => $this->client->getUyeKodu()];
        $resp = $this->client->call('order', $this->method('select_status_list'), $params);

        // Yanıt yapısı: SelectSiparisDurumlariResult → SiparisDurumlari → EnumKeyValue[]{Key, Value}
        $resultObj = null;
        if (is_object($resp) && isset($resp->SelectSiparisDurumlariResult)) {
            $resultObj = $resp->SelectSiparisDurumlariResult;
        } elseif (is_object($resp)) {
            $resultObj = $resp;
        }

        $items = $resultObj->SiparisDurumlari->EnumKeyValue ?? null;
        if (! $items) {
            return [];
        }

        // Tek kayıt gelirse object, çok kayıt gelirse array
        if (is_object($items)) {
            $items = [$items];
        }

        $result = [];
        foreach ((array) $items as $item) {
            $id = (int) (is_object($item) ? $item->Key : ($item['Key'] ?? null));
            $ad = (string) (is_object($item) ? $item->Value : ($item['Value'] ?? "Durum #{$id}"));
            $result[] = ['id' => $id, 'ad' => $ad];
        }

        usort($result, fn ($a, $b) => $a['id'] <=> $b['id']);

        return $result;
    }

    /**
     * Esnek filtreli sipariş listeleme — manuel aktarım panelinde kullanılır.
     * Kullanıcının seçtiği tarih aralığı / sipariş no / ödeme tipi / aktarılma durumuna göre
     * Ticimax'tan sipariş çeker. getNewOrders'tan farkı:
     *  - EntegrasyonAktarildi default -1 (hepsi) ama overrride edilebilir
     *  - PaketlemeDurumu default 0 (hepsi)
     *  - SiparisNo ile spesifik arama yapılabilir
     */
    public function getOrdersByFilter(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);
        $bas = $filters['date_from'] ?? Carbon::now()->subDays(30)->format('Y-m-d\T00:00:00');
        $son = $filters['date_to'] ?? Carbon::now()->format('Y-m-d\T23:59:59');

        // Carbon objesi veya date-only string gelirse Ticimax formatına çevir
        $bas = $bas instanceof Carbon ? $bas->format('Y-m-d\T00:00:00')
            : (strlen((string) $bas) === 10 ? $bas.'T00:00:00' : $bas);
        $son = $son instanceof Carbon ? $son->format('Y-m-d\T23:59:59')
            : (strlen((string) $son) === 10 ? $son.'T23:59:59' : $son);

        // Ticimax SOAP DataContract bu alanları zorunlu kabul ediyor — omit edersek
        // sessizce boş cevap dönüyor. Alanları HER ZAMAN ekleriz; "Hepsi" için -1 göndeririz
        // (Ticimax bunu çoğu alanda "ignore" olarak işliyor).
        //
        // İSTİSNA: SiparisDurumu için -1 geçerli değil ve Ticimax 0 sonuç döner. Bu yüzden
        // "Hepsi" seçiliyse 23 durumu döngüye alıp birleştirip dedupe ediyoruz (Hasan'ın
        // PullBayiOrdersJob.handle() ile aynı yaklaşım).
        $siparisDurumu = $filters['siparis_durumu'] ?? -1;
        $odemeDurumu = $filters['odeme_durumu'] ?? -1;
        $paketlemeDurumu = $filters['paketleme_durumu'] ?? -1;
        $odemeTipi = $filters['odeme_tipi'] ?? -1;
        $aktarildi = $filters['aktarildi'] ?? -1;

        $buildParams = function (int $siparisDurumuValue) use (
            $startIdx, $perPage, $bas, $son, $filters,
            $odemeDurumu, $paketlemeDurumu, $odemeTipi, $aktarildi
        ): array {
            return [
                'UyeKodu' => $this->client->getUyeKodu(),
                'f' => [
                    'DuzenlemeTarihiBas' => null,
                    'DuzenlemeTarihiSon' => null,
                    'EntegrasyonAktarildi' => $aktarildi,
                    'EntegrasyonParams' => ['EntegrasyonParamsAktif' => false],
                    'IptalEdilmisUrunler' => false,
                    'KampanyaGetir' => false,
                    'KargoFirmaID' => 0,
                    'OdemeDurumu' => $odemeDurumu,
                    'OdemeTipi' => $odemeTipi,
                    'PaketlemeDurumu' => $paketlemeDurumu,
                    'SiparisDurumu' => $siparisDurumuValue,
                    'SiparisID' => $filters['siparis_id'] ?? 0,
                    'SiparisKaynagi' => $filters['siparis_kaynagi'] ?? '',
                    'SiparisNo' => $filters['siparis_no'] ?? null,
                    'SiparisTarihiBas' => $bas,
                    'SiparisTarihiSon' => $son,
                    'StrSiparisDurumu' => '',
                    'TedarikciID' => -1,
                    'UrunGetir' => true,
                    'UyeID' => -1,
                    'UyeTelefon' => $filters['uye_telefon'] ?? null,
                ],
                's' => [
                    'BaslangicIndex' => $startIdx,
                    'KayitSayisi' => $perPage,
                    'SiralamaYonu' => 'DESC',
                ],
            ];
        };

        // TEK STATÜ — direkt SOAP çağrısı
        if ($siparisDurumu >= 0) {
            $resp = $this->client->call('order', $this->method('select'), $buildParams($siparisDurumu));

            return $this->normalizeList($resp, $this->method('select'));
        }

        // HEPSİ MODU — kullanıcı "Hepsi" seçti, tüm bilinen durumları döngüye al + dedup.
        // Performans: 23 SOAP çağrısı. Aynı filtreyle peş peşe tıklamalarda (sayfa
        // değiştir / geri dön) tekrar 23 çağrı yapılmaması için kısa-süreli cache:
        // 60 saniye boyunca aynı (filtre+sayfa+perPage) kombinasyonu cache'ten döner.
        // Cache key: store_key + tüm filtre + page bilgisini içeren hash.
        $storeKey = $this->client->getCredential()->store_key ?? 'unknown';
        $cacheKey = 'ticimax.orders.hepsi.'.$storeKey.'.'.md5(json_encode([
            $bas, $son, $odemeDurumu, $paketlemeDurumu, $odemeTipi, $aktarildi,
            $filters['siparis_id'] ?? 0,
            $filters['siparis_no'] ?? null,
            $filters['siparis_kaynagi'] ?? '',
            $filters['uye_telefon'] ?? null,
            $startIdx, $perPage,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($buildParams, $startIdx, $perPage) {
            $merged = [];
            $seen = [];
            foreach (range(0, 22) as $statusCode) {
                $resp = $this->client->call('order', $this->method('select'), $buildParams($statusCode));
                $batch = $this->normalizeList($resp, $this->method('select'));
                foreach ($batch as $o) {
                    $id = (string) ($o['ID'] ?? $o['SiparisID'] ?? '');
                    if ($id !== '' && ! isset($seen[$id])) {
                        $seen[$id] = true;
                        $merged[] = $o;
                    }
                }
            }
            // Tarihe göre DESC sırala
            usort($merged, function ($a, $b) {
                return strcmp(
                    (string) ($b['SiparisTarihi'] ?? $b['DuzenlemeTarihi'] ?? ''),
                    (string) ($a['SiparisTarihi'] ?? $a['DuzenlemeTarihi'] ?? '')
                );
            });

            return array_slice($merged, $startIdx, $perPage);
        });
    }

    /**
     * Listede ana durumlarını göstermek için kısa-süreli cache'li `getOrderById`.
     * 30 saniye TTL — kullanıcı listede sayfa değiştirip dönerse her seferde
     * yeniden SOAP gitmesin. Modal'lar (durum/ürün edit) her zaman taze veri
     * istediğinden onlar `getOrderById`'yi doğrudan çağırmaya devam etsin.
     */
    public function getOrderByIdCached(int $siparisId, int $ttlSeconds = 30): ?array
    {
        $storeKey = $this->client->getCredential()->store_key ?? 'unknown';
        $cacheKey = "ticimax.order.byid.{$storeKey}.{$siparisId}";

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($siparisId) {
            return $this->getOrderById($siparisId);
        });
    }

    /**
     * Tek bir siparişi ID üzerinden çek (manuel aktarım butonunun arkasındaki çağrı).
     * Bulamazsa null döner.
     */
    public function getOrderById(int $siparisId): ?array
    {
        $orders = $this->getOrdersByFilter([
            'siparis_id' => $siparisId,
            'date_from' => Carbon::now()->subYear()->format('Y-m-d\T00:00:00'),
            'date_to' => Carbon::now()->format('Y-m-d\T23:59:59'),
        ], 1, 1);

        return $orders[0] ?? null;
    }

    /**
     * Ana mağazada sipariş oluştur. Payload ProductMapper::bayiOrderToAnaCreatePayload
     * tarafından gerçek WebSiparis envelope şemasında hazırlanır.
     */
    public function createOrder(array $payload): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'siparis' => $payload,
        ];
        $resp = $this->client->call('order', $this->method('save'), $params);

        // Ticimax SaveSiparis "başarı sayma" mantığı:
        //  • Asıl gösterge SiparisDetayi'nin DOLU olmasıdır (yeni sipariş ID dönüyor)
        //  • IsError=true bazen yumuşak uyarı olarak gelir (örn. "stok hesabı geç güncellendi")
        //    ama sipariş yine de oluşmuş olur. O yüzden SiparisDetayi varsa başarı sayalım.
        //  • Sadece SiparisDetayi NIL ise gerçekten reddetmiş demektir → exception fırlat.
        $result = $resp;
        if (is_object($result) && isset($result->SaveSiparisResult)) {
            $result = $result->SaveSiparisResult;
        }
        $resultArr = is_object($result) ? json_decode(json_encode($result), true) : (array) $result;

        $siparisDetayi = $resultArr['SiparisDetayi'] ?? null;
        // SOAP nil → ['@attributes' => ['nil' => 'true']] veya boş array gelir
        $detayiBos = $siparisDetayi === null
            || (is_array($siparisDetayi) && (
                empty($siparisDetayi)
                || (isset($siparisDetayi['@attributes']['nil']) && filter_var($siparisDetayi['@attributes']['nil'], FILTER_VALIDATE_BOOLEAN))
                || (isset($siparisDetayi['nil']) && filter_var($siparisDetayi['nil'], FILTER_VALIDATE_BOOLEAN))
            ));

        if ($detayiBos) {
            // Gerçek reddedilme → Ticimax hata mesajını topla ve fırlat
            $msg = trim((string) ($resultArr['ErrorMessage'] ?? 'Bilinmeyen Ticimax hatası'));
            $details = $resultArr['Messages']['WebServisResponse'] ?? [];
            if (is_array($details) && ! array_is_list($details)) {
                $details = [$details];
            }
            $subMsgs = [];
            foreach ((array) $details as $d) {
                $code = $d['ErrorCode'] ?? '';
                $emsg = trim((string) ($d['ErrorMessage'] ?? ''));
                if ($emsg !== '') {
                    $subMsgs[] = "  • [{$code}] {$emsg}";
                }
            }
            $full = "Ticimax SaveSiparis reddetti: {$msg}"
                .($subMsgs ? "\n".implode("\n", $subMsgs) : '');
            throw new \RuntimeException($full);
        }

        // BAŞARI — SiparisDetayi dolu. PullBayiOrdersJob ana_order_id'yi okuyabilsin diye
        // SiparisDetayi'ndeki ID'yi üst seviyeye taşıyalım.
        $anaOrderId = null;
        if (is_array($siparisDetayi)) {
            $anaOrderId = $siparisDetayi['ID']
                ?? $siparisDetayi['SiparisID']
                ?? $siparisDetayi['SiparisId']
                ?? null;
        }

        $normalized = $this->normalizeOne($resp) ?? [];
        if ($anaOrderId !== null && ! isset($normalized['SiparisID'])) {
            $normalized['SiparisID'] = (string) $anaOrderId;
        }

        return $normalized;
    }

    /**
     * Aktarım sonrası bayi'deki siparişe "aktarıldı" damgası vur.
     * Eski API'de SetSiparisAktarildi vardı; siparis_aktar.py'de Hasan
     * SetSiparisPaketlemeDurum(2) kullanıyor (paketlendi → bir daha çekilmez).
     * Burada ikisini de destekliyoruz: önce SetSiparisAktarildi dene, yoksa paketleme.
     */
    /**
     * Bu mağazanın WSDL'inde tanımlı WebSiparisDurumlari enum değerlerini dön.
     * Sonuç int index → enum string. Sıra Ticimax HTML panelindeki dropdown ile aynı.
     * Her mağazaya göre farklı olabilir (eski WSDL 19, yeni WSDL 23 değer içerir).
     * 1 gün cache'lenir.
     */
    public function getSupportedSiparisDurumEnums(): array
    {
        return $this->fetchEnumFromWsdl('WebSiparisDurumlari');
    }

    /** Aynı şekilde ödeme durumları (WebOdemeDurumlari). */
    public function getSupportedOdemeDurumEnums(): array
    {
        return $this->fetchEnumFromWsdl('WebOdemeDurumlari');
    }

    /**
     * Sipariş servisinin XSD'lerini taraşı, belirtilen enum tipinin değerlerini index'li array
     * olarak dön: [0 => 'OnSiparis', 1 => 'OnayBekliyor', ...]. 1 gün cache.
     */
    protected function fetchEnumFromWsdl(string $enumType): array
    {
        $cred = $this->client->getCredential();
        $wsdlPath = $cred->wsdl_path_order ?: config('ticimax.wsdl_paths.order');
        $endpoint = preg_match('#^https?://#i', (string) $wsdlPath)
            ? $wsdlPath
            : rtrim($cred->endpoint_url, '/').'/'.ltrim($wsdlPath, '/');
        $base = preg_replace('/\?wsdl$/i', '', $endpoint);
        $cacheKey = "ticimax.enum.{$cred->store_key}.{$enumType}";

        return Cache::remember($cacheKey, now()->addDay(), function () use ($base, $enumType) {
            // Ticimax XSD'leri xsd0, xsd1, ... olarak parçalı dağıtıyor — en fazla 10'a kadar deneriz
            for ($i = 0; $i < 10; $i++) {
                $xml = @file_get_contents($base.'?xsd=xsd'.$i);
                if (! $xml || strpos($xml, '"'.$enumType.'"') === false) {
                    continue;
                }
                // simpleType[name=$enumType] içindeki enumeration value'larını çek
                $doc = new \DOMDocument;
                @$doc->loadXML($xml);
                $xp = new \DOMXPath($doc);
                $xp->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
                $nodes = $xp->query("//xs:simpleType[@name='{$enumType}']//xs:enumeration");
                if ($nodes && $nodes->length > 0) {
                    $result = [];
                    foreach ($nodes as $idx => $node) {
                        $result[$idx] = $node->getAttribute('value');
                    }

                    return $result;
                }
            }

            return [];
        });
    }

    /**
     * Ticimax WSDL enum WebSiparisDurumlari — int index → enum string.
     * Bu mapping XSD'den birebir çekildi (digitalsupport.ticimaxtest.com).
     * 19+ indexli durumlar bu enum'da yok; istersen Ticimax hata döner.
     *
     * NOT: Bu fallback const'tur. Runtime'da getSupportedSiparisDurumEnums() çağrısı
     * canlı WSDL'den çekip cache'ler — yeni Ticimax sürümlerini de destekler.
     */
    public const SIPARIS_DURUM_ENUM = [
        0 => 'OnSiparis',
        1 => 'OnayBekliyor',
        2 => 'Onaylandi',
        3 => 'OdemeBekliyor',
        4 => 'Paketleniyor',
        5 => 'TedarikEdiliyor',
        6 => 'KargoyaVerildi',
        7 => 'TeslimEdildi',
        8 => 'Iptal',
        9 => 'Iade',
        10 => 'Silinmis',
        11 => 'IadeTalepAlindi',
        12 => 'IadeUlastiOdemeYapilacak',
        13 => 'IadeOdemeYapildi',
        14 => 'TeslimOncesiIptal',
        15 => 'IptalTalebi',
        16 => 'KismiIadeTalebi',
        17 => 'KismiIadeYapildi',
        18 => 'TeslimEdilemedi',
    ];

    /** Ticimax WSDL enum WebOdemeDurumlari — int index → enum string. */
    public const ODEME_DURUM_ENUM = [
        0 => 'OnayBekliyor',
        1 => 'Onaylandi',
        2 => 'Hatali',
        3 => 'IadeEdilmis',
        4 => 'IptalEdilmis',
    ];

    /**
     * Siparişin "Sipariş Durumu"nu (SiparisDurumu) güncelle.
     * Kod listesi: 0=Sipariş Alındı, 1=Onay Bekliyor, 2=Onaylandı, 4=Paketleniyor,
     *             6=Kargoya Verildi, 7=Teslim Edildi, 8=İptal Edildi vb.
     */
    public function updateSiparisDurum(int $siparisId, int $durumKodu, string $siparisNo = ''): array
    {
        // Ticimax WebSiparisDurumlari ENUM ister (numeric değil) — önce LIVE WSDL'den çek
        // (yeni Ticimax sürümlerinde ekstra durumlar var), bulunamazsa eski sabit liste fallback.
        $live = $this->getSupportedSiparisDurumEnums();
        $durumEnum = $live[$durumKodu] ?? self::SIPARIS_DURUM_ENUM[$durumKodu] ?? null;
        if ($durumEnum === null) {
            throw new \RuntimeException("Geçersiz sipariş durumu kodu: {$durumKodu} (bu mağazanın WSDL'inde tanımlı değil).");
        }

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'request' => [
                'Durum' => $durumEnum,
                'KargoTakipLink' => '',
                'KargoTakipNo' => '',
                'MailBilgilendir' => false,
                'PreventStockOperation' => false,
                'SiparisID' => $siparisId,
                'SiparisNo' => $siparisNo,
            ],
        ];
        $resp = $this->client->call('order', 'SetSiparisDurum', $params);

        return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisDurum', 'durum' => $durumEnum];
    }

    /**
     * Siparişin "Paketleme Durumu"nu güncelle.
     * Kod listesi: 1=Beklemede, 2=Paketleniyor, 3=Eksik Ürün, 4=Fatura Bekliyor,
     *             5=Fatura Kesildi, 6=Eksik Gönderildi.
     */
    public function updatePaketlemeDurum(int $siparisId, int $paketlemeKodu): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'SiparisId' => $siparisId,
            'PaketlemeDurumId' => $paketlemeKodu,
        ];
        $resp = $this->client->call('order', 'SetSiparisPaketlemeDurum', $params);

        return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisPaketlemeDurum'];
    }

    /**
     * Siparişin "Ödeme Durumu"nu güncelle. SetSiparisOdemeDurum OdemeId zorunlu ister;
     * verilmezse SelectSiparisOdeme ile ilk ödeme kaydını bulup ID'yi çıkartırız.
     * Kod listesi: 0=Onay Bekliyor, 1=Onaylandı, 2=Hatalı, 3=İade, 4=İptal,
     *             5=Ödeme Bekliyor, 6=Ödeme Talep Edildi.
     */
    public function updateOdemeDurum(int $siparisId, int $odemeDurumKodu, ?int $odemeId = null): array
    {
        if (! $odemeId) {
            // Önce hızlı yol: siparişi getOrderById ile çek, Odemeler.WebSiparisOdeme.ID oku.
            // Bulamazsak SelectSiparisOdeme'a düş.
            $siparis = $this->getOrderById($siparisId);
            if ($siparis) {
                $ode = $siparis['Odemeler']['WebSiparisOdeme'] ?? null;
                if (is_array($ode) && array_is_list($ode)) {
                    $ode = $ode[0] ?? null;
                }
                if (is_array($ode) && isset($ode['ID']) && is_numeric($ode['ID'])) {
                    $odemeId = (int) $ode['ID'];
                }
            }

            if (! $odemeId) {
                // SelectSiparisOdeme fallback
                $lookup = [
                    'UyeKodu' => $this->client->getUyeKodu(),
                    'siparisId' => $siparisId,
                    'odemeId' => 0,
                    'isAktarildi' => false,
                ];
                $odemeResp = $this->client->call('order', 'SelectSiparisOdeme', $lookup);
                $arr = is_object($odemeResp) ? json_decode(json_encode($odemeResp), true) : (array) $odemeResp;
                $odemeId = $this->findOdemeId($arr);
            }

            if (! $odemeId) {
                throw new \RuntimeException("Sipariş #{$siparisId} için ödeme kaydı bulunamadı (OdemeId alınamadı).");
            }
        }

        // Ticimax WebOdemeDurumlari ENUM ister — önce live WSDL, sonra const fallback
        $live = $this->getSupportedOdemeDurumEnums();
        $odemeDurumEnum = $live[$odemeDurumKodu] ?? self::ODEME_DURUM_ENUM[$odemeDurumKodu] ?? null;
        if ($odemeDurumEnum === null) {
            throw new \RuntimeException("Geçersiz ödeme durumu kodu: {$odemeDurumKodu} (bu mağazanın WSDL'inde tanımlı değil).");
        }

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'request' => [
                'BilgiMailiGonderme' => false,
                'OdemeDurum' => $odemeDurumEnum,
                'OdemeId' => $odemeId,
                'SiparisId' => $siparisId,
            ],
        ];
        $resp = $this->client->call('order', 'SetSiparisOdemeDurum', $params);

        return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisOdemeDurum', 'odeme_id' => $odemeId, 'durum' => $odemeDurumEnum];
    }

    /** SelectSiparisOdeme yanıtında ilk ödeme kaydının ID'sini bul. */
    protected function findOdemeId(array $data): ?int
    {
        if (isset($data['ID']) && is_numeric($data['ID'])) {
            return (int) $data['ID'];
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = $this->findOdemeId($v);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function markOrderTransferred(string $orderId, ?string $externalRef = null): array
    {
        try {
            $params = [
                'UyeKodu' => $this->client->getUyeKodu(),
                'siparisId' => (int) $orderId,
                'aktarilanSiparisKodu' => $externalRef ?? '',
            ];
            $resp = $this->client->call('order', $this->method('mark_transferred'), $params);

            return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisAktarildi'];
        } catch (\Throwable $e) {
            // Fallback: paketleme durumunu 2 yap → bir daha "yeni" filtresine girmez.
            $params = [
                'UyeKodu' => $this->client->getUyeKodu(),
                'SiparisId' => (int) $orderId,
                'PaketlemeDurumId' => 2,
            ];
            $resp = $this->client->call('order', 'SetSiparisPaketlemeDurum', $params);

            return $this->normalizeOne($resp) ?? ['method' => 'SetSiparisPaketlemeDurum', 'fallback_reason' => $e->getMessage()];
        }
    }

    protected function method(string $key): string
    {
        return (string) config("ticimax.methods.order.{$key}");
    }

    protected function normalizeList(mixed $resp, string $method = ''): array
    {
        $resultKey = $method.'Result';
        if (is_object($resp) && isset($resp->{$resultKey})) {
            $resp = $resp->{$resultKey};
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        if (! is_array($resp)) {
            return [];
        }
        // WebSiparis (yeni) veya Siparis (eski) wrapper'ı
        foreach (['WebSiparis', 'Siparis'] as $key) {
            if (isset($resp[$key])) {
                $resp = is_array($resp[$key]) && array_is_list($resp[$key]) ? $resp[$key] : [$resp[$key]];
                break;
            }
        }
        if (! array_is_list($resp)) {
            $resp = [$resp];
        }

        return array_map(fn ($r) => $this->toArray($r), $resp);
    }

    protected function normalizeOne(mixed $resp): ?array
    {
        return $resp ? $this->toArray($resp) : null;
    }

    protected function toArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_map(fn ($x) => is_object($x) || is_array($x) ? $this->toArray($x) : $x, $v);
        }
        if (is_object($v)) {
            return $this->toArray((array) $v);
        }

        return [];
    }
}
