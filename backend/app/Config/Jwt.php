<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Jwt extends BaseConfig
{
    public string $secret = '';
    public string $refreshSecret = '';
    public string $algo = 'HS256';
    public int $accessTtl = 900;          // 15 min
    public int $refreshTtl = 1209600;     // 14 days
    public string $issuer = 'hris-api';
    public string $audience = 'hris-app';

    public function __construct()
    {
        parent::__construct();

        // Pull from env, with strict validation in production
        $this->secret        = env('jwt.secret', $this->secret);
        $this->refreshSecret = env('jwt.refreshSecret', $this->refreshSecret);
        $this->algo          = env('jwt.algo', $this->algo);
        $this->accessTtl     = (int) env('jwt.accessTtl', $this->accessTtl);
        $this->refreshTtl    = (int) env('jwt.refreshTtl', $this->refreshTtl);
        $this->issuer        = env('jwt.issuer', $this->issuer);
        $this->audience      = env('jwt.audience', $this->audience);

        if (ENVIRONMENT === 'production') {
            if (strlen($this->secret) < 32 || str_contains($this->secret, 'CHANGE_ME')) {
                throw new \RuntimeException('JWT secret is not properly configured for production.');
            }
            if (strlen($this->refreshSecret) < 32 || str_contains($this->refreshSecret, 'CHANGE_ME')) {
                throw new \RuntimeException('JWT refresh secret is not properly configured for production.');
            }
        }
    }
}