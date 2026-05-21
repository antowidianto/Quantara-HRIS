<?php

namespace Config;

/**
 * Paths
 *
 * Holds the paths that are used by the system to locate the
 * main directories: framework, system, etc.
 */
class Paths
{
    /**
     * Path to the system directory (CodeIgniter framework).
     * Composer installs CI to vendor/codeigniter4/framework/system.
     */
    public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';

    /**
     * Path to the application directory.
     */
    public string $appDirectory = __DIR__ . '/..';

    /**
     * Path to the writable directory.
     */
    public string $writableDirectory = __DIR__ . '/../../writable';

    /**
     * Path to the tests directory.
     */
    public string $testsDirectory = __DIR__ . '/../../tests';

    /**
     * Path to the view directory.
     */
    public string $viewDirectory = __DIR__ . '/../Views';
}