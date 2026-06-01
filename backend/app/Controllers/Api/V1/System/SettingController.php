<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class SettingController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $rows = $db->table('settings')->where('company_id', $this->companyId())->get()->getResultArray();
        $kv = [];
        foreach ($rows as $r) {
            $kv[$r['key']] = $this->cast($r['value'], $r['data_type']);
        }
        $company = $db->table('companies')->where('id', $this->companyId())->get()->getRowArray();
        $bpjs = $db->table('bpjs_rates')->where('company_id', $this->companyId())->get()->getRowArray();

        return $this->ok([
            'company'    => $company,
            'bpjs_rates' => $bpjs,
            'settings'   => $kv,
        ]);
    }

    public function update()
    {
        $data = $this->getJsonInput();
        $db = \Config\Database::connect();

        if (! empty($data['settings']) && is_array($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                $type = is_array($value) ? 'JSON' : (is_bool($value) ? 'BOOL' : (is_int($value) ? 'INT' : (is_float($value) ? 'FLOAT' : 'STRING')));
                $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;

                $existing = $db->table('settings')
                    ->where('company_id', $this->companyId())
                    ->where('key', $key)
                    ->get()->getRowArray();
                if ($existing) {
                    $db->table('settings')->where('id', $existing['id'])->update([
                        'value'      => $stored, 'data_type' => $type,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $db->table('settings')->insert([
                        'company_id' => $this->companyId(),
                        'key'        => $key,
                        'value'      => $stored,
                        'data_type'  => $type,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        if (! empty($data['bpjs_rates']) && is_array($data['bpjs_rates'])) {
            $rates = array_intersect_key($data['bpjs_rates'], array_flip([
                'kes_employee_rate','kes_company_rate','kes_max_salary',
                'jht_employee_rate','jht_company_rate',
                'jp_employee_rate','jp_company_rate','jp_max_salary',
                'jkk_company_rate','jkm_company_rate',
            ]));
            $rates['updated_at'] = date('Y-m-d H:i:s');
            $existing = $db->table('bpjs_rates')->where('company_id', $this->companyId())->countAllResults();
            if ($existing > 0) {
                $db->table('bpjs_rates')->where('company_id', $this->companyId())->update($rates);
            } else {
                $rates['company_id'] = $this->companyId();
                $rates['created_at'] = date('Y-m-d H:i:s');
                $db->table('bpjs_rates')->insert($rates);
            }
        }

        $changedKeys = array_keys($data['settings'] ?? []);
        $changedRates = array_keys($data['bpjs_rates'] ?? []);
        $desc = 'Mengubah pengaturan perusahaan';
        if ($changedKeys || $changedRates) {
            $desc .= ' (' . count($changedKeys) . ' settings, ' . count($changedRates) . ' BPJS rates)';
        }
        Services::audit()
            ->forModule('SETTING')
            ->log(
                action: 'settings.update',
                entity: 'settings',
                entityId: $this->companyId(),
                after: ['settings' => $data['settings'] ?? [], 'bpjs_rates' => $data['bpjs_rates'] ?? []],
                description: $desc,
                severity: 'NOTICE'
            );
        return $this->ok(null, 'Settings updated');
    }

    private function cast(?string $value, string $type)
    {
        if ($value === null) return null;
        return match ($type) {
            'INT'   => (int) $value,
            'FLOAT' => (float) $value,
            'BOOL'  => (bool) $value,
            'JSON'  => json_decode($value, true),
            default => $value,
        };
    }
}