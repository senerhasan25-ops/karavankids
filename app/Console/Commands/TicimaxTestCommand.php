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
        {--types : Bütün WSDL tip tanımlarını yazdır (struct alanları için)}
        {--inspect= : Belirli bir tipin alanlarını göster (örn: --inspect=WebUrun)}
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

        if ($this->option('types') || $this->option('inspect')) {
            $filter = $this->option('inspect');
            $this->newLine();
            $this->info($filter ? "--- WSDL Types matching '{$filter}' ---" : '--- WSDL Types ---');
            foreach (['product', 'order'] as $svc) {
                try {
                    $types = $client->client($svc)->__getTypes();
                    foreach ($types as $t) {
                        if (! $filter || stripos($t, $filter) !== false) {
                            $this->line("[{$svc}]");
                            $this->line($t);
                            $this->newLine();
                        }
                    }
                } catch (Throwable $e) {
                    $this->error("[{$svc}] hata: " . $e->getMessage());
                }
            }
        }

        if ($this->option('list-products')) {
            $this->newLine();
            $this->info('--- Tüm ürünler (ilk 5) ---');
            try {
                $svc = new ProductService($client);
                $products = $svc->getNewProducts(null, 1, 5); // tarih filtresi yok
                if (empty($products)) {
                    $this->warn('  Hiç ürün dönmedi. Mağaza boş olabilir veya kullanıcı yetkilendirilmemiş.');
                } else {
                    foreach ($products as $p) {
                        $id = $p['ID'] ?? '-';
                        $ad = $p['UrunAdi'] ?? '-';
                        $aktif = ! empty($p['Aktif']) ? 'aktif' : 'pasif';
                        $varList = $p['Varyasyonlar'] ?? [];
                        if (isset($varList['Varyasyon'])) {
                            $varList = is_array($varList['Varyasyon']) && array_is_list($varList['Varyasyon']) ? $varList['Varyasyon'] : [$varList['Varyasyon']];
                        }
                        $varCount = is_array($varList) ? count($varList) : 0;
                        $primary = $varCount > 0 ? $varList[0] : null;
                        $barkod = $primary['Barkod'] ?? '-';
                        $stok = $primary['StokAdedi'] ?? '-';
                        $fiyat = $primary['SatisFiyati'] ?? '-';
                        $this->line("  ID={$id} | {$ad} | {$aktif} | varyasyon={$varCount} | birincil: barkod={$barkod} stok={$stok} fiyat={$fiyat}");
                    }
                }
            } catch (Throwable $e) {
                $this->error('  hata: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
