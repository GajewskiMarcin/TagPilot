<?php
/**
 * TagPilot upgrade 1.0.3 → 1.0.4
 *
 * UI-only fix: the "Step 1: Connect your Google account" card in the GTM Setup
 * wizard was hidden with `display:none` once the user finished configuring
 * everything (step > 1 AND gtmConfigured). That hid the Disconnect button and
 * the OAuth credential fields, so once a user was fully set up they had no way
 * to switch Google accounts, revoke tokens, or re-enter credentials — short
 * of editing the database.
 *
 * The fix is purely in the Twig template (wizard.html.twig). No database or
 * hook changes.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_4($module): bool
{
    return true;
}
