<?php
/**
 * TagPilot — one-off GA4 backfill for orders that missed live tracking.
 *
 * Finds PrestaShop orders from the last 72 hours that are MISSING from
 * `tagpilot_order_log` (typically SimplyLease / external-redirect payment
 * methods where the customer never returned to /potwierdzenie-zamowienia)
 * and sends each to GA4 Measurement Protocol with:
 *
 *   • full ecommerce block (transaction_id, value, tax, shipping, items)
 *   • timestamp_micros = actual order date (so events land on the right day)
 *   • campaign_source / campaign_medium / campaign_name / gclid parsed from
 *     ps_connections_source URLs — so GA4 attributes to the real channel
 *     instead of dumping the transaction into "Unassigned"
 *   • Enhanced Conversions user_data (SHA-256 hashed email / phone / address)
 *     — so Google Ads can match the conversion back to the click that
 *     brought the customer, via the GA4↔Ads link
 *
 * Hard limit: GA4 silently drops MP events older than 72 hours. This script
 * won't even try to send those (it filters at the SQL level).
 *
 * Usage:
 *   # from shop root:
 *   php modules/tagpilot/tools/backfill-ga4.php           # dry-run (default)
 *   php modules/tagpilot/tools/backfill-ga4.php --run     # actually send (last 72h)
 *   php modules/tagpilot/tools/backfill-ga4.php --run --payment=SimplyLease
 *
 *   # For orders OLDER than 72h (whole month reconciliation):
 *   php modules/tagpilot/tools/backfill-ga4.php --run --force-recent --hours=720
 *
 * --force-recent overrides the 72h GA4 MP hard limit by REWRITING each order's
 * timestamp_micros to "just now" (spread across the last hour, 1s apart). This
 * means all backfilled events land on TODAY in GA4 daily reports (not on their
 * real order date) — monthly totals reconcile, but daily/hourly graphs will
 * show a spike today and gaps historically. Use when you care about totals
 * and Google Ads conversion match, not about historical daily distribution.
 *
 * After a successful send each order is inserted into tagpilot_order_log so
 * the script is idempotent — re-running skips already-processed orders.
 */

declare(strict_types=1);

// ── CLI arg parsing ─────────────────────────────────────────────────────────
$opts = getopt('', ['run', 'dry-run', 'hours::', 'payment::', 'force-recent']);
$dryRun = !isset($opts['run']);
$forceRecent = isset($opts['force-recent']);
$maxHours = $forceRecent ? 720 : 72; // 30 days when forcing, 72h GA4 hard limit otherwise
$hours = isset($opts['hours']) ? max(1, min($maxHours, (int) $opts['hours'])) : ($forceRecent ? 720 : 72);
$paymentFilter = isset($opts['payment']) ? (string) $opts['payment'] : '';

// ── PrestaShop bootstrap ────────────────────────────────────────────────────
// Script sits in modules/tagpilot/tools/ — shop root is 3 levels up.
$shopRoot = realpath(__DIR__ . '/../../..');
if (!$shopRoot || !file_exists($shopRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "ERROR: cannot locate PrestaShop config from " . __DIR__ . "\n");
    exit(1);
}
require_once $shopRoot . '/config/config.inc.php';

// ── Config check ────────────────────────────────────────────────────────────
$measurementId = (string) Configuration::get('TAGPILOT_GA4_MEASUREMENT_ID');
$apiSecret = (string) Configuration::get('TAGPILOT_GA4_API_SECRET');
$priceWithTax = (bool) Configuration::get('TAGPILOT_PRICE_WITH_TAX');
$idFieldConfig = (string) Configuration::get('TAGPILOT_PRODUCT_ID_FIELD') ?: 'id';

if ($measurementId === '' || $apiSecret === '') {
    fwrite(STDERR, "ERROR: TAGPILOT_GA4_MEASUREMENT_ID or TAGPILOT_GA4_API_SECRET missing in configuration\n");
    exit(1);
}

// ── PS Context bootstrap for CLI ────────────────────────────────────────────
// Web requests get Context populated by the FrontController; CLI does not.
// Without this, `new Order(...)` blows up inside PS currency-formatting code
// (ComputingPrecision::getPrecision on a null currency).
$ctx = Context::getContext();
if (!$ctx->shop || !$ctx->shop->id) {
    $ctx->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT') ?: 1);
    Shop::setContext(Shop::CONTEXT_SHOP, (int) $ctx->shop->id);
}
if (!$ctx->currency || !$ctx->currency->id) {
    $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
}
if (!$ctx->language || !$ctx->language->id) {
    $ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
}
if (!$ctx->country || !$ctx->country->id) {
    $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
}
if (!$ctx->employee || !$ctx->employee->id) {
    // Not strictly required for order reads, but some hooks probe it — cheap safety.
    $ctx->employee = new Employee(1);
}

// ── Banner ──────────────────────────────────────────────────────────────────
echo str_repeat('=', 72) . "\n";
echo "TagPilot GA4 Backfill\n";
echo str_repeat('=', 72) . "\n";
echo "Mode:         " . ($dryRun ? 'DRY-RUN (nothing sent)' : 'LIVE (sending to GA4)') . "\n";
echo "Window:       last {$hours}h\n";
echo "Measurement:  {$measurementId}\n";
echo "Payment:      " . ($paymentFilter !== '' ? $paymentFilter : '(all)') . "\n";
if ($forceRecent) {
    echo "Timestamp:    FORCE-RECENT — all events sent with 'just now' timestamp\n";
    echo "              → daily/hourly reports in GA4 will show spike TODAY\n";
    echo "              → monthly totals will reconcile, Ads conversions will match\n";
}
echo str_repeat('=', 72) . "\n\n";

// Track spread offset for --force-recent mode. Starts at (now - 3600s), incremented by 1s
// per order so each event has a unique timestamp and they land in chronological order
// within the last hour.
$recentOffsetBase = time() - 3600;
$recentOffsetCounter = 0;

// ── Fetch orders ────────────────────────────────────────────────────────────
$db = Db::getInstance();

$paymentClause = '';
if ($paymentFilter !== '') {
    $paymentClause = " AND o.payment LIKE '%" . pSQL($paymentFilter) . "%'";
}

$sql = "
    SELECT o.id_order, o.reference, o.id_customer, o.id_cart,
           o.id_address_invoice, o.id_address_delivery,
           o.total_paid_tax_incl, o.total_paid_tax_excl,
           o.total_shipping_tax_incl, o.total_shipping_tax_excl,
           o.date_add, o.payment, o.id_currency
    FROM `" . _DB_PREFIX_ . "orders` o
    LEFT JOIN `" . _DB_PREFIX_ . "tagpilot_order_log` tl
      ON tl.id_order = o.id_order AND tl.is_refund = 0
    WHERE o.date_add >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND tl.id_order IS NULL
      {$paymentClause}
    ORDER BY o.date_add ASC
";

$rows = $db->executeS($sql);
if (empty($rows)) {
    echo "No untracked orders found in the last {$hours}h. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($rows) . " order(s) to process.\n\n";

// ── Processing loop ─────────────────────────────────────────────────────────
$stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

foreach ($rows as $row) {
    $orderId = (int) $row['id_order'];
    $orderRef = $row['reference'];
    $orderDate = $row['date_add'];
    $payment = $row['payment'];

    echo "── Order #{$orderId} ({$orderRef}) · {$orderDate} · {$payment}\n";

    try {
        // In force-recent mode, override timestamp for this event to fit within GA4's 72h window.
        $forcedTimestamp = null;
        if ($forceRecent) {
            $orderAgeHours = (time() - strtotime($orderDate)) / 3600.0;
            if ($orderAgeHours > 72) {
                $forcedTimestamp = ($recentOffsetBase + $recentOffsetCounter) * 1_000_000;
                $recentOffsetCounter++;
            }
        }

        $payload = buildPayload($row, $priceWithTax, $idFieldConfig, $measurementId, $forcedTimestamp);
        if ($payload === null) {
            echo "   SKIP  no valid line items\n";
            $stats['skipped']++;
            continue;
        }

        // Show attribution + user_data status
        $ctx = $payload['_context'];
        unset($payload['_context']);
        echo "   src   " . ($ctx['source'] !== '' ? "campaign_source={$ctx['source']}, campaign_medium={$ctx['medium']}" : "(direct)");
        if ($ctx['gclid'] !== '') { echo ", gclid={$ctx['gclid']}"; }
        echo "\n";
        echo "   ec    " . ($ctx['has_user_data'] ? 'user_data (hashed): email' . ($ctx['has_phone'] ? '+phone' : '') . '+address' : '(no user_data)') . "\n";
        echo "   $$    value={$payload['events'][0]['params']['value']} " . $payload['events'][0]['params']['currency'] . ", " . count($payload['events'][0]['params']['items']) . " item(s)\n";
        if ($forcedTimestamp !== null) {
            echo "   time  original " . $orderDate . " → forced to " . date('Y-m-d H:i:s', (int) ($forcedTimestamp / 1_000_000)) . " (>72h old)\n";
        }

        if ($dryRun) {
            echo "   DRY   would POST to /mp/collect\n";
            $stats['skipped']++;
            continue;
        }

        [$httpCode, $body] = sendMp($measurementId, $apiSecret, $payload);
        if ($httpCode >= 200 && $httpCode < 300) {
            echo "   OK    HTTP {$httpCode}\n";
            logOrder($db, $row, $payload);
            $stats['sent']++;
        } else {
            echo "   FAIL  HTTP {$httpCode} — " . substr($body, 0, 200) . "\n";
            $stats['errors']++;
        }

        // Gentle rate limit — GA4 MP allows plenty, but be a nice citizen.
        usleep(200000); // 200ms

    } catch (\Throwable $e) {
        echo "   ERR   " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
        $stats['errors']++;
    }

    echo "\n";
}

// ── Summary ────────────────────────────────────────────────────────────────
echo str_repeat('=', 72) . "\n";
if ($dryRun) {
    echo "DRY-RUN complete: {$stats['skipped']} would-be-sent, {$stats['errors']} errors.\n";
    echo "Re-run with --run to actually send.\n";
} else {
    echo "Backfill complete: {$stats['sent']} sent, {$stats['skipped']} skipped, {$stats['errors']} errors.\n";
    echo "Sent orders logged to tagpilot_order_log — safe to re-run (idempotent).\n";
}
echo str_repeat('=', 72) . "\n";
exit($stats['errors'] > 0 ? 2 : 0);


// ══════════════════════════════════════════════════════════════════════════
//  Helpers
// ══════════════════════════════════════════════════════════════════════════

function buildPayload(array $row, bool $priceWithTax, string $idField, string $measurementId, ?int $forcedTimestampMicros = null): ?array
{
    $db = Db::getInstance();
    $orderId = (int) $row['id_order'];

    // ── Order line items ────────────────────────────────────────────────
    $order = new Order($orderId);
    if (!Validate::isLoadedObject($order)) {
        return null;
    }
    $products = $order->getProducts();
    if (empty($products)) {
        return null;
    }

    $items = [];
    $index = 0;
    foreach ($products as $p) {
        $items[] = array_filter([
            'item_id' => (string) ($p['product_id'] ?? $p['id_product'] ?? ''),
            'item_name' => (string) ($p['product_name'] ?? ''),
            'price' => (float) ($p['unit_price_tax_incl'] ?? $p['product_price'] ?? 0),
            'quantity' => (int) ($p['product_quantity'] ?? 1),
            'index' => $index++,
            'item_variant' => !empty($p['product_attribute_id']) ? (string) $p['product_attribute_id'] : null,
            'item_category' => trim((string) ($p['product_category_default_name'] ?? '')) ?: null,
        ], fn($v) => $v !== null && $v !== '');
    }

    $currency = new Currency((int) $row['id_currency']);
    $currencyCode = Validate::isLoadedObject($currency) ? $currency->iso_code : 'PLN';

    $total = $priceWithTax ? (float) $row['total_paid_tax_incl'] : (float) $row['total_paid_tax_excl'];
    $shipping = $priceWithTax ? (float) $row['total_shipping_tax_incl'] : (float) $row['total_shipping_tax_excl'];
    $tax = (float) $row['total_paid_tax_incl'] - (float) $row['total_paid_tax_excl'];

    $transactionId = $idField === 'reference' ? $row['reference'] : (string) $orderId;

    // ── Attribution: parse ps_connections_source for this order's session ─
    $source = parseSourceForOrder($db, $orderId);

    // ── User data (Enhanced Conversions) ────────────────────────────────
    $userData = buildUserData($db, $order, (int) $row['id_customer'], (int) $row['id_address_invoice']);

    // ── Timestamp ───────────────────────────────────────────────────────
    // Real order date unless caller forced a recent one (for orders >72h old — GA4 MP would
    // silently drop them otherwise).
    $timestampMicros = $forcedTimestampMicros !== null
        ? $forcedTimestampMicros
        : strtotime($row['date_add']) * 1_000_000;

    // ── Client_id: deterministic, per-customer ──────────────────────────
    // Prefer real _ga cookie value if we ever captured one, else derive.
    $clientId = deriveClientId((int) $row['id_customer'], (int) $orderId);

    // ── Session_id: derived from order timestamp (fake session) ──────────
    $sessionId = (string) (strtotime($row['date_add']));

    $eventParams = [
        'session_id' => $sessionId,
        'engagement_time_msec' => 100,
        'transaction_id' => $transactionId,
        'currency' => $currencyCode,
        'value' => round($total, 2),
        'tax' => round($tax, 2),
        'shipping' => round($shipping, 2),
        'items' => $items,
    ];

    // Attribution params — attached to the event so GA4 doesn't dump into Unassigned.
    if ($source['source'] !== '') {
        $eventParams['campaign_source'] = $source['source'];
    }
    if ($source['medium'] !== '') {
        $eventParams['campaign_medium'] = $source['medium'];
    }
    if ($source['campaign'] !== '') {
        $eventParams['campaign_name'] = $source['campaign'];
    }
    if ($source['campaign_id'] !== '') {
        $eventParams['campaign_id'] = $source['campaign_id'];
    }
    if ($source['gclid'] !== '') {
        // Both gclid (older) and gbraid/wbraid (cookieless) supported by GA4 attribution
        $eventParams['gclid'] = $source['gclid'];
    }
    if ($source['gbraid'] !== '') {
        $eventParams['gbraid'] = $source['gbraid'];
    }

    $payload = [
        'client_id' => $clientId,
        'timestamp_micros' => $timestampMicros,
        'non_personalized_ads' => false,
        'events' => [[
            'name' => 'purchase',
            'params' => $eventParams,
        ]],
    ];

    if (!empty($userData)) {
        $payload['user_data'] = $userData;
    }
    if ((int) $row['id_customer'] > 0) {
        $payload['user_id'] = (string) $row['id_customer'];
    }

    // Attach context for logging (stripped before send)
    $payload['_context'] = [
        'source' => $source['source'],
        'medium' => $source['medium'],
        'gclid' => $source['gclid'] ?: $source['gbraid'],
        'has_user_data' => !empty($userData),
        'has_phone' => !empty($userData['sha256_phone_number'] ?? null),
    ];

    return $payload;
}

/**
 * Look up the customer's session sources for this order's cart, parse the URLs
 * (utm_*, gclid, gbraid, gad_*) and return canonical GA4 attribution fields.
 *
 * Priority order: Google Ads gclid > gbraid > explicit UTM params > referrer inference.
 */
function parseSourceForOrder(Db $db, int $orderId): array
{
    $default = ['source' => '', 'medium' => '', 'campaign' => '', 'campaign_id' => '', 'gclid' => '', 'gbraid' => ''];

    $sql = "
        SELECT cs.http_referer, cs.request_uri
        FROM `" . _DB_PREFIX_ . "orders` o
        JOIN `" . _DB_PREFIX_ . "cart` c ON c.id_cart = o.id_cart
        JOIN `" . _DB_PREFIX_ . "connections` conn ON conn.id_guest = c.id_guest
        JOIN `" . _DB_PREFIX_ . "connections_source` cs ON cs.id_connections = conn.id_connections
        WHERE o.id_order = " . (int) $orderId . "
        ORDER BY cs.date_add ASC
    ";
    $sources = $db->executeS($sql);
    if (empty($sources)) {
        return $default;
    }

    // Walk sources chronologically; first Ads-like source wins.
    $best = $default;
    foreach ($sources as $s) {
        $uri = (string) ($s['request_uri'] ?? '');
        $ref = (string) ($s['http_referer'] ?? '');

        $parsed = parseUrl($uri, $ref);
        // Prefer paid > organic > direct
        if ($parsed['gclid'] !== '' || $parsed['gbraid'] !== '') {
            return $parsed; // best possible signal, no need to keep looking
        }
        if ($best['source'] === '' && $parsed['source'] !== '') {
            $best = $parsed;
        }
    }
    return $best;
}

function parseUrl(string $uri, string $ref): array
{
    $out = ['source' => '', 'medium' => '', 'campaign' => '', 'campaign_id' => '', 'gclid' => '', 'gbraid' => ''];

    $q = [];
    if (($qs = parse_url($uri, PHP_URL_QUERY)) !== null && $qs !== false) {
        parse_str((string) $qs, $q);
    }

    // Google Ads click IDs
    if (!empty($q['gclid'])) {
        $out['gclid'] = (string) $q['gclid'];
        $out['source'] = 'google';
        $out['medium'] = 'cpc';
    }
    if (!empty($q['gbraid'])) {
        $out['gbraid'] = (string) $q['gbraid'];
        if ($out['source'] === '') {
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }
    }
    if (!empty($q['wbraid'])) {
        if ($out['gbraid'] === '') $out['gbraid'] = (string) $q['wbraid'];
        if ($out['source'] === '') {
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }
    }
    if (!empty($q['gad_campaignid'])) {
        $out['campaign_id'] = (string) $q['gad_campaignid'];
    }
    if (!empty($q['gad_source'])) {
        // gad_source=1 means Google Ads
        if ($out['source'] === '') {
            $out['source'] = 'google';
            $out['medium'] = 'cpc';
        }
    }

    // Explicit UTM params (override auto-detected if present)
    if (!empty($q['utm_source']))   $out['source']   = (string) $q['utm_source'];
    if (!empty($q['utm_medium']))   $out['medium']   = (string) $q['utm_medium'];
    if (!empty($q['utm_campaign'])) $out['campaign'] = (string) $q['utm_campaign'];
    if (!empty($q['utm_id']))       $out['campaign_id'] = (string) $q['utm_id'];

    // Referrer-based fallback (organic detection)
    if ($out['source'] === '' && $ref !== '') {
        $host = parse_url($ref, PHP_URL_HOST) ?: '';
        $host = strtolower(preg_replace('/^www\./', '', $host));
        if (preg_match('/^(google\.|bing\.|duckduckgo\.|yahoo\.)/', $host)) {
            $out['source'] = preg_replace('/\..*/', '', $host);
            $out['medium'] = 'organic';
        } elseif (preg_match('/(facebook\.|instagram\.|twitter\.|x\.com|linkedin\.|tiktok\.)/', $host)) {
            $out['source'] = preg_replace('/\..*/', '', $host);
            $out['medium'] = 'social';
        } elseif ($host !== '') {
            $out['source'] = $host;
            $out['medium'] = 'referral';
        }
    }

    return $out;
}

function buildUserData(Db $db, Order $order, int $customerId, int $addressId): array
{
    $out = [];

    $customer = new Customer($customerId);
    if (Validate::isLoadedObject($customer) && !empty($customer->email)) {
        $out['sha256_email_address'] = [hash('sha256', strtolower(trim((string) $customer->email)))];
    }

    if ($addressId > 0) {
        $address = new Address($addressId);
        if (Validate::isLoadedObject($address)) {
            $phone = normalizePhone($address->phone_mobile ?? $address->phone, (int) $address->id_country);
            if ($phone !== '') {
                $out['sha256_phone_number'] = [hash('sha256', $phone)];
            }
            $addrBlock = array_filter([
                'sha256_first_name' => !empty($address->firstname) ? hash('sha256', strtolower(trim($address->firstname))) : null,
                'sha256_last_name' => !empty($address->lastname) ? hash('sha256', strtolower(trim($address->lastname))) : null,
                'postal_code' => (string) $address->postcode,
                'country' => strtoupper((string) Country::getIsoById((int) $address->id_country)),
            ], fn($v) => $v !== null && $v !== '');
            if (!empty($addrBlock)) {
                $out['address'] = [$addrBlock];
            }
        }
    }

    return $out;
}

function normalizePhone(?string $phone, int $countryId = 0): string
{
    if ($phone === null || trim($phone) === '') return '';
    $hasPlus = strpos(trim($phone), '+') === 0;
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits === '') return '';
    if ($hasPlus) return '+' . $digits;
    if (strpos($digits, '00') === 0) return '+' . substr($digits, 2);

    $callingCode = '48'; // default PL
    $map = ['PL'=>'48','DE'=>'49','CZ'=>'420','SK'=>'421','GB'=>'44','UK'=>'44','FR'=>'33','IT'=>'39','ES'=>'34','NL'=>'31','US'=>'1','CA'=>'1'];
    if ($countryId > 0) {
        $iso = (string) Country::getIsoById($countryId);
        if (isset($map[strtoupper($iso)])) $callingCode = $map[strtoupper($iso)];
    }
    return '+' . $callingCode . ltrim($digits, '0');
}

function deriveClientId(int $customerId, int $orderId): string
{
    // Deterministic per customer — if the same customer places multiple orders, they'll
    // share client_id, which is closer to reality than a random one per order.
    if ($customerId > 0) {
        $hash = hexdec(substr(md5('tp.' . $customerId), 0, 8));
        return $hash . '.' . (strtotime('2020-01-01'));
    }
    return $orderId . '.' . (strtotime('2020-01-01'));
}

function sendMp(string $measurementId, string $apiSecret, array $payload): array
{
    $url = 'https://www.google-analytics.com/mp/collect'
        . '?measurement_id=' . urlencode($measurementId)
        . '&api_secret=' . urlencode($apiSecret);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    // Prefer curl (faster, more configurable) when available; fall back to stream
    // wrappers so the script works on hosting where CLI PHP lacks the curl extension.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp];
    }

    // Stream fallback — needs allow_url_fopen=On (default in most PHP installs).
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true, // let us see 4xx/5xx bodies instead of throwing
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int) $m[1];
                break;
            }
        }
    }
    if ($resp === false && $code === 0) {
        return [0, 'stream request failed (allow_url_fopen off?)'];
    }
    return [$code, (string) $resp];
}

function logOrder(Db $db, array $row, array $payload): void
{
    // Strip context, then store what we sent so it's auditable
    $eventPayload = $payload;
    unset($eventPayload['_context']);

    $now = date('Y-m-d H:i:s');
    $db->insert('tagpilot_order_log', [
        'id_order' => (int) $row['id_order'],
        'order_reference' => pSQL((string) $row['reference']),
        'gtm_id' => pSQL((string) Configuration::get('TAGPILOT_GTM_ID')),
        'dl_ok' => 0, // browser never rendered the page — this is server-side backfill only
        'sent_mp' => 1,
        'resent' => 1, // flag = "not sent live, backfilled after the fact"
        'is_refund' => 0,
        'total' => (float) ($row['total_paid_tax_incl'] ?? 0),
        'payment' => pSQL((string) $row['payment']),
        'status' => pSQL('backfilled'),
        'datalayer' => pSQL(json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
        'date_order' => pSQL((string) $row['date_add']),
        'date_add' => $now,
        'date_upd' => $now,
    ]);
}
