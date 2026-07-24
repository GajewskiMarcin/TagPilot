<?php

declare(strict_types=1);

namespace Flavor\TagPilot\Controller\Admin;

use Configuration;
use Db;
use Flavor\TagPilot\Service\GoogleOAuthService;
use Flavor\TagPilot\Service\GtmApiService;
use Module;
use Order;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

class ApiController extends BaseController
{
    private const PREFIX = 'TAGPILOT_';

    /**
     * Save configuration
     */
    public function saveConfig(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (empty($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }

        $allowedKeys = [
            'ENABLED', 'GTM_ID', 'GA4_MEASUREMENT_ID', 'GA4_API_SECRET',
            'LOAD_GTM_SCRIPT', 'CONSENT_MODE', 'CONSENT_DEFAULT_ANALYTICS',
            'CONSENT_DEFAULT_ADS', 'SERVER_SIDE_PURCHASE', 'SERVER_SIDE_REFUND',
            'LOG_EVENTS', 'LOG_RETENTION_DAYS', 'PRODUCT_ID_FIELD',
            'PRICE_WITH_TAX', 'CATEGORY_HIERARCHY', 'CUSTOMER_DATA', 'DEBUG_MODE',
            'EVENT_PAGE_VIEW', 'EVENT_VIEW_ITEM', 'EVENT_VIEW_ITEM_LIST',
            'EVENT_SELECT_ITEM', 'EVENT_ADD_TO_CART', 'EVENT_REMOVE_FROM_CART',
            'EVENT_VIEW_CART', 'EVENT_BEGIN_CHECKOUT', 'EVENT_ADD_SHIPPING_INFO',
            'EVENT_ADD_PAYMENT_INFO', 'EVENT_PURCHASE', 'EVENT_REFUND',
            'EVENT_LOGIN', 'EVENT_SIGN_UP', 'EVENT_SEARCH',
            'GTM_CONFIGURED', 'ADS_CONVERSION_ID', 'ADS_CONVERSION_LABEL',
            'LOG_EVENT_PAGE_VIEW', 'LOG_EVENT_VIEW_ITEM', 'LOG_EVENT_VIEW_ITEM_LIST',
            'LOG_EVENT_SELECT_ITEM', 'LOG_EVENT_ADD_TO_CART', 'LOG_EVENT_REMOVE_FROM_CART',
            'LOG_EVENT_VIEW_CART', 'LOG_EVENT_BEGIN_CHECKOUT', 'LOG_EVENT_ADD_SHIPPING_INFO',
            'LOG_EVENT_ADD_PAYMENT_INFO', 'LOG_EVENT_LOGIN', 'LOG_EVENT_SIGN_UP',
            'LOG_EVENT_SEARCH',
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                Configuration::updateValue(self::PREFIX . $key, $value);
            }
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Test GA4 Measurement Protocol connection
     */
    public function testConnection(Request $request): JsonResponse
    {
        $measurementId = Configuration::get(self::PREFIX . 'GA4_MEASUREMENT_ID');
        $apiSecret = Configuration::get(self::PREFIX . 'GA4_API_SECRET');

        if (empty($measurementId) || empty($apiSecret)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'GA4 Measurement ID and API Secret are required.',
            ]);
        }

        // Send a validation request to the debug endpoint
        $url = 'https://www.google-analytics.com/debug/mp/collect'
            . '?measurement_id=' . urlencode($measurementId)
            . '&api_secret=' . urlencode($apiSecret);

        $payload = [
            'client_id' => 'tagpilot_test_' . time(),
            'events' => [
                [
                    'name' => 'tagpilot_test',
                    'params' => [
                        'test' => true,
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Connection failed: ' . $error,
            ]);
        }

        $body = json_decode($response, true);
        $validationMessages = $body['validationMessages'] ?? [];

        return new JsonResponse([
            'success' => $httpCode >= 200 && $httpCode < 300 && empty($validationMessages),
            'httpCode' => $httpCode,
            'validationMessages' => $validationMessages,
            'response' => $body,
        ]);
    }

    /**
     * Re-send order to GA4 via Measurement Protocol
     */
    public function resendOrder(int $id, Request $request): JsonResponse
    {
        $db = Db::getInstance();
        $log = $db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_order_log` WHERE id_order_log = ' . (int) $id
        );

        if (!$log) {
            return new JsonResponse(['success' => false, 'error' => 'Order log not found'], 404);
        }

        $datalayer = json_decode($log['datalayer'], true);
        if (empty($datalayer)) {
            return new JsonResponse(['success' => false, 'error' => 'No dataLayer data found'], 400);
        }

        $module = Module::getInstanceByName('tagpilot');
        $measurementId = Configuration::get(self::PREFIX . 'GA4_MEASUREMENT_ID');
        $apiSecret = Configuration::get(self::PREFIX . 'GA4_API_SECRET');

        if (empty($measurementId) || empty($apiSecret)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'GA4 Measurement Protocol not configured.',
            ]);
        }

        $event = $datalayer['event'] ?? 'purchase';
        $eventParams = $datalayer['ecommerce'] ?? $datalayer;
        unset($eventParams['event']);

        $payload = [
            'client_id' => 'ps.resend.' . $log['id_order'] . '.' . time(),
            'events' => [
                [
                    'name' => $event,
                    'params' => $eventParams,
                ],
            ],
        ];

        $url = 'https://www.google-analytics.com/mp/collect'
            . '?measurement_id=' . urlencode($measurementId)
            . '&api_secret=' . urlencode($apiSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $success = $httpCode >= 200 && $httpCode < 300;

        if ($success) {
            $db->update('tagpilot_order_log', [
                'resent' => 1,
                'sent_mp' => 1,
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_order_log = ' . (int) $id);
        }

        return new JsonResponse([
            'success' => $success,
            'httpCode' => $httpCode,
        ]);
    }

    /**
     * Purge old event logs
     */
    public function purgeLogs(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $purgeAll = !empty($data['all']);

        $db = Db::getInstance();
        $before = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`'
        );

        if ($purgeAll) {
            $db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'tagpilot_event_log`');
            $days = 0;
        } else {
            $days = (int) Configuration::get(self::PREFIX . 'LOG_RETENTION_DAYS');
            if ($days <= 0) {
                $days = 30;
            }
            $db->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`
                 WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
            );
        }

        $remaining = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`'
        );
        $deleted = $before - $remaining;

        return new JsonResponse([
            'success' => true,
            'deleted' => $deleted,
            'remaining' => $remaining,
            'days' => $days,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  OAuth & GTM API
    // ──────────────────────────────────────────────────────────────

    public function saveOAuthCredentials(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $clientId = trim($data['client_id'] ?? '');
        $clientSecret = trim($data['client_secret'] ?? '');

        if (empty($clientId) || empty($clientSecret)) {
            return new JsonResponse(['success' => false, 'error' => 'Client ID and Secret are required'], 400);
        }

        Configuration::updateValue(self::PREFIX . 'GOOGLE_CLIENT_ID', $clientId);
        Configuration::updateValue(self::PREFIX . 'GOOGLE_CLIENT_SECRET', $clientSecret);

        return new JsonResponse(['success' => true]);
    }

    public function oauthStart(Request $request): JsonResponse
    {
        $oauth = new GoogleOAuthService();

        if (!$oauth->isConfigured()) {
            return new JsonResponse(['success' => false, 'error' => 'OAuth credentials not configured'], 400);
        }

        $context = \Context::getContext();
        $redirectUri = $context->link->getModuleLink('tagpilot', 'oauthcallback', [], true);

        $state = bin2hex(random_bytes(16));
        Configuration::updateValue(self::PREFIX . 'OAUTH_STATE', $state);

        // Save wizard return URL so front controller callback can redirect back
        $router = $this->psRouter;
        $wizardUrl = $request->getSchemeAndHttpHost() . $router->generate('tagpilot_wizard');
        Configuration::updateValue(self::PREFIX . 'OAUTH_RETURN_URL', $wizardUrl);

        $authUrl = $oauth->getAuthorizationUrl($redirectUri, $state);

        return new JsonResponse(['success' => true, 'url' => $authUrl]);
    }

    public function oauthCallback(Request $request): Response
    {
        $code = $request->query->get('code', '');
        $state = $request->query->get('state', '');
        $error = $request->query->get('error', '');

        $router = $this->psRouter;
        $wizardUrl = $router->generate('tagpilot_wizard');

        if ($error) {
            return new RedirectResponse($wizardUrl . '?oauth_error=' . urlencode($error));
        }

        // Verify state
        $savedState = (string) Configuration::get(self::PREFIX . 'OAUTH_STATE');
        if (empty($state) || $state !== $savedState) {
            return new RedirectResponse($wizardUrl . '?oauth_error=invalid_state');
        }

        Configuration::deleteByName(self::PREFIX . 'OAUTH_STATE');

        $oauth = new GoogleOAuthService();
        $redirectUri = $request->getSchemeAndHttpHost() . $router->generate('tagpilot_oauth_callback');
        $result = $oauth->exchangeCode($code, $redirectUri);

        if (isset($result['access_token'])) {
            return new RedirectResponse($wizardUrl . '?oauth_success=1');
        }

        $errorMsg = $result['error_description'] ?? $result['error'] ?? 'Token exchange failed';
        return new RedirectResponse($wizardUrl . '?oauth_error=' . urlencode($errorMsg));
    }

    public function gtmAccounts(): JsonResponse
    {
        $oauth = new GoogleOAuthService();
        $gtm = new GtmApiService($oauth);

        $accounts = $gtm->listAccounts();

        if (isset($accounts['error'])) {
            return new JsonResponse(['success' => false, 'error' => $accounts['error']['message'] ?? 'Failed to fetch accounts']);
        }

        return new JsonResponse(['success' => true, 'accounts' => $accounts]);
    }

    public function gtmContainers(string $accountId): JsonResponse
    {
        $oauth = new GoogleOAuthService();
        $gtm = new GtmApiService($oauth);

        $containers = $gtm->listContainers($accountId);

        if (isset($containers['error'])) {
            return new JsonResponse(['success' => false, 'error' => $containers['error']['message'] ?? 'Failed to fetch containers']);
        }

        return new JsonResponse(['success' => true, 'containers' => $containers]);
    }

    public function gtmConfigure(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $accountId = $data['account_id'] ?? '';
        $containerId = $data['container_id'] ?? '';
        $containerPublicId = $data['container_public_id'] ?? '';
        $containerName = $data['container_name'] ?? '';
        $measurementId = $data['measurement_id'] ?? '';
        $adsConversionId = $data['ads_conversion_id'] ?? '';
        $adsConversionLabel = $data['ads_conversion_label'] ?? '';

        if (empty($accountId) || empty($containerId) || empty($measurementId)) {
            return new JsonResponse(['success' => false, 'error' => 'Account, container and Measurement ID are required'], 400);
        }

        // Store selected container info
        Configuration::updateValue(self::PREFIX . 'GTM_ACCOUNT_ID', $accountId);
        Configuration::updateValue(self::PREFIX . 'GTM_CONTAINER_ID', $containerId);
        Configuration::updateValue(self::PREFIX . 'GTM_CONTAINER_NAME', $containerName);
        Configuration::updateValue(self::PREFIX . 'GA4_MEASUREMENT_ID', $measurementId);

        if ($containerPublicId) {
            Configuration::updateValue(self::PREFIX . 'GTM_ID', $containerPublicId);
        }

        if ($adsConversionId) {
            Configuration::updateValue(self::PREFIX . 'ADS_CONVERSION_ID', $adsConversionId);
        }
        if ($adsConversionLabel) {
            Configuration::updateValue(self::PREFIX . 'ADS_CONVERSION_LABEL', $adsConversionLabel);
        }

        // Run auto-configuration
        $oauth = new GoogleOAuthService();
        $gtm = new GtmApiService($oauth);

        $result = $gtm->autoConfigureContainer($accountId, $containerId, [
            'measurement_id' => $measurementId,
            'ads_conversion_id' => $adsConversionId,
            'ads_conversion_label' => $adsConversionLabel,
        ]);

        if ($result['success']) {
            Configuration::updateValue(self::PREFIX . 'GTM_CONFIGURED', '1');
            Configuration::updateValue(self::PREFIX . 'ENABLED', '1');
        }

        return new JsonResponse($result);
    }

    public function gtmPublish(): JsonResponse
    {
        $oauth = new GoogleOAuthService();
        $gtm = new GtmApiService($oauth);

        $result = $gtm->publishContainer();

        return new JsonResponse($result);
    }

    public function gtmDisconnect(): JsonResponse
    {
        $oauth = new GoogleOAuthService();
        $oauth->disconnect();

        return new JsonResponse(['success' => true]);
    }

    public function gtmStatus(): JsonResponse
    {
        $oauth = new GoogleOAuthService();

        return new JsonResponse([
            'isConfigured' => $oauth->isConfigured(),
            'isConnected' => $oauth->isConnected(),
            'gtmConfigured' => (bool) Configuration::get(self::PREFIX . 'GTM_CONFIGURED'),
            'gtmId' => (string) Configuration::get(self::PREFIX . 'GTM_ID'),
            'containerName' => (string) Configuration::get(self::PREFIX . 'GTM_CONTAINER_NAME'),
            'measurementId' => (string) Configuration::get(self::PREFIX . 'GA4_MEASUREMENT_ID'),
            'lastPublish' => (string) Configuration::get(self::PREFIX . 'GTM_LAST_PUBLISH'),
        ]);
    }
}
