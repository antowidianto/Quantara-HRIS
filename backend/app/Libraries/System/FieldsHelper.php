<?php

namespace App\Libraries\System;

use Config\Services;

/**
 * FieldsHelper — Mobile Prep / MOB-4
 *
 * Utility ringan untuk slim-response pattern. Mobile app sering hanya butuh
 * sebagian field per record; kirim 50 field employee saat list page kerja
 * jelek di paket data tipis.
 *
 * Pemakaian di controller:
 *
 *   $fields = FieldsHelper::fromRequest(['id', 'full_name', 'avatar_url']);
 *   $rows   = $model->findAll();
 *   return $this->ok(FieldsHelper::project($rows, $fields));
 *
 * Frontend cara pakai:
 *
 *   GET /api/v1/employees?fields=id,full_name,avatar_url
 *
 * Conventions:
 *   - Daftar field whitelisted per controller (mencegah eksfiltrasi field
 *     sensitif lewat trick `?fields=password_hash`).
 *   - Field nested via dot notation tidak di-support di MVP (cukup top-level).
 *   - Kalau `?fields=` tidak di-set → pakai default whitelist sebagai full set.
 */
class FieldsHelper
{
    /**
     * Parse `?fields=` dari request, intersect dengan whitelist allowed.
     *
     * @param array<int,string> $allowed   Daftar field yang aman dikirim.
     * @param array<int,string>|null $default Default kalau client tidak filter
     *                                        (default = $allowed full).
     * @return array<int,string>
     */
    public static function fromRequest(array $allowed, ?array $default = null): array
    {
        $request = Services::request();
        $raw     = trim((string) $request->getGet('fields'));

        // Tidak ada filter → full default
        if ($raw === '') {
            return $default ?? $allowed;
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)));
        $intersect = array_values(array_intersect($allowed, $requested));

        // Kalau ternyata setelah intersect kosong (semua tidak valid),
        // safer kembali ke default daripada return [] (yang akan response empty).
        if ($intersect === []) {
            return $default ?? $allowed;
        }
        return $intersect;
    }

    /**
     * Project (filter) array of rows ke fields tertentu saja.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $fields
     * @return array<int,array<string,mixed>>
     */
    public static function project(array $rows, array $fields): array
    {
        if ($rows === [] || $fields === []) {
            return $rows;
        }
        $flip = array_flip($fields);
        $out  = [];
        foreach ($rows as $row) {
            $out[] = array_intersect_key($row, $flip);
        }
        return $out;
    }

    /**
     * Project single row.
     *
     * @param array<string,mixed> $row
     * @param array<int,string>   $fields
     * @return array<string,mixed>
     */
    public static function projectOne(array $row, array $fields): array
    {
        if ($fields === []) return $row;
        return array_intersect_key($row, array_flip($fields));
    }
}