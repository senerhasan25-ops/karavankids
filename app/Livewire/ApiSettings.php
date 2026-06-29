<?php

namespace App\Livewire;

use App\Models\ApiCredential;
use App\Services\Ticimax\OrderService;
use App\Services\Ticimax\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('API Ayarları')]
#[Layout('layouts.app')]
class ApiSettings extends Component
{
    public string $ana_endpoint = '';

    public string $ana_wsdl_product = '';

    public string $ana_wsdl_order = '';

    public string $ana_username = '';

    public string $ana_password = '';

    public bool $ana_active = true;

    public string $bayi_endpoint = '';

    public string $bayi_wsdl_product = '';

    public string $bayi_wsdl_order = '';

    public string $bayi_username = '';

    public string $bayi_password = '';

    public bool $bayi_active = true;

    public array $testResults = [];

    public function mount(): void
    {
        foreach (['ana', 'bayi'] as $store) {
            $cred = ApiCredential::where('store_key', $store)->first();
            if ($cred) {
                $this->{"{$store}_endpoint"} = $cred->endpoint_url ?? '';
                $this->{"{$store}_wsdl_product"} = $cred->wsdl_path_product ?? '';
                $this->{"{$store}_wsdl_order"} = $cred->wsdl_path_order ?? '';
                $this->{"{$store}_username"} = $cred->username ?? '';
                $this->{"{$store}_password"} = $cred->password ?? '';
                $this->{"{$store}_active"} = (bool) $cred->is_active;
            }
        }
    }

    public function loadTestDefaults(): void
    {
        // YÖN: Ürünler kaynak (karavankids.ticimaxtest.com) → hedef (digitalsupport)
        //       Siparişler hedef (digitalsupport) → kaynak (karavankids.ticimaxtest.com)
        // "ana" slot = kaynak (ürünlerin asıl olduğu yer)
        // "bayi" slot = hedef (sipariş alan ve ürünleri yansıtacağımız yer)
        $this->ana_endpoint = 'https://karavankids.ticimaxtest.com';
        $this->ana_wsdl_product = '/servis/UrunServis.svc?wsdl';
        $this->ana_wsdl_order = '/servis/SiparisServis.svc?wsdl';

        $this->bayi_endpoint = 'https://digitalsupport.ticimaxtest.com';
        $this->bayi_wsdl_product = '/Servis/UrunServis.svc?wsdl';
        $this->bayi_wsdl_order = '/Servis/SiparisServis.svc?wsdl';

        session()->flash('status', 'Test ortamı URL\'leri formda dolduruldu. Kullanıcı kodu ve şifreyi de gir, "Kaydet" tıkla.');
    }

    protected function rules(): array
    {
        return [
            'ana_endpoint' => ['required', 'url'],
            'ana_wsdl_product' => ['nullable', 'string'],
            'ana_wsdl_order' => ['nullable', 'string'],
            'ana_username' => ['required', 'string'],
            'ana_password' => ['nullable', 'string'],
            'bayi_endpoint' => ['required', 'url'],
            'bayi_wsdl_product' => ['nullable', 'string'],
            'bayi_wsdl_order' => ['nullable', 'string'],
            'bayi_username' => ['required', 'string'],
            'bayi_password' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        foreach (['ana', 'bayi'] as $store) {
            ApiCredential::updateOrCreate(
                ['store_key' => $store],
                [
                    'endpoint_url' => $this->{"{$store}_endpoint"},
                    'wsdl_path_product' => $this->{"{$store}_wsdl_product"} ?: null,
                    'wsdl_path_order' => $this->{"{$store}_wsdl_order"} ?: null,
                    'username' => $this->{"{$store}_username"},
                    'password' => $this->{"{$store}_password"},
                    'is_active' => $this->{"{$store}_active"},
                ]
            );
        }

        // Process-içi forStore cache'ini temizle ki bu istekte güncel değer okunsun (#10).
        ApiCredential::forgetCache();

        session()->flash('status', 'API ayarları kaydedildi.');
    }

    /**
     * GERÇEK bağlantı testi — yalnızca WSDL'e erişilebiliyor mu değil, KAYDEDİLMİŞ
     * yetki kodunun GEÇERLİ olduğunu da doğrular. Kimlikli bir SelectUrun (1 kayıt)
     * çağrısı yapar; yetki kodu yanlışsa Ticimax "Hatalı Kullanıcı Kodu" döner.
     * Aynı yetki kodu hem ürün hem sipariş servisinde kullanıldığı için ürün testi
     * geçerse kimlik geçerlidir; sipariş servisi ek/bilgilendirici kontroldür.
     *
     * NOT: Kaydedilmiş bilgilerle test eder — önce "Kaydet", sonra "Test Et".
     */
    public function testConnection(string $store): void
    {
        $result = ['store' => $store, 'product' => null, 'order' => null, 'overall' => false];

        // 1) ÜRÜN servisi — kimlik doğrulama (asıl sinyal)
        try {
            ProductService::for($store)->getNewProducts(null, 1, 1, 'DESC');
            $result['product'] = ['ok' => true, 'message' => '✓ Yetki doğrulandı — ürün servisine erişildi.'];
        } catch (\Throwable $e) {
            $result['product'] = ['ok' => false, 'message' => self::classifyConnectionError($e->getMessage())];
        }

        // 2) SİPARİŞ servisi — ek kontrol (aynı yetki kodu; kimi kurulumda durum
        //    listesi metodu kaprisli olabilir, bu yüzden başarısızlığı UYARI sayarız).
        try {
            OrderService::for($store)->getOrderStatuses();
            $result['order'] = ['ok' => true, 'message' => '✓ Sipariş servisine erişildi.'];
        } catch (\Throwable $e) {
            $result['order'] = ['ok' => false, 'message' => '⚠ Sipariş servisi kontrolü: '.self::classifyConnectionError($e->getMessage())];
        }

        // Genel sonuç ÜRÜN testine bağlı — yetkinin asıl kanıtı odur.
        $result['overall'] = (bool) ($result['product']['ok'] ?? false);
        $this->testResults[$store] = $result;
    }

    /**
     * SOAP/bağlantı hata mesajını kullanıcı dostu Türkçe tanıya çevirir.
     */
    public static function classifyConnectionError(string $msg): string
    {
        if (stripos($msg, 'Hatalı Kullanıcı Kodu') !== false || stripos($msg, 'Hatali Kullanici Kodu') !== false) {
            return '❌ Geçersiz yetki kodu (Hatalı Kullanıcı Kodu) — Web Servis Yetki Kodunu kontrol et.';
        }
        if (stripos($msg, 'init failed') !== false
            || stripos($msg, 'Could not connect') !== false
            || stripos($msg, 'getaddrinfo') !== false
            || stripos($msg, 'Couldn\'t resolve') !== false
            || stripos($msg, 'failed to load external entity') !== false) {
            return '❌ Sunucuya ulaşılamadı — endpoint/WSDL adresi yanlış veya ağ sorunu.';
        }

        return '❌ Hata: '.mb_substr($msg, 0, 200);
    }

    public function render()
    {
        return view('livewire.api-settings');
    }
}
