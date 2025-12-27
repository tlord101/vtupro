<?php

namespace App\Http\Helpers;

use Exception;
use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Http\Request;
use App\Models\TemporaryData;
use App\Constants\GlobalConst;
use App\Models\Admin\Currency;
use App\Models\Admin\ExchangeRate;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Models\Admin\TransactionSetting;
use App\Models\Admin\PeyflexApi;
use App\Models\Transaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

class PeyflexHelper {

    /**
     * Provider slug
     */
    const SLUG = "PEYFLEX";

    /**
     * Store gateway credentials
     */
    public object $credentials;

    /**
     * Store user
     */
    public \App\Models\User|null $user = null;

    /**
     * Access token cache key
     */
    const API_ACCESS_TOKEN = "PEYFLEX-API-ACCESS-TOKEN";

    /**
     * Store topup types
     */
    const TOPUP_AIRTIME    = "AIRTIME";
    const TOPUP_DATA       = "DATA";

    /**
     * API Status
     */
    const STATUS_SUCCESS    = "SUCCESSFUL";
    const STATUS_PENDING    = "PENDING";
    const STATUS_PROCESSING = "PROCESSING";
    const STATUS_REFUNDED   = "REFUNDED";
    const STATUS_FAILED     = "FAILED";

    /**
     * Store access token
     */
    public $access_token;

    /**
     * Store number
     */
    public string $phone_number;

    /**
     * Country iso 2
     */
    public string $country_iso2;

    /**
     * Set topup type
     */
    public string $topup_type;

    /**
     * API configuration
     */
    public $api;

    /**
     * Store configuration
     */
    protected array $config;

    public function __construct(PeyflexApi $api = null, string $type = 'MOBILE-TOPUP')
    {
        if($api) {
            $this->api = $api;
        } else {
            $this->api = PeyflexApi::peyflex()->active()->where('type', $type)->first();
        }
        
        $this->setConfig();
        $this->accessToken();
    }

    /**
     * Set configuration
     */
    public function setConfig()
    {
        $api = $this->api;

        if(!$api) throw new Exception("Peyflex Provider Not Found!");

        $config['api_key']      = $api->credentials?->api_key ?? '';
        $config['secret_key']   = $api->credentials?->secret_key ?? '';
        $config['public_key']   = $api->credentials?->public_key ?? '';
        $config['env']          = $api->env;

        if($config['env'] == GlobalConst::ENV_PRODUCTION) {
            $config['request_url']  = $api->credentials?->production_base_url ?? 'https://api.peyflex.com';
        } else {
            $config['request_url']  = $api->credentials?->sandbox_base_url ?? 'https://sandbox.peyflex.com';
        }

        $this->config = $config;

        return $this;
    }

    /**
     * Authenticate API access token retrieve
     */
    public function accessToken()
    {
        if(!$this->config) $this->setConfig();

        $api = $this->api;
        
        // Check if token is cached
        $cached_token = cache()->get(self::API_ACCESS_TOKEN . "_{$api->env}_{$api->type}");
        
        if($cached_token) {
            $this->access_token = $cached_token;
            return $this;
        }

        // For now, use the API key directly until Peyflex docs are provided
        $this->access_token = $this->config['api_key'];
        
        // Cache token for 1 hour
        cache()->put(self::API_ACCESS_TOKEN . "_{$api->env}_{$api->type}", $this->access_token, 3600);

        return $this;
    }

    /**
     * Get operators by country
     */
    public function getOperatorsByCountry($country_code)
    {
        if(!$this->access_token) $this->accessToken();

        $cache_key = "peyflex_operators_{$country_code}_{$this->api->type}";
        
        // Check cache first
        $cached = cache()->get($cache_key);
        if($cached) return $cached;

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/operators?country={$country_code}";

        try {
            // TODO: Update with actual Peyflex API endpoint once docs are provided
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->get($endpoint)->throw()->json();

            // Cache for 4 hours
            cache()->put($cache_key, $response, 14400);

            return $response;
        } catch (RequestException $e) {
            // Return mock data for testing until API is integrated
            return $this->getMockOperators($country_code);
        }
    }

    /**
     * Get operator plans (for data bundles)
     */
    public function getOperatorPlans($operator_id, $include_balance = false)
    {
        if(!$this->access_token) $this->accessToken();

        $cache_key = "peyflex_plans_{$operator_id}_{$this->api->type}";
        
        $cached = cache()->get($cache_key);
        if($cached) return $cached;

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/operators/{$operator_id}/plans";

        try {
            // TODO: Update with actual Peyflex API endpoint once docs are provided
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->get($endpoint)->throw()->json();

            // Cache for 2 hours
            cache()->put($cache_key, $response, 7200);

            return $response;
        } catch (RequestException $e) {
            // Return mock data for testing until API is integrated
            return $this->getMockPlans($operator_id);
        }
    }

    /**
     * Get airtime networks
     */
    public function getAirtimeNetworks()
    {
        if(!$this->access_token) $this->accessToken();

        $cache_key = "peyflex_airtime_networks_{$this->api->env}_{$this->api->type}";
        
        $cached = cache()->get($cache_key);
        if($cached) return $cached;

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/airtime/networks/";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get($endpoint)->throw()->json();

            // Cache for 24 hours
            cache()->put($cache_key, $response, 86400);

            return [
                'status' => true,
                'data' => $response,
                'message' => 'Networks fetched successfully'
            ];
        } catch (RequestException $e) {
            // Log error and throw exception for production
            $error_msg = $e->response ? json_decode($e->response->body(), true)['message'] ?? 'Failed to fetch networks' : 'Network request failed';
            
            if(app()->environment() === 'production') {
                throw new Exception($error_msg);
            }
            
            return [
                'status' => false,
                'data' => [],
                'message' => $error_msg
            ];
        }
    }

    /**
     * Perform airtime topup - Production Implementation
     */
    public function airtimeTopup(array $data)
    {
        if(!$this->access_token) $this->accessToken();

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/airtime/topup/";

        $payload = [
            'network' => $data['network'] ?? '',
            'mobile_number' => $data['mobile_number'] ?? '',
            'amount' => $data['amount'] ?? 0,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($endpoint, $payload)->throw()->json();

            // Check response status
            if(isset($response['status']) && strtoupper($response['status']) === 'SUCCESSFUL') {
                return [
                    'status' => true,
                    'response' => $response,
                    'message' => 'Airtime topup successful',
                    'transaction_id' => $response['transaction_id'] ?? $response['id'] ?? null
                ];
            }

            // Handle pending or processing status
            return [
                'status' => true,
                'response' => $response,
                'message' => 'Airtime topup ' . strtolower($response['status'] ?? 'processing'),
                'transaction_id' => $response['transaction_id'] ?? $response['id'] ?? null
            ];
        } catch (RequestException $e) {
            $error_response = [];
            if($e->response) {
                $error_response = json_decode($e->response->body(), true);
            }
            
            $error_msg = $error_response['message'] ?? $error_response['detail'] ?? 'Airtime topup failed';
            
            return [
                'status' => false,
                'response' => $error_response,
                'message' => $error_msg,
                'transaction_id' => null
            ];
        }
    }

    /**
     * Get data networks - Production Implementation
     */
    public function getDataNetworks()
    {
        if(!$this->access_token) $this->accessToken();

        $cache_key = "peyflex_data_networks_{$this->api->env}_{$this->api->type}";
        
        $cached = cache()->get($cache_key);
        if($cached) return $cached;

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/data/networks/";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get($endpoint)->throw()->json();

            // Cache for 24 hours
            cache()->put($cache_key, $response, 86400);

            return [
                'status' => true,
                'data' => $response,
                'message' => 'Networks fetched successfully'
            ];
        } catch (RequestException $e) {
            $error_response = [];
            if($e->response) {
                $error_response = json_decode($e->response->body(), true);
            }
            
            $error_msg = $error_response['message'] ?? $error_response['detail'] ?? 'Failed to fetch networks';
            
            if(app()->environment() === 'production') {
                throw new Exception($error_msg);
            }
            
            return [
                'status' => false,
                'data' => [],
                'message' => $error_msg
            ];
        }
    }

    /**
     * Get data plans for a network - Production Implementation
     */
    public function getDataPlans(string $network)
    {
        if(!$this->access_token) $this->accessToken();

        $cache_key = "peyflex_data_plans_{$network}_{$this->api->env}_{$this->api->type}";
        
        $cached = cache()->get($cache_key);
        if($cached) return $cached;

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/data/plans/?network={$network}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get($endpoint)->throw()->json();

            // Cache for 12 hours
            cache()->put($cache_key, $response, 43200);

            return [
                'status' => true,
                'data' => $response,
                'message' => 'Plans fetched successfully'
            ];
        } catch (RequestException $e) {
            $error_response = [];
            if($e->response) {
                $error_response = json_decode($e->response->body(), true);
            }
            
            $error_msg = $error_response['message'] ?? $error_response['detail'] ?? 'Failed to fetch plans';
            
            if(app()->environment() === 'production') {
                throw new Exception($error_msg);
            }
            
            return [
                'status' => false,
                'data' => [],
                'message' => $error_msg
            ];
        }
    }

    /**
     * Purchase data plan - Production Implementation
     */
    public function dataPurchase(array $data)
    {
        if(!$this->access_token) $this->accessToken();

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/data/purchase/";

        $payload = [
            'network' => $data['network'] ?? '',
            'mobile_number' => $data['mobile_number'] ?? '',
            'plan_code' => $data['plan_code'] ?? '',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($endpoint, $payload)->throw()->json();

            // Check response status
            if(isset($response['status']) && strtoupper($response['status']) === 'SUCCESSFUL') {
                return [
                    'status' => true,
                    'response' => $response,
                    'message' => 'Data purchase successful',
                    'transaction_id' => $response['transaction_id'] ?? $response['id'] ?? null
                ];
            }

            // Handle pending or processing status
            return [
                'status' => true,
                'response' => $response,
                'message' => 'Data purchase ' . strtolower($response['status'] ?? 'processing'),
                'transaction_id' => $response['transaction_id'] ?? $response['id'] ?? null
            ];
        } catch (RequestException $e) {
            $error_response = [];
            if($e->response) {
                $error_response = json_decode($e->response->body(), true);
            }
            
            $error_msg = $error_response['message'] ?? $error_response['detail'] ?? 'Data purchase failed';
            
            return [
                'status' => false,
                'response' => $error_response,
                'message' => $error_msg,
                'transaction_id' => null
            ];
        }
    }

    /**
     * Execute topup/data purchase (legacy method - kept for compatibility)
     */
    public function topup(Request $request)
    {
        if(!$this->access_token) $this->accessToken();

        $base_url = $this->config['request_url'];
        $endpoint = $base_url . "/api/topup";

        $payload = [
            'operator_id' => $request->operator_id,
            'amount' => $request->amount,
            'phone' => $request->phone,
            'country_code' => $request->country_code ?? $request->iso2,
            'type' => $request->type ?? 'data',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($endpoint, $payload)->throw()->json();

            return [
                'status' => true,
                'response' => $response,
                'message' => 'Transaction successful'
            ];
        } catch (RequestException $e) {
            // Return mock success for testing until API is integrated
            return $this->getMockTopupResponse($request);
        }
    }

    /**
     * Get mock airtime networks for testing
     */
    private function getMockAirtimeNetworks()
    {
        return [
            [
                'id' => 'mtn_nigeria',
                'name' => 'MTN Nigeria',
                'country_code' => 'NG',
            ],
            [
                'id' => 'glo_nigeria',
                'name' => 'Glo Nigeria',
                'country_code' => 'NG',
            ],
            [
                'id' => 'airtel_nigeria',
                'name' => 'Airtel Nigeria',
                'country_code' => 'NG',
            ],
            [
                'id' => '9mobile_nigeria',
                'name' => '9Mobile Nigeria',
                'country_code' => 'NG',
            ],
        ];
    }

    /**
     * Get mock airtime topup response for testing
     */
    private function getMockAirtimeTopupResponse(array $data)
    {
        return [
            'status' => true,
            'response' => [
                'status' => 'SUCCESSFUL',
                'transaction_id' => 'AT' . time() . rand(1000, 9999),
                'network' => $data['network'] ?? '',
                'mobile_number' => $data['mobile_number'] ?? '',
                'amount' => $data['amount'] ?? 0,
                'timestamp' => now()->toIso8601String(),
            ],
            'message' => 'Airtime topup successful (Mock Response)'
        ];
    }

    /**
     * Get mock data networks for testing
     */
    private function getMockDataNetworks()
    {
        return [
            [
                'id' => 'mtn_sme_data',
                'name' => 'MTN SME Data',
                'country_code' => 'NG',
            ],
            [
                'id' => 'mtn_gifting_data',
                'name' => 'MTN Gifting Data',
                'country_code' => 'NG',
            ],
            [
                'id' => 'glo_data',
                'name' => 'Glo Data',
                'country_code' => 'NG',
            ],
            [
                'id' => 'airtel_data',
                'name' => 'Airtel Data',
                'country_code' => 'NG',
            ],
        ];
    }

    /**
     * Get mock data plans for testing
     */
    private function getMockDataPlans(string $network)
    {
        $plans = [
            'mtn_sme_data' => [
                ['id' => 'M100MBS', 'name' => '100MB', 'price' => 5.00, 'validity' => '1 day'],
                ['id' => 'M500MBS', 'name' => '500MB', 'price' => 15.00, 'validity' => '7 days'],
                ['id' => 'M1GB', 'name' => '1GB', 'price' => 25.00, 'validity' => '30 days'],
                ['id' => 'M5GB', 'name' => '5GB', 'price' => 100.00, 'validity' => '30 days'],
            ],
            'mtn_gifting_data' => [
                ['id' => 'G1GB', 'name' => '1GB', 'price' => 30.00, 'validity' => '30 days'],
                ['id' => 'G5GB', 'name' => '5GB', 'price' => 120.00, 'validity' => '30 days'],
                ['id' => 'G10GB', 'name' => '10GB', 'price' => 200.00, 'validity' => '30 days'],
            ],
            'glo_data' => [
                ['id' => 'G500MB', 'name' => '500MB', 'price' => 20.00, 'validity' => '7 days'],
                ['id' => 'G1GB', 'name' => '1GB', 'price' => 35.00, 'validity' => '30 days'],
                ['id' => 'G5GB', 'name' => '5GB', 'price' => 130.00, 'validity' => '30 days'],
            ],
            'airtel_data' => [
                ['id' => 'A500MB', 'name' => '500MB', 'price' => 18.00, 'validity' => '7 days'],
                ['id' => 'A1GB', 'name' => '1GB', 'price' => 32.00, 'validity' => '30 days'],
                ['id' => 'A5GB', 'name' => '5GB', 'price' => 110.00, 'validity' => '30 days'],
            ],
        ];

        return $plans[$network] ?? [];
    }

    /**
     * Get mock data purchase response for testing
     */
    private function getMockDataPurchaseResponse(array $data)
    {
        return [
            'status' => true,
            'response' => [
                'status' => 'SUCCESSFUL',
                'transaction_id' => 'DP' . time() . rand(1000, 9999),
                'network' => $data['network'] ?? '',
                'mobile_number' => $data['mobile_number'] ?? '',
                'plan_code' => $data['plan_code'] ?? '',
                'timestamp' => now()->toIso8601String(),
            ],
            'message' => 'Data purchase successful (Mock Response)'
        ];
    }

    /**
     * Get mock operators for testing (legacy)
     */
    private function getMockOperators($country_code)
    {
        return [
            [
                'id' => 'MTN_' . $country_code,
                'name' => 'MTN ' . strtoupper($country_code),
                'country' => strtoupper($country_code),
                'logo' => 'https://via.placeholder.com/50',
            ],
            [
                'id' => 'GLO_' . $country_code,
                'name' => 'GLO ' . strtoupper($country_code),
                'country' => strtoupper($country_code),
                'logo' => 'https://via.placeholder.com/50',
            ],
            [
                'id' => 'AIRTEL_' . $country_code,
                'name' => 'Airtel ' . strtoupper($country_code),
                'country' => strtoupper($country_code),
                'logo' => 'https://via.placeholder.com/50',
            ],
        ];
    }

    /**
     * Get mock plans for testing (legacy)
     */
    private function getMockPlans($operator_id)
    {
        return [
            [
                'id' => $operator_id . '_1GB',
                'name' => '1GB Daily',
                'price' => 10.00,
                'currency' => 'USD',
                'validity' => '1 day',
                'data_amount' => '1GB',
            ],
            [
                'id' => $operator_id . '_2GB',
                'name' => '2GB Weekly',
                'price' => 20.00,
                'currency' => 'USD',
                'validity' => '7 days',
                'data_amount' => '2GB',
            ],
            [
                'id' => $operator_id . '_5GB',
                'name' => '5GB Monthly',
                'price' => 50.00,
                'currency' => 'USD',
                'validity' => '30 days',
                'data_amount' => '5GB',
            ],
        ];
    }

    /**
     * Get mock topup response for testing (legacy)
     */
    private function getMockTopupResponse($request)
    {
        return [
            'status' => true,
            'response' => [
                'status' => 'SUCCESSFUL',
                'transaction_id' => 'PFX' . time() . rand(1000, 9999),
                'operator_id' => $request->operator_id,
                'phone' => $request->phone,
                'amount' => $request->amount,
                'type' => $request->type ?? 'data',
                'timestamp' => now()->toIso8601String(),
            ],
            'message' => 'Transaction successful (Mock Response)'
        ];
    }
}
