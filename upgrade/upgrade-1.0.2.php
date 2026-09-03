<?php
/**
 * TagPilot upgrade 1.0.1 → 1.0.2
 *
 * Populates the previously-empty `hookActionOrderStatusUpdate` and rewrites
 * `hookActionObjectOrderSlipAddAfter` so refunds and cancellations flow to
 * GA4 automatically from BackOffice context.
 *
 * No hook registration changes required (both hooks were already registered
 * in 1.0.0), no schema changes. This upgrade file exists purely so PS
 * recognises the version bump as an upgradable step and doesn't complain.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module): bool
{
    return true;
}
