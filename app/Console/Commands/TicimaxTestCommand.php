<?php

namespace App\Console\Commands;

use App\Models\ApiCredential;
use App\Services\Ticimax\ProductService;
use App\Services\Ticimax\TicimaxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class TicimaxTestCommand extends Command
{
    protected $signature = 'ticimax:test
        {store : ana|bayi}
        {--endpoint= : Override endpoint URL (DB\'ye kaydetmeden geçici test)}
        {--user= : Override kullanıcı kodu}
        {--password= : Override şifre}
        {--methods : Bütün SOAP method listesini yazdır (WSDL doğrulama için)}
        {--list-products : Son 7 günde değişen ilk 3 ürünü listele}';

    protected $description = 'Ticimax bağlantısını test et. DB kaydı veya --endpoint/--user/--password ile çalışır.';

    public function handle(): int
    {
        $store = $this->argument('store');
        if (! in_array($store, ['ana', 'bayi'], true)) {
            $this->error("store argümanı 'ana' veya 'bayi' olmalı.");
            return self::FAILURE;
        }

        if ($this->option('endpoint') || $this->option('user') || $this->option('password')) {
            $endpoint = $this->option('endpoint') ?: $this->ask('Endpoint URL');
            $user = $this->option('user') ?: $this->ask('Kullanıcı kodu');
            $pass = $this->option('password') ?: $this->secret('Şifre');

            ApiCredential::updateOrCreate(
                ['store_key' => $store],
                ['endpoint_url' => $endpoint, 'username' => $user, 'password' => $pass, 'is_active' => true]
            );
            $this->info("[{$store}] credentials geçici olarak kaydedildi.");
        }

        $cred = ApiCredential::forStore($store);
        if (! $cred) {
            $this->error("[{$store}] için credential bulunamadı. Önce panelden veya --endpoint/--user/--password ile gir.");
            return self::FAILURE;
        }

        $this->line("Store: {$cred->store_key}");
        $this->line("Endpoint: {$cred->endpoint_url}");
        $this->line("User: {$cred->username}");
        $this->newLine();

        try {
            $client = new TicimaxClient($store);
        } catch (Throwable $e) {
            $this->error('Client init başarısız: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('--- Bağlantı testi ---');
        $results = $client->testConnection();
        foreach ($results as $svc => $r) {
            if (! empty($r['ok'])) {
                $this->line("  [{$svc}] OK — {$r['function_count']} SOAP method bulundu");
            } else {
                $this->error("  [{$svc}] FAIL — " . ($r['error'] ?? 'bilinmiyor'));
            }
        }

        if ($this->option('methods')) {
            $this->newLine();
            $this->info('--- WSDL Methods ---');
            foreach (['product', 'order'] as $svc) {
                $this->line("[{$svc}]");
                try {
                    $functions = $client->client($svc)->__getFunctions();
                    foreach ($functions as $fn) {
                        $this->line("  $fn");
                    }
                } catch (Throwable $e) {
                    $this->error("  hata: " . $e->getMessage());
                }
                $this->newLine();
            }
        }

        if ($this->option('list-products')) {
            $this->newLine();
            $this->info('--- Son 7 günde değişen ilk 3 ürün ---');
            try {
                $svc = new ProductService($client);
                $products = $svc->getNewProducts(Carbon::now()->subDays(7), 1, 3);
                if (empty($products)) {
                    $this->warn('  Hiç ürün dönmedi.');
                } else {
                    foreach ($products as $p) {
                        $barkod = $p['Barkod'] ?? '-';
                        $ad = $p['UrunAdi'] ?? '-';
                        $stok = $p['StokAdedi'] ?? '-';
                        $fiyat = $p['SatisFiyati'] ?? '-';
                        $this->line("  {$barkod} | {$ad} | stok={$stok} fiyat={$fiyat}");
                    }
                }
            } catch (Throwable $e) {
                $this->error('  hata: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
