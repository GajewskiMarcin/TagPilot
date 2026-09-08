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

    public function disconnect(): void
    {
        Configuration::deleteByName(self::PREFIX . 'GOOGLE_ACCESS_TOKEN');
        Configuration::deleteByName(self::PREFIX . 'GOOGLE_REFRESH_TOKEN');
        Configuration::deleteByName(self::PREFIX . 'GOOGLE_TOKEN_EXPIRES');
        Configuration::deleteByName(self::PREFIX . 'GTM_ACCOUNT_ID');
        Configuration::deleteByName(self::PREFIX . 'GTM_CONTAINER_ID');
        Configuration::deleteByName(self::PREFIX . 'GTM_CONTAINER_NAME');
        Configuration::deleteByName(self::PREFIX . 'GTM_CONFIGURED');
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
