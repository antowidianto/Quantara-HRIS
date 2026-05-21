<?php

/*
 * Production environment bootstrap.
 *
 * Catatan PHP 8.4+:
 *  Konstanta E_STRICT dihapus per PHP 8.4 dan menyebabkan warning deprecated
 *  bila masih dirujuk langsung. Kita susun mask error secara dinamis supaya
 *  file ini tetap aman di-include pada PHP 7.4 sampai 8.4+.
 */
ini_set('display_errors', '0');

$mask = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_DEPRECATED;

// E_STRICT dideprecate di PHP 8.4 dan menghasilkan warning meskipun
// konstantanya masih terdaftar untuk kompatibilitas. Kita hanya
// mereferensikan-nya pada PHP < 8.4. Bitnya sendiri (2048) sudah tidak
// pernah dipakai PHP modern, jadi aman jika tidak dimask.
if (PHP_VERSION_ID < 80400 && defined('E_STRICT')) {
    $mask &= ~constant('E_STRICT');
}
error_reporting($mask);
unset($mask);

defined('CI_DEBUG') || define('CI_DEBUG', false);
