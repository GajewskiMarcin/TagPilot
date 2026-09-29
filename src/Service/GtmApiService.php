<?php

declare(strict_types=1);

namespace Flavor\TagPilot\Service;

use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class GtmApiService
{
    private const PREFIX = 'TAGPILOT_';
    private const API_BASE = 'https://www.googleapis.com/tagmanager/v2';
    private const ALL_PAGES_TRIGGER_ID = '2147479553';

    private GoogleOAuthService $oauth;

    public function __construct(GoogleOAuthService $oauth)
    {
        $this->oauth = $oauth;
    }

    // ── Account / Container listing ─────────────────────────────

    public function listAccounts(): array
    {
        $response = $this->apiGet('/accounts');
        return $response['account'] ?? [];
    }

    public function listContainers(string $accountId): array
    {
        $response = $this->apiGet('/accounts/' . $accountId . '/containers');
        return $response['container'] ?? [];
    }

    public function getContainerInfo(): array
    {
        $accountId = (string) Configuration::get(self::PREFIX . 'GTM_ACCOUNT_ID');
        $containerId = (string) Configuration::get(self::PREFIX . 'GTM_CONTAINER_ID');

        if (empty($accountId) || empty($containerId)) {
            return [];
        }

        return $this->apiGet('/accounts/' . $accountId . '/containers/' . $containerId);
    }

    // ── Workspace ───────────────────────────────────────────────

    public function getOrCreateWorkspace(string $accountId, string $containerId): array
    {
        $path = '/accounts/' . $accountId . '/containers/' . $containerId . '/workspaces';
        $workspaces = $this->apiGet($path);
        $list = $workspaces['workspace'] ?? [];

        // Use "Default Workspace" or first available
        foreach ($list as $ws) {
            if ($ws['name'] === 'Default Workspace') {
                return $ws;
            }
        }

        // Create a TagPilot workspace
        return $this->apiPost($path, [
            'name' => 'TagPilot Auto-Config',
            'description' => 'Created by TagPilot module for PrestaShop',
        ]);
    }

    // ── Variables ───────────────────────────────────────────────

    public function createDataLayerVariable(string $workspacePath, string $name, string $dataLayerName): array
    {
        return $this->apiPost($workspacePath . '/variables', [
            'name' => $name,
            'type' => 'v',
            'parameter' => [
                ['type' => 'integer', 'key' => 'dataLayerVersion', 'value' => '2'],
                ['type' => 'boolean', 'key' => 'setDefaultValue', 'value' => 'false'],
                ['type' => 'template', 'key' => 'name', 'value' => $dataLayerName],
            ],
        ]);
    }

    /**
     * User-Provided Data variable (GTM type "awec") for Enhanced Conversions.
     * Aggregates email/phone/address from DLV variables into one structured value
     * that GA4 and Google Ads tags can reference via {{name}}.
     */
    public function createUserProvidedDataVariable(string $workspacePath, string $name = 'TagPilot - User Provided Data'): array
    {
        return $this->apiPost($workspacePath . '/variables', [
            'name' => $name,
            'type' => 'awec',
            'parameter' => [
                ['type' => 'template', 'key' => 'mode', 'value' => 'MANUAL'],
                ['type' => 'template', 'key' => 'email', 'value' => '{{DLV - user_data.email}}'],
                ['type' => 'template', 'key' => 'phone_number', 'value' => '{{DLV - user_data.phone_number}}'],
                ['type' => 'template', 'key' => 'first_name', 'value' => '{{DLV - user_data.address.first_name}}'],
                ['type' => 'template', 'key' => 'last_name', 'value' => '{{DLV - user_data.address.last_name}}'],
                ['type' => 'template', 'key' => 'postal_code', 'value' => '{{DLV - user_data.address.postal_code}}'],
                ['type' => 'template', 'key' => 'country', 'value' => '{{DLV - user_data.address.country}}'],
            ],
        ]);
    }

    // ── Triggers ────────────────────────────────────────────────

    public function createCustomEventTrigger(string $workspacePath, string $name, string $eventName): array
    {
        return $this->apiPost($workspacePath . '/triggers', [
            'name' => $name,
            'type' => 'customEvent',
            'customEventFilter' => [
                [
                    'type' => 'equals',
                    'parameter' => [
                        ['type' => 'template', 'key' => 'arg0', 'value' => '{{_event}}'],
                        ['type' => 'template', 'key' => 'arg1', 'value' => $eventName],
                    ],
                ],
            ],
        ]);
    }

    // ── Tags ────────────────────────────────────────────────────

    public function createGA4ConfigTag(string $workspacePath, string $measurementId): array
    {
        return $this->apiPost($workspacePath . '/tags', [
            'name' => 'TagPilot - GA4 Configuration',
            'type' => 'googtag',
            'parameter' => [
                ['type' => 'template', 'key' => 'tagId', 'value' => $measurementId],
            ],
            'firingTriggerId' => [self::ALL_PAGES_TRIGGER_ID],
            // Advanced Consent Mode: tag fires even when analytics_storage=denied
            // and sends cookieless pings. Required for GA4 modeling to work.
            'consentSettings' => ['consentStatus' => 'notNeeded'],
        ]);
    }

    /**
     * @param bool $sendEcommerceData Enable "Send Ecommerce Data" ONLY for events that actually
     *                                carry an `ecommerce` block in dataLayer. Leaving it on for
     *                                page_view / login / sign_up / search triggers a GA4 warning
     *                                ("Invalid Ecommerce event name") and degrades data quality.
     */
    public function createGA4EventTag(string $workspacePath, string $name, string $eventName, string $triggerId, array $eventParams = [], string $measurementId = '', bool $sendEcommerceData = true): array
    {
        if (empty($measurementId)) {
            $measurementId = (string) Configuration::get(self::PREFIX . 'GA4_MEASUREMENT_ID');
        }

        $parameters = [
            ['type' => 'template', 'key' => 'measurementIdOverride', 'value' => $measurementId],
            ['type' => 'template', 'key' => 'eventName', 'value' => $eventName],
        ];

        if ($sendEcommerceData) {
            $parameters[] = ['type' => 'boolean', 'key' => 'sendEcommerceData', 'value' => 'true'];
            $parameters[] = ['type' => 'template', 'key' => 'getEcommerceDataFrom', 'value' => 'dataLayer'];
        }

        // Add custom event parameters if any (uses eventSettingsTable — GTM's current canonical key).
        if (!empty($eventParams)) {
            $paramList = [];
            foreach ($eventParams as $paramName => $paramValue) {
                $paramList[] = [
                    'type' => 'map',
                    'map' => [
                        ['type' => 'template', 'key' => 'parameter', 'value' => $paramName],
                        ['type' => 'template', 'key' => 'parameterValue', 'value' => $paramValue],
                    ],
                ];
            }
            $parameters[] = [
                'type' => 'list',
                'key' => 'eventSettingsTable',
                'list' => $paramList,
            ];
        }

        $tag = [
            'name' => $name,
            'type' => 'gaawe',
            'parameter' => $parameters,
            'firingTriggerId' => [$triggerId],
            // Advanced Consent Mode: event fires even when analytics_storage=denied
            // (GA4 handles consent internally via the Configuration tag).
            'consentSettings' => ['consentStatus' => 'notNeeded'],
        ];

        return $this->apiPost($workspacePath . '/tags', $tag);
    }

    public function createAdsConversionTag(string $workspacePath, string $conversionId, string $conversionLabel, string $triggerId): array
    {
        return $this->apiPost($workspacePath . '/tags', [
            'name' => 'TagPilot - Google Ads Conversion',
            'type' => 'awct',
            'parameter' => [
                ['type' => 'template', 'key' => 'conversionId', 'value' => $conversionId],
                ['type' => 'template', 'key' => 'conversionLabel', 'value' => $conversionLabel],
                ['type' => 'template', 'key' => 'conversionValue', 'value' => '{{DLV - ecommerce.value}}'],
                ['type' => 'template', 'key' => 'currencyCode', 'value' => '{{DLV - ecommerce.currency}}'],
                ['type' => 'template', 'key' => 'orderId', 'value' => '{{DLV - ecommerce.transaction_id}}'],
            ],
            'firingTriggerId' => [$triggerId],
        ]);
    }

    public function createAdsRemarketingTag(string $workspacePath, string $conversionId): array
    {
        return $this->apiPost($workspacePath . '/tags', [
            'name' => 'TagPilot - Google Ads Remarketing',
            'type' => 'sp',
            'parameter' => [
                ['type' => 'template', 'key' => 'conversionId', 'value' => $conversionId],
            ],
            'firingTriggerId' => [self::ALL_PAGES_TRIGGER_ID],
        ]);
    }

    // ── Full auto-configure ─────────────────────────────────────

    public function autoConfigureContainer(string $accountId, string $containerId, array $options = []): array
    {
        $measurementId = $options['measurement_id'] ?? '';
        $adsConversionId = $options['ads_conversion_id'] ?? '';
        $adsConversionLabel = $options['ads_conversion_label'] ?? '';

        if (empty($measurementId)) {
            return ['success' => false, 'error' => 'GA4 Measurement ID is required'];
        }

        $result = [
            'success' => true,
            'created' => ['tags' => [], 'triggers' => [], 'variables' => []],
            'errors' => [],
        ];

        // 1. Get or create workspace
        $workspace = $this->getOrCreateWorkspace($accountId, $containerId);
        if (empty($workspace['path'])) {
            return ['success' => false, 'error' => 'Failed to get workspace'];
        }
        $wsPath = $workspace['path'];

        // 2. Create DataLayer variables
        $dlVariables = [
            'DLV - ecommerce.transaction_id' => 'ecommerce.transaction_id',
            'DLV - ecommerce.value' => 'ecommerce.value',
            'DLV - ecommerce.currency' => 'ecommerce.currency',
            'DLV - ecommerce.tax' => 'ecommerce.tax',
            'DLV - ecommerce.shipping' => 'ecommerce.shipping',
            'DLV - ecommerce.items' => 'ecommerce.items',
            'DLV - search_term' => 'search_term',
            'DLV - pageCategory' => 'pageCategory',
            'DLV - user_id' => 'user_id',
            'DLV - method' => 'method',
            // Enhanced Conversions / User-Provided Data — referenced by the UDV variable below.
            'DLV - user_data.email' => 'user_data.email',
            'DLV - user_data.phone_number' => 'user_data.phone_number',
            'DLV - user_data.address.first_name' => 'user_data.address.first_name',
            'DLV - user_data.address.last_name' => 'user_data.address.last_name',
            'DLV - user_data.address.postal_code' => 'user_data.address.postal_code',
            'DLV - user_data.address.country' => 'user_data.address.country',
        ];

        foreach ($dlVariables as $name => $dlName) {
            $var = $this->createDataLayerVariable($wsPath, $name, $dlName);
            if (!empty($var['variableId'])) {
                $result['created']['variables'][] = $name;
            } else {
                $result['errors'][] = 'Variable "' . $name . '": ' . ($var['error']['message'] ?? 'unknown error');
            }
        }

        // 2b. Create User-Provided Data variable (aggregates the user_data DLV vars for Enhanced Conversions).
        $udv = $this->createUserProvidedDataVariable($wsPath);
        if (!empty($udv['variableId'])) {
            $result['created']['variables'][] = 'TagPilot - User Provided Data';
        } else {
            $result['errors'][] = 'UDV variable: ' . ($udv['error']['message'] ?? 'unknown error');
        }

        // 3. Create GA4 Config tag (fires on All Pages)
        $configTag = $this->createGA4ConfigTag($wsPath, $measurementId);
        if (!empty($configTag['tagId'])) {
            $result['created']['tags'][] = 'GA4 Configuration';
        } else {
            $result['errors'][] = 'GA4 Config tag: ' . ($configTag['error']['message'] ?? 'unknown error');
        }

        // 4. Create event triggers and GA4 event tags.
        // `ecommerce` flag controls whether the tag sends the dataLayer ecommerce block. Leaving it on
        // for non-ecommerce events (page_view/login/sign_up/search) triggers a GA4 validator warning
        // "Invalid Ecommerce event name" and degrades attribution quality — must be OFF for those.
        $events = [
            'page_view' => ['tag' => 'GA4 - page_view', 'params' => ['page_category' => '{{DLV - pageCategory}}'], 'ecommerce' => false],
            'view_item' => ['tag' => 'GA4 - view_item'],
            'view_item_list' => ['tag' => 'GA4 - view_item_list'],
            'select_item' => ['tag' => 'GA4 - select_item'],
            'add_to_cart' => ['tag' => 'GA4 - add_to_cart'],
            'remove_from_cart' => ['tag' => 'GA4 - remove_from_cart'],
            'view_cart' => ['tag' => 'GA4 - view_cart'],
            'begin_checkout' => ['tag' => 'GA4 - begin_checkout'],
            'add_shipping_info' => ['tag' => 'GA4 - add_shipping_info'],
            'add_payment_info' => ['tag' => 'GA4 - add_payment_info'],
            // Enhanced Conversions: user_data attaches the UDV variable to the purchase event.
            'purchase' => ['tag' => 'GA4 - purchase', 'params' => ['user_data' => '{{TagPilot - User Provided Data}}']],
            'refund' => ['tag' => 'GA4 - refund'],
            'login' => ['tag' => 'GA4 - login', 'params' => ['method' => '{{DLV - method}}'], 'ecommerce' => false],
            'sign_up' => ['tag' => 'GA4 - sign_up', 'params' => ['method' => '{{DLV - method}}'], 'ecommerce' => false],
            'search' => ['tag' => 'GA4 - search', 'params' => ['search_term' => '{{DLV - search_term}}'], 'ecommerce' => false],
        ];

        $purchaseTriggerId = null;

        foreach ($events as $eventName => $eventConfig) {
            // Create trigger
            $trigger = $this->createCustomEventTrigger($wsPath, 'CE - ' . $eventName, $eventName);
            $triggerId = $trigger['triggerId'] ?? '';

            if (empty($triggerId)) {
                $result['errors'][] = 'Trigger "' . $eventName . '": ' . ($trigger['error']['message'] ?? 'unknown error');
                continue;
            }
            $result['created']['triggers'][] = $eventName;

            if ($eventName === 'purchase') {
                $purchaseTriggerId = $triggerId;
            }

            // Create GA4 event tag
            $params = $eventConfig['params'] ?? [];
            $sendEcommerce = $eventConfig['ecommerce'] ?? true;
            $tag = $this->createGA4EventTag($wsPath, $eventConfig['tag'], $eventName, $triggerId, $params, $measurementId, $sendEcommerce);
            if (!empty($tag['tagId'])) {
                $result['created']['tags'][] = $eventConfig['tag'];
            } else {
                $result['errors'][] = 'Tag "' . $eventConfig['tag'] . '": ' . ($tag['error']['message'] ?? 'unknown error');
            }
        }

        // 5. Google Ads Conversion (optional)
        if (!empty($adsConversionId) && !empty($adsConversionLabel) && $purchaseTriggerId) {
            $adsTag = $this->createAdsConversionTag($wsPath, $adsConversionId, $adsConversionLabel, $purchaseTriggerId);
            if (!empty($adsTag['tagId'])) {
                $result['created']['tags'][] = 'Google Ads Conversion';
            } else {
                $result['errors'][] = 'Ads Conversion tag: ' . ($adsTag['error']['message'] ?? 'unknown error');
            }

            // Remarketing tag
            $remarketing = $this->createAdsRemarketingTag($wsPath, $adsConversionId);
            if (!empty($remarketing['tagId'])) {
                $result['created']['tags'][] = 'Google Ads Remarketing';
            } else {
                $result['errors'][] = 'Ads Remarketing tag: ' . ($remarketing['error']['message'] ?? 'unknown error');
            }
        }

        // Store workspace path for publishing
        Configuration::updateValue(self::PREFIX . 'GTM_WORKSPACE_PATH', $wsPath);

        return $result;
    }

    // ── Publish ─────────────────────────────────────────────────

    public function publishContainer(): array
    {
        $wsPath = (string) Configuration::get(self::PREFIX . 'GTM_WORKSPACE_PATH');
        if (empty($wsPath)) {
            return ['success' => false, 'error' => 'No workspace path stored'];
        }

        // Create version from workspace
        $version = $this->apiPost($wsPath . ':create_version', [
            'name' => 'TagPilot v' . date('Y-m-d H:i'),
            'notes' => 'Auto-configured by TagPilot for PrestaShop',
        ]);

        $containerVersion = $version['containerVersion'] ?? null;
        if (!$containerVersion || empty($containerVersion['path'])) {
            // Check if it returned compilerError
            if (isset($version['compilerError'])) {
                return ['success' => false, 'error' => 'Compiler error: check GTM for conflicts'];
            }
            $apiError = $version['error']['message'] ?? ('HTTP error, wsPath=' . $wsPath);
            return ['success' => false, 'error' => 'Failed to create version: ' . $apiError];
        }

        // Publish
        $publishResult = $this->apiPost($containerVersion['path'] . ':publish', null);

        if (isset($publishResult['containerVersion'])) {
            Configuration::updateValue(self::PREFIX . 'GTM_LAST_PUBLISH', date('Y-m-d H:i:s'));
            return ['success' => true, 'version' => $publishResult['containerVersion']['name'] ?? ''];
        }

        $apiError = $publishResult['error']['message'] ?? json_encode($publishResult);
        return ['success' => false, 'error' => 'Publish failed: ' . $apiError];
    }

    // ── List existing TagPilot tags ─────────────────────────────

    public function listTagPilotEntities(): array
    {
        $accountId = (string) Configuration::get(self::PREFIX . 'GTM_ACCOUNT_ID');
        $containerId = (string) Configuration::get(self::PREFIX . 'GTM_CONTAINER_ID');

        if (empty($accountId) || empty($containerId)) {
            return ['tags' => [], 'triggers' => [], 'variables' => []];
        }

        $workspace = $this->getOrCreateWorkspace($accountId, $containerId);
        if (empty($workspace['path'])) {
            return ['tags' => [], 'triggers' => [], 'variables' => []];
        }

        $wsPath = $workspace['path'];

        $tags = $this->apiGet($wsPath . '/tags');
        $triggers = $this->apiGet($wsPath . '/triggers');
        $variables = $this->apiGet($wsPath . '/variables');

        // Filter to TagPilot-created entities
        $tpTags = array_filter($tags['tag'] ?? [], function ($t) {
            return strpos($t['name'], 'TagPilot') === 0 || strpos($t['name'], 'GA4 -') === 0;
        });
        $tpTriggers = array_filter($triggers['trigger'] ?? [], function ($t) {
            return strpos($t['name'], 'CE -') === 0;
        });
        $tpVars = array_filter($variables['variable'] ?? [], function ($v) {
            return strpos($v['name'], 'DLV -') === 0;
        });

        return [
            'tags' => array_values($tpTags),
            'triggers' => array_values($tpTriggers),
            'variables' => array_values($tpVars),
        ];
    }

    // ── HTTP helpers ────────────────────────────────────────────

    private function apiGet(string $path): array
    {
        return $this->apiRequest('GET', $path);
    }

    private function apiPost(string $path, ?array $body): array
    {
        return $this->apiRequest('POST', $path, $body);
    }

    private function apiRequest(string $method, string $path, array $body = null, int $retryCount = 0): array
    {
        $token = $this->oauth->getAccessToken();
        if (empty($token)) {
            return ['error' => ['message' => 'Not authenticated']];
        }

        // Throttle writes to stay under GTM API quota (15 req/min)
        if ($method === 'POST' && $retryCount === 0) {
            usleep(500000); // 500ms between write requests
        }

        // Ensure path starts with /
        if (!empty($path) && $path[0] !== '/') {
            $path = '/' . $path;
        }
        $url = self::API_BASE . $path;

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?: [];

        // Retry on quota/rate limit errors with exponential backoff
        if (($httpCode === 429 || $httpCode === 403) && $retryCount < 5) {
            $errorMsg = $decoded['error']['message'] ?? '';
            if ($httpCode === 429 || stripos($errorMsg, 'quota') !== false || stripos($errorMsg, 'rate') !== false) {
                $waitSeconds = pow(2, $retryCount) * 2; // 2, 4, 8, 16, 32 seconds
                sleep($waitSeconds);
                return $this->apiRequest($method, $path, $body, $retryCount + 1);
            }
        }

        if ($httpCode >= 400) {
            $errorMsg = $decoded['error']['message'] ?? 'HTTP ' . $httpCode;
            return ['error' => ['message' => $errorMsg, 'code' => $httpCode]];
        }

        return $decoded;
    }
}
