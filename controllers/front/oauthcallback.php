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

        $code = Tools::getValue('code', '');
        $state = Tools::getValue('state', '');
        $error = Tools::getValue('error', '');

        $returnUrl = Configuration::get('TAGPILOT_OAUTH_RETURN_URL');
        if (empty($returnUrl)) {
            die('OAuth callback error: no return URL configured');
        }

        if ($error) {
            Tools::redirect($returnUrl . '?oauth_error=' . urlencode($error));
            return;
        }

        // Verify state
        $savedState = (string) Configuration::get('TAGPILOT_OAUTH_STATE');
        if (empty($state) || $state !== $savedState) {
            Tools::redirect($returnUrl . '?oauth_error=invalid_state');
            return;
        }

        Configuration::deleteByName('TAGPILOT_OAUTH_STATE');

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
