<?php
/**
 * TagPilot upgrade 1.0.4 → 1.0.5
 *
 * New: a "Clear saved credentials" link in the GTM Setup wizard that wipes
 * Client ID + Client Secret in addition to the tokens cleared by Disconnect.
 * Lets the user swap to a different Google Cloud OAuth app entirely without
 * touching the database by hand.
 *
 * Implementation: new `GoogleOAuthService::clearCredentials()`, new
 * `ApiController::gtmClearCredentials` endpoint + route, and a small UI
 * button in `wizard.html.twig`. No DB migration.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module): bool
{
    return true;
}
