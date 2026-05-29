<?php

use App\Models\ApiCredential;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$cred = ApiCredential::forStore('bayi');
$url = rtrim($cred->endpoint_url, '/').'/Servis/SiparisServis.svc?wsdl';
$client = new SoapClient($url, ['cache_wsdl' => WSDL_CACHE_NONE, 'trace' => true, 'exceptions' => true, 'connection_timeout' => 15]);

echo "=== SelectSiparisDurumlari deneniyor ===\n";
try {
    $resp = $client->__soapCall('SelectSiparisDurumlari', [['UyeKodu' => $cred->username]]);
    echo "Yanit:\n";
    print_r($resp);
} catch (Throwable $e) {
    echo 'HATA: '.$e->getMessage()."\n";
    echo "Request XML:\n".$client->__getLastRequest()."\n";
    echo "Response XML:\n".$client->__getLastResponse()."\n";
}
