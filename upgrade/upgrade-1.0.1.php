<?php
/**
 * TagPilot upgrade 1.0.0 → 1.0.1
 *
 * Registers the actionValidateOrder hook so server-side Measurement Protocol
 * fires on order creation regardless of whether the customer ever reaches the
 * order confirmation page. Fixes tracking gaps for payment methods that
 * redirect externally without returning (leasing, some deferred payments,
 * customers who close the tab before the confirmation page renders).
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module): bool
{
    if (!$module->registerHook('actionValidateOrder')) {
        return false;
    }

    return true;
}
