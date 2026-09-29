<?php
/**
 * TagPilot upgrade 1.0.2 → 1.0.3
 *
 * Registers the two GDPR hooks dispatched by the official psgdpr module, so
 * erasure and access requests reach the customer data TagPilot stores in
 * `tagpilot_order_log.datalayer` (Enhanced Conversions user_data: email, phone,
 * name, postal code, country).
 *
 * Needed as an upgrade step rather than an install-only change: hooks are only
 * registered by install(), so without this every shop already running TagPilot
 * would keep ignoring GDPR requests.
 *
 * No schema changes. The retention sweep uses the existing LOG_RETENTION_DAYS
 * setting and stores its last-run timestamp in TAGPILOT_PII_SWEEP_LAST, which
 * is created on demand.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module): bool
{
    // registerHook() is idempotent -- it returns true for an already registered hook -- so this
    // is safe to re-run.
    if (!$module->registerHook('actionDeleteGDPRCustomer')) {
        return false;
    }

    if (!$module->registerHook('actionExportGDPRData')) {
        return false;
    }

    return true;
}
