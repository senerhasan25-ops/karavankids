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
    public int $odemeTipi = -1;     // -1 = hepsi
    public int $odemeDurumu = -1;   // -1 = hepsi, 1 = ödendi, 2 = bekliyor, 3 = iptal/iade
    public int $siparisDurumu = 0;  // 0 = hepsi, 1 = onaylandı, 2 = hazırlanıyor, 3 = kargolandı, 4 = teslim, 5 = iptal
    public int $paketlemeDurumu = 0; // 0 = hepsi, 1 = beklemede, 2 = paketlendi, 3 = kargolandı
    public int $aktarildi = -1;     // -1 = hepsi, 0 = aktarılmamış, 1 = aktarılmış
    public int $page = 1;
    public int $perPage = 25;

    public array $orders = [];
    public bool $loading = false;
    public ?string $lastError = null;
    public bool $hasSearched = false;

    // Ticimax ödeme tipleri (siparis_aktar referansı)
    public array $odemeTipleri = [
        -1 => 'Hepsi',
        0 => 'Kredi Kartı',
        1 => 'Havale',
        2 => 'Kapıda Ödeme',
        3 => 'Hediye Çeki',
        4 => 'Pazaryeri',
        10 => 'Diğer',
    ];

    // Ticimax ödeme durumu kodları
    public array $odemeDurumlari = [
        -1 => 'Hepsi',
        1 => 'Ödendi',
        2 => 'Bekliyor',
        3 => 'İptal / İade',
    ];

    // Ticimax sipariş durumu kodları (genel — sürüme göre değişebilir)
    public array $siparisDurumlari = [
        0 => 'Hepsi',
        1 => 'Sipariş Alındı',
        2 => 'Hazırlanıyor',
        3 => 'Kargolandı',
        4 => 'Teslim Edildi',
        5 => 'İptal',
        6 => 'İade',
    ];

    // Ticimax paketleme durumu kodları
    public array $paketlemeDurumlari = [
        0 => 'Hepsi',
        1 => 'Beklemede',
        2 => 'Paketlendi',
        3 => 'Kargolandı',
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
        $this->siparisDurumu = 0;
        $this->paketlemeDurumu = 0;
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
