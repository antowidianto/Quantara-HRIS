<?php

namespace App\Libraries\Performance;

/**
 * PulseSurveyService (Sprint 19+ / H4.4)
 *
 * Employee Wellbeing & Mental Health surveys — anonymous pulse survey
 * dengan eNPS / burnout detection score + privacy guarantee:
 *
 *   1. Tidak ada `employee_id` di tabel response. Yang disimpan hanya
 *      `submission_hash = SHA256(employee_id . survey_id . salt)` untuk
 *      anti-double-submit. Hash tidak reversible.
 *   2. Demographic bucket (department, branch, tenure, gender) opsional
 *      — di-strip di response kalau jumlah responden < threshold per
 *      bucket (default 5) supaya tidak bisa di-de-anonymize.
 *   3. eNPS classic: % Promoter (9-10) − % Detractor (0-6) = score -100..100
 *   4. Burnout score: rata-rata dari 5 question MBI shortcut (reverse-scored
 *      kalau perlu) → 0-100, lebih tinggi = lebih burnout.
 *
 * Pure helpers:
 *   - submissionHash(employeeId, surveyId, salt)
 *   - tenureBucketFromMonths(months)
 *   - calcENPS(scores[])
 *   - calcBurnoutScore(answers[], questions[])
 */
class PulseSurveyService
{
    public const QUESTION_TYPES = ['ENPS', 'LIKERT_5', 'LIKERT_7', 'BURNOUT', 'YES_NO', 'OPEN_TEXT'];
    public const TENURE_BUCKETS = ['LESS_1Y', '1_3Y', '3_5Y', '5_10Y', 'OVER_10Y'];
    public const FREQUENCIES = ['ONE_TIME', 'WEEKLY', 'BIWEEKLY', 'MONTHLY', 'QUARTERLY'];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Pure anti-double-submit hash. Salt diambil dari env supaya stabil
     * across deploy dan tidak bocor di codebase.
     */
    public static function submissionHash(int $employeeId, int $surveyId, string $salt): string
    {
        return hash('sha256', $employeeId . ':' . $surveyId . ':' . $salt);
    }

    /**
     * Pure tenure bucket mapper.
     */
    public static function tenureBucketFromMonths(int $months): string
    {
        if ($months < 12) return 'LESS_1Y';
        if ($months < 36) return '1_3Y';
        if ($months < 60) return '3_5Y';
        if ($months < 120) return '5_10Y';
        return 'OVER_10Y';
    }

    /**
     * Pure eNPS calculator (classic NPS).
     * Input: array of 0-10 scores. Output: -100..100.
     *   Promoter: 9-10
     *   Passive:  7-8
     *   Detractor: 0-6
     */
    public static function calcENPS(array $scores): float
    {
        $valid = array_values(array_filter($scores,
            fn ($s) => is_numeric($s) && $s >= 0 && $s <= 10));
        $n = count($valid);
        if ($n === 0) return 0.0;

        $promoter = 0;
        $detractor = 0;
        foreach ($valid as $s) {
            $v = (int) $s;
            if ($v >= 9) $promoter++;
            elseif ($v <= 6) $detractor++;
        }
        return round((($promoter - $detractor) / $n) * 100, 2);
    }

    /**
     * Pure burnout score calculator. Input: jawaban + question definitions
     * dengan flag reverse_score.
     *
     * Burnout MBI-inspired short scale (5 question, 1-5 Likert):
     *   1. Saya merasa habis tenaga di akhir hari kerja (reverse: NO, langsung)
     *   2. Saya merasa pekerjaan saya bermakna (reverse: YES — answer tinggi = LOW burnout)
     *   3. Saya merasa cemas memikirkan pekerjaan saat libur
     *   4. Saya melihat hasil positif dari kerja saya (reverse: YES)
     *   5. Saya kesulitan tidur karena pekerjaan
     *
     * Formula: rata-rata semua jawaban (reverse di-flip 6-x), normalize ke 0-100.
     *
     * @param array<int, int|float> $answers   Map question_id => answer (1-5 untuk LIKERT_5)
     * @param array<int, array{id:int, reverse_score:int, question_type:string}> $questions
     */
    public static function calcBurnoutScore(array $answers, array $questions): ?float
    {
        $burnoutAnswers = [];
        foreach ($questions as $q) {
            if (! in_array($q['question_type'], ['BURNOUT', 'LIKERT_5'], true)) continue;
            if (! isset($answers[$q['id']])) continue;
            $val = (float) $answers[$q['id']];
            if ($val <= 0) continue;
            // Reverse-score: 6 - val for 1-5 scale
            if (! empty($q['reverse_score'])) {
                $val = 6 - $val;
            }
            $burnoutAnswers[] = $val;
        }
        if (empty($burnoutAnswers)) return null;
        $avg = array_sum($burnoutAnswers) / count($burnoutAnswers); // 1-5
        // Normalize ke 0-100: (avg - 1) / 4 × 100
        return round((($avg - 1) / 4) * 100, 2);
    }

    /**
     * Submit response dari karyawan (anonim). employee_id hanya dipakai
     * untuk compute hash + demographic bucket; tidak disimpan di tabel.
     *
     * @return array{id: int}
     */
    public function submitResponse(
        int $surveyId,
        int $employeeId,
        array $answers,
        ?string $salt = null,
    ): array {
        $salt = $salt ?? (string) env('PULSE_SURVEY_SALT', 'quantara-pulse-default');

        $db = $this->db();
        $survey = $db->table('pulse_surveys')->where('id', $surveyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $survey) throw new \RuntimeException('Survey tidak ditemukan');
        if ($survey['status'] !== 'OPEN') {
            throw new \RuntimeException('Survey tidak terbuka untuk respon: ' . $survey['status']);
        }
        if (! empty($survey['ends_at']) && strtotime($survey['ends_at']) < time()) {
            throw new \RuntimeException('Survey sudah ditutup');
        }

        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->where('is_active', 1)->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak aktif');
        if ((int) $emp['company_id'] !== (int) $survey['company_id']) {
            throw new \RuntimeException('Cross-tenant submission tidak diperbolehkan');
        }

        // Demographic bucket
        $tenureBucket = null;
        if (! empty($emp['join_date'])) {
            $tenureMonths = (int) (((time() - strtotime($emp['join_date'])) / 86400) / 30.4);
            $tenureBucket = self::tenureBucketFromMonths($tenureMonths);
        }

        $hash = self::submissionHash($employeeId, $surveyId, $salt);
        // Anti-double-submit
        $exists = $db->table('pulse_survey_responses')
            ->where('survey_id', $surveyId)
            ->where('submission_hash', $hash)
            ->countAllResults();
        if ($exists > 0) {
            throw new \RuntimeException('Anda sudah submit respon untuk survey ini');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('pulse_survey_responses')->insert([
            'survey_id'       => $surveyId,
            'submission_hash' => $hash,
            'department_id'   => isset($emp['department_id']) ? (int) $emp['department_id'] : null,
            'branch_id'       => isset($emp['branch_id']) ? (int) $emp['branch_id'] : null,
            'tenure_bucket'   => $tenureBucket,
            'gender'          => $emp['gender'] ?? null,
            'answers_json'    => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'submitted_at'    => $now,
            'created_at'      => $now,
        ]);
        return ['id' => (int) $db->insertID()];
    }

    /**
     * Compute aggregate untuk satu scope. Enforce minimum response count
     * untuk anonimitas — kalau < threshold, return null untuk eNPS/burnout
     * (tidak boleh expose data agar tidak de-anonymize).
     *
     * @param string $scopeType  COMPANY | DEPARTMENT | BRANCH | TENURE | GENDER
     * @param int|null $scopeId  department_id / branch_id; null untuk COMPANY/TENURE/GENDER
     * @param string|null $scopeLabel  Label override (mis. tenure bucket name)
     */
    public function computeAggregate(
        int $surveyId,
        string $scopeType,
        ?int $scopeId = null,
        ?string $scopeLabel = null,
    ): array {
        $db = $this->db();
        $survey = $db->table('pulse_surveys')->where('id', $surveyId)->get()->getRowArray();
        if (! $survey) throw new \RuntimeException('Survey tidak ditemukan');

        $minThreshold = (int) ($survey['min_participation_for_dept_view'] ?? 5);
        $b = $db->table('pulse_survey_responses')->where('survey_id', $surveyId);
        switch ($scopeType) {
            case 'DEPARTMENT': $b->where('department_id', $scopeId); break;
            case 'BRANCH':     $b->where('branch_id', $scopeId); break;
            case 'TENURE':     $b->where('tenure_bucket', $scopeLabel); break;
            case 'GENDER':     $b->where('gender', $scopeLabel); break;
            // COMPANY = no filter
        }
        $rows = $b->get()->getResultArray();
        $count = count($rows);

        $result = [
            'survey_id'      => $surveyId,
            'scope_type'     => $scopeType,
            'scope_id'       => $scopeId,
            'scope_label'    => $scopeLabel,
            'response_count' => $count,
            'enps_score'     => null,
            'burnout_score'  => null,
            'satisfaction_avg' => null,
        ];

        // Privacy gate: kalau < threshold, return tanpa metric values
        if ($count < $minThreshold && $scopeType !== 'COMPANY') {
            $result['suppressed'] = true;
            $result['suppression_reason'] = "min_threshold={$minThreshold}, actual={$count}";
            return $result;
        }
        if ($count === 0) return $result;

        // Load questions
        $questions = $db->table('pulse_survey_questions')
            ->where('survey_id', $surveyId)->get()->getResultArray();
        $questionsById = [];
        foreach ($questions as $q) $questionsById[(int) $q['id']] = $q;

        // Aggregate answers per question
        $enpsAnswers = [];
        $burnoutPerResp = [];
        $likertSum = 0.0;
        $likertCount = 0;

        foreach ($rows as $r) {
            $answers = json_decode((string) $r['answers_json'], true) ?: [];
            foreach ($answers as $qid => $val) {
                $q = $questionsById[(int) $qid] ?? null;
                if (! $q) continue;
                if ($q['question_type'] === 'ENPS' && is_numeric($val)) {
                    $enpsAnswers[] = (int) $val;
                } elseif (in_array($q['question_type'], ['LIKERT_5', 'LIKERT_7'], true)
                    && is_numeric($val)) {
                    $likertSum += (float) $val;
                    $likertCount++;
                }
            }
            $burnoutScore = self::calcBurnoutScore($answers, $questions);
            if ($burnoutScore !== null) $burnoutPerResp[] = $burnoutScore;
        }

        $result['enps_score']       = ! empty($enpsAnswers)
            ? self::calcENPS($enpsAnswers) : null;
        $result['burnout_score']    = ! empty($burnoutPerResp)
            ? round(array_sum($burnoutPerResp) / count($burnoutPerResp), 2) : null;
        $result['satisfaction_avg'] = $likertCount > 0
            ? round($likertSum / $likertCount, 2) : null;
        $result['suppressed']       = false;

        // Persist ke pulse_survey_aggregates (cache)
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('pulse_survey_aggregates')
            ->where('survey_id', $surveyId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId, $scopeId === null)
            ->get()->getRowArray();
        /** @var array<string, mixed> $payload */
        $payload = $result + ['computed_at' => $now];
        unset($payload['suppressed'], $payload['suppression_reason']);
        if ($existing) {
            $db->table('pulse_survey_aggregates')->where('id', (int) $existing['id'])
                ->update($payload);
        } else {
            $db->table('pulse_survey_aggregates')->insert($payload);
        }
        return $result;
    }

    /**
     * Compute semua aggregate sekaligus (company, per dept, per branch).
     * Berguna untuk batch refresh dashboard.
     *
     * @return array<int, array<string,mixed>>
     */
    public function computeAllAggregates(int $surveyId): array
    {
        $db = $this->db();
        $survey = $db->table('pulse_surveys')->where('id', $surveyId)->get()->getRowArray();
        if (! $survey) throw new \RuntimeException('Survey tidak ditemukan');
        $companyId = (int) $survey['company_id'];

        $results = [];
        // Company-wide
        $results[] = $this->computeAggregate($surveyId, 'COMPANY');

        // Per department
        $depts = $db->table('departments')->select('id, name')
            ->where('company_id', $companyId)->where('deleted_at IS NULL')
            ->get()->getResultArray();
        foreach ($depts as $d) {
            $results[] = $this->computeAggregate(
                $surveyId, 'DEPARTMENT', (int) $d['id'], $d['name']
            );
        }

        // Per branch
        $branches = $db->table('branches')->select('id, name')
            ->where('company_id', $companyId)->where('deleted_at IS NULL')
            ->get()->getResultArray();
        foreach ($branches as $b) {
            $results[] = $this->computeAggregate(
                $surveyId, 'BRANCH', (int) $b['id'], $b['name']
            );
        }

        // Per tenure bucket
        foreach (self::TENURE_BUCKETS as $tb) {
            $results[] = $this->computeAggregate($surveyId, 'TENURE', null, $tb);
        }

        return $results;
    }
}
