<?php
/**
 * TagPilot - Google Tag Manager & GA4 Integration for PrestaShop
 *
 * @author    Flavor
 * @copyright 2026 Flavor
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class tagpilot extends Module
{
    const PREFIX = 'TAGPILOT_';

    public function __construct()
    {
        $this->name = 'tagpilot';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.3';
        $this->author = 'Flavor';
        $this->need_instance = 0;
        $this->bootstrap = false;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('TagPilot – GTM & GA4 Ecommerce', [], 'Modules.Tagpilot.Admin');
        $this->description = $this->trans('Full Google Tag Manager integration with GA4 Enhanced Ecommerce, Measurement Protocol API & Consent Mode v2.', [], 'Modules.Tagpilot.Admin');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDatabase()
            && $this->installTabs()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayAfterBodyOpeningTag')
            && $this->registerHook('displayBeforeBodyClosingTag')
            && $this->registerHook('displayOrderConfirmation')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->registerHook('actionCartUpdateQuantityBefore')
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionObjectOrderSlipAddAfter')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->setDefaultConfig();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallDatabase()
            && $this->uninstallTabs()
            && $this->deleteConfig();
    }

    // ──────────────────────────────────────────────────────────────
    //  Database
    // ──────────────────────────────────────────────────────────────

    private function installDatabase(): bool
    {
        $sql = [];
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tagpilot_event_log` (
            `id_event_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event` VARCHAR(64) NOT NULL,
            `uri` VARCHAR(512) DEFAULT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `datalayer` TEXT,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_event_log`),
            KEY `idx_event` (`event`),
            KEY `idx_date` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tagpilot_order_log` (
            `id_order_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `order_reference` VARCHAR(16) DEFAULT NULL,
            `gtm_id` VARCHAR(32) DEFAULT NULL,
            `dl_ok` TINYINT(1) NOT NULL DEFAULT 0,
            `sent_mp` TINYINT(1) NOT NULL DEFAULT 0,
            `resent` TINYINT(1) NOT NULL DEFAULT 0,
            `is_refund` TINYINT(1) NOT NULL DEFAULT 0,
            `total` DECIMAL(17,2) NOT NULL DEFAULT 0,
            `payment` VARCHAR(128) DEFAULT NULL,
            `status` VARCHAR(64) DEFAULT NULL,
            `datalayer` TEXT,
            `date_order` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_order_log`),
            KEY `idx_order` (`id_order`),
            KEY `idx_reference` (`order_reference`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDatabase(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'tagpilot_event_log`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'tagpilot_order_log`');
    }

    // ──────────────────────────────────────────────────────────────
    //  Admin Tabs
    // ──────────────────────────────────────────────────────────────

    private function installTabs(): bool
    {
        // ── Step 1: Find or create "Secret Sauce" category ──
        $improveId = (int) Tab::getIdFromClassName('IMPROVE');
        if (!$improveId) {
            $improveId = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        }
        if (!$improveId) {
            $improveId = 0;
        }

        $secretSauceClass = 'AdminSecretSauce';
        $secretSauceId = (int) Tab::getIdFromClassName($secretSauceClass);

        if (!$secretSauceId) {
            $secretSauce = new Tab();
            $secretSauce->active = 1;
            $secretSauce->class_name = $secretSauceClass;
            $secretSauce->id_parent = $improveId;
            $secretSauce->module = '';
            if (property_exists($secretSauce, 'icon')) {
                $secretSauce->icon = 'science';
            }
            foreach (Language::getLanguages(false) as $lang) {
                $secretSauce->name[(int) $lang['id_lang']] = 'Secret Sauce';
            }
            if (!$secretSauce->add()) {
                return false;
            }
            $secretSauceId = (int) $secretSauce->id;
        }

        // ── Step 2: Add TagPilot tabs ──
        // DO NOT set `route_name` on the tab. On PS 8.2, if the Symfony route
        // cache hasn't been regenerated after install (very common for the
        // first admin request post-install), AdminController::getTabs() throws
        // RouteNotFoundException and calls $this->get('logger'). When invoked
        // via Reflection by ps_edition_basic (which bypasses DI), $container
        // is null and the admin crashes with
        //   Call to a member function get() on null
        // — locking the user out of the Back Office.
        //
        // Legacy routing via class_name (AdminTagPilot → the stub controller
        // in controllers/admin/) is immune to this: PS resolves it directly
        // without hitting the Symfony router, so there's no exception to
        // trigger the container-null path.
        $tabs = [
            [
                'class_name' => 'AdminTagPilot',
                'name' => 'TagPilot',
                'id_parent' => $secretSauceId,
            ],
        ];

        foreach ($tabs as $t) {
            $existingId = (int) Tab::getIdFromClassName($t['class_name']);
            if ($existingId) {
                $tab = new Tab($existingId);
                $tab->id_parent = (int) $t['id_parent'];
                $tab->active = 1;
                // Clear any legacy `route_name` set by prior versions of this module.
                $tab->route_name = '';
                $tab->save();
                continue;
            }

            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $t['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = (int) $t['id_parent'];

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = $t['name'];
            }

            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    private function uninstallTabs(): bool
    {
        $myTabs = ['AdminTagPilot'];

        foreach ($myTabs as $class) {
            $id = (int) Tab::getIdFromClassName($class);
            if ($id) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }

        // Remove Secret Sauce only if no other children remain
        $secretSauceId = (int) Tab::getIdFromClassName('AdminSecretSauce');
        if ($secretSauceId) {
            $children = Tab::getTabs($this->context->language->id, $secretSauceId);
            if (empty($children)) {
                $ss = new Tab($secretSauceId);
                $ss->delete();
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  Configuration
    // ──────────────────────────────────────────────────────────────

    public static function cfg(string $key, $default = null)
    {
        $val = Configuration::get(self::PREFIX . $key);
        return ($val === false || $val === null) ? $default : $val;
    }

    public static function cfgSet(string $key, $value): bool
    {
        return Configuration::updateValue(self::PREFIX . $key, $value);
    }

    private function setDefaultConfig(): bool
    {
        $defaults = [
            'ENABLED' => '0',
            'GTM_ID' => '',
            'GA4_MEASUREMENT_ID' => '',
            'GA4_API_SECRET' => '',
            'LOAD_GTM_SCRIPT' => '1',
            'CONSENT_MODE' => '1',
            'CONSENT_DEFAULT_ANALYTICS' => 'denied',
            'CONSENT_DEFAULT_ADS' => 'denied',
            'SERVER_SIDE_PURCHASE' => '1',
            'SERVER_SIDE_REFUND' => '1',
            'LOG_EVENTS' => '1',
            'LOG_RETENTION_DAYS' => '30',
            'LOG_EVENT_PAGE_VIEW' => '0',
            'LOG_EVENT_VIEW_ITEM' => '0',
            'LOG_EVENT_VIEW_ITEM_LIST' => '0',
            'LOG_EVENT_SELECT_ITEM' => '0',
            'LOG_EVENT_ADD_TO_CART' => '0',
            'LOG_EVENT_REMOVE_FROM_CART' => '0',
            'LOG_EVENT_VIEW_CART' => '0',
            'LOG_EVENT_BEGIN_CHECKOUT' => '0',
            'LOG_EVENT_ADD_SHIPPING_INFO' => '0',
            'LOG_EVENT_ADD_PAYMENT_INFO' => '0',
            'LOG_EVENT_LOGIN' => '0',
            'LOG_EVENT_SIGN_UP' => '0',
            'LOG_EVENT_SEARCH' => '0',
            'EVENT_PAGE_VIEW' => '1',
            'EVENT_VIEW_ITEM' => '1',
            'EVENT_VIEW_ITEM_LIST' => '1',
            'EVENT_SELECT_ITEM' => '1',
            'EVENT_ADD_TO_CART' => '1',
            'EVENT_REMOVE_FROM_CART' => '1',
            'EVENT_VIEW_CART' => '1',
            'EVENT_BEGIN_CHECKOUT' => '1',
            'EVENT_ADD_SHIPPING_INFO' => '1',
            'EVENT_ADD_PAYMENT_INFO' => '1',
            'EVENT_PURCHASE' => '1',
            'EVENT_REFUND' => '1',
            'EVENT_LOGIN' => '1',
            'EVENT_SIGN_UP' => '1',
            'EVENT_SEARCH' => '1',
            'PRODUCT_ID_FIELD' => 'id',
            'PRICE_WITH_TAX' => '1',
            'CATEGORY_HIERARCHY' => '1',
            'CUSTOMER_DATA' => 'purchase',
            'DEBUG_MODE' => '0',
            'GOOGLE_CLIENT_ID' => '',
            'GOOGLE_CLIENT_SECRET' => '',
            'GTM_CONFIGURED' => '0',
            'ADS_CONVERSION_ID' => '',
            'ADS_CONVERSION_LABEL' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (Configuration::get(self::PREFIX . $key) === false) {
                Configuration::updateValue(self::PREFIX . $key, $value);
            }
        }

        return true;
    }

    private function deleteConfig(): bool
    {
        $keys = Db::getInstance()->executeS(
            'SELECT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE name LIKE \'' . pSQL(self::PREFIX) . '%\''
        );

        if ($keys) {
            foreach ($keys as $row) {
                Configuration::deleteByName($row['name']);
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  Admin config link
    // ──────────────────────────────────────────────────────────────

    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminTagPilot')
        );
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  Hooks
    // ──────────────────────────────────────────────────────────────

    /**
     * Inject GTM head snippet + consent defaults
     */
    public function hookDisplayHeader(array $params): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $gtmId = self::cfg('GTM_ID', '');
        if (empty($gtmId)) {
            return '';
        }

        // Consent Mode v2 defaults are owned by ConsentFlow (hookDisplayHeader
        // with higher priority). TagPilot only bootstraps GTM here.
        $this->context->smarty->assign([
            'tp_gtm_id' => $gtmId,
            'tp_debug' => (bool) self::cfg('DEBUG_MODE', false),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/head.tpl');
    }

    /**
     * GTM noscript iframe after <body>
     */
    public function hookDisplayAfterBodyOpeningTag(array $params): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $gtmId = self::cfg('GTM_ID', '');
        if (empty($gtmId)) {
            return '';
        }

        $this->context->smarty->assign(['tp_gtm_id' => $gtmId]);

        return $this->display(__FILE__, 'views/templates/hook/body_open.tpl');
    }

    /**
     * Push dataLayer events before </body>
     */
    public function hookDisplayBeforeBodyClosingTag(array $params): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $dataLayer = $this->buildPageDataLayer();

        if (empty($dataLayer)) {
            return '';
        }

        // Log event
        if ((bool) self::cfg('LOG_EVENTS', true)) {
            $this->logEvent($dataLayer);
        }

        $this->context->smarty->assign([
            'tp_datalayer_json' => json_encode($dataLayer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'tp_debug' => (bool) self::cfg('DEBUG_MODE', false),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/datalayer.tpl');
    }

    /**
     * Add module JS/CSS to front
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->context->controller->registerJavascript(
            'tagpilot-front',
            'modules/' . $this->name . '/views/js/tagpilot-front.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    /**
     * Purchase event on order confirmation page
     */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_PURCHASE', true)) {
            return '';
        }

        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return '';
        }

        $dataLayer = $this->buildPurchaseDataLayer($order);

        // Save/update order log (marks dl_ok=1 for orders that also reached the confirmation page).
        $this->logOrder($order, $dataLayer);

        // Server-side MP is NOT called here — it fires from hookActionValidateOrder instead,
        // which runs on order creation regardless of whether the customer ever reaches this page
        // (crucial for payment methods that redirect externally without returning — e.g. leasing).

        if ((bool) self::cfg('LOG_EVENTS', true)) {
            $this->logEvent($dataLayer);
        }

        $this->context->smarty->assign([
            'tp_datalayer_json' => json_encode([$dataLayer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'tp_debug' => (bool) self::cfg('DEBUG_MODE', false),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/datalayer.tpl');
    }

    /**
     * Fires whenever PrestaShop internally validates (creates) an order — before any redirect
     * to external payment/leasing systems. Runs in the browser request context that submitted
     * checkout, so $_COOKIE still carries the customer's real gtag session cookies.
     *
     * This is where server-side Measurement Protocol fires. Guarantees every order lands in
     * GA4 regardless of whether the customer ever reaches /potwierdzenie-zamowienia.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_PURCHASE', true)) {
            return;
        }
        if (!(bool) self::cfg('SERVER_SIDE_PURCHASE', true)) {
            return;
        }

        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        $dataLayer = $this->buildPurchaseDataLayer($order);

        // Log order upfront with dl_ok=false — displayOrderConfirmation will flip it to 1 later
        // if the customer actually reaches the confirmation page. dl_ok=0 on this row is a
        // useful signal that the order was tracked server-side only (e.g. external redirect
        // that never returned to the shop).
        $this->logOrder($order, $dataLayer, false, false);

        $this->sendMeasurementProtocol($dataLayer);
    }

    /**
     * Refund tracking via order slip (admin creates a partial or full refund document in BO).
     *
     * Runs in BackOffice context — no customer cookies available. Uses the server-only refund
     * path which reads user_data from the order and attribution from ps_connections_source
     * instead of the browser's _ga / _ga_<STREAM> cookies.
     */
    public function hookActionObjectOrderSlipAddAfter(array $params): void
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_REFUND', true)) {
            return;
        }

        $orderSlip = $params['object'] ?? null;
        if (!$orderSlip || !Validate::isLoadedObject($orderSlip)) {
            return;
        }

        $order = new Order((int) $orderSlip->id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        if ($this->refundAlreadyLogged((int) $order->id)) {
            return;
        }

        $dataLayer = $this->buildRefundDataLayer($order, $orderSlip);

        if ((bool) self::cfg('SERVER_SIDE_REFUND', true)) {
            $this->sendServerSideRefund($order, $dataLayer);
        }

        $this->logOrder($order, $dataLayer, true);
    }

    /**
     * Fires whenever an order's status changes. Detects transitions to a cancelled or refunded
     * state (PS_OS_CANCELED / PS_OS_REFUND) and sends a `refund` event to GA4 so the original
     * purchase revenue is reversed in the reports.
     *
     * Guards:
     *   - only fires when the order actually has a purchase event in tagpilot_order_log (no point
     *     "refunding" something GA4 never saw as a purchase)
     *   - dedupes against existing refund rows in tagpilot_order_log (status can flip back and
     *     forth in BO; we only want to send one refund per order)
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_REFUND', true)) {
            return;
        }
        if (!(bool) self::cfg('SERVER_SIDE_REFUND', true)) {
            return;
        }

        $newStatus = $params['newOrderStatus'] ?? null;
        $orderId = (int) ($params['id_order'] ?? 0);
        if (!$newStatus || !Validate::isLoadedObject($newStatus) || $orderId <= 0) {
            return;
        }

        $cancelStateIds = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'),
        ]);
        if (!in_array((int) $newStatus->id, $cancelStateIds, true)) {
            return;
        }

        // Only reverse a purchase we actually reported to GA4.
        if (!$this->purchaseWasSent($orderId)) {
            return;
        }
        if ($this->refundAlreadyLogged($orderId)) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        // Full-order refund — no OrderSlip means "reverse everything".
        $dataLayer = $this->buildRefundDataLayer($order, null);
        $this->sendServerSideRefund($order, $dataLayer);
        $this->logOrder($order, $dataLayer, true);
    }

    /**
     * Login event
     */
    public function hookActionAuthentication(array $params): void
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_LOGIN', true)) {
            return;
        }

        $this->context->cookie->__set('tp_event_login', '1');
    }

    /**
     * Sign up event
     */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        if (!$this->isActive() || !(bool) self::cfg('EVENT_SIGN_UP', true)) {
            return;
        }

        $this->context->cookie->__set('tp_event_signup', '1');
    }

    /**
     * Order status change — used for detecting refund statuses
     */
    /**
     * Cart quantity update — placeholder for future add/remove tracking
     */
    public function hookActionCartUpdateQuantityBefore(array $params): void
    {
        // Reserved for future cart quantity change tracking
    }

    // ──────────────────────────────────────────────────────────────
    //  DataLayer Builders
    // ──────────────────────────────────────────────────────────────

    private function isActive(): bool
    {
        return (bool) self::cfg('ENABLED', false) && !empty(self::cfg('GTM_ID', ''));
    }

    private function getControllerName(): string
    {
        $controller = Tools::getValue('controller', '');
        if (empty($controller) && isset($this->context->controller)) {
            $controller = get_class($this->context->controller);
        }
        return strtolower($controller);
    }

    private function getPageCategory(): string
    {
        $controller = $this->getControllerName();
        $map = [
            'index' => 'home',
            'product' => 'product',
            'category' => 'category',
            'search' => 'search',
            'cart' => 'cart',
            'order' => 'checkout',
            'orderopc' => 'checkout',
            'orderconfirmation' => 'purchase',
            'cms' => 'content',
            'contact' => 'contact',
            'myaccount' => 'account',
            'identity' => 'account',
            'history' => 'account',
            'addresses' => 'account',
            'address' => 'account',
        ];

        return $map[$controller] ?? 'other';
    }

    /**
     * Build the main dataLayer for the current page
     */
    private function buildPageDataLayer(): array
    {
        // Soft-fail: an analytics module must never bring down the storefront.
        // Any exception inside the builder is swallowed, logged, and the page
        // simply gets an empty dataLayer.
        try {
            return $this->buildPageDataLayerUnsafe();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'TagPilot: buildPageDataLayer failed — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                2,
                null,
                'TagPilot',
                null,
                true
            );
            return [];
        }
    }

    private function buildPageDataLayerUnsafe(): array
    {
        $controller = $this->getControllerName();
        $ctrl = $this->context->controller;
        $dataLayer = [];

        // page_view event
        if ((bool) self::cfg('EVENT_PAGE_VIEW', true)) {
            $dataLayer[] = [
                'event' => 'page_view',
                'pageCategory' => $this->getPageCategory(),
            ];
        }

        // Deferred events from cookies (login, sign_up)
        if ($this->context->cookie->__isset('tp_event_login')) {
            $dataLayer[] = ['event' => 'login', 'method' => 'website'];
            $this->context->cookie->__unset('tp_event_login');
        }
        if ($this->context->cookie->__isset('tp_event_signup')) {
            $dataLayer[] = ['event' => 'sign_up', 'method' => 'website'];
            $this->context->cookie->__unset('tp_event_signup');
        }

        // view_item - product page
        // Guard method_exists() — third-party controllers may share php_self="product"
        // without extending ProductControllerCore (so getProduct() may not exist).
        if ($controller === 'product' && (bool) self::cfg('EVENT_VIEW_ITEM', true) && method_exists($ctrl, 'getProduct')) {
            $product = $ctrl->getProduct();
            if ($product && is_object($product) && !empty($product->id)) {
                $item = $this->buildItemData($product);
                $dataLayer[] = [
                    'event' => 'view_item',
                    'ecommerce' => [
                        'currency' => $this->getCurrencyCode(),
                        'value' => $this->getProductPrice($product),
                        'items' => [$item],
                    ],
                ];
            }
        }

        // view_item_list - category page
        // Same guard as above — blog/CMS/landing controllers may use php_self="category"
        // and return a stdClass (or nothing) from getCategory().
        if ($controller === 'category' && (bool) self::cfg('EVENT_VIEW_ITEM_LIST', true) && method_exists($ctrl, 'getCategory')) {
            $category = $ctrl->getCategory();
            if ($category && is_object($category) && !empty($category->id)) {
                $catId = (int) $category->id;
                $catName = (string) ($category->name ?? '');
                $products = $this->context->smarty->getTemplateVars('listing')['products'] ?? [];
                $items = [];
                $totalValue = 0;
                foreach ($products as $index => $prod) {
                    $item = $this->buildItemDataFromArray($prod, $index);
                    $item['item_list_name'] = $catName;
                    $item['item_list_id'] = 'category_' . $catId;
                    $items[] = $item;
                    $totalValue += (float) ($item['price'] ?? 0);
                }
                if (!empty($items)) {
                    $dataLayer[] = [
                        'event' => 'view_item_list',
                        'ecommerce' => [
                            'currency' => $this->getCurrencyCode(),
                            'item_list_name' => $catName,
                            'item_list_id' => 'category_' . $catId,
                            'items' => $items,
                        ],
                    ];
                }
            }
        }

        // search
        if ($controller === 'search' && (bool) self::cfg('EVENT_SEARCH', true)) {
            $searchQuery = Tools::getValue('s', Tools::getValue('search_query', ''));
            if (!empty($searchQuery)) {
                $products = $this->context->smarty->getTemplateVars('listing')['products'] ?? [];
                $items = [];
                foreach ($products as $index => $prod) {
                    $item = $this->buildItemDataFromArray($prod, $index);
                    $item['item_list_name'] = 'Search Results';
                    $item['item_list_id'] = 'search_results';
                    $items[] = $item;
                }
                $dataLayer[] = [
                    'event' => 'search',
                    'search_term' => $searchQuery,
                ];
                if (!empty($items)) {
                    $dataLayer[] = [
                        'event' => 'view_item_list',
                        'ecommerce' => [
                            'currency' => $this->getCurrencyCode(),
                            'item_list_name' => 'Search Results',
                            'item_list_id' => 'search_results',
                            'items' => $items,
                        ],
                    ];
                }
            }
        }

        // view_cart
        if (in_array($controller, ['cart', 'order']) && (bool) self::cfg('EVENT_VIEW_CART', true)) {
            $cart = $this->context->cart;
            if ($cart && $cart->nbProducts() > 0) {
                $cartData = $this->buildCartDataLayer($cart);
                $dataLayer[] = [
                    'event' => 'view_cart',
                    'ecommerce' => $cartData,
                ];
            }
        }

        // begin_checkout
        if ($controller === 'order' && (bool) self::cfg('EVENT_BEGIN_CHECKOUT', true)) {
            $step = (int) Tools::getValue('step', 0);
            if ($step === 0 || Tools::getValue('action') === 'show') {
                $cart = $this->context->cart;
                if ($cart && $cart->nbProducts() > 0) {
                    $cartData = $this->buildCartDataLayer($cart);
                    $dataLayer[] = [
                        'event' => 'begin_checkout',
                        'ecommerce' => $cartData,
                    ];
                }
            }
        }

        // User data for enhanced conversions
        $customerData = $this->getCustomerData();
        if (!empty($customerData)) {
            $dataLayer[] = $customerData;
        }

        return $dataLayer;
    }

    /**
     * Build purchase dataLayer from Order object
     */
    private function buildPurchaseDataLayer(Order $order): array
    {
        $products = $order->getProducts();
        $items = [];
        $index = 0;

        foreach ($products as $product) {
            $items[] = $this->buildItemDataFromOrderProduct($product, $index++);
        }

        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        $total = $priceWithTax ? (float) $order->total_paid_tax_incl : (float) $order->total_paid_tax_excl;
        $shipping = $priceWithTax ? (float) $order->total_shipping_tax_incl : (float) $order->total_shipping_tax_excl;
        $tax = (float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl;

        $idField = self::cfg('PRODUCT_ID_FIELD', 'id');
        $transactionId = $idField === 'reference' ? $order->reference : (string) $order->id;

        $payload = [
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => $transactionId,
                'currency' => $this->getCurrencyCode(),
                'value' => round($total, 2),
                'tax' => round($tax, 2),
                'shipping' => round($shipping, 2),
                'items' => $items,
            ],
        ];

        // Enhanced Conversions / User-Provided Data — raw (NOT hashed); GTM/gtag hashes before send.
        if (self::cfg('CUSTOMER_DATA', 'purchase') !== 'never') {
            $userData = $this->getUserDataFromOrder($order);
            if (!empty($userData)) {
                $payload['user_data'] = $userData;
                if ((int) $order->id_customer > 0) {
                    $payload['user_id'] = (string) $order->id_customer;
                }
            }
        }

        return $payload;
    }

    /**
     * Build refund dataLayer
     */
    /**
     * @param OrderSlip|null $orderSlip pass null for a full-order refund (e.g. status change to
     *                                  cancelled) — then all order line items are included and
     *                                  the ecommerce.value is the full order total.
     */
    private function buildRefundDataLayer(Order $order, $orderSlip = null): array
    {
        $idField = self::cfg('PRODUCT_ID_FIELD', 'id');
        $transactionId = $idField === 'reference' ? $order->reference : (string) $order->id;

        $items = [];
        $isFullRefund = ($orderSlip === null);

        if ($orderSlip !== null && property_exists($orderSlip, 'product_quantity_list') && !empty($orderSlip->product_quantity_list)) {
            // partial refund (order slip covers specific products)
            $products = $order->getProducts();
            foreach ($products as $product) {
                $items[] = $this->buildItemDataFromOrderProduct($product, 0);
            }
        } elseif ($isFullRefund) {
            // full-order refund — every product from the order
            $products = $order->getProducts();
            foreach ($products as $product) {
                $items[] = $this->buildItemDataFromOrderProduct($product, 0);
            }
        }

        $ecommerce = [
            'transaction_id' => $transactionId,
            'currency' => $this->getCurrencyCode(),
        ];

        // Include the reversed value only on full refunds so GA4 subtracts the whole order.
        if ($isFullRefund) {
            $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
            $ecommerce['value'] = round((float) ($priceWithTax ? $order->total_paid_tax_incl : $order->total_paid_tax_excl), 2);
        }

        if (!empty($items)) {
            $ecommerce['items'] = $items;
        }

        return [
            'event' => 'refund',
            'ecommerce' => $ecommerce,
        ];
    }

    /**
     * Build cart ecommerce data
     */
    private function buildCartDataLayer(Cart $cart): array
    {
        $products = $cart->getProducts(true);
        $items = [];
        $totalValue = 0;
        $index = 0;

        foreach ($products as $product) {
            $item = $this->buildItemDataFromArray($product, $index++);
            $items[] = $item;
            $totalValue += (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        return [
            'currency' => $this->getCurrencyCode(),
            'value' => round($totalValue, 2),
            'items' => $items,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Item builders
    // ──────────────────────────────────────────────────────────────

    private function buildItemData(Product $product, int $index = 0): array
    {
        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        $price = $priceWithTax
            ? Product::getPriceStatic($product->id, true, null, 2)
            : Product::getPriceStatic($product->id, false, null, 2);

        $item = [
            'item_id' => $this->getProductIdentifier($product),
            'item_name' => $product->name,
            'price' => round($price, 2),
            'quantity' => 1,
            'index' => $index,
        ];

        // Brand
        if ($product->id_manufacturer) {
            $item['item_brand'] = Manufacturer::getNameById((int) $product->id_manufacturer);
        }

        // Categories
        $this->addCategoryHierarchy($item, (int) $product->id_category_default);

        return $item;
    }

    private function buildItemDataFromArray(array|\ArrayAccess $product, int $index = 0): array
    {
        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        $price = $priceWithTax
            ? ($product['price_wt'] ?? $product['price'] ?? 0)
            : ($product['price'] ?? $product['price_tax_exc'] ?? 0);

        $quantity = (int) ($product['cart_quantity'] ?? $product['quantity'] ?? 1);

        $item = [
            'item_id' => $this->getProductIdentifierFromArray($product),
            'item_name' => is_array($product['name'] ?? null) ? reset($product['name']) : ($product['name'] ?? ''),
            'price' => round((float) $price, 2),
            'quantity' => $quantity,
            'index' => $index,
        ];

        // Brand
        $manufacturer = $product['manufacturer_name'] ?? null;
        if ($manufacturer) {
            $item['item_brand'] = $manufacturer;
        }

        // Category
        $categoryId = (int) ($product['id_category_default'] ?? 0);
        if ($categoryId) {
            $this->addCategoryHierarchy($item, $categoryId);
        }

        // Variant
        if (!empty($product['attributes_small'])) {
            $item['item_variant'] = $product['attributes_small'];
        }

        return $item;
    }

    private function buildItemDataFromOrderProduct(array $product, int $index = 0): array
    {
        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        $price = $priceWithTax
            ? (float) ($product['unit_price_tax_incl'] ?? 0)
            : (float) ($product['unit_price_tax_excl'] ?? 0);

        $item = [
            'item_id' => $this->getProductIdentifierFromArray($product),
            'item_name' => $product['product_name'] ?? '',
            'price' => round($price, 2),
            'quantity' => (int) ($product['product_quantity'] ?? 1),
            'index' => $index,
        ];

        if (!empty($product['product_reference'])) {
            $item['item_reference'] = $product['product_reference'];
        }

        $categoryId = (int) ($product['id_category_default'] ?? 0);
        if ($categoryId) {
            $this->addCategoryHierarchy($item, $categoryId);
        }

        return $item;
    }

    private function getProductIdentifier($product): string
    {
        $field = self::cfg('PRODUCT_ID_FIELD', 'id');
        switch ($field) {
            case 'reference':
                return (string) ($product->reference ?? $product->id);
            case 'ean13':
                return (string) ($product->ean13 ?? $product->id);
            default:
                return (string) $product->id;
        }
    }

    private function getProductIdentifierFromArray(array|\ArrayAccess $product): string
    {
        $field = self::cfg('PRODUCT_ID_FIELD', 'id');
        switch ($field) {
            case 'reference':
                return (string) ($product['reference'] ?? $product['product_reference'] ?? $product['id_product'] ?? '');
            case 'ean13':
                return (string) ($product['ean13'] ?? $product['product_ean13'] ?? $product['id_product'] ?? '');
            default:
                return (string) ($product['id_product'] ?? $product['product_id'] ?? '');
        }
    }

    private function getProductPrice(Product $product): float
    {
        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        return round(Product::getPriceStatic($product->id, $priceWithTax, null, 2), 2);
    }

    private function addCategoryHierarchy(array &$item, int $categoryId): void
    {
        if (!$categoryId) {
            return;
        }

        $category = new Category($categoryId, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($category)) {
            return;
        }

        $item['item_category'] = $category->name;

        if ((bool) self::cfg('CATEGORY_HIERARCHY', true)) {
            $parents = $category->getParentsCategories((int) $this->context->language->id);
            $parents = array_reverse($parents);
            // Remove root and home categories
            $parents = array_filter($parents, function ($cat) {
                return (int) $cat['id_category'] > 2;
            });
            $parents = array_values($parents);

            $level = 2;
            foreach ($parents as $parent) {
                if ((int) $parent['id_category'] === $categoryId) {
                    continue;
                }
                $key = 'item_category' . ($level <= 5 ? $level : 5);
                if (!isset($item[$key])) {
                    $item[$key] = $parent['name'];
                    $level++;
                }
                if ($level > 5) {
                    break;
                }
            }
        }
    }

    private function getCurrencyCode(): string
    {
        return $this->context->currency->iso_code ?? 'PLN';
    }

    // ──────────────────────────────────────────────────────────────
    //  Customer Data (Enhanced Conversions)
    // ──────────────────────────────────────────────────────────────

    /**
     * User data for logged-in customers on non-purchase pages.
     * Returns a separate dataLayer entry; purchase page builds user_data from $order instead.
     */
    private function getCustomerData(): array
    {
        $mode = self::cfg('CUSTOMER_DATA', 'purchase');
        if ($mode === 'never') {
            return [];
        }

        $pageCategory = $this->getPageCategory();
        // On the purchase page, user_data is merged into the purchase event itself
        // (see buildPurchaseDataLayer), so skip the standalone push here.
        if ($pageCategory === 'purchase') {
            return [];
        }
        if ($mode === 'purchase') {
            return [];
        }

        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged() || empty($customer->email)) {
            return [];
        }

        $userData = ['email' => $this->normalizeEmail($customer->email)];

        $address = $this->getCustomerDefaultAddress($customer);
        if ($address) {
            $phone = $this->normalizePhone($address->phone_mobile ?? $address->phone, (int) $address->id_country);
            if ($phone !== '') {
                $userData['phone_number'] = $phone;
            }
            $userData['address'] = $this->buildAddressData($address);
        }

        return [
            'user_id' => (string) $customer->id,
            'user_data' => $userData,
        ];
    }

    /**
     * Build user_data array from an Order — works for both guests and logged-in customers.
     * Returns [] if there is no usable email.
     */
    private function getUserDataFromOrder(Order $order): array
    {
        $customer = new Customer((int) $order->id_customer);
        $email = Validate::isLoadedObject($customer) ? (string) $customer->email : '';
        if ($email === '') {
            return [];
        }

        $userData = ['email' => $this->normalizeEmail($email)];

        $addressId = (int) ($order->id_address_invoice ?: $order->id_address_delivery);
        if ($addressId > 0) {
            $address = new Address($addressId);
            if (Validate::isLoadedObject($address)) {
                $phone = $this->normalizePhone($address->phone_mobile ?? $address->phone, (int) $address->id_country);
                if ($phone !== '') {
                    $userData['phone_number'] = $phone;
                }
                $userData['address'] = $this->buildAddressData($address);
            }
        }

        return $userData;
    }

    private function buildAddressData(Address $address): array
    {
        return [
            'first_name' => (string) $address->firstname,
            'last_name' => (string) $address->lastname,
            'city' => (string) $address->city,
            'postal_code' => (string) $address->postcode,
            'country' => (string) Country::getIsoById((int) $address->id_country),
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Normalize phone to E.164 (e.g. +48600100200).
     * - Strips spaces, dashes, parentheses, dots.
     * - "00xx..." → "+xx..."
     * - Bare number without prefix → prepend country calling code derived from $countryId.
     * Returns '' when no usable digits remain.
     */
    private function normalizePhone(?string $phone, int $countryId = 0): string
    {
        if ($phone === null) {
            return '';
        }
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlus = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '' || $digits === null) {
            return '';
        }

        if ($hasPlus) {
            return '+' . $digits;
        }
        if (strpos($digits, '00') === 0) {
            return '+' . substr($digits, 2);
        }

        $callingCode = $this->getCountryCallingCode($countryId);
        if ($callingCode !== '') {
            // Strip a leading "0" trunk prefix (common in PL/EU) before prepending country code.
            $digits = ltrim($digits, '0');
            if ($digits === '') {
                return '';
            }
            return '+' . $callingCode . $digits;
        }

        // Last resort: return digits with leading "+" — best-effort, GTM/gtag will still hash.
        return '+' . $digits;
    }

    /**
     * Resolve the country calling code (without "+") from a PrestaShop country id.
     * Falls back to PL configuration or "48" so PL phones still land in E.164.
     */
    private function getCountryCallingCode(int $countryId): string
    {
        static $cache = [];
        if ($countryId > 0 && isset($cache[$countryId])) {
            return $cache[$countryId];
        }

        $iso = $countryId > 0 ? Country::getIsoById($countryId) : '';
        if (!$iso) {
            $iso = (string) Configuration::get('PS_COUNTRY_DEFAULT_ISO');
            if ($iso === '') {
                $defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
                $iso = $defaultCountryId > 0 ? (string) Country::getIsoById($defaultCountryId) : 'PL';
            }
        }

        $map = [
            'PL' => '48', 'DE' => '49', 'CZ' => '420', 'SK' => '421', 'AT' => '43',
            'GB' => '44', 'UK' => '44', 'FR' => '33', 'IT' => '39', 'ES' => '34',
            'NL' => '31', 'BE' => '32', 'DK' => '45', 'SE' => '46', 'NO' => '47',
            'FI' => '358', 'IE' => '353', 'PT' => '351', 'CH' => '41', 'HU' => '36',
            'RO' => '40', 'BG' => '359', 'GR' => '30', 'LT' => '370', 'LV' => '371',
            'EE' => '372', 'SI' => '386', 'HR' => '385', 'LU' => '352', 'MT' => '356',
            'CY' => '357', 'US' => '1', 'CA' => '1',
        ];

        $code = $map[strtoupper($iso)] ?? '48';
        if ($countryId > 0) {
            $cache[$countryId] = $code;
        }
        return $code;
    }

    private function getCustomerDefaultAddress(Customer $customer): ?Address
    {
        $addresses = $customer->getAddresses((int) $this->context->language->id);
        if (empty($addresses)) {
            return null;
        }

        $addr = reset($addresses);
        $address = new Address((int) $addr['id_address']);
        return Validate::isLoadedObject($address) ? $address : null;
    }

    // ──────────────────────────────────────────────────────────────
    //  GA4 Measurement Protocol
    // ──────────────────────────────────────────────────────────────

    /**
     * Send a server-side event via GA4 Measurement Protocol.
     *
     * Consent Mode contract (ConsentFlow integration):
     *   - Requires analytics_storage=granted (reads flavor_cookie_consent).
     *   - Requires a REAL GA4 session context (client_id + session_id read from
     *     the browser's _ga / _ga_XXXXXXXXX cookies set by gtag).
     *   - NEVER fabricates client_id. NEVER sends without session_id.
     *
     * Sending MP without session_id creates a synthetic Google-signals-less
     * session on GA4's side — which then wins transaction_id dedupe against the
     * (correctly-attributed) browser-side purchase and permanently attributes
     * the transaction to "Unassigned". That is the exact bug we are avoiding.
     *
     * Payload attaches session_id + engagement_time_msec inside event params
     * (as required by GA4 MP for session stitching) and respects ad_user_data
     * consent when relaying any user_data block.
     */
    private function sendMeasurementProtocol(array $dataLayer): bool
    {
        $measurementId = self::cfg('GA4_MEASUREMENT_ID', '');
        $apiSecret = self::cfg('GA4_API_SECRET', '');

        if (empty($measurementId) || empty($apiSecret)) {
            return false;
        }

        // Consent gate — never bypass ConsentFlow.
        $consent = $this->getConsentState();
        if (($consent['analytics'] ?? false) !== true) {
            return false;
        }

        // Session gate — no fabricated identifiers.
        $session = $this->getGaSessionContext($measurementId);
        if ($session === null) {
            return false;
        }

        $event = $dataLayer['event'] ?? '';
        $eventParams = $dataLayer['ecommerce'] ?? $dataLayer;
        unset($eventParams['event'], $eventParams['ecommerce'], $eventParams['user_data'], $eventParams['user_id']);

        // GA4 MP requires session_id in event params to stitch onto the browser session
        // (so this event carries source/medium/campaign already captured by gtag).
        $eventParams['session_id'] = $session['session_id'];
        $eventParams['engagement_time_msec'] = 100;

        $payload = [
            'client_id' => $session['client_id'],
            'events' => [
                [
                    'name' => $event,
                    'params' => $eventParams,
                ],
            ],
        ];

        // user_id: only when logged in AND we already have real gtag session (implied by session gate above).
        if ($this->context->customer && $this->context->customer->isLogged()) {
            $payload['user_id'] = (string) $this->context->customer->id;
        }

        // Enhanced Conversions via MP — only when ad_user_data consent is granted.
        // Browser-side gtag already sends user_data with hashing, so this is redundancy
        // for adblock cases. Values must be SHA-256 hashed for MP (unlike gtag).
        if (($consent['ad_user_data'] ?? false) === true && !empty($dataLayer['user_data'])) {
            $hashedUserData = $this->buildHashedUserDataForMp($dataLayer['user_data']);
            if (!empty($hashedUserData)) {
                $payload['user_data'] = $hashedUserData;
            }
        }

        $url = 'https://www.google-analytics.com/mp/collect'
            . '?measurement_id=' . urlencode($measurementId)
            . '&api_secret=' . urlencode($apiSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Read ConsentFlow's consent cookie and expose the signals TagPilot cares about.
     * Returns a normalized array; missing cookie / bad JSON → all false.
     */
    private function getConsentState(): array
    {
        $default = [
            'analytics' => false,
            'marketing' => false,
            'ad_user_data' => false,
            'ad_personalization' => false,
        ];

        $raw = $_COOKIE['flavor_cookie_consent'] ?? '';
        if ($raw === '' || !is_string($raw)) {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['necessary'])) {
            return $default;
        }

        $marketing = !empty($decoded['marketing']);
        return [
            'analytics' => !empty($decoded['analytics']),
            'marketing' => $marketing,
            'ad_user_data' => $marketing,
            'ad_personalization' => $marketing,
        ];
    }

    /**
     * Read the real GA4 session context from cookies set by gtag on the browser.
     * Returns ['client_id' => ..., 'session_id' => ...] or null when either cookie
     * is missing / malformed. NEVER fabricates identifiers.
     *
     * Cookie formats handled:
     *   _ga:                    GA1.<domainDepth>.<clientId1>.<clientId2>
     *   _ga_XXXXXXXXX (GS1):    GS1.1.<sessionId>.<sessionNum>.<engaged>.<hits>.<ts>.<nextTs>.0
     *   _ga_XXXXXXXXX (GS2):    GS2.1.s<sessionId>$o<sessionNum>$g<engaged>$t<lastHitTs>$j<n>$l<n>$h<n>
     *                                        ^ Google switched to "$"-separated compact format ~2024.
     */
    private function getGaSessionContext(string $measurementId): ?array
    {
        $gaCookie = $_COOKIE['_ga'] ?? '';
        if (!is_string($gaCookie) || $gaCookie === '') {
            return null;
        }
        $parts = explode('.', $gaCookie);
        if (count($parts) < 4) {
            return null;
        }
        $clientId = $parts[2] . '.' . $parts[3];

        // Derive stream cookie name: G-XXXXXXXXXX → _ga_XXXXXXXXXX
        $streamSuffix = strpos($measurementId, 'G-') === 0 ? substr($measurementId, 2) : $measurementId;
        $streamCookieName = '_ga_' . $streamSuffix;

        $streamCookie = $_COOKIE[$streamCookieName] ?? '';
        if (!is_string($streamCookie) || $streamCookie === '') {
            return null;
        }

        // Regex handles both formats:
        //   GS2.1.s<sessionId>$... → group 1
        //   GS1.1.<sessionId>.<sessionNum>... → group 2
        if (!preg_match('/^GS[12]\.\d+\.(?:s(\d+)|(\d+))/', $streamCookie, $m)) {
            return null;
        }
        $sessionId = ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        if ($sessionId === '' || !ctype_digit($sessionId)) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'session_id' => $sessionId,
        ];
    }

    /**
     * Build the SHA-256 hashed user_data block that GA4 Measurement Protocol expects.
     * Different from gtag which auto-hashes raw values — for MP we MUST hash before send.
     * Reference: https://developers.google.com/analytics/devguides/collection/protocol/ga4/user-data
     */
    private function buildHashedUserDataForMp(array $userData): array
    {
        $out = [];

        if (!empty($userData['email'])) {
            $out['sha256_email_address'] = [hash('sha256', strtolower(trim((string) $userData['email'])))];
        }
        if (!empty($userData['phone_number'])) {
            // Phone already normalized to E.164 (with leading +) by normalizePhone() before it hit dataLayer.
            $out['sha256_phone_number'] = [hash('sha256', trim((string) $userData['phone_number']))];
        }

        $address = $userData['address'] ?? null;
        if (is_array($address) && !empty($address)) {
            $addrBlock = [];
            if (!empty($address['first_name'])) {
                $addrBlock['sha256_first_name'] = hash('sha256', strtolower(trim((string) $address['first_name'])));
            }
            if (!empty($address['last_name'])) {
                $addrBlock['sha256_last_name'] = hash('sha256', strtolower(trim((string) $address['last_name'])));
            }
            if (!empty($address['postal_code'])) {
                $addrBlock['postal_code'] = (string) $address['postal_code'];
            }
            if (!empty($address['country'])) {
                $addrBlock['country'] = strtoupper((string) $address['country']);
            }
            if (!empty($addrBlock)) {
                $out['address'] = [$addrBlock];
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    //  Logging
    // ──────────────────────────────────────────────────────────────

    private function logEvent(array $dataLayer): void
    {
        $event = 'batch';
        if (count($dataLayer) === 1) {
            $event = $dataLayer[0]['event'] ?? 'unknown';
        } elseif (!empty($dataLayer['event'])) {
            $event = $dataLayer['event'];
            $dataLayer = [$dataLayer];
        }

        if (!$this->shouldLogEvent($event, $dataLayer)) {
            return;
        }

        Db::getInstance()->insert('tagpilot_event_log', [
            'event' => pSQL($event),
            'uri' => pSQL(substr($_SERVER['REQUEST_URI'] ?? '', 0, 512)),
            'id_shop' => (int) $this->context->shop->id,
            'datalayer' => pSQL(json_encode($dataLayer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if an event should be logged based on config.
     * Purchase and refund are always logged (business critical).
     */
    private function shouldLogEvent(string $event, array $dataLayer): bool
    {
        // Essential events — always logged
        $alwaysLog = ['purchase', 'refund'];

        // For batch events, check if any sub-event is essential
        if ($event === 'batch') {
            foreach ($dataLayer as $item) {
                $subEvent = $item['event'] ?? '';
                if (in_array($subEvent, $alwaysLog, true)) {
                    return true;
                }
            }
            // For non-essential batches, check if at least one sub-event has log flag
            foreach ($dataLayer as $item) {
                $subEvent = $item['event'] ?? '';
                if ($subEvent && $this->isEventLoggingEnabled($subEvent)) {
                    return true;
                }
            }
            return false;
        }

        if (in_array($event, $alwaysLog, true)) {
            return true;
        }

        return $this->isEventLoggingEnabled($event);
    }

    private function isEventLoggingEnabled(string $event): bool
    {
        $key = 'LOG_EVENT_' . strtoupper($event);
        return (bool) self::cfg($key, false);
    }

    /**
     * @param bool $dlOk true when called from a browser context (hookDisplayOrderConfirmation)
     *                   meaning the customer actually reached the confirmation page and the
     *                   dataLayer purchase event was pushed client-side. false when called from
     *                   hookActionValidateOrder (server-only path — no guarantee the customer's
     *                   browser will ever see the confirmation page).
     */
    private function logOrder(Order $order, array $dataLayer, bool $isRefund = false, bool $dlOk = true): void
    {
        $priceWithTax = (bool) self::cfg('PRICE_WITH_TAX', true);
        $total = $priceWithTax ? $order->total_paid_tax_incl : $order->total_paid_tax_excl;
        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');

        // Upsert by id_order — actionValidateOrder + displayOrderConfirmation both call this
        // for the same order; we don't want duplicate rows.
        $existingId = (int) $db->getValue(
            'SELECT id_order_log FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`
             WHERE id_order = ' . (int) $order->id . ' AND is_refund = ' . (int) $isRefund . ' LIMIT 1'
        );

        if ($existingId > 0) {
            $update = [
                'datalayer' => pSQL(json_encode($dataLayer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
                'date_upd' => $now,
            ];
            // Only ever flip dl_ok from 0 to 1 — never back.
            if ($dlOk) {
                $update['dl_ok'] = 1;
            }
            $db->update('tagpilot_order_log', $update, 'id_order_log = ' . $existingId);
            return;
        }

        $db->insert('tagpilot_order_log', [
            'id_order' => (int) $order->id,
            'order_reference' => pSQL($order->reference),
            'gtm_id' => pSQL(self::cfg('GTM_ID', '')),
            'dl_ok' => (int) $dlOk,
            'sent_mp' => (int) (bool) self::cfg('SERVER_SIDE_PURCHASE', true),
            'resent' => 0,
            'is_refund' => (int) $isRefund,
            'total' => (float) $total,
            'payment' => pSQL($order->payment),
            'status' => pSQL(''),
            'datalayer' => pSQL(json_encode($dataLayer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'date_order' => pSQL($order->date_add),
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Server-side refund (BackOffice context — no customer cookies)
    // ──────────────────────────────────────────────────────────────

    private function refundAlreadyLogged(int $orderId): bool
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_order_log FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`
             WHERE id_order = ' . $orderId . ' AND is_refund = 1 LIMIT 1'
        ) > 0;
    }

    private function purchaseWasSent(int $orderId): bool
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_order_log FROM `' . _DB_PREFIX_ . 'tagpilot_order_log`
             WHERE id_order = ' . $orderId . ' AND is_refund = 0 AND sent_mp = 1 LIMIT 1'
        ) > 0;
    }

    /**
     * Send a refund event via GA4 Measurement Protocol from BackOffice context.
     *
     * Unlike sendMeasurementProtocol() which requires browser cookies (_ga / _ga_<STREAM> /
     * flavor_cookie_consent), this method works from admin-side hooks where no customer session
     * cookies are available. Session context and attribution are reconstructed from:
     *   • ps_connections_source (source / medium / campaign / gclid of the original session)
     *   • Order data (user_data hashed for Enhanced Conversions, deterministic client_id)
     *
     * timestamp_micros is set to "now" — GA4 MP would drop events older than 72h and the refund
     * decision was made "now" from the admin's perspective anyway.
     */
    private function sendServerSideRefund(Order $order, array $dataLayer): bool
    {
        $measurementId = self::cfg('GA4_MEASUREMENT_ID', '');
        $apiSecret = self::cfg('GA4_API_SECRET', '');
        if (empty($measurementId) || empty($apiSecret)) {
            return false;
        }

        $eventParams = $dataLayer['ecommerce'] ?? [];
        // GA4 requires session_id + engagement_time_msec on events for proper stitching.
        $eventParams['session_id'] = (string) time();
        $eventParams['engagement_time_msec'] = 100;

        // Attribution — read the customer's original session sources so the refund reverses in
        // the right channel (or attach explicit campaign params if we recognize a paid click).
        $attribution = $this->getOrderAttribution((int) $order->id);
        foreach (['campaign_source', 'campaign_medium', 'campaign_name', 'campaign_id', 'gclid', 'gbraid'] as $k) {
            if (!empty($attribution[$k])) {
                $eventParams[$k] = $attribution[$k];
            }
        }

        $payload = [
            'client_id' => $this->deriveDeterministicClientId((int) $order->id_customer, (int) $order->id),
            'timestamp_micros' => time() * 1_000_000,
            'non_personalized_ads' => false,
            'events' => [[
                'name' => 'refund',
                'params' => $eventParams,
            ]],
        ];
        if ((int) $order->id_customer > 0) {
            $payload['user_id'] = (string) $order->id_customer;
        }

        // Enhanced Conversions user_data — hashed per MP spec.
        $userData = $this->buildHashedUserDataForOrder($order);
        if (!empty($userData)) {
            $payload['user_data'] = $userData;
        }

        $url = 'https://www.google-analytics.com/mp/collect'
            . '?measurement_id=' . urlencode($measurementId)
            . '&api_secret=' . urlencode($apiSecret);

        return $this->postJsonToGa($url, $payload);
    }

    /**
     * Read the customer's session sources for this order from ps_connections* and pick the best
     * attribution signal. Priority: Google Ads click id > utm_* > referrer inference > direct.
     */
    private function getOrderAttribution(int $orderId): array
    {
        $default = ['campaign_source' => '', 'campaign_medium' => '', 'campaign_name' => '', 'campaign_id' => '', 'gclid' => '', 'gbraid' => ''];

        $sources = Db::getInstance()->executeS(
            "SELECT cs.http_referer, cs.request_uri
             FROM `" . _DB_PREFIX_ . "orders` o
             JOIN `" . _DB_PREFIX_ . "cart` c ON c.id_cart = o.id_cart
             JOIN `" . _DB_PREFIX_ . "connections` conn ON conn.id_guest = c.id_guest
             JOIN `" . _DB_PREFIX_ . "connections_source` cs ON cs.id_connections = conn.id_connections
             WHERE o.id_order = " . $orderId . "
             ORDER BY cs.date_add ASC"
        );
        if (empty($sources)) {
            return $default;
        }

        $best = $default;
        foreach ($sources as $s) {
            $parsed = $this->parseAttributionFromUrl((string) ($s['request_uri'] ?? ''), (string) ($s['http_referer'] ?? ''));
            if ($parsed['gclid'] !== '' || $parsed['gbraid'] !== '') {
                return $parsed; // best possible signal, no need to keep looking
            }
            if ($best['campaign_source'] === '' && $parsed['campaign_source'] !== '') {
                $best = $parsed;
            }
        }
        return $best;
    }

    private function parseAttributionFromUrl(string $uri, string $ref): array
    {
        $out = ['campaign_source' => '', 'campaign_medium' => '', 'campaign_name' => '', 'campaign_id' => '', 'gclid' => '', 'gbraid' => ''];
        $q = [];
        if (($qs = parse_url($uri, PHP_URL_QUERY)) !== null && $qs !== false) {
            parse_str((string) $qs, $q);
        }

        if (!empty($q['gclid'])) {
            $out['gclid'] = (string) $q['gclid'];
            $out['campaign_source'] = 'google';
            $out['campaign_medium'] = 'cpc';
        }
        if (!empty($q['gbraid']) || !empty($q['wbraid'])) {
            $out['gbraid'] = (string) ($q['gbraid'] ?? $q['wbraid']);
            if ($out['campaign_source'] === '') {
                $out['campaign_source'] = 'google';
                $out['campaign_medium'] = 'cpc';
            }
        }
        if (!empty($q['gad_campaignid'])) {
            $out['campaign_id'] = (string) $q['gad_campaignid'];
        }
        if (!empty($q['gad_source']) && $out['campaign_source'] === '') {
            $out['campaign_source'] = 'google';
            $out['campaign_medium'] = 'cpc';
        }
        if (!empty($q['utm_source']))   $out['campaign_source'] = (string) $q['utm_source'];
        if (!empty($q['utm_medium']))   $out['campaign_medium'] = (string) $q['utm_medium'];
        if (!empty($q['utm_campaign'])) $out['campaign_name']   = (string) $q['utm_campaign'];
        if (!empty($q['utm_id']))       $out['campaign_id']     = (string) $q['utm_id'];

        if ($out['campaign_source'] === '' && $ref !== '') {
            $host = strtolower(preg_replace('/^www\./', '', (string) parse_url($ref, PHP_URL_HOST)));
            if (preg_match('/^(google\.|bing\.|duckduckgo\.|yahoo\.)/', $host)) {
                $out['campaign_source'] = preg_replace('/\..*/', '', $host);
                $out['campaign_medium'] = 'organic';
            } elseif (preg_match('/(facebook\.|instagram\.|twitter\.|x\.com|linkedin\.|tiktok\.)/', $host)) {
                $out['campaign_source'] = preg_replace('/\..*/', '', $host);
                $out['campaign_medium'] = 'social';
            } elseif ($host !== '') {
                $out['campaign_source'] = $host;
                $out['campaign_medium'] = 'referral';
            }
        }
        return $out;
    }

    private function buildHashedUserDataForOrder(Order $order): array
    {
        $out = [];
        $customer = new Customer((int) $order->id_customer);
        if (Validate::isLoadedObject($customer) && !empty($customer->email)) {
            $out['sha256_email_address'] = [hash('sha256', strtolower(trim((string) $customer->email)))];
        }
        $addressId = (int) ($order->id_address_invoice ?: $order->id_address_delivery);
        if ($addressId > 0) {
            $address = new Address($addressId);
            if (Validate::isLoadedObject($address)) {
                $phone = $this->normalizePhone($address->phone_mobile ?? $address->phone, (int) $address->id_country);
                if ($phone !== '') {
                    $out['sha256_phone_number'] = [hash('sha256', $phone)];
                }
                $addr = array_filter([
                    'sha256_first_name' => !empty($address->firstname) ? hash('sha256', strtolower(trim($address->firstname))) : null,
                    'sha256_last_name' => !empty($address->lastname) ? hash('sha256', strtolower(trim($address->lastname))) : null,
                    'postal_code' => (string) $address->postcode,
                    'country' => strtoupper((string) Country::getIsoById((int) $address->id_country)),
                ], fn($v) => $v !== null && $v !== '');
                if (!empty($addr)) {
                    $out['address'] = [$addr];
                }
            }
        }
        return $out;
    }

    private function deriveDeterministicClientId(int $customerId, int $orderId): string
    {
        if ($customerId > 0) {
            return hexdec(substr(md5('tp.' . $customerId), 0, 8)) . '.' . strtotime('2020-01-01');
        }
        return $orderId . '.' . strtotime('2020-01-01');
    }

    /**
     * POST a JSON payload to a Google endpoint. Uses curl when available, else stream wrappers
     * so the module works on installs where the curl extension is absent from PHP.
     */
    private function postJsonToGa(string $url, array $payload): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
        return $code >= 200 && $code < 300;
    }
}
