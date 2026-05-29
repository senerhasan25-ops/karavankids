<?php

namespace App\Console\Commands;

use App\Models\ApiCredential;
use Illuminate\Console\Command;

class TicimaxSeedTestCredentialsCommand extends Command
{
    protected $signature = 'ticimax:seed-test
        {--ana-password= : Override ana mağaza şifresi (env yerine)}
        {--bayi-password= : Override bayi mağaza şifresi (env yerine)}';

    protected $description = '.env\'deki TICIMAX_*_ENDPOINT/USERNAME/PASSWORD değerlerini DB\'ye api_credentials olarak yazar.';

    public function handle(): int
    {
        $stores = [
            'ana' => [
                'endpoint' => env('TICIMAX_ANA_ENDPOINT'),
                'wsdl_product' => env('TICIMAX_ANA_WSDL_PRODUCT'),
                'wsdl_order' => env('TICIMAX_ANA_WSDL_ORDER'),
                'username' => env('TICIMAX_ANA_USERNAME'),
                'password' => $this->option('ana-password') ?: env('TICIMAX_ANA_PASSWORD'),
            ],
            'bayi' => [
                'endpoint' => env('TICIMAX_BAYI_ENDPOINT'),
                'wsdl_product' => env('TICIMAX_BAYI_WSDL_PRODUCT'),
                'wsdl_order' => env('TICIMAX_BAYI_WSDL_ORDER'),
                'username' => env('TICIMAX_BAYI_USERNAME'),
                'password' => $this->option('bayi-password') ?: env('TICIMAX_BAYI_PASSWORD'),
            ],
        ];

        foreach ($stores as $key => $cfg) {
            if (! $cfg['endpoint']) {
                $this->warn("[{$key}] TICIMAX_".strtoupper($key)."_ENDPOINT .env'de yok, atlandı.");

                continue;
            }
            if (! $cfg['username']) {
                $this->warn("[{$key}] kullanıcı adı .env'de boş — sonra panelden gir.");
            }
            if (! $cfg['password']) {
                $this->warn("[{$key}] şifre .env'de boş — sonra panelden gir veya --{$key}-password ile geç.");
            }

            ApiCredential::updateOrCreate(
                ['store_key' => $key],
                [
                    'endpoint_url' => $cfg['endpoint'],
                    'wsdl_path_product' => $cfg['wsdl_product'],
                    'wsdl_path_order' => $cfg['wsdl_order'],
                    'username' => $cfg['username'] ?? '',
                    'password' => $cfg['password'] ?? '',
                    'is_active' => true,
                ]
            );

            $this->info("[{$key}] DB'ye yazıldı: {$cfg['endpoint']}");
        }

        return self::SUCCESS;
    }
}
