<?php

declare(strict_types=1);

namespace Flavor\TagPilot\Controller\Admin;

use Configuration;
use Db;
use Flavor\TagPilot\Service\GoogleOAuthService;
use Flavor\TagPilot\Service\GtmApiService;
use Module;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

class TagPilotController extends BaseController
{
    private const PREFIX = 'TAGPILOT_';
    private const PER_PAGE = 30;

    public function __construct(RouterInterface $psRouter)
    {
        parent::__construct($psRouter);
    }

    private function getModule(): Module
    {
        return Module::getInstanceByName('tagpilot');
    }

    private function nav(string $active): array
    {
        $router = $this->psRouter;
        $tabs = [
            ['key' => 'dashboard', 'label' => $this->trans('Dashboard', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_dashboard'],
            ['key' => 'wizard', 'label' => $this->trans('GTM Setup', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_wizard'],
            ['key' => 'config', 'label' => $this->trans('Configuration', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_config'],
            ['key' => 'events', 'label' => $this->trans('Events', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_events'],
            ['key' => 'datalayer', 'label' => $this->trans('DataLayer Log', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_datalayer_log'],
            ['key' => 'orders', 'label' => $this->trans('Order Log', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_order_log'],
            ['key' => 'debug', 'label' => $this->trans('Debug', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_debug'],
            ['key' => 'support', 'label' => $this->trans('Support', 'Modules.Tagpilot.Admin'), 'route' => 'tagpilot_support'],
        ];

        return array_map(function ($tab) use ($active, $router) {
            return [
                'label' => $tab['label'],
                'url' => $router->generate($tab['route']),
                'active' => $tab['key'] === $active,
            ];
        }, $tabs);
    }

    private function cfg(string $key, $default = null)
    {
        $val = Configuration::get(self::PREFIX . $key);
        return ($val === false || $val === null) ? $default : $val;
    }

    // ──────────────────────────────────────────────────────────────
    //  Dashboard
    // ──────────────────────────────────────────────────────────────

    public function dashboard(): Response
    {
        $db = Db::getInstance();

        // Stats
        $totalEvents = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`'
        );
        $totalOrders = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_order_log` WHERE is_refund = 0'
        );
        $sentMp = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_order_log` WHERE sent_mp = 1 AND is_refund = 0'
        );
        $refunds = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_order_log` WHERE is_refund = 1'
        );
        $todayEvents = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log` WHERE date_add >= CURDATE()'
        );

        // Recent events
        $recentEvents = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`
             ORDER BY date_add DESC LIMIT 10'
        ) ?: [];

        // Recent orders
        $recentOrders = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`
             ORDER BY date_add DESC LIMIT 10'
        ) ?: [];

        return $this->render('@Modules/tagpilot/views/templates/admin/dashboard.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('dashboard'),
            'page' => 'dashboard',
            'moduleVersion' => $this->getModule()->version,
            'isEnabled' => (bool) $this->cfg('ENABLED', false),
            'gtmId' => $this->cfg('GTM_ID', ''),
            'ga4MeasurementId' => $this->cfg('GA4_MEASUREMENT_ID', ''),
            'hasApiSecret' => !empty($this->cfg('GA4_API_SECRET', '')),
            'stats' => [
                'totalEvents' => $totalEvents,
                'todayEvents' => $todayEvents,
                'totalOrders' => $totalOrders,
                'sentMp' => $sentMp,
                'refunds' => $refunds,
            ],
            'recentEvents' => $recentEvents,
            'recentOrders' => $recentOrders,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Configuration
    // ──────────────────────────────────────────────────────────────

    public function configuration(Request $request): Response
    {
        $configKeys = [
            'ENABLED', 'GTM_ID', 'GA4_MEASUREMENT_ID', 'GA4_API_SECRET',
            'LOAD_GTM_SCRIPT', 'CONSENT_MODE', 'CONSENT_DEFAULT_ANALYTICS',
            'CONSENT_DEFAULT_ADS', 'SERVER_SIDE_PURCHASE', 'SERVER_SIDE_REFUND',
            'LOG_EVENTS', 'LOG_RETENTION_DAYS', 'PRODUCT_ID_FIELD',
            'PRICE_WITH_TAX', 'CATEGORY_HIERARCHY', 'CUSTOMER_DATA', 'DEBUG_MODE',
            'LOG_EVENT_PAGE_VIEW', 'LOG_EVENT_VIEW_ITEM', 'LOG_EVENT_VIEW_ITEM_LIST',
            'LOG_EVENT_SELECT_ITEM', 'LOG_EVENT_ADD_TO_CART', 'LOG_EVENT_REMOVE_FROM_CART',
            'LOG_EVENT_VIEW_CART', 'LOG_EVENT_BEGIN_CHECKOUT', 'LOG_EVENT_ADD_SHIPPING_INFO',
            'LOG_EVENT_ADD_PAYMENT_INFO', 'LOG_EVENT_LOGIN', 'LOG_EVENT_SIGN_UP',
            'LOG_EVENT_SEARCH',
        ];

        $config = [];
        foreach ($configKeys as $key) {
            $config[$key] = $this->cfg($key, '');
        }

        return $this->render('@Modules/tagpilot/views/templates/admin/configuration.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('config'),
            'page' => 'configuration',
            'moduleVersion' => $this->getModule()->version,
            'config' => $config,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Events configuration
    // ──────────────────────────────────────────────────────────────

    public function events(): Response
    {
        $eventKeys = [
            'EVENT_PAGE_VIEW', 'EVENT_VIEW_ITEM', 'EVENT_VIEW_ITEM_LIST',
            'EVENT_SELECT_ITEM', 'EVENT_ADD_TO_CART', 'EVENT_REMOVE_FROM_CART',
            'EVENT_VIEW_CART', 'EVENT_BEGIN_CHECKOUT', 'EVENT_ADD_SHIPPING_INFO',
            'EVENT_ADD_PAYMENT_INFO', 'EVENT_PURCHASE', 'EVENT_REFUND',
            'EVENT_LOGIN', 'EVENT_SIGN_UP', 'EVENT_SEARCH',
        ];

        $events = [];
        foreach ($eventKeys as $key) {
            $events[$key] = (bool) $this->cfg($key, true);
        }

        $eventDescriptions = [
            'EVENT_PAGE_VIEW' => ['name' => 'page_view', 'desc' => 'Fired on every page load', 'type' => 'page'],
            'EVENT_VIEW_ITEM' => ['name' => 'view_item', 'desc' => 'Product detail page', 'type' => 'ecommerce'],
            'EVENT_VIEW_ITEM_LIST' => ['name' => 'view_item_list', 'desc' => 'Category / search results', 'type' => 'ecommerce'],
            'EVENT_SELECT_ITEM' => ['name' => 'select_item', 'desc' => 'Product click from listing', 'type' => 'ecommerce'],
            'EVENT_ADD_TO_CART' => ['name' => 'add_to_cart', 'desc' => 'Product added to cart', 'type' => 'ecommerce'],
            'EVENT_REMOVE_FROM_CART' => ['name' => 'remove_from_cart', 'desc' => 'Product removed from cart', 'type' => 'ecommerce'],
            'EVENT_VIEW_CART' => ['name' => 'view_cart', 'desc' => 'Cart page viewed', 'type' => 'ecommerce'],
            'EVENT_BEGIN_CHECKOUT' => ['name' => 'begin_checkout', 'desc' => 'Checkout started', 'type' => 'ecommerce'],
            'EVENT_ADD_SHIPPING_INFO' => ['name' => 'add_shipping_info', 'desc' => 'Shipping method selected', 'type' => 'ecommerce'],
            'EVENT_ADD_PAYMENT_INFO' => ['name' => 'add_payment_info', 'desc' => 'Payment method selected', 'type' => 'ecommerce'],
            'EVENT_PURCHASE' => ['name' => 'purchase', 'desc' => 'Order confirmed (client + server-side)', 'type' => 'conversion'],
            'EVENT_REFUND' => ['name' => 'refund', 'desc' => 'Order refunded (server-side)', 'type' => 'conversion'],
            'EVENT_LOGIN' => ['name' => 'login', 'desc' => 'Customer logged in', 'type' => 'engagement'],
            'EVENT_SIGN_UP' => ['name' => 'sign_up', 'desc' => 'New account created', 'type' => 'engagement'],
            'EVENT_SEARCH' => ['name' => 'search', 'desc' => 'Search performed', 'type' => 'engagement'],
        ];

        return $this->render('@Modules/tagpilot/views/templates/admin/events.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('events'),
            'page' => 'events',
            'moduleVersion' => $this->getModule()->version,
            'events' => $events,
            'eventDescriptions' => $eventDescriptions,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  DataLayer Log
    // ──────────────────────────────────────────────────────────────

    public function datalayerLog(Request $request): Response
    {
        $db = Db::getInstance();
        $page = max(1, (int) $request->query->get('p', 1));
        $filterEvent = $request->query->get('event', '');

        $where = '1';
        if ($filterEvent) {
            $where = "event = '" . pSQL($filterEvent) . "'";
        }

        $total = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log` WHERE ' . $where
        );

        $offset = ($page - 1) * self::PER_PAGE;
        $logs = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`
             WHERE ' . $where . '
             ORDER BY date_add DESC
             LIMIT ' . (int) $offset . ', ' . self::PER_PAGE
        ) ?: [];

        // Available event types for filter
        $eventTypes = $db->executeS(
            'SELECT DISTINCT event FROM `' . _DB_PREFIX_ . 'tagpilot_event_log` ORDER BY event'
        ) ?: [];

        return $this->render('@Modules/tagpilot/views/templates/admin/datalayer_log.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('datalayer'),
            'page' => 'datalayer',
            'moduleVersion' => $this->getModule()->version,
            'logs' => $logs,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'perPage' => self::PER_PAGE,
            'filterEvent' => $filterEvent,
            'eventTypes' => array_column($eventTypes, 'event'),
        ]));
    }

    public function eventDetail(int $id): Response
    {
        $db = Db::getInstance();
        $log = $db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_event_log` WHERE id_event_log = ' . (int) $id
        );

        if (!$log) {
            $this->addFlash('error', $this->trans('Event log not found.', 'Modules.Tagpilot.Admin'));
            return $this->redirectToRoute('tagpilot_datalayer_log');
        }

        return $this->render('@Modules/tagpilot/views/templates/admin/event_detail.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('datalayer'),
            'page' => 'datalayer',
            'moduleVersion' => $this->getModule()->version,
            'log' => $log,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Order Log
    // ──────────────────────────────────────────────────────────────

    public function orderLog(Request $request): Response
    {
        $db = Db::getInstance();
        $page = max(1, (int) $request->query->get('p', 1));

        $total = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`'
        );

        $offset = ($page - 1) * self::PER_PAGE;
        $logs = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`
             ORDER BY date_add DESC
             LIMIT ' . (int) $offset . ', ' . self::PER_PAGE
        ) ?: [];

        return $this->render('@Modules/tagpilot/views/templates/admin/order_log.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('orders'),
            'page' => 'orders',
            'moduleVersion' => $this->getModule()->version,
            'logs' => $logs,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'perPage' => self::PER_PAGE,
        ]));
    }

    public function orderDetail(int $id): Response
    {
        $db = Db::getInstance();
        $log = $db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_order_log` WHERE id_order_log = ' . (int) $id
        );

        if (!$log) {
            $this->addFlash('error', $this->trans('Order log not found.', 'Modules.Tagpilot.Admin'));
            return $this->redirectToRoute('tagpilot_order_log');
        }

        return $this->render('@Modules/tagpilot/views/templates/admin/order_detail.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('orders'),
            'page' => 'orders',
            'moduleVersion' => $this->getModule()->version,
            'log' => $log,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Debug
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  GTM Wizard
    // ──────────────────────────────────────────────────────────────

    public function wizard(): Response
    {
        $oauth = new GoogleOAuthService();
        $gtm = new GtmApiService($oauth);

        $isOAuthConfigured = $oauth->isConfigured();
        $isConnected = $oauth->isConnected();
        $gtmConfigured = (bool) $this->cfg('GTM_CONFIGURED', false);

        // Determine current step
        $step = 1;
        if ($isOAuthConfigured && $isConnected) {
            $step = 2;
        }
        if ($gtmConfigured) {
            $step = 3;
        }

        // Load accounts if connected
        $accounts = [];
        if ($isConnected) {
            $accounts = $gtm->listAccounts();
        }

        // Load existing entities if configured
        $entities = [];
        if ($gtmConfigured) {
            $entities = $gtm->listTagPilotEntities();
        }

        return $this->render('@Modules/tagpilot/views/templates/admin/wizard.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('wizard'),
            'page' => 'wizard',
            'moduleVersion' => $this->getModule()->version,
            'step' => $step,
            'isOAuthConfigured' => $isOAuthConfigured,
            'isConnected' => $isConnected,
            'gtmConfigured' => $gtmConfigured,
            'accounts' => $accounts,
            'selectedAccountId' => $this->cfg('GTM_ACCOUNT_ID', ''),
            'selectedContainerId' => $this->cfg('GTM_CONTAINER_ID', ''),
            'selectedContainerName' => $this->cfg('GTM_CONTAINER_NAME', ''),
            'ga4MeasurementId' => $this->cfg('GA4_MEASUREMENT_ID', ''),
            'gtmId' => $this->cfg('GTM_ID', ''),
            'lastPublish' => $this->cfg('GTM_LAST_PUBLISH', ''),
            'entities' => $entities,
            'adsConversionId' => $this->cfg('ADS_CONVERSION_ID', ''),
            'adsConversionLabel' => $this->cfg('ADS_CONVERSION_LABEL', ''),
        ]));
    }

    public function debug(): Response
    {
        $db = Db::getInstance();

        $recentEvents = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`
             ORDER BY date_add DESC LIMIT 20'
        ) ?: [];

        // Installed hooks
        $hooks = $db->executeS(
            'SELECT h.name, hp.position
             FROM `' . _DB_PREFIX_ . 'hook_module` hp
             JOIN `' . _DB_PREFIX_ . 'hook` h ON hp.id_hook = h.id_hook
             JOIN `' . _DB_PREFIX_ . 'module` m ON hp.id_module = m.id_module
             WHERE m.name = "tagpilot"
             ORDER BY h.name'
        ) ?: [];

        return $this->render('@Modules/tagpilot/views/templates/admin/debug.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('debug'),
            'page' => 'debug',
            'moduleVersion' => $this->getModule()->version,
            'isEnabled' => (bool) $this->cfg('ENABLED', false),
            'gtmId' => $this->cfg('GTM_ID', ''),
            'debugMode' => (bool) $this->cfg('DEBUG_MODE', false),
            'hooks' => $hooks,
            'recentEvents' => $recentEvents,
            'config' => [
                'GA4_MEASUREMENT_ID' => $this->cfg('GA4_MEASUREMENT_ID', ''),
                'SERVER_SIDE_PURCHASE' => (bool) $this->cfg('SERVER_SIDE_PURCHASE', true),
                'CONSENT_MODE' => (bool) $this->cfg('CONSENT_MODE', true),
            ],
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Support
    // ──────────────────────────────────────────────────────────────

    public function support(): Response
    {
        $db = Db::getInstance();
        $oauth = new GoogleOAuthService();

        $totalEvents = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tagpilot_event_log`'
        );

        return $this->render('@Modules/tagpilot/views/templates/admin/support.html.twig', array_merge($this->getHeaderVars(), [
            'nav' => $this->nav('support'),
            'page' => 'support',
            'moduleVersion' => $this->getModule()->version,
            'isEnabled' => (bool) $this->cfg('ENABLED', false),
            'gtmId' => $this->cfg('GTM_ID', ''),
            'ga4MeasurementId' => $this->cfg('GA4_MEASUREMENT_ID', ''),
            'hasApiSecret' => !empty($this->cfg('GA4_API_SECRET', '')),
            'gtmApiConnected' => $oauth->isConnected(),
            'consentMode' => (bool) $this->cfg('CONSENT_MODE', true),
            'totalEvents' => $totalEvents,
        ]));
    }
}
