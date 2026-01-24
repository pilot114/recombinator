<?php

/**
 * Class with constants that can be inlined.
 *
 * Before: class constant references
 * After: literal values inlined
 */

class Config
{
    const API_VERSION = 2;

    const API_BASE_URL = "https://api.example.com";

    const MAX_RETRIES = 3;

    const TIMEOUT = 30;
}

class Auth
{
    const HASH_ALGORITHM = "sha256";

    const TOKEN_LIFETIME = 3600;

    const SALT = "secure_salt_123";

    public function validateToken($token): string
    {
        if ($token !== null) {
            return "valid";
        }

        return "invalid";
    }
}

$version = Config::API_VERSION + 1;
$baseUrl = Config::API_BASE_URL;
$retries = Config::MAX_RETRIES * 2;
$timeout = Config::TIMEOUT / 2;

echo "API Version: " . $version;
echo "URL: " . $baseUrl;
