<?php
/**
 * Legacy AdminController stub for TagPilot.
 *
 * The real admin UI lives in Symfony controllers (src/Controller/Admin/*) wired
 * through config/routes.yml. This file exists only because PrestaShop 8.2 and
 * some third-party modules (notably ps_edition_basic) instantiate the class
 * named in the tab's `class_name` via Reflection during tab enumeration — and
 * without a physical class on disk, the Reflection-created AdminController has
 * no DI container, which crashes with:
 *
 *     Call to a member function get() on null
 *     at AdminController::get('logger') from Controller.php:816
 *
 * …effectively locking the user out of the Back Office right after install.
 *
 * This stub gives PS a real class to instantiate so parent::__construct runs,
 * the DI container gets attached, and the admin panel loads normally. Anyone
 * who actually navigates to this legacy URL is redirected to the Symfony route.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminTagPilotController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        // Forward to the real (Symfony) dashboard route.
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminTagPilot', true)
        );
    }
}
