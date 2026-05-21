<?php

/*
 *---------------------------------------------------------------
 * APP NAMESPACE
 *---------------------------------------------------------------
 *
 * This defines the default Namespace that is used throughout
 * CodeIgniter to refer to the Application directory. Change
 * this constant if you want to alter the default Namespace
 * used by CodeIgniter. To regenerate the autoloader files
 * run `composer dump-autoload`.
 */
defined('APP_NAMESPACE') || define('APP_NAMESPACE', 'App');

/*
 *---------------------------------------------------------------
 * COMPOSER PATH
 *---------------------------------------------------------------
 */
defined('COMPOSER_PATH') || define('COMPOSER_PATH', rtrim(ROOTPATH, '\\/ ') . DIRECTORY_SEPARATOR . 'vendor/autoload.php');

/*
 *---------------------------------------------------------------
 * TIMING CONSTANTS
 *---------------------------------------------------------------
 */
defined('SECOND') || define('SECOND', 1);
defined('MINUTE') || define('MINUTE', 60);
defined('HOUR')   || define('HOUR', 3600);
defined('DAY')    || define('DAY', 86400);
defined('WEEK')   || define('WEEK', 604800);
defined('MONTH')  || define('MONTH', 2_592_000);
defined('YEAR')   || define('YEAR', 31_536_000);
defined('DECADE') || define('DECADE', 315_360_000);

/*
 *---------------------------------------------------------------
 * EXIT CODES
 *---------------------------------------------------------------
 */
defined('EXIT_SUCCESS')        || define('EXIT_SUCCESS', 0);
defined('EXIT_ERROR')          || define('EXIT_ERROR', 1);
defined('EXIT_CONFIG')         || define('EXIT_CONFIG', 3);
defined('EXIT_UNKNOWN_FILE')   || define('EXIT_UNKNOWN_FILE', 4);
defined('EXIT_UNKNOWN_CLASS')  || define('EXIT_UNKNOWN_CLASS', 5);
defined('EXIT_UNKNOWN_METHOD') || define('EXIT_UNKNOWN_METHOD', 6);
defined('EXIT_USER_INPUT')     || define('EXIT_USER_INPUT', 7);
defined('EXIT_DATABASE')       || define('EXIT_DATABASE', 8);
defined('EXIT__AUTO_MIN')      || define('EXIT__AUTO_MIN', 9);
defined('EXIT__AUTO_MAX')      || define('EXIT__AUTO_MAX', 125);