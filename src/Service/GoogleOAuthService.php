<?php

declare(strict_types=1);

namespace Flavor\TagPilot\Service;

use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class GoogleOAuthService
{
    private const PREFIX = 'TAGPILOT_';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Everything disconnect() removes: the OAuth credentials, the tokens, and the state that
     * only the Tag Manager API integration uses.
     *
     * GTM_ID, GA4_MEASUREMENT_ID, GA4_API_SECRET and ENABLED are deliberately NOT in this list.
     * Storefront tracking depends only on those (tagpilot::isActive() is ENABLED && GTM_ID, and
     * Measurement Protocol needs just the measurement id and the API secret), so disconnecting
     * must not stop the shop from tracking.
     */
    private const DISCONNECT_KEYS = [
        // Credentials and tokens
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_ACCESS_TOKEN',
        'GOOGLE_REFRESH_TOKEN',
        'GOOGLE_TOKEN_EXPIRES',
        // Transient OAuth handshake state
        'OAUTH_STATE',
        'OAUTH_RETURN_URL',
        // Tag Manager API bookkeeping
        'GTM_ACCOUNT_ID',
        'GTM_CONTAINER_ID',
        'GTM_CONTAINER_NAME',
        'GTM_CONFIGURED',
        'GTM_WORKSPACE_PATH',
        'GTM_LAST_PUBLISH',
    ];
    private const SCOPES = [
        'https://www.googleapis.com/auth/tagmanager.readonly',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
    ];

    public function getClientId(): string
    {
        return (string) Configuration::get(self::PREFIX . 'GOOGLE_CLIENT_ID');
    }

    public function getClientSecret(): string
    {
        return (string) Configuration::get(self::PREFIX . 'GOOGLE_CLIENT_SECRET');
    }

    public function isConfigured(): bool
    {
        return !empty($this->getClientId()) && !empty($this->getClientSecret());
    }

    public function isConnected(): bool
    {
        $token = $this->getAccessToken();
        return !empty($token);
    }

    public function getAuthorizationUrl(string $redirectUri, string $state = ''): string
    {
        $params = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->httpPost(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (isset($response['access_token'])) {
            $this->storeTokens($response);
        }

        return $response;
    }

    public function getAccessToken(): string
    {
        $token = (string) Configuration::get(self::PREFIX . 'GOOGLE_ACCESS_TOKEN');
        $expiresAt = (int) Configuration::get(self::PREFIX . 'GOOGLE_TOKEN_EXPIRES');

        if (empty($token)) {
            return '';
        }

        // Refresh if expired (with 60s buffer)
        if ($expiresAt > 0 && time() >= ($expiresAt - 60)) {
            $token = $this->refreshToken();
        }

        return $token;
    }

    public function refreshToken(): string
    {
        $refreshToken = (string) Configuration::get(self::PREFIX . 'GOOGLE_REFRESH_TOKEN');
        if (empty($refreshToken)) {
            return '';
        }

        $response = $this->httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if (isset($response['access_token'])) {
            Configuration::updateValue(self::PREFIX . 'GOOGLE_ACCESS_TOKEN', $response['access_token']);
            $expiresIn = (int) ($response['expires_in'] ?? 3600);
            Configuration::updateValue(self::PREFIX . 'GOOGLE_TOKEN_EXPIRES', (string) (time() + $expiresIn));
            return $response['access_token'];
        }

        return '';
    }

    /**
     * Revoke the grant at Google, then delete every local credential.
     *
     * Previously this only deleted the three token rows: the shop forgot the tokens, but the
     * authorisation stayed live on the Google account, and GOOGLE_CLIENT_ID /
     * GOOGLE_CLIENT_SECRET were left behind. Since the scopes include tagmanager.publish --
     * which is the ability to publish arbitrary JavaScript to the storefront -- a refresh token
     * that is never revoked is worth removing properly.
     *
     * The local wipe runs whether or not the revoke call succeeds. If Google is unreachable the
     * right outcome is still to forget the credentials locally; the return value says whether
     * the remote grant is actually gone so the caller can tell the user to finish the job by
     * hand at https://myaccount.google.com/permissions.
     *
     * @return array{revoked: bool, hadToken: bool, cleared: string[]}
     */
    public function disconnect(): array
    {
        // Revoking the refresh token invalidates the whole grant, access tokens included, so it
        // is the one to send when present.
        $refreshToken = (string) Configuration::get(self::PREFIX . 'GOOGLE_REFRESH_TOKEN');
        $accessToken = (string) Configuration::get(self::PREFIX . 'GOOGLE_ACCESS_TOKEN');
        $token = $refreshToken !== '' ? $refreshToken : $accessToken;

        $revoked = false;
        if ($token !== '') {
            $revoked = $this->revokeToken($token);
        }

        $cleared = [];
        foreach (self::DISCONNECT_KEYS as $key) {
            if (Configuration::get(self::PREFIX . $key) !== false) {
                $cleared[] = $key;
            }
            Configuration::deleteByName(self::PREFIX . $key);
        }

        return [
            'revoked' => $revoked,
            'hadToken' => $token !== '',
            'cleared' => $cleared,
        ];
    }

    /**
     * POST the token to Google's revocation endpoint.
     *
     * Returns true only when the grant is genuinely gone:
     *   - 200                        the token was revoked
     *   - 400 with "invalid_token"   Google does not recognise it, so there is nothing left to
     *                                revoke -- same end state as far as the shop is concerned
     *
     * Everything else is false: a network failure, a 5xx, or a 400 for any other reason. Being
     * strict matters here, because this boolean is what tells the merchant whether they still
     * need to revoke by hand at myaccount.google.com/permissions. Reporting success on a
     * malformed-request 400 would be a quietly false reassurance.
     */
    private function revokeToken(string $token): bool
    {
        $ch = curl_init(self::REVOKE_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['token' => $token]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            return true;
        }

        if ($status === 400) {
            $decoded = json_decode((string) $body, true);
            return ($decoded['error'] ?? '') === 'invalid_token';
        }

        return false;
    }

    private function storeTokens(array $response): void
    {
        Configuration::updateValue(self::PREFIX . 'GOOGLE_ACCESS_TOKEN', $response['access_token']);

        if (isset($response['refresh_token'])) {
            Configuration::updateValue(self::PREFIX . 'GOOGLE_REFRESH_TOKEN', $response['refresh_token']);
        }

        $expiresIn = (int) ($response['expires_in'] ?? 3600);
        Configuration::updateValue(self::PREFIX . 'GOOGLE_TOKEN_EXPIRES', (string) (time() + $expiresIn));
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
}
