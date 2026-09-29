<?php

declare(strict_types=1);

use Flavor\TagPilot\Service\GoogleOAuthService;

if (!defined('_PS_VERSION_')) {
    exit;
}

class TagpilotOauthcallbackModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $code = (string) Tools::getValue('code', '');
        $state = (string) Tools::getValue('state', '');
        $error = (string) Tools::getValue('error', '');

        $returnUrl = Configuration::get('TAGPILOT_OAUTH_RETURN_URL');
        if (empty($returnUrl)) {
            die('OAuth callback error: no return URL configured');
        }

        // Consume the stored state up front so it is single-use whatever happens next --
        // previously the error branch below returned while leaving it valid for a replay.
        $savedState = (string) Configuration::get('TAGPILOT_OAUTH_STATE');
        Configuration::deleteByName('TAGPILOT_OAUTH_STATE');

        if ($error !== '') {
            Tools::redirect($returnUrl . '?oauth_error=' . urlencode($error));
            return;
        }

        // Verify state. The empty checks are not redundant: hash_equals('', '') is true, so
        // without them a callback with no state would pass when none was stored.
        if ($state === '' || $savedState === '' || !hash_equals($savedState, $state)) {
            Tools::redirect($returnUrl . '?oauth_error=invalid_state');
            return;
        }

        $oauth = new GoogleOAuthService();
        $redirectUri = $this->context->link->getModuleLink('tagpilot', 'oauthcallback', [], true);
        $result = $oauth->exchangeCode($code, $redirectUri);

        if (isset($result['access_token'])) {
            Tools::redirect($returnUrl . '?oauth_success=1');
            return;
        }

        $errorMsg = $result['error_description'] ?? $result['error'] ?? 'Token exchange failed';
        Tools::redirect($returnUrl . '?oauth_error=' . urlencode($errorMsg));
    }
}
