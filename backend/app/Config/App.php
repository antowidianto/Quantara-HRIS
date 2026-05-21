<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{
    public string $baseURL = 'http://localhost:8080/';
    public array $allowedHostnames = [];
    public string $indexPage = '';
    public string $uriProtocol = 'REQUEST_URI';
    public string $defaultLocale = 'id';
    public bool $negotiateLocale = false;
    public array $supportedLocales = ['id', 'en'];
    public string $appTimezone = 'Asia/Jakarta';
    public string $charset = 'UTF-8';
    public bool $forceGlobalSecureRequests = false;
    public array $proxyIPs = [];

    public bool $CSPEnabled = false;

    public string $permittedURIChars = 'a-z 0-9~%.:_\-';
}