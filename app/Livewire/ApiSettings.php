<?php

namespace App\Livewire;

use App\Models\ApiCredential;
use App\Services\Ticimax\TicimaxClient;
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

        session()->flash('status', 'API ayarları kaydedildi.');
    }

    public function testConnection(string $store): void
    {
        try {
            $client = TicimaxClient::for($store);
            $this->testResults[$store] = $client->testConnection();
        } catch (\Throwable $e) {
            $this->testResults[$store] = ['error' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.api-settings');
    }
}
