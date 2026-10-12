<?php
/**
 * TagPilot upgrade 1.0.5 → 1.0.6
 *
 * Translations-only follow-up to 1.0.5: adds Polish strings for the
 * "Clear saved credentials" link, its confirmation prompt, the success toast,
 * and the "Need to use a different Google Cloud OAuth app?" heading, plus
 * registers the two new JS translation keys (confirmClearCredentials,
 * credentialsCleared) in BaseController so pl-PL users see Polish instead
 * of the English fallback.
 *
 * No DB or hook changes.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_6($module): bool
{
    return true;
}
