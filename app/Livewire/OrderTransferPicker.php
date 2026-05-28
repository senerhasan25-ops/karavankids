<?php

namespace App\Livewire;

use App\Jobs\TransferSingleBayiOrderJob;
use App\Models\OrderTransfer;
use App\Services\Ticimax\OrderService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Manuel sipariş aktarım paneli — bayi'deki siparişleri filtre ile listeler ve
 * her satırın yanındaki butonla tek tek aktarımı tetikler.
 *
 * Filtreler: tarih aralığı, sipariş no, ödeme tipi, aktarılma durumu.
 */
#[Title('Sipariş Aktarım Paneli')]
#[Layout('layouts.app')]
class OrderTransferPicker extends Component
{
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $siparisNo = '';
    public string $aliciAdi = '';    // istemci-taraflı arama (Ticimax SOAP filtresi desteklemiyor)
    public string $aliciMail = '';   // istemci-taraflı arama
    public string $telefon = '';     // Ticimax UyeTelefon filtresi var, SOAP tarafında uygulanır
    // -1 = Hepsi (filtre yok). Ticimax bu kodu valid bir durum olarak tanımamış,
    // server'da ignore edilir (veya getOrdersByFilter'da SOAP'a hiç eklenmez).
    public int $odemeTipi = -1;
    public int $odemeDurumu = -1;
    public int $siparisDurumu = -1;
    public int $paketlemeDurumu = -1;
    public int $aktarildi = -1;     // -1 = hepsi, 0 = aktarılmamış, 1 = aktarılmış
    public int $page = 1;
    public int $perPage = 25;

    public array $orders = [];
    public bool $loading = false;
    public ?string $lastError = null;
    public bool $hasSearched = false;

    // === ÜRÜN DÜZENLEME MODAL'I ===
    // Modal açıkken hangi sipariş düzenleniyor; ürün satırları state'te tutulur.
    // Kaydet'e basılınca order_transfers.product_overrides'a yazılır,
    // 'Aktar' job'u bunu okuyup Urunler listesini değiştirir.
    public ?string $editingBayiOrderId = null;
    public bool $showEditor = false;
    public bool $editorLoading = false;
    public ?string $editorError = null;
    /** @var array<int, array{stok_kodu:string,urun_adi:string,adet:int,birim_fiyat:float,removed:bool}> */
    public array $editingLines = [];
    public bool $hasOverride = false; // bu sipariş için önceden override kaydı var mı?

    // === DURUM GÜNCELLEME MODAL'I ===
    // Bayi VEYA ana'da siparişin Sipariş/Ödeme/Paketleme durumunu değiştirmek için.
    // Her tarafa ayrı dropdown seti (Bayi tab'ı her zaman aktif; Ana sadece aktarılmışsa).
    public ?string $statusEditingBayiOrderId = null;
    public bool $showStatusEditor = false;
    public ?string $statusEditorError = null;
    public ?string $statusEditorSuccess = null;
    public bool $statusSaving = false;

    // Hangi tarafı düzenliyoruz: 'bayi' veya 'ana'
    public string $statusEditTarget = 'bayi';
    // Ana'da düzenleme için aktarım sırasında alınan ana_order_id (yoksa null)
    public ?string $statusEditAnaOrderId = null;

    // Dropdown değerleri (kullanıcı modal'da seçer); -1 = "Değiştirme"
    public int $editSiparisDurumu = -1;
    public int $editOdemeDurumu = -1;
    public int $editPaketlemeDurumu = -1;
    public string $editSiparisNo = '';

    // Hangi durum kodları HANGİ tarafta destekleniyor (WSDL'den dinamik çekilir, cache'lenir)
    public array $bayiSupportedSiparisCodes = [];
    public array $anaSupportedSiparisCodes = [];
    public array $bayiSupportedOdemeCodes = [];
    public array $anaSupportedOdemeCodes = [];

    // === DIAGNOSTIC (Aktarım Detayı) MODAL'I ===
    // Aktarım başarılı veya başarısız olsun, SyncLog'tan kayıtları çekip
    // SOAP request/response XML'i + hata trace'i kullanıcıya gösterir.
    public ?string $diagBayiOrderId = null;
    public bool $showDiagnostics = false;
    public array $diagLogs = []; // [{status, message, raw_request, raw_response, created_at, action}]

    // Ticimax panel terminolojisi ile birebir aynı etiketler.
    // Numeric kodlar Ticimax SelectSiparis filtresine SOAP üzerinden geçer; sürüme göre
    // değişebilir — etiketler değişirse buradan güncelle.

    public array $odemeTipleri = [
        -1 => 'Hepsi',
        0 => 'Kredi Kartı',
        1 => 'Havale / EFT',
        2 => 'Kapıda Nakit Ödeme',
        3 => 'Hediye Çeki',
        4 => 'Pazaryeri',
        10 => 'Diğer',
    ];

    // Ödeme Durumu — Ticimax bayi panelindeki sıraya göre (Ali'nin gönderdiği screenshot)
    // Ödeme Durumu — Ticimax HTML inspect'ten gerçek option value'ları (id=ddl_Odeme_Durum)
    public array $odemeDurumlari = [
        -1 => 'Hepsi',
        0 => 'Onay Bekliyor',
        1 => 'Onaylandı',
        2 => 'Hatalı',
        3 => 'İade Edilmiş',
        4 => 'İptal Edilmiş',
        5 => 'Ödeme Bekliyor',
        6 => 'Ödeme Talep Edildi',
    ];

    // Sipariş Durumu — Ticimax HTML inspect'ten gerçek 23 durum (id=ddl_Siparis_Durum)
    // DİKKAT: 0 = "Sipariş Alındı" (bizim eski default 0 = "Hepsi" varsayımımız YANLIŞTI,
    // sadece Sipariş Alındı statüsündeki siparişleri görüyorduk). -1 = Hepsi olarak ekledik.
    public array $siparisDurumlari = [
        -1 => 'Hepsi',
        0 => 'Sipariş Alındı',
        1 => 'Onay Bekliyor',
        2 => 'Onaylandı',
        3 => 'Ödeme Bekliyor',
        4 => 'Paketleniyor',
        5 => 'Tedarik Ediliyor',
        6 => 'Kargoya Verildi',
        7 => 'Teslim Edildi',
        8 => 'İptal Edildi',
        9 => 'İade Edildi',
        10 => 'Silinmiş',
        11 => 'İade Talebi Alındı',
        12 => 'İade Ulaştı Ödeme Yapılacak',
        13 => 'İade Ödemesi Yapıldı',
        14 => 'Teslimat Öncesi İptal Talebi',
        15 => 'İptal Talebi',
        16 => 'Kısmı İade Talebi',
        17 => 'Kısmı İade Yapıldı',
        18 => 'Teslim Edilemedi',
        19 => 'Mağazaya Gönderildi',
        20 => 'Mağazaya Ulaştı',
        21 => 'Mağazada Teslim Bekliyor',
        22 => 'Cüzdana İade',
    ];

    // Paketleme Durumu — Ticimax HTML inspect'ten gerçek 6 durum (id=ddl_Paketleme_Durum)
    // Kodlar 1'den başlar (0 yok), bu yüzden Hepsi için -1.
    public array $paketlemeDurumlari = [
        -1 => 'Hepsi',
        1 => 'Beklemede',
        2 => 'Paketleniyor',
        3 => 'Eksik Ürün',
        4 => 'Fatura Bekliyor',
        5 => 'Fatura Kesildi',
        6 => 'Eksik Gönderildi',
    ];

    public function mount(): void
    {
        $this->dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->dateTo = Carbon::now()->format('Y-m-d');
    }

    public function listele(): void
    {
        $this->loading = true;
        $this->lastError = null;
        $this->hasSearched = true;

        try {
            $bayi = OrderService::for('bayi');

            $filters = [
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'aktarildi' => $this->aktarildi,
                'odeme_tipi' => $this->odemeTipi,
                'odeme_durumu' => $this->odemeDurumu,
                'siparis_durumu' => $this->siparisDurumu,
                'paketleme_durumu' => $this->paketlemeDurumu,
            ];
            if (trim($this->siparisNo) !== '') {
                $filters['siparis_no'] = trim($this->siparisNo);
            }
            // Telefon Ticimax tarafında SOAP filtresiyle uygulanır (UyeTelefon)
            if (trim($this->telefon) !== '') {
                $filters['uye_telefon'] = trim($this->telefon);
            }

            $raw = $bayi->getOrdersByFilter($filters, $this->page, $this->perPage);

            // İSTEMCİ-TARAFLI FİLTRE (Ticimax SOAP destekleminediği alanlar)
            // Alıcı adı/mail için case-insensitive partial match — Türkçe karakterler dahil.
            $needleAdi = trim($this->aliciAdi);
            $needleMail = trim($this->aliciMail);
            if ($needleAdi !== '' || $needleMail !== '') {
                $raw = array_values(array_filter($raw, function ($o) use ($needleAdi, $needleMail) {
                    if ($needleAdi !== '') {
                        $alici = (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? '');
                        if (mb_stripos($alici, $needleAdi) === false) {
                            return false;
                        }
                    }
                    if ($needleMail !== '') {
                        $mail = (string) ($o['Mail'] ?? $o['UyeMail'] ?? '');
                        if (mb_stripos($mail, $needleMail) === false) {
                            return false;
                        }
                    }
                    return true;
                }));
            }

            // Hangi sipariş ID'leri zaten yerel olarak aktarılmış?
            $bayiIds = array_filter(array_map(
                fn ($o) => (string) ($o['ID'] ?? $o['SiparisID'] ?? ''),
                $raw
            ));
            $localStatus = OrderTransfer::whereIn('bayi_order_id', $bayiIds)
                ->get(['bayi_order_id', 'ana_order_id', 'status', 'transferred_at', 'last_error', 'product_overrides'])
                ->keyBy('bayi_order_id');

            // Ticimax SOAP iki ayrı alan dönüyor:
            //   SiparisDurumu / PaketlemeDurumu = METİN ("Onaylandı")
            //   Durum / PaketlemeDurumuID       = SAYI (2, 1)
            // Görüntülemek için SAYIYI istiyoruz (siparisDurumlari lookup map'ine girer).
            $this->orders = array_map(function ($o) use ($localStatus) {
                $id = (string) ($o['ID'] ?? $o['SiparisID'] ?? '');
                $local = $localStatus->get($id);
                // Ticimax ödeme verisi gerçek path: $o['Odemeler']['WebSiparisOdeme']
                // Bu array (multi-payment) veya tek obje olabilir — ilk kaydı al.
                $ode = $this->extractFirstOdeme($o);
                // OdemeDurumu metni gelmeyebilir; Onaylandi=1 ise "Onaylandı" (kod 1), aksi takdirde boş bırak.
                $odemeDurumuKod = isset($ode['Onaylandi']) && (int) $ode['Onaylandi'] === 1 ? '1' : '';
                return [
                    'id' => $id,
                    'siparis_no' => (string) ($o['SiparisNo'] ?? $o['SiparisKodu'] ?? ''),
                    'tarih' => (string) ($o['SiparisTarihi'] ?? $o['DuzenlemeTarihi'] ?? ''),
                    'alici' => (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? ''),
                    'mail' => (string) ($o['Mail'] ?? $o['UyeMail'] ?? ''),
                    'telefon' => (string) ($o['Telefon'] ?? $o['UyeCep'] ?? ''),
                    'tutar' => (float) ($o['OdenenTutar'] ?? $o['SiparisToplamTutari'] ?? 0),
                    'odeme_tipi' => isset($ode['OdemeTipi']) && is_numeric($ode['OdemeTipi']) ? (string) $ode['OdemeTipi'] : '',
                    'odeme_durumu' => $odemeDurumuKod,
                    // SAYISAL Durum'u öncelikle al — SiparisDurumu metin gelir ve (int)'den 0 olur (display bug'ı kaynağı)
                    'siparis_durumu' => isset($o['Durum']) && is_numeric($o['Durum'])
                        ? (string) $o['Durum']
                        : (is_numeric($o['SiparisDurumu'] ?? null) ? (string) $o['SiparisDurumu'] : ''),
                    'paketleme_durumu' => isset($o['PaketlemeDurumuID']) && is_numeric($o['PaketlemeDurumuID'])
                        ? (string) $o['PaketlemeDurumuID']
                        : (isset($o['PaketlemeDurumuId']) && is_numeric($o['PaketlemeDurumuId']) ? (string) $o['PaketlemeDurumuId'] : ''),
                    'entegrasyon_aktarildi' => (bool) ($o['EntegrasyonAktarildi'] ?? false),
                    'local_status' => $local?->status,
                    'local_ana_id' => $local?->ana_order_id,
                    'local_error' => $local?->last_error,
                    'has_override' => $local && ! empty($local->product_overrides),
                    // ANA durumları aşağıda dolduracağız
                    'ana_odeme_tipi' => null,
                    'ana_odeme_durumu' => null,
                    'ana_siparis_durumu' => null,
                    'ana_paketleme_durumu' => null,
                ];
            }, $raw);

            // Aktarılmış siparişler için ANA mağazadan durumları da çek
            // (sadece transferred + ana_order_id'si olanlar — performans için ek SOAP zorunlu).
            $transferredAnaIds = array_filter(array_map(
                fn ($o) => $o['local_status'] === 'transferred' && $o['local_ana_id']
                    ? (int) $o['local_ana_id']
                    : null,
                $this->orders
            ));

            if (! empty($transferredAnaIds)) {
                try {
                    $anaService = OrderService::for('ana');
                    foreach ($this->orders as $i => $row) {
                        if (! $row['local_ana_id'] || $row['local_status'] !== 'transferred') {
                            continue;
                        }
                        try {
                            // Cache'li çağrı — listede gezinirken her sayfa değişiminde
                            // tekrar SOAP yapılmasın (30sn TTL).
                            $anaO = $anaService->getOrderByIdCached((int) $row['local_ana_id']);
                            if (! $anaO) {
                                continue;
                            }
                            $anaOde = $this->extractFirstOdeme($anaO);
                            $this->orders[$i]['ana_odeme_tipi'] = isset($anaOde['OdemeTipi']) && is_numeric($anaOde['OdemeTipi']) ? (string) $anaOde['OdemeTipi'] : '';
                            $this->orders[$i]['ana_odeme_durumu'] = isset($anaOde['Onaylandi']) && (int) $anaOde['Onaylandi'] === 1 ? '1' : '';
                            $this->orders[$i]['ana_siparis_durumu'] = isset($anaO['Durum']) && is_numeric($anaO['Durum'])
                                ? (string) $anaO['Durum']
                                : '';
                            $this->orders[$i]['ana_paketleme_durumu'] = isset($anaO['PaketlemeDurumuID']) && is_numeric($anaO['PaketlemeDurumuID'])
                                ? (string) $anaO['PaketlemeDurumuID']
                                : '';
                        } catch (Throwable $e) {
                            // tek sipariş patlarsa diğerleri devam etsin
                        }
                    }
                } catch (Throwable $e) {
                    // Ana mağaza servisi hiç ayağa kalkmazsa sessiz geç (bayi listesi yine görünür)
                }
            }
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->orders = [];
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Modal'ı aç → siparişi bayi'den taze çek → ürün satırlarını state'e koy.
     * Önceden kayıtlı override varsa onu uygula (adet değişiklikleri, silinmişler).
     */
    public function openEditor(string $bayiOrderId): void
    {
        $this->editingBayiOrderId = $bayiOrderId;
        $this->showEditor = true;
        $this->editorLoading = true;
        $this->editorError = null;
        $this->editingLines = [];
        $this->hasOverride = false;

        try {
            $bayi = OrderService::for('bayi');
            $o = $bayi->getOrderById((int) $bayiOrderId);
            if (! $o) {
                throw new \RuntimeException("Bayi'de #{$bayiOrderId} ID'li sipariş bulunamadı.");
            }

            // Ürünleri normalize et (flattenOrderLines mantığı)
            $urunler = $o['Urunler'] ?? [];
            if (isset($urunler['WebSiparisUrun'])) {
                $urunler = is_array($urunler['WebSiparisUrun']) && array_is_list($urunler['WebSiparisUrun'])
                    ? $urunler['WebSiparisUrun']
                    : [$urunler['WebSiparisUrun']];
            } elseif (! array_is_list((array) $urunler) && ! empty($urunler)) {
                $urunler = [$urunler];
            }

            // Var olan override'ı oku (varsa)
            $existing = OrderTransfer::where('bayi_order_id', $bayiOrderId)->first();
            $overrideMap = [];
            if ($existing && is_array($existing->product_overrides ?? null)) {
                foreach ($existing->product_overrides['lines'] ?? [] as $ovr) {
                    $sk = (string) ($ovr['stok_kodu'] ?? '');
                    if ($sk !== '') {
                        $overrideMap[$sk] = $ovr;
                    }
                }
                $this->hasOverride = ! empty($overrideMap);
            }

            foreach ((array) $urunler as $line) {
                $stokKodu = (string) ($line['StokKodu'] ?? '');
                if ($stokKodu === '') {
                    continue;
                }
                $ovr = $overrideMap[$stokKodu] ?? null;
                $this->editingLines[] = [
                    'stok_kodu' => $stokKodu,
                    'urun_adi' => (string) ($line['UrunAdi'] ?? $line['Urun']['Adi'] ?? '—'),
                    'adet' => (int) ($ovr['adet'] ?? $line['Adet'] ?? 1),
                    'birim_fiyat' => (float) ($line['Tutar'] ?? $line['BirimFiyat'] ?? $line['SatisFiyati'] ?? 0),
                    'removed' => (bool) ($ovr['removed'] ?? false),
                ];
            }
        } catch (Throwable $e) {
            $this->editorError = $e->getMessage();
        } finally {
            $this->editorLoading = false;
        }
    }

    public function closeEditor(): void
    {
        $this->editingBayiOrderId = null;
        $this->showEditor = false;
        $this->editingLines = [];
        $this->editorError = null;
        $this->hasOverride = false;
    }

    /** Satırı "silinmiş" işaretle — tamamen array'den çıkarmıyoruz ki kullanıcı geri alabilsin. */
    public function toggleRemoveLine(int $idx): void
    {
        if (isset($this->editingLines[$idx])) {
            $this->editingLines[$idx]['removed'] = ! ($this->editingLines[$idx]['removed'] ?? false);
        }
    }

    /** Düzenlemeleri DB'ye kaydet — sadece "değiştirilmiş" satırları override olarak yaz. */
    public function saveEdits(): void
    {
        if (! $this->editingBayiOrderId) {
            return;
        }

        // Sadece değişikliği olan satırları override'a kaydet (sapma yoksa kayıt da kalmaz)
        $overrideLines = [];
        foreach ($this->editingLines as $line) {
            // Eğer kullanıcı bir şey değiştirmemişse (orijinal adetle aynı + silinmemiş) → skip
            // Burada "ne kadar değişti" anlamak için orijinal payload'ı tekrar okumamız gerekir,
            // basitlik için: kullanıcı bu ekrandan saveEdits'e basıyorsa, ekranda gördüğü her satırı kaydet.
            // (Override mantığı: stok_kodu = anahtar, adet/removed her zaman uygulanır.)
            $overrideLines[] = [
                'stok_kodu' => (string) $line['stok_kodu'],
                'adet' => max(1, (int) $line['adet']),
                'removed' => (bool) ($line['removed'] ?? false),
            ];
        }

        $transfer = OrderTransfer::firstOrNew(['bayi_order_id' => $this->editingBayiOrderId]);
        $transfer->product_overrides = ['lines' => $overrideLines, 'edited_at' => now()->toIso8601String()];
        // Yeni kayıt ise status 'pending' kalsın
        if (! $transfer->exists) {
            $transfer->status = 'pending';
        }
        $transfer->save();

        session()->flash('status', "#{$this->editingBayiOrderId} için ürün düzenlemeleri kaydedildi. Şimdi 'Aktar' butonuna basabilirsin.");
        $this->closeEditor();
    }

    /** Override'ı tamamen sıfırla — orijinal bayi siparişi olduğu gibi aktarılır. */
    public function clearOverride(): void
    {
        if (! $this->editingBayiOrderId) {
            return;
        }
        $transfer = OrderTransfer::where('bayi_order_id', $this->editingBayiOrderId)->first();
        if ($transfer) {
            $transfer->product_overrides = null;
            $transfer->save();
        }
        session()->flash('status', "#{$this->editingBayiOrderId} için düzenlemeler temizlendi (orijinal sipariş aktarılacak).");
        $this->closeEditor();
    }

    /**
     * Durum güncelleme modal'ını aç. Mevcut durumları $orders state'inden okur
     * ki kullanıcı önce mevcut değeri görür, sonra değiştirir.
     */
    public function openStatusEditor(string $bayiOrderId): void
    {
        $this->statusEditingBayiOrderId = $bayiOrderId;
        $this->showStatusEditor = true;
        $this->statusEditorError = null;
        $this->statusEditorSuccess = null;
        $this->statusEditTarget = 'bayi';
        $this->statusEditAnaOrderId = null;
        $this->editSiparisDurumu = -1;
        $this->editOdemeDurumu = -1;
        $this->editPaketlemeDurumu = -1;
        $this->editSiparisNo = '';

        foreach ($this->orders as $o) {
            if ((string) $o['id'] === (string) $bayiOrderId) {
                // Mevcut değerleri ön-yükle (kullanıcı sadece değiştireceğini değiştirsin)
                $this->editSiparisDurumu = $o['siparis_durumu'] !== '' ? (int) $o['siparis_durumu'] : -1;
                $this->editOdemeDurumu = $o['odeme_durumu'] !== '' ? (int) $o['odeme_durumu'] : -1;
                $this->editPaketlemeDurumu = $o['paketleme_durumu'] !== '' ? (int) $o['paketleme_durumu'] : -1;
                $this->editSiparisNo = (string) ($o['siparis_no'] ?? '');
                $this->statusEditAnaOrderId = $o['local_ana_id'] ?? null;
                break;
            }
        }

        // Her iki mağazanın WSDL'inden desteklenen enum kodlarını çek (cache'li, 1 günlük)
        try {
            $bayi = OrderService::for('bayi');
            $this->bayiSupportedSiparisCodes = array_keys($bayi->getSupportedSiparisDurumEnums());
            $this->bayiSupportedOdemeCodes = array_keys($bayi->getSupportedOdemeDurumEnums());
        } catch (Throwable $e) {
            // sessiz geç — fallback const her zaman çalışır
            $this->bayiSupportedSiparisCodes = array_keys(\App\Services\Ticimax\OrderService::SIPARIS_DURUM_ENUM);
            $this->bayiSupportedOdemeCodes = array_keys(\App\Services\Ticimax\OrderService::ODEME_DURUM_ENUM);
        }
        try {
            $ana = OrderService::for('ana');
            $this->anaSupportedSiparisCodes = array_keys($ana->getSupportedSiparisDurumEnums());
            $this->anaSupportedOdemeCodes = array_keys($ana->getSupportedOdemeDurumEnums());
        } catch (Throwable $e) {
            $this->anaSupportedSiparisCodes = array_keys(\App\Services\Ticimax\OrderService::SIPARIS_DURUM_ENUM);
            $this->anaSupportedOdemeCodes = array_keys(\App\Services\Ticimax\OrderService::ODEME_DURUM_ENUM);
        }
    }

    public function closeStatusEditor(): void
    {
        $this->showStatusEditor = false;
        $this->statusEditingBayiOrderId = null;
        $this->statusEditorError = null;
        $this->statusEditorSuccess = null;
        $this->statusSaving = false;
    }

    public function setStatusTarget(string $target): void
    {
        if (in_array($target, ['bayi', 'ana'], true)) {
            $this->statusEditTarget = $target;
            $this->statusEditorError = null;
            $this->statusEditorSuccess = null;
        }
    }

    /**
     * Modal'daki seçimlere göre SOAP çağrılarını yap. -1 olan alanlar atlanır.
     * Bayi VEYA ana hedefini günceller — kullanıcı tab'dan seçer, her iki tarafı
     * tek seferde yapmak istiyorsa iki kere "Kaydet"e basar.
     */
    public function saveStatusUpdates(): void
    {
        if (! $this->statusEditingBayiOrderId) {
            return;
        }
        $this->statusEditorError = null;
        $this->statusEditorSuccess = null;
        $this->statusSaving = true;

        try {
            $service = OrderService::for($this->statusEditTarget);

            $orderId = $this->statusEditTarget === 'bayi'
                ? (int) $this->statusEditingBayiOrderId
                : (int) ($this->statusEditAnaOrderId ?? 0);

            if (! $orderId) {
                throw new \RuntimeException("Ana siparişi henüz aktarılmamış — ana tarafında güncelleme yapılamaz. Önce siparişi aktar.");
            }

            $updates = [];
            if ($this->editSiparisDurumu >= 0) {
                $service->updateSiparisDurum($orderId, $this->editSiparisDurumu, $this->editSiparisNo);
                $updates[] = "Sipariş Durumu → " . ($this->siparisDurumlari[$this->editSiparisDurumu] ?? $this->editSiparisDurumu);
            }
            if ($this->editPaketlemeDurumu >= 1) {
                $service->updatePaketlemeDurum($orderId, $this->editPaketlemeDurumu);
                $updates[] = "Paketleme Durumu → " . ($this->paketlemeDurumlari[$this->editPaketlemeDurumu] ?? $this->editPaketlemeDurumu);
            }
            if ($this->editOdemeDurumu >= 0) {
                $service->updateOdemeDurum($orderId, $this->editOdemeDurumu);
                $updates[] = "Ödeme Durumu → " . ($this->odemeDurumlari[$this->editOdemeDurumu] ?? $this->editOdemeDurumu);
            }

            if (empty($updates)) {
                $this->statusEditorError = 'Hiçbir alan değiştirilmedi.';
            } else {
                $hedef = $this->statusEditTarget === 'bayi' ? 'Bayi' : 'Ana';
                $this->statusEditorSuccess = "✓ {$hedef} güncellendi: " . implode(' · ', $updates);
                // Listeyi tazele (yeni durumlar görünsün)
                $this->listele();
            }
        } catch (Throwable $e) {
            $this->statusEditorError = $e->getMessage();
        } finally {
            $this->statusSaving = false;
        }
    }

    public function aktar(string $bayiOrderId, bool $force = false): void
    {
        TransferSingleBayiOrderJob::dispatch($bayiOrderId, $force);
        session()->flash('status', "#{$bayiOrderId} aktarım kuyruğa alındı. Loglar sekmesinden takip edebilirsin.");

        // Yerel durumu hemen "queued" göster ki kullanıcı butona tekrar basmasın
        foreach ($this->orders as $i => $o) {
            if ($o['id'] === $bayiOrderId) {
                $this->orders[$i]['local_status'] = 'queued';
            }
        }
    }

    // === TOPLU AKTARIM ===
    // Kullanıcı checkbox'larla birden fazla siparişi seçip tek butonla aktarabilir.
    // Her seçili sipariş için ayrı bir job dispatch edilir (paralel çalışma — kuyruk worker'ı kadar).
    public array $selectedBayiIds = [];

    /** "Tümünü seç" toggle — sadece "aktarılmamış" siparişleri seçer (transferred/queued olanlar atlanır). */
    public function toggleSelectAll(): void
    {
        $eligible = array_values(array_filter(array_map(
            fn ($o) => in_array($o['local_status'], [null, 'failed', 'pending'], true) ? (string) $o['id'] : null,
            $this->orders
        )));
        // Hepsi zaten seçiliyse temizle, değilse tümünü seç
        $allSelected = ! empty($eligible) && empty(array_diff($eligible, $this->selectedBayiIds));
        $this->selectedBayiIds = $allSelected ? [] : $eligible;
    }

    public function clearSelection(): void
    {
        $this->selectedBayiIds = [];
    }

    /** Seçilenleri sırayla kuyruğa al. Worker birden fazla iş paralel çalıştırırsa otomatik paralelleşir. */
    public function topluAktar(bool $force = false): void
    {
        $count = 0;
        $skipped = 0;
        foreach ($this->selectedBayiIds as $bayiOrderId) {
            // Sadece henüz aktarılmamış olanları kuyruğa al — yanlışlıkla zaten 'transferred' olanları
            // tekrar dispatch etmeyelim (yine de force ile geçilebilsin).
            $row = null;
            foreach ($this->orders as $o) {
                if ((string) $o['id'] === (string) $bayiOrderId) {
                    $row = $o;
                    break;
                }
            }
            if ($row && in_array($row['local_status'], ['transferred', 'queued'], true) && ! $force) {
                $skipped++;
                continue;
            }
            TransferSingleBayiOrderJob::dispatch((string) $bayiOrderId, $force);
            $count++;
            // UI'da hemen "queued" görünsün ki kullanıcı tekrar bekliyor mu diye merak etmesin
            foreach ($this->orders as $i => $o) {
                if ((string) $o['id'] === (string) $bayiOrderId) {
                    $this->orders[$i]['local_status'] = 'queued';
                }
            }
        }
        $msg = "{$count} sipariş aktarım kuyruğuna alındı.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} sipariş zaten aktarılmış/kuyrukta olduğundan atlandı.)";
        }
        session()->flash('status', $msg);
        $this->selectedBayiIds = [];
    }

    public function sayfaSonraki(): void
    {
        $this->page++;
        $this->listele();
    }

    public function sayfaOnceki(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->listele();
        }
    }

    public function resetFilters(): void
    {
        $this->dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->dateTo = Carbon::now()->format('Y-m-d');
        $this->siparisNo = '';
        $this->aliciAdi = '';
        $this->aliciMail = '';
        $this->telefon = '';
        $this->odemeTipi = -1;
        $this->odemeDurumu = -1;
        $this->siparisDurumu = -1;
        $this->paketlemeDurumu = -1;
        $this->aktarildi = -1;
        $this->page = 1;
        $this->orders = [];
        $this->hasSearched = false;
    }

    /**
     * Aktarım detay modal'ını aç — bu bayi siparişine ait son SyncLog kayıtlarını yükler.
     * Hem başarılı (action=transfer_order_manual/transfer_order, status=success) hem
     * başarısız (status=error) kayıtları gösterir.
     */
    public function openDiagnostics(string $bayiOrderId): void
    {
        $this->diagBayiOrderId = $bayiOrderId;
        $this->showDiagnostics = true;

        $logs = \App\Models\SyncLog::where('bayi_id', $bayiOrderId)
            ->whereIn('action', ['transfer_order', 'transfer_order_manual', 'mark_order'])
            ->latest('id')
            ->take(5)
            ->get(['id', 'action', 'status', 'message', 'raw_request', 'raw_response', 'created_at']);

        $this->diagLogs = $logs->map(fn ($l) => [
            'id' => $l->id,
            'action' => $l->action,
            'status' => $l->status,
            'message' => $l->message,
            'raw_request' => $l->raw_request,
            'raw_response' => $l->raw_response,
            'created_at' => $l->created_at?->format('Y-m-d H:i:s'),
        ])->toArray();
    }

    public function closeDiagnostics(): void
    {
        $this->showDiagnostics = false;
        $this->diagBayiOrderId = null;
        $this->diagLogs = [];
    }

    /**
     * Ticimax SOAP yanıtında ödeme bilgisi $o['Odemeler']['WebSiparisOdeme'] altında.
     * Tek ödeme varsa direkt obje, birden fazla varsa array of objects.
     * İlk (veya tek) ödeme kaydını dön, yoksa [] dön.
     */
    protected function extractFirstOdeme(array $o): array
    {
        $ode = $o['Odemeler']['WebSiparisOdeme'] ?? null;
        if (! $ode) {
            return [];
        }
        if (is_array($ode) && array_is_list($ode)) {
            return is_array($ode[0] ?? null) ? $ode[0] : [];
        }
        return is_array($ode) ? $ode : [];
    }

    public function render()
    {
        return view('livewire.order-transfer-picker');
    }
}
