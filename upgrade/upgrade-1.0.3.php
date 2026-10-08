<?php
/**
 * TagPilot upgrade 1.0.2 → 1.0.3
 *
 * Fixes a PS 8.2 Back Office crash reproduced as:
 *
 *     Fatal: Call to a member function get() on null
 *     at AdminController::get('logger') from Controller.php:816
 *     via AdminController::getTabs()
 *     via ReflectionMethod->invoke(object(AdminController))
 *     from ps_edition_basic/src/Controller/AdminPsEditionBasicController.php:171
 *
 * Root cause: tabs with a `route_name` force AdminController::getTabs() to
 * resolve the Symfony route via Context::link->getTabLink(). On the first
 * admin request after a fresh install, the Symfony route cache often doesn't
 * yet contain the module's routes.yml entries, so `RouteNotFoundException` is
 * thrown. The exception handler calls `$this->get('logger')` — fine for
 * normally-constructed controllers, but when ps_edition_basic invokes
 * getTabs() via Reflection on $context->controller, that controller may have
 * been built without the DI container being wired, and `$this->container` is
 * null. The admin dies with a 500 the user cannot recover from in-browser.
 *
 * Fix: don't set `route_name` on our tab — PS resolves it via class_name
 * (AdminTagPilot) through the legacy controllers path, which never hits the
 * Symfony router and so never triggers the exception. The legacy controller
 * (controllers/admin/AdminTagPilotController.php, also added in 1.0.3) is a
 * thin stub that redirects to the Symfony dashboard route the moment the
 * admin actually clicks the tab.
 *
 * For existing installs that already have a tab row with the legacy
 * `route_name = tagpilot_dashboard` written by 1.0.0–1.0.2, this upgrade
 * clears that field. New installs register tabs without it to begin with.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module): bool
{
    $db = Db::getInstance();

    // Clear the legacy route_name from any TagPilot tab row written by prior
    // versions. Safe to run multiple times — idempotent UPDATE.
    $db->execute(
        "UPDATE `" . _DB_PREFIX_ . "tab`
         SET `route_name` = ''
         WHERE `class_name` = 'AdminTagPilot'"
    );

    return true;
}
