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
        return [
            'UyeKodu' => $this->credential->username,
            'UyeSifre' => $this->credential->password,
        ];
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

        try {
            $this->clients[$service] = new SoapClient($url, [
                'cache_wsdl' => WSDL_CACHE_NONE,
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

        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $client = $this->client($service);
                return $client->__soapCall($method, [$params]);
            } catch (SoapFault $e) {
                $last = $e;
                Log::warning("Ticimax SOAP attempt {$i} failed", [
                    'service' => $service,
                    'method' => $method,
                    'store' => $this->credential->store_key,
                    'error' => $e->getMessage(),
                ]);
                if ($i < $attempts) {
                    sleep($delay * $i);
                }
            }
        }
        throw new Exception("Ticimax call failed after {$attempts} attempts: " . ($last?->getMessage() ?? 'unknown'), 0, $last);
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
