<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * RecruitmentPipelineController — master template + stages pipeline rekrutmen.
 *
 * HR boleh punya beberapa template (mis. "Standar", "Eksekutif", "Pabrik",
 * "Sales") dengan urutan stages bebas. Setiap stage punya `stage_type` untuk
 * routing UI/notifikasi dan optional `required_test_category_id` sebagai
 * gate validation (kandidat harus selesaikan online test kategori X
 * sebelum boleh maju).
 *
 * Migration #065 men-seed satu template "STANDARD" per company yang setara
 * pipeline lama (NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR →
 * INTERVIEW_USER → OFFER → HIRED + REJECTED + WITHDRAWN).
 *
 * Workflow umum:
 *   1. HR clone template "STANDARD" → custom (mis. "Sales Pipeline").
 *   2. HR atur stages: drag-drop reorder, tambah/hapus stage, set test gate.
 *   3. HR pilih template di Job Posting form.
 *   4. Saat candidate move-stage, RecruitmentService validasi urutan stage
 *      sesuai template.
 */
class RecruitmentPipelineController extends ApiController
{
    /** GET /recruitment-pipelines */
    public function index()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search  = trim((string) $this->request->getGet('search'));

        $builder = $db->table('recruitment_pipeline_templates t')
            ->select('t.*,'
                . ' (SELECT COUNT(*) FROM recruitment_pipeline_stages s WHERE s.template_id = t.id) AS stage_count,'
                . ' (SELECT COUNT(*) FROM job_postings jp WHERE jp.pipeline_template_id = t.id AND jp.deleted_at IS NULL) AS posting_count')
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 't.company_id');

        if ($search !== '') {
            $builder->groupStart()
                ->like('t.name', $search)
                ->orLike('t.code', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('t.is_default', 'DESC')
            ->orderBy('t.name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** GET /recruitment-pipelines/picker — dropdown ringkas */
    public function picker()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        $rows = $db->table('recruitment_pipeline_templates')
            ->select('id, code, name, is_default')
            ->where('deleted_at IS NULL')
            ->where('is_active', 1)
            ->where('company_id', $companyId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    /** GET /recruitment-pipelines/(:num) — detail + stages */
    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $tpl = $db->table('recruitment_pipeline_templates')
            ->where('id', (int) $id)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $tpl) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $tpl['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $stages = $db->table('recruitment_pipeline_stages s')
            ->select('s.*, c.name AS required_test_category_name, c.code AS required_test_category_code')
            ->join('online_test_categories c', 'c.id = s.required_test_category_id', 'left')
            ->where('s.template_id', (int) $id)
            ->orderBy('s.order_index', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->get()->getResultArray();

        $tpl['stages'] = $stages;
        return $this->ok($tpl);
    }

    /** POST /recruitment-pipelines */
    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|alpha_dash|max_length[32]',
            'name' => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $code = strtoupper((string) $data['code']);

        $dup = $db->table('recruitment_pipeline_templates')
            ->where('company_id', $companyId)->where('code', $code)
            ->where('deleted_at IS NULL')->countAllResults();
        if ($dup > 0) return $this->fail("Code '{$code}' sudah dipakai", 422);

        $now = date('Y-m-d H:i:s');
        $isDefault = isset($data['is_default']) ? (int) $data['is_default'] : 0;

        // Kalau set is_default=1, unset default lain
        if ($isDefault === 1) {
            $db->table('recruitment_pipeline_templates')
                ->where('company_id', $companyId)
                ->where('is_default', 1)
                ->update(['is_default' => 0, 'updated_at' => $now]);
        }

        $insert = [
            'company_id'  => $companyId,
            'code'        => $code,
            'name'        => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_default'  => $isDefault,
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('recruitment_pipeline_templates')->insert($insert);
        $tplId = (int) $db->insertID();

        // Optional: bawa stages di payload create. Validasi dulu.
        if (! empty($data['stages']) && is_array($data['stages'])) {
            $err = $this->insertStages($db, $tplId, $companyId, $data['stages'], $now);
            if ($err) {
                // Rollback: hapus template + stages yg sudah dibuat
                $db->table('recruitment_pipeline_stages')->where('template_id', $tplId)->delete();
                $db->table('recruitment_pipeline_templates')->where('id', $tplId)->delete();
                return $this->fail($err, 422, 'INVALID_STAGES');
            }
        }

        Services::audit()->forModule('MASTER')->logCreate(
            'recruitment_pipeline_template',
            $tplId,
            $insert,
            "Tambah pipeline: {$insert['name']} ({$code})"
        );

        return $this->show($tplId);
    }

    /** PUT /recruitment-pipelines/(:num) */
    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('recruitment_pipeline_templates')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $now = date('Y-m-d H:i:s');
        $update = array_intersect_key($data, array_flip([
            'name', 'description', 'is_default', 'is_active',
        ]));

        if (! empty($update['is_default']) && (int) $update['is_default'] === 1) {
            $db->table('recruitment_pipeline_templates')
                ->where('company_id', (int) $existing['company_id'])
                ->where('id !=', $id)
                ->where('is_default', 1)
                ->update(['is_default' => 0, 'updated_at' => $now]);
        }
        $update['updated_at'] = $now;
        $db->table('recruitment_pipeline_templates')->where('id', $id)->update($update);

        // Replace stages kalau dikirim di payload
        if (isset($data['stages']) && is_array($data['stages'])) {
            // Cek apakah template ini sudah punya kandidat aktif yang ref stages
            // existing — kalau ya, restrict structural changes (only rename/reorder).
            $hasActiveCandidates = $db->table('candidate_stages cs')
                ->join('candidates c', 'c.id = cs.candidate_id')
                ->join('job_postings jp', 'jp.id = c.job_posting_id')
                ->where('jp.pipeline_template_id', $id)
                ->whereNotIn('cs.stage', ['HIRED', 'REJECTED', 'WITHDRAWN'])
                ->countAllResults();

            if ($hasActiveCandidates > 0) {
                // Allow update existing stages saja (by id), tidak boleh delete
                // stage yang masih dipakai. Cek code match.
                $existingStages = $db->table('recruitment_pipeline_stages')
                    ->where('template_id', $id)->get()->getResultArray();
                $existingCodes = array_column($existingStages, 'code');
                $newCodes = array_column($data['stages'], 'code');

                $removed = array_diff($existingCodes, $newCodes);
                $stillUsed = [];
                foreach ($removed as $code) {
                    $usage = $db->table('candidate_stages cs')
                        ->join('candidates c', 'c.id = cs.candidate_id')
                        ->join('job_postings jp', 'jp.id = c.job_posting_id')
                        ->where('jp.pipeline_template_id', $id)
                        ->where('cs.stage', $code)
                        ->whereNotIn('cs.stage', ['HIRED', 'REJECTED', 'WITHDRAWN'])
                        ->countAllResults();
                    if ($usage > 0) $stillUsed[] = $code;
                }
                if (! empty($stillUsed)) {
                    return $this->fail(
                        'Stage berikut tidak boleh dihapus karena masih ada kandidat aktif: '
                        . implode(', ', $stillUsed),
                        409, 'STAGES_IN_USE'
                    );
                }
            }

            // Replace
            $db->table('recruitment_pipeline_stages')->where('template_id', $id)->delete();
            $err = $this->insertStages($db, $id, (int) $existing['company_id'], $data['stages'], $now);
            if ($err) return $this->fail($err, 422, 'INVALID_STAGES');
        }

        Services::audit()->forModule('MASTER')->logUpdate(
            'recruitment_pipeline_template',
            $id,
            array_intersect_key($existing, $update),
            $update,
            "Update pipeline: {$existing['name']}"
        );

        return $this->show($id);
    }

    /** DELETE /recruitment-pipelines/(:num) */
    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('recruitment_pipeline_templates')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Cek penggunaan
        $usedBy = $db->table('job_postings')
            ->where('pipeline_template_id', $id)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($usedBy > 0) {
            return $this->fail(
                "Pipeline masih dipakai oleh {$usedBy} job posting. Pindahkan dulu sebelum hapus.",
                409, 'PIPELINE_IN_USE'
            );
        }

        $db->table('recruitment_pipeline_templates')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()->forModule('MASTER')->logDelete(
            'recruitment_pipeline_template',
            $id,
            $existing,
            "Hapus pipeline: {$existing['name']}"
        );
        return $this->ok(null, 'Deleted');
    }

    /**
     * POST /recruitment-pipelines/(:num)/duplicate
     * Quick action: clone template + stages dengan nama baru. Berguna untuk
     * HR yang mau modifikasi pipeline standar tanpa merusak yang asli.
     */
    public function duplicate($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $src = $db->table('recruitment_pipeline_templates')->where('id', $id)->get()->getRowArray();
        if (! $src) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $src['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $newCode = strtoupper((string) ($data['code'] ?? $src['code'] . '_COPY'));
        $newName = (string) ($data['name'] ?? ($src['name'] . ' (Copy)'));
        $now = date('Y-m-d H:i:s');

        // Cek dup
        $cnt = $db->table('recruitment_pipeline_templates')
            ->where('company_id', (int) $src['company_id'])
            ->where('code', $newCode)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($cnt > 0) return $this->fail("Code '{$newCode}' sudah dipakai", 422);

        $db->table('recruitment_pipeline_templates')->insert([
            'company_id'  => (int) $src['company_id'],
            'code'        => $newCode,
            'name'        => $newName,
            'description' => $src['description'],
            'is_default'  => 0,
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $newId = (int) $db->insertID();

        // Clone stages
        $stages = $db->table('recruitment_pipeline_stages')
            ->where('template_id', $id)
            ->orderBy('order_index', 'ASC')
            ->get()->getResultArray();
        foreach ($stages as $s) {
            unset($s['id']);
            $s['template_id'] = $newId;
            $s['created_at']  = $now;
            $s['updated_at']  = $now;
            $db->table('recruitment_pipeline_stages')->insert($s);
        }

        Services::audit()->forModule('MASTER')->logCreate(
            'recruitment_pipeline_template',
            $newId,
            ['name' => $newName, 'duplicated_from' => $id],
            "Duplikat pipeline '{$src['name']}' → '{$newName}'"
        );

        return $this->show($newId);
    }

    // ─── Helpers ───────────────────────────────────────────────

    /**
     * Validate + insert stages array. Return string error kalau invalid,
     * null kalau sukses.
     *
     * Validation rules:
     *  - Setiap stage wajib punya `code` (uppercase, alpha_dash, ≤32) + `name`.
     *  - `code` unik dalam template.
     *  - `stage_type` valid (atau default CUSTOM).
     *  - Wajib ada minimal 1 stage terminal (HIRED/REJECTED/WITHDRAWN type).
     *  - `required_test_category_id` (kalau ada) harus valid + accessible.
     */
    private function insertStages($db, int $templateId, int $companyId, array $stages, string $now): ?string
    {
        if (empty($stages)) return 'Pipeline harus punya minimal 1 stage';

        $validTypes = ['SCREENING', 'ONLINE_TEST', 'INTERVIEW', 'ASSESSMENT',
                       'OFFER', 'BACKGROUND_CHECK', 'MEDICAL_CHECK', 'CUSTOM',
                       'HIRED', 'REJECTED', 'WITHDRAWN'];
        $codesSeen = [];
        $hasTerminalHired = false;

        foreach ($stages as $i => $s) {
            $code = strtoupper(trim((string) ($s['code'] ?? '')));
            $name = trim((string) ($s['name'] ?? ''));
            if ($code === '' || ! preg_match('/^[A-Z0-9_]{1,32}$/', $code)) {
                return "Stage #{$i}: code wajib (huruf kapital/digit/_, maks 32)";
            }
            if ($name === '') return "Stage #{$i} ({$code}): name wajib";
            if (isset($codesSeen[$code])) return "Stage code '{$code}' duplikat";
            $codesSeen[$code] = true;

            $type = strtoupper((string) ($s['stage_type'] ?? 'CUSTOM'));
            if (! in_array($type, $validTypes, true)) {
                return "Stage '{$code}': stage_type invalid ({$type})";
            }

            $isTerminal = (int) ($s['is_terminal'] ?? (in_array($type, ['HIRED','REJECTED','WITHDRAWN'], true) ? 1 : 0));
            if ($isTerminal === 1 && $type === 'HIRED') $hasTerminalHired = true;

            $reqCat = isset($s['required_test_category_id']) && $s['required_test_category_id'] !== ''
                ? (int) $s['required_test_category_id'] : null;
            if ($reqCat !== null) {
                $cat = $db->table('online_test_categories')
                    ->where('id', $reqCat)
                    ->where('deleted_at IS NULL')
                    ->groupStart()
                        ->where('company_id IS NULL')
                        ->orWhere('company_id', $companyId)
                    ->groupEnd()
                    ->get()->getRowArray();
                if (! $cat) return "Stage '{$code}': required_test_category_id tidak valid atau tidak accessible";
            }
        }

        if (! $hasTerminalHired) {
            return 'Pipeline harus punya minimal 1 stage dengan stage_type=HIRED';
        }

        // All good — insert
        foreach ($stages as $i => $s) {
            $db->table('recruitment_pipeline_stages')->insert([
                'template_id' => $templateId,
                'company_id'  => $companyId,
                'order_index' => isset($s['order_index']) ? (int) $s['order_index'] : $i,
                'code'        => strtoupper(trim((string) $s['code'])),
                'name'        => trim((string) $s['name']),
                'stage_type'  => strtoupper((string) ($s['stage_type'] ?? 'CUSTOM')),
                'required_test_category_id' => isset($s['required_test_category_id']) && $s['required_test_category_id'] !== ''
                    ? (int) $s['required_test_category_id'] : null,
                'description' => $s['description'] ?? null,
                'sla_hours'   => isset($s['sla_hours']) && $s['sla_hours'] !== ''
                    ? (int) $s['sla_hours'] : null,
                'is_terminal' => (int) ($s['is_terminal'] ?? 0),
                'is_optional' => (int) ($s['is_optional'] ?? 0),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
        return null;
    }
}