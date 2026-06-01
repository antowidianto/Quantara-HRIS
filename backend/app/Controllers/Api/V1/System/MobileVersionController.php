<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
/**
 * MobileVersionController — Sprint M4.
 *
 * Public endpoint (no auth) yang dipanggil mobile app saat startup.
 * Mobile bandingkan `min_supported` dengan `env.appVersion` lokal:
 *   - kalau lebih kecil → force-update screen
 *   - kalau >= min_supported tapi < latest → soft-recommend (banner)
 *   - kalau >= latest → ok, lanjut normal
 *
 * Sumber kebenaran: env variable `mobile.minSupported` & `mobile.latest`.
 * Diatur di `.env` per environment, ops bisa rolling-update tanpa perlu
 * deploy ulang (bisa pakai feature flag dashboard kedepannya).
 *
 * Default kalau env kosong:
 *   - min_supported = "0.1.0"  (current MVP baseline)
 *   - latest        = "0.1.0"
 *
 * Tidak butuh tabel — versi murni config-driven. Kalau enterprise butuh
 * granular per-tenant override, tambah kolom `min_mobile_version` di
 * tabel `companies` dan resolve dari sana.
 */
class MobileVersionController extends ApiController
{
    public function index()
    {
        $min = (string) env('mobile.minSupported', '0.1.0');
        $lat = (string) env('mobile.latest', $min);
        $iosUrl = (string) env('mobile.updateUrlIos', '');
        $androidUrl = (string) env('mobile.updateUrlAndroid', '');
        $msg = (string) env('mobile.updateMessage', '');

        return $this->ok([
            'min_supported'      => $min,
            'latest'             => $lat,
            'update_url_ios'     => $iosUrl !== '' ? $iosUrl : null,
            'update_url_android' => $androidUrl !== '' ? $androidUrl : null,
            'message'            => $msg !== '' ? $msg : null,
        ]);
    }
}