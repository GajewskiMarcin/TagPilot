<?php

declare(strict_types=1);

namespace Flavor\TagPilot\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class BaseController extends FrameworkBundleAdminController
{
    /**
     * The tab / legacy controller these routes hang off, as declared by _legacy_controller in
     * config/routes.yml and created by tagpilot::installTabs(). PageVoter joins it with the
     * permission to build the ROLE_MOD_TAB_ADMINTAGPILOT_<PERM> role it looks up.
     */
    protected const LEGACY_CONTROLLER = 'AdminTagPilot';

    /** @var RouterInterface */
    protected $psRouter;

    public function __construct(RouterInterface $psRouter)
    {
        $this->psRouter = $psRouter;
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            'translator' => '?Symfony\\Contracts\\Translation\\TranslatorInterface',
        ];
    }

    protected function getContext()
    {
        return \Context::getContext();
    }

    /**
     * Whether the current employee holds $permission ('read', 'create', 'update' or 'delete')
     * on this module's tab.
     *
     * Checked in code rather than with the AdminSecurity annotation/attribute on purpose: the
     * module declares compatibility with PrestaShop 8.0 through 9.x, and there is no single
     * declarative form that enforces across that whole range. PS 8 only ships
     * Security\Annotation\AdminSecurity; PS 9 added Security\Attribute\AdminSecurity and
     * deprecated the annotation. The attribute is silently ignored on PS 8 -- it would look
     * protected while enforcing nothing -- and the annotation logs a deprecation on PS 9 and is
     * slated for removal. PageVoter, which is what both forms ultimately consult, has an
     * identical contract in both versions, so calling it directly is the portable option.
     */
    protected function hasTagPilotPermission(string $permission): bool
    {
        $employee = $this->getContext()->employee ?? null;

        // Super admins always pass. Tab::initAccess() does grant profile 1 all four permissions
        // unconditionally at install time, so this is belt-and-braces rather than the primary
        // path -- but installTabs() updates a pre-existing AdminTagPilot tab with save(), which
        // does not run initAccess(), and locking the shop owner out of their own module would be
        // a worse bug than the one this fixes.
        if ($employee && (int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_) {
            return true;
        }

        return $this->isGranted($permission, static::LEGACY_CONTROLLER);
    }

    /**
     * Abort with 403 unless the current employee holds $permission. For page controllers --
     * PrestaShop turns AccessDeniedException into its standard "no permission" screen.
     */
    protected function denyUnlessGranted(string $permission): void
    {
        if (!$this->hasTagPilotPermission($permission)) {
            throw new AccessDeniedException(sprintf(
                'Employee lacks the "%s" permission on %s.',
                $permission,
                static::LEGACY_CONTROLLER
            ));
        }
    }

    protected function generateSidebarLink($section, $title = false)
    {
        if (empty($title)) {
            $title = $this->trans('Help', 'Admin.Global');
        }
        $urls = $this->getContext()->smarty->getTemplateVars('help_page_urls') ?? [];
        return $urls[$section] ?? '';
    }

    protected function getToolbarButtons(): array
    {
        $link = $this->getContext()->link;
        $lang = $this->getContext()->language;

        $translateUrl = $this->psRouter->generate('admin_international_translation_overview', [
            'type' => 'modules',
            'selected' => 'tagpilot',
            'lang' => $lang->iso_code,
            'locale' => $lang->locale,
        ]);

        return [
            'translate' => [
                'href' => $translateUrl,
                'desc' => $this->trans('Translate', 'Admin.Actions'),
            ],
            'hooks' => [
                'href' => $link->getAdminLink('AdminModulesPositions', true, [], [
                    'show_modules' => (int) \Module::getModuleIdByName('tagpilot'),
                ]),
                'desc' => $this->trans('Manage hooks', 'Admin.Modules.Feature'),
            ],
        ];
    }

    protected function getHeaderVars(): array
    {
        $module = \Module::getInstanceByName('tagpilot');

        return [
            'layoutTitle' => $module->displayName,
            'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
            'enableSidebar' => false,
            'help_link' => '',
            // Pre-encoded here rather than with Twig's |json_encode, which applies no flags:
            // this block is inlined in the page, so it needs the same JSON_HEX_TAG treatment as
            // the storefront dataLayer or a translation containing "</script>" would break out.
            'jsTranslationsJson' => json_encode(
                $this->getJsTranslations(),
                \tagpilot::JSON_INLINE_SCRIPT_FLAGS
            ),
        ];
    }

    protected function getJsTranslations(): array
    {
        return [
            'configSaved' => $this->trans('Configuration saved', 'Modules.Tagpilot.Admin'),
            'error' => $this->trans('Error', 'Modules.Tagpilot.Admin'),
            'unknownError' => $this->trans('Unknown error', 'Modules.Tagpilot.Admin'),
            'networkError' => $this->trans('Network error', 'Modules.Tagpilot.Admin'),
            'mpConnectionSuccess' => $this->trans('GA4 Measurement Protocol connection successful', 'Modules.Tagpilot.Admin'),
            'connectionFailed' => $this->trans('Connection failed', 'Modules.Tagpilot.Admin'),
            'orderResent' => $this->trans('Order re-sent to GA4 successfully', 'Modules.Tagpilot.Admin'),
            'failed' => $this->trans('Failed', 'Modules.Tagpilot.Admin'),
            'logsPurged' => $this->trans('Logs purged.', 'Modules.Tagpilot.Admin'),
            'eventsRemaining' => $this->trans('%count% events remaining.', 'Modules.Tagpilot.Admin'),
            'failedPurgeLogs' => $this->trans('Failed to purge logs', 'Modules.Tagpilot.Admin'),
            'enterCredentials' => $this->trans('Please enter both Client ID and Client Secret', 'Modules.Tagpilot.Admin'),
            'credentialsSaved' => $this->trans('Credentials saved! Reloading...', 'Modules.Tagpilot.Admin'),
            'couldNotStartOAuth' => $this->trans('Could not start OAuth', 'Modules.Tagpilot.Admin'),
            'disconnected' => $this->trans('Disconnected', 'Modules.Tagpilot.Admin'),
            'confirmDisconnectFull' => $this->trans(
                'Disconnect the Google account?'
                . "\n\n"
                . 'This will revoke TagPilot\'s access at Google and delete the OAuth client ID, '
                . 'client secret and both tokens from this shop. Nothing about your GTM container '
                . 'or GA4 property changes.'
                . "\n\n"
                . 'Tracking keeps working: the GTM container ID, GA4 Measurement ID and API secret '
                . 'are kept. You only need to reconnect if you want to run the GTM auto-configurator again.',
                'Modules.Tagpilot.Admin'
            ),
            'disconnectedRevoked' => $this->trans('Disconnected. Access revoked at Google and all credentials deleted.', 'Modules.Tagpilot.Admin'),
            'disconnectedNotRevoked' => $this->trans(
                'Credentials deleted from this shop, but Google could not be reached to revoke access. '
                . 'Please remove it manually at myaccount.google.com/permissions',
                'Modules.Tagpilot.Admin'
            ),
            'errorLoadingContainers' => $this->trans('Error loading containers', 'Modules.Tagpilot.Admin'),
            'selectAccountContainer' => $this->trans('Please select an account, container, and enter GA4 Measurement ID', 'Modules.Tagpilot.Admin'),
            'gtmConfigured' => $this->trans('GTM auto-configured! Click "Publish" to go live.', 'Modules.Tagpilot.Admin'),
            'configFailed' => $this->trans('Configuration failed', 'Modules.Tagpilot.Admin'),
            'published' => $this->trans('Published to GTM!', 'Modules.Tagpilot.Admin'),
            'version' => $this->trans('Version', 'Modules.Tagpilot.Admin'),
            'publishFailed' => $this->trans('Publish failed', 'Modules.Tagpilot.Admin'),
            'googleConnected' => $this->trans('Google account connected successfully!', 'Modules.Tagpilot.Admin'),
            'oauthError' => $this->trans('OAuth error', 'Modules.Tagpilot.Admin'),
            'validationOk' => $this->trans('Validation OK — no errors', 'Modules.Tagpilot.Admin'),
            'validationErrors' => $this->trans('Validation errors', 'Modules.Tagpilot.Admin'),
            'saving' => $this->trans('Saving...', 'Modules.Tagpilot.Admin'),
            'saveConfig' => $this->trans('Save configuration', 'Modules.Tagpilot.Admin'),
            'save' => $this->trans('Save', 'Modules.Tagpilot.Admin'),
            'sending' => $this->trans('Sending...', 'Modules.Tagpilot.Admin'),
            'resent' => $this->trans('Re-sent!', 'Modules.Tagpilot.Admin'),
            'resendToGa4' => $this->trans('Re-send to GA4', 'Modules.Tagpilot.Admin'),
            'confirmResend' => $this->trans('Are you sure you want to re-send this order to GA4?', 'Modules.Tagpilot.Admin'),
            'confirmPurge' => $this->trans('Purge old event logs? This cannot be undone.', 'Modules.Tagpilot.Admin'),
            'confirmPurgeAll' => $this->trans('Delete ALL event logs? This cannot be undone.', 'Modules.Tagpilot.Admin'),
            'logsPurgedDetails' => $this->trans('Deleted %deleted% logs, %remaining% remaining.', 'Modules.Tagpilot.Admin'),
            'saveCredentials' => $this->trans('Save credentials', 'Modules.Tagpilot.Admin'),
            'confirmDisconnect' => $this->trans('Disconnect Google account? You can reconnect later.', 'Modules.Tagpilot.Admin'),
            'loading' => $this->trans('Loading...', 'Modules.Tagpilot.Admin'),
            'selectContainer' => $this->trans('-- Select container --', 'Modules.Tagpilot.Admin'),
            'errorLoading' => $this->trans('Error loading', 'Modules.Tagpilot.Admin'),
            'configuringGtm' => $this->trans('Configuring GTM...', 'Modules.Tagpilot.Admin'),
            'startingConfig' => $this->trans('Starting auto-configuration...', 'Modules.Tagpilot.Admin'),
            'configComplete' => $this->trans('Configuration complete!', 'Modules.Tagpilot.Admin'),
            'warnings' => $this->trans('Warnings', 'Modules.Tagpilot.Admin'),
            'activateTracking' => $this->trans('Activate tracking', 'Modules.Tagpilot.Admin'),
            'confirmPublish' => $this->trans('Publish changes to your GTM container? This will make them live.', 'Modules.Tagpilot.Admin'),
            'publishing' => $this->trans('Publishing...', 'Modules.Tagpilot.Admin'),
            'publishToGtm' => $this->trans('Publish to GTM', 'Modules.Tagpilot.Admin'),
            'confirmReconfigure' => $this->trans('Reconfigure will create new tags/triggers in GTM. Continue?', 'Modules.Tagpilot.Admin'),
        ];
    }
}
