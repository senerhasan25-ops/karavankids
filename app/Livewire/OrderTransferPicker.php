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
                ->get(['bayi_order_id', 'ana_order_id', 'status', 'transferred_at', 'last_error'])
                ->keyBy('bayi_order_id');

            $this->orders = array_map(function ($o) use ($localStatus) {
                $id = (string) ($o['ID'] ?? $o['SiparisID'] ?? '');
                $local = $localStatus->get($id);
                return [
                    'id' => $id,
                    'siparis_no' => (string) ($o['SiparisNo'] ?? $o['SiparisKodu'] ?? ''),
                    'tarih' => (string) ($o['SiparisTarihi'] ?? $o['DuzenlemeTarihi'] ?? ''),
                    'alici' => (string) ($o['AdiSoyadi'] ?? $o['UyeAdi'] ?? ''),
                    'mail' => (string) ($o['Mail'] ?? $o['UyeMail'] ?? ''),
                    'telefon' => (string) ($o['Telefon'] ?? $o['UyeCep'] ?? ''),
                    'tutar' => (float) ($o['OdenenTutar'] ?? $o['SiparisToplamTutari'] ?? 0),
                    'odeme_tipi' => (string) ($o['Odeme']['OdemeTipi'] ?? $o['OdemeTipi'] ?? ''),
                    'odeme_durumu' => (string) ($o['Odeme']['OdemeDurumu'] ?? $o['OdemeDurumu'] ?? ''),
                    'siparis_durumu' => (string) ($o['SiparisDurumu'] ?? $o['Durum'] ?? ''),
                    'paketleme_durumu' => (string) ($o['PaketlemeDurumu'] ?? $o['PaketlemeDurumuId'] ?? ''),
                    'entegrasyon_aktarildi' => (bool) ($o['EntegrasyonAktarildi'] ?? false),
                    'local_status' => $local?->status,
                    'local_ana_id' => $local?->ana_order_id,
                    'local_error' => $local?->last_error,
                ];
            }, $raw);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->orders = [];
        } finally {
            $this->loading = false;
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

    public function render()
    {
        return view('livewire.order-transfer-picker');
    }
}
