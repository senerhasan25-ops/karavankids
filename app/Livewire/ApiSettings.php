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
    public string $ana_username = '';
    public string $ana_password = '';
    public bool $ana_active = true;

    public string $bayi_endpoint = '';
    public string $bayi_username = '';
    public string $bayi_password = '';
    public bool $bayi_active = true;

    public array $testResults = [];

    public function mount(): void
    {
        foreach (['ana', 'bayi'] as $store) {
            $cred = ApiCredential::where('store_key', $store)->first();
            if ($cred) {
                $this->{"{$store}_endpoint"} = $cred->endpoint_url;
                $this->{"{$store}_username"} = $cred->username;
                $this->{"{$store}_password"} = $cred->password;
                $this->{"{$store}_active"} = (bool) $cred->is_active;
            }
        }
    }

    protected function rules(): array
    {
        return [
            'ana_endpoint' => ['required', 'url'],
            'ana_username' => ['required', 'string'],
            'ana_password' => ['required', 'string'],
            'bayi_endpoint' => ['required', 'url'],
            'bayi_username' => ['required', 'string'],
            'bayi_password' => ['required', 'string'],
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
