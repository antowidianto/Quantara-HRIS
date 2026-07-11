<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;

/**
 * SyncCareerMasterData
 *
 * Menarik referensi online ke tabel lokal supaya form career tetap cepat
 * dan tidak bergantung ke internet saat kandidat sedang apply.
 *
 * Usage:
 *   php spark career:sync-master-data --locations
 *   php spark career:sync-master-data --schools --limit-pages=10
 *   php spark career:sync-master-data --universities-keywords=Universitas,Politeknik,Institut
 *   php spark career:sync-master-data --institutions-url=https://example.com/universities.csv
 */
class SyncCareerMasterData extends BaseCommand
{
    protected $group       = 'HRIS';
    protected $name        = 'career:sync-master-data';
    protected $description = 'Sync master lokasi dan institusi pendidikan untuk career form dari sumber online.';
    protected $usage       = 'career:sync-master-data [--locations] [--schools] [--school-levels=CSV] [--universities-keywords=CSV] [--institutions-url=URL] [--limit-provinces=N] [--limit-pages=N] [--dry-run] [--insecure]';
    protected $options     = [
        '--locations'        => 'Tarik provinsi/kota/kecamatan/kodepos dari alamat.thecloudalert.com',
        '--schools'          => 'Tarik data sekolah SD/SMP/SMA/SMK dari api-sekolah-indonesia.vercel.app',
        '--school-levels'    => 'Batasi jenjang sekolah CSV, mis. SMP,SMA,SMK',
        '--universities-keywords' => 'Tarik perguruan tinggi dari pencarian PDDIKTI berdasarkan keyword CSV, mis. Universitas,Politeknik,Institut',
        '--institutions-url' => 'Tarik institusi dari URL JSON/CSV. Kolom minimal: name; optional type, education_level, province, city, district, npsn.',
        '--limit-provinces'  => 'Batasi jumlah provinsi saat sync lokasi untuk trial/debug',
        '--limit-pages'      => 'Batasi jumlah halaman saat sync sekolah untuk trial/debug',
        '--dry-run'          => 'Tampilkan proses tanpa insert/update DB',
        '--insecure'         => 'Dev only: nonaktifkan SSL peer verification jika CA lokal bermasalah',
    ];

    private const ADDRESS_BASE = 'https://alamat.thecloudalert.com/api';
    private const SCHOOL_BASE = 'https://api-sekolah-indonesia.vercel.app';
    private const PDDIKTI_BASE = 'https://api-pddikti.kemdiktisaintek.go.id';

    private bool $dryRun = false;
    private bool $insecure = false;

    public function run(array $params)
    {
        $this->dryRun = (bool) CLI::getOption('dry-run');
        $this->insecure = (bool) CLI::getOption('insecure');
        $syncLocations = (bool) CLI::getOption('locations');
        $syncSchools = (bool) CLI::getOption('schools');
        $universitiesKeywords = trim((string) (CLI::getOption('universities-keywords') ?? ''));
        $institutionsUrl = trim((string) (CLI::getOption('institutions-url') ?? ''));

        if (! $syncLocations && ! $syncSchools && $universitiesKeywords === '' && $institutionsUrl === '') {
            CLI::error('Pilih minimal salah satu: --locations, --schools, --universities-keywords=CSV, atau --institutions-url=URL');
            return 1;
        }

        $db = Database::connect();
        foreach (['career_master_locations', 'career_master_institutions'] as $table) {
            if (! $db->tableExists($table)) {
                CLI::error("Tabel {$table} belum ada. Jalankan migration dulu.");
                return 1;
            }
        }

        $totals = ['locations' => 0, 'schools' => 0, 'universities' => 0, 'institutions' => 0];

        try {
            if ($syncLocations) {
                $totals['locations'] = $this->syncLocations();
            }
            if ($syncSchools) {
                $totals['schools'] = $this->syncSchools();
            }
            if ($universitiesKeywords !== '') {
                $totals['universities'] = $this->syncUniversitiesFromPddikti($universitiesKeywords);
            }
            if ($institutionsUrl !== '') {
                $totals['institutions'] = $this->syncInstitutionsFromUrl($institutionsUrl);
            }
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }

        CLI::write(sprintf(
            'Sync selesai: %d lokasi, %d sekolah, %d kampus PDDIKTI, %d institusi URL%s.',
            $totals['locations'],
            $totals['schools'],
            $totals['universities'],
            $totals['institutions'],
            $this->dryRun ? ' (dry-run)' : '',
        ), 'green');

        return 0;
    }

    private function syncLocations(): int
    {
        $limitProvinces = (int) (CLI::getOption('limit-provinces') ?? 0);
        $count = 0;
        $provinces = $this->fetchAddressResult('/provinsi/get/');
        if ($limitProvinces > 0) {
            $provinces = array_slice($provinces, 0, $limitProvinces);
        }

        foreach ($provinces as $province) {
            $provinceId = (string) ($province['id'] ?? '');
            $provinceName = $this->cleanName((string) ($province['text'] ?? ''));
            if ($provinceId === '' || $provinceName === '') continue;
            CLI::write("Lokasi: {$provinceName}", 'yellow');

            $cities = $this->fetchAddressResult('/kabkota/get/?d_provinsi_id=' . rawurlencode($provinceId));
            foreach ($cities as $city) {
                $cityId = (string) ($city['id'] ?? '');
                $cityName = $this->cleanName((string) ($city['text'] ?? ''));
                if ($cityId === '' || $cityName === '') continue;

                $districts = $this->fetchAddressResult('/kecamatan/get/?d_kabkota_id=' . rawurlencode($cityId));
                foreach ($districts as $district) {
                    $districtId = (string) ($district['id'] ?? '');
                    $districtName = $this->cleanName((string) ($district['text'] ?? ''));
                    if ($districtId === '' || $districtName === '') continue;

                    $postalCodes = $this->fetchAddressResult(
                        '/kodepos/get/?d_kabkota_id=' . rawurlencode($cityId)
                        . '&d_kecamatan_id=' . rawurlencode($districtId),
                    );
                    foreach ($postalCodes as $postal) {
                        $code = preg_replace('/\D+/', '', (string) ($postal['text'] ?? ''));
                        if (strlen($code) !== 5) continue;

                        $this->upsertLocation([
                            'province' => $provinceName,
                            'city' => $cityName,
                            'district' => $districtName,
                            'postal_code' => $code,
                            'source' => 'alamat.thecloudalert.com',
                            'source_ids' => json_encode([
                                'province_id' => $provinceId,
                                'city_id' => $cityId,
                                'district_id' => $districtId,
                                'postal_id' => (string) ($postal['id'] ?? ''),
                            ], JSON_UNESCAPED_SLASHES),
                        ]);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function syncSchools(): int
    {
        $limitPages = (int) (CLI::getOption('limit-pages') ?? 0);
        $count = 0;
        $levelsOption = trim((string) (CLI::getOption('school-levels') ?? ''));
        $levels = $levelsOption !== ''
            ? array_map(static fn($level) => strtoupper(trim((string) $level)), explode(',', $levelsOption))
            : ['SD', 'SMP', 'SMA', 'SMK'];
        $levels = array_values(array_intersect($levels, ['SD', 'SMP', 'SMA', 'SMK']));
        if (empty($levels)) {
            CLI::error('school-levels tidak valid. Gunakan SD,SMP,SMA,SMK.');
            return 0;
        }

        foreach ($levels as $level) {
            $page = 1;
            while (true) {
                if ($limitPages > 0 && $page > $limitPages) break;
                CLI::write("Sekolah {$level}: page {$page}", 'yellow');
                $payload = $this->fetchJson(self::SCHOOL_BASE . '/sekolah/' . strtolower($level) . '?page=' . $page . '&perPage=100');
                $rows = is_array($payload['dataSekolah'] ?? null) ? $payload['dataSekolah'] : $payload;
                if (! is_array($rows) || empty($rows)) break;

                foreach ($rows as $row) {
                    if (! is_array($row)) continue;
                    $name = $this->cleanName((string) ($row['sekolah'] ?? ''));
                    if ($name === '') continue;
                    $this->upsertInstitution([
                        'name' => $name,
                        'type' => 'SCHOOL',
                        'education_level' => strtoupper((string) ($row['bentuk'] ?? $level)),
                        'status' => (string) ($row['status'] ?? ''),
                        'npsn' => trim((string) ($row['npsn'] ?? '')),
                        'external_id' => trim((string) ($row['id'] ?? '')),
                        'province' => $this->cleanName((string) ($row['propinsi'] ?? '')),
                        'city' => $this->cleanName((string) ($row['kabupaten_kota'] ?? '')),
                        'district' => $this->cleanName((string) ($row['kecamatan'] ?? '')),
                        'address' => $this->cleanName((string) ($row['alamat_jalan'] ?? '')),
                        'lat' => $this->decimalOrNull($row['lintang'] ?? null),
                        'lng' => $this->decimalOrNull($row['bujur'] ?? null),
                        'source' => 'api-sekolah-indonesia',
                        'raw_payload' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $count++;
                }

                $page++;
            }
        }

        return $count;
    }

    private function syncUniversitiesFromPddikti(string $keywordsCsv): int
    {
        $keywords = array_values(array_filter(array_map(
            static fn($value) => trim((string) $value),
            explode(',', $keywordsCsv),
        )));
        $count = 0;

        foreach ($keywords as $keyword) {
            CLI::write("Perguruan tinggi PDDIKTI: {$keyword}", 'yellow');
            $json = $this->fetchPddiktiJson('/pencarian/pt/' . rawurlencode($keyword));
            $rows = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);

            foreach ($rows as $row) {
                if (! is_array($row)) continue;
                $name = $this->cleanName((string) ($row['nama'] ?? $row['nama_pt'] ?? ''));
                if ($name === '') continue;

                $this->upsertInstitution([
                    'name' => $name,
                    'type' => 'UNIVERSITY',
                    'education_level' => 'PT',
                    'status' => $row['status'] ?? null,
                    'npsn' => null,
                    'external_id' => $row['id'] ?? $row['kode'] ?? null,
                    'province' => $row['provinsi'] ?? null,
                    'city' => $row['kab_kota'] ?? $row['kota'] ?? null,
                    'district' => null,
                    'address' => null,
                    'lat' => null,
                    'lng' => null,
                    'source' => 'pddikti.kemdiktisaintek.go.id',
                    'raw_payload' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function syncInstitutionsFromUrl(string $url): int
    {
        $content = $this->fetchRaw($url);
        $rows = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.csv')
            ? $this->parseCsvRows($content)
            : json_decode($content, true);

        if (! is_array($rows)) {
            throw new \RuntimeException('Format institutions-url harus JSON array atau CSV.');
        }

        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $name = $this->cleanName((string) ($row['name'] ?? $row['nama'] ?? $row['institution'] ?? ''));
            if ($name === '') continue;
            $this->upsertInstitution([
                'name' => $name,
                'type' => strtoupper((string) ($row['type'] ?? 'UNIVERSITY')),
                'education_level' => $row['education_level'] ?? $row['level'] ?? null,
                'status' => $row['status'] ?? null,
                'npsn' => $row['npsn'] ?? null,
                'external_id' => $row['external_id'] ?? $row['id'] ?? null,
                'province' => $row['province'] ?? $row['provinsi'] ?? null,
                'city' => $row['city'] ?? $row['kota'] ?? $row['kabupaten_kota'] ?? null,
                'district' => $row['district'] ?? $row['kecamatan'] ?? null,
                'address' => $row['address'] ?? $row['alamat'] ?? null,
                'lat' => $this->decimalOrNull($row['lat'] ?? $row['latitude'] ?? null),
                'lng' => $this->decimalOrNull($row['lng'] ?? $row['longitude'] ?? null),
                'source' => parse_url($url, PHP_URL_HOST) ?: 'institutions-url',
                'raw_payload' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $count++;
        }

        return $count;
    }

    private function fetchAddressResult(string $path): array
    {
        $json = $this->fetchJson(self::ADDRESS_BASE . $path);
        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    private function fetchJson(string $url): array
    {
        $content = $this->fetchRaw($url);
        $json = json_decode($content, true);
        if (! is_array($json)) {
            throw new \RuntimeException("Response bukan JSON valid: {$url}");
        }
        return $json;
    }

    private function fetchPddiktiJson(string $path): array
    {
        $client = Services::curlrequest([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => ! $this->insecure,
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Origin' => 'https://pddikti.kemdiktisaintek.go.id',
                'Referer' => 'https://pddikti.kemdiktisaintek.go.id/',
                'User-Agent' => 'Mozilla/5.0 HRIS-CareerMasterSync/1.0',
            ],
        ]);
        $url = self::PDDIKTI_BASE . $path;
        $response = $client->get($url);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} saat mengambil {$url}");
        }
        $json = json_decode((string) $response->getBody(), true);
        if (! is_array($json)) {
            throw new \RuntimeException("Response PDDIKTI bukan JSON valid: {$url}");
        }
        return $json;
    }

    private function fetchRaw(string $url): string
    {
        $client = Services::curlrequest([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => ! $this->insecure,
            'headers' => ['User-Agent' => 'HRIS-CareerMasterSync/1.0'],
        ]);
        $response = $client->get($url);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} saat mengambil {$url}");
        }
        return (string) $response->getBody();
    }

    private function upsertLocation(array $row): void
    {
        if ($this->dryRun) return;

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('career_master_locations')
            ->where('province', $row['province'])
            ->where('city', $row['city'])
            ->where('district', $row['district'])
            ->where('postal_code', $row['postal_code'])
            ->get()->getRowArray();

        $payload = $row + ['is_active' => 1];
        $payload['updated_at'] = $now;
        if ($existing) {
            $db->table('career_master_locations')->where('id', (int) $existing['id'])->update($payload);
            return;
        }
        $payload['created_at'] = $now;
        $db->table('career_master_locations')->insert($payload);
    }

    private function upsertInstitution(array $row): void
    {
        if ($this->dryRun) return;

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $type = in_array($row['type'] ?? '', ['SCHOOL', 'UNIVERSITY', 'OTHER'], true) ? $row['type'] : 'OTHER';
        $province = $this->cleanName((string) ($row['province'] ?? ''));
        $city = $this->cleanName((string) ($row['city'] ?? ''));

        $existing = null;
        if (! empty($row['npsn'])) {
            $existing = $db->table('career_master_institutions')
                ->where('npsn', $row['npsn'])
                ->get()->getRowArray();
        }
        if (! $existing) {
            $existing = $db->table('career_master_institutions')
                ->where('type', $type)
                ->where('name', $row['name'])
                ->where('province', $province ?: null)
                ->where('city', $city ?: null)
                ->get()->getRowArray();
        }

        $payload = [
            'name' => $row['name'],
            'type' => $type,
            'education_level' => $row['education_level'] ?: null,
            'status' => $row['status'] ?: null,
            'npsn' => $row['npsn'] ?: null,
            'external_id' => $row['external_id'] ?: null,
            'province' => $province ?: null,
            'city' => $city ?: null,
            'district' => $this->cleanName((string) ($row['district'] ?? '')) ?: null,
            'address' => $this->cleanName((string) ($row['address'] ?? '')) ?: null,
            'lat' => $row['lat'] ?? null,
            'lng' => $row['lng'] ?? null,
            'source' => $row['source'] ?: 'manual',
            'raw_payload' => $row['raw_payload'] ?? null,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($existing) {
            $db->table('career_master_institutions')->where('id', (int) $existing['id'])->update($payload);
            return;
        }
        $payload['created_at'] = $now;
        $db->table('career_master_institutions')->insert($payload);
    }

    private function parseCsvRows(string $content): array
    {
        $lines = preg_split('/\R/', trim($content));
        if (! $lines || count($lines) < 2) return [];
        $headers = str_getcsv(array_shift($lines));
        $headers = array_map(static fn($h) => strtolower(trim((string) $h)), $headers);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = $values[$idx] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function cleanName(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $value = preg_replace('/^Prov\.\s*/i', '', $value) ?? $value;
        $value = preg_replace('/^Kec\.\s*/i', '', $value) ?? $value;
        return trim($value);
    }

    private function decimalOrNull($value): ?float
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (float) $value : null;
    }
}
