<?php

namespace App\Services\Ticimax;

use App\Models\ApiCredential;
use Exception;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;

class TicimaxClient
{
    protected ApiCredential $credential;
    protected array $clients = [];

    /** Son SOAP request XML'i (debug için job'lar __getLastRequest() yerine bunu okuyabilir) */
    protected ?string $lastRequestXml = null;
    /** Son SOAP response XML'i */
    protected ?string $lastResponseXml = null;
    /** Son çağrı bilgisi: service, method, store */
    protected array $lastCallMeta = [];

    public function __construct(string $storeKey)
    {
        $cred = ApiCredential::forStore($storeKey);
        if (! $cred) {
            throw new Exception("API credentials not configured for store: {$storeKey}");
        }
        $this->credential = $cred;
    }

    public static function for(string $storeKey): self
    {
        return new self($storeKey);
    }

    public function getCredential(): ApiCredential
    {
        return $this->credential;
    }

    public function getAuth(): array
    {
        // Yeni Ticimax B2B: UyeKodu = Web Servis Yetki Kodu, UyeSifre bos string.
        // Eski kurulumlar: ikisi de dolu (username/password ciftleri).
        return [
            'UyeKodu' => (string) $this->credential->username,
            'UyeSifre' => (string) $this->credential->password,
        ];
    }

    public function getUyeKodu(): string
    {
        return (string) $this->credential->username;
    }

    public function getUyeSifre(): string
    {
        return (string) $this->credential->password;
    }

    public function client(string $service): SoapClient
    {
        if (isset($this->clients[$service])) {
            return $this->clients[$service];
        }

        // Önce credential'ın kendi override'ına bak; yoksa config default'una düş.
        $credPathKey = "wsdl_path_{$service}";
        $wsdlPath = $this->credential->{$credPathKey} ?: config("ticimax.wsdl_paths.{$service}");
        if (! $wsdlPath) {
            throw new Exception("Unknown Ticimax service: {$service}");
        }

        // wsdlPath bir tam URL ise (http ile başlıyorsa) doğrudan kullan; yoksa endpoint'in ardına ekle.
        if (preg_match('#^https?://#i', $wsdlPath)) {
            $url = $wsdlPath;
        } else {
            $url = rtrim($this->credential->endpoint_url, '/') . '/' . ltrim($wsdlPath, '/');
        }

        // WSDL disk cache için yazılabilir bir dizin garanti et. PHP'nin varsayılan
        // soap.wsdl_cache_dir'i Windows'ta /tmp'e bakıyor (yazılamaz → cache sessizce
        // devre dışı). storage altında kendi dizinimize yönlendiriyoruz. (#5)
        $soapCacheDir = storage_path('framework/cache/soap');
        if (! is_dir($soapCacheDir)) {
            @mkdir($soapCacheDir, 0775, true);
        }
        if (is_dir($soapCacheDir) && is_writable($soapCacheDir)) {
            ini_set('soap.wsdl_cache_dir', $soapCacheDir);
        }

        try {
            $this->clients[$service] = new SoapClient($url, [
                // WSDL+XSD'ler büyük; her worker process'inde yeniden indirmek yerine
                // diskte cache'le (TTL = soap.wsdl_cache_ttl, varsayılan 1 gün). WSDL
                // nadiren değişir; değişirse cache dizini temizlenir. (#5 — job başlangıcı hızlanır.)
                'cache_wsdl' => WSDL_CACHE_DISK,
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => config('ticimax.connection_timeout'),
                'keep_alive' => false,
            ]);
        } catch (SoapFault $e) {
            throw new Exception("SOAP client init failed for {$service}: " . $e->getMessage(), 0, $e);
        }

        return $this->clients[$service];
    }

    public function call(string $service, string $method, array $params): mixed
    {
        $attempts = (int) config('ticimax.retry_attempts', 3);
        $delay = (int) config('ticimax.retry_delay_seconds', 5);

        $this->lastCallMeta = ['service' => $service, 'method' => $method, 'store' => $this->credential->store_key];

        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $client = $this->client($service);
                $result = $client->__soapCall($method, [$params]);
                // Başarılı çağrıda da raw'ı sakla (audit için)
                $this->lastRequestXml = $this->maskCredentials((string) $client->__getLastRequest());
                $this->lastResponseXml = (string) $client->__getLastResponse();
                return $result;
            } catch (SoapFault $e) {
                // Hata olsa da raw'ı yakala — job debug için kullanır
                if (isset($client)) {
                    $this->lastRequestXml = $this->maskCredentials((string) $client->__getLastRequest());
                    $this->lastResponseXml = (string) $client->__getLastResponse();
                }
                $last = $e;
                Log::warning("Ticimax SOAP attempt {$i} failed", [
                    'service' => $service,
                    'method' => $method,
                    'store' => $this->credential->store_key,
                    'error' => $e->getMessage(),
                ]);

                // Rate limit yakala: "Next Query Allowed After 42 seconds. |42"
                if (preg_match('/Next Query Allowed After (\d+) seconds/i', $e->getMessage(), $m)) {
                    $waitSeconds = (int) $m[1] + 2; // güvenlik için +2sn
                    Log::info("Ticimax rate limit; {$waitSeconds}sn bekleniyor", [
                        'service' => $service, 'method' => $method,
                    ]);
                    sleep($waitSeconds);
                    continue; // bu attempt'i tekrarla
                }

                if ($i < $attempts) {
                    sleep($delay * $i);
                }
            }
        }
        throw new Exception("Ticimax call failed after {$attempts} attempts: " . ($last?->getMessage() ?? 'unknown'), 0, $last);
    }

    public function getLastRequestXml(): ?string
    {
        return $this->lastRequestXml;
    }

    public function getLastResponseXml(): ?string
    {
        return $this->lastResponseXml;
    }

    public function getLastCallMeta(): array
    {
        return $this->lastCallMeta;
    }

    /**
     * UyeKodu (auth token) icerikleri loglarda gozukmesin diye maskele.
     */
    protected function maskCredentials(string $xml): string
    {
        return preg_replace(
            '#(<[^>]*UyeKodu[^>]*>)([^<]+)(</[^>]*UyeKodu>)#i',
            '$1***MASKED***$3',
            $xml
        ) ?? $xml;
    }

    public function testConnection(): array
    {
        $results = [];
        foreach (['product', 'order'] as $svc) {
            try {
                $client = $this->client($svc);
                $functions = $client->__getFunctions();
                $results[$svc] = [
                    'ok' => true,
                    'function_count' => is_array($functions) ? count($functions) : 0,
                ];
            } catch (Exception $e) {
                $results[$svc] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }
}
