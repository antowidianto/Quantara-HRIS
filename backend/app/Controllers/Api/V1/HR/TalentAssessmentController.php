<?php

namespace App\Controllers\Api\V1\HR;

use App\Controllers\Api\V1\ApiController;

/**
 * Nine-Box Matrix (Talent Assessment)
 * 
 * Memetakan karyawan ke grid 3x3 berdasarkan Performance (X) vs Potential (Y).
 * Label otomatis dihitung dari kombinasi skor (1-5).
 */
class TalentAssessmentController extends ApiController
{
    private const NINE_BOX_LABELS = [
        'HIGH_HIGH'   => 'Star / Future Leader',
        'HIGH_MED'    => 'High Potential',
        'HIGH_LOW'    => 'Inconsistent Player',
        'MED_HIGH'    => 'Growth Employee',
        'MED_MED'     => 'Core Player',
        'MED_LOW'     => 'Average Performer',
        'LOW_HIGH'    => 'Enigma / Risk',
        'LOW_MED'     => 'Underperformer',
        'LOW_LOW'     => 'Talent Risk / Exit',
    ];

    private function computeLabel(float $performance, float $potential): string
    {
        $perfTier = $performance >= 4 ? 'HIGH' : ($performance >= 2.5 ? 'MED' : 'LOW');
        $potTier  = $potential >= 4 ? 'HIGH' : ($potential >= 2.5 ? 'MED' : 'LOW');
        $key = "{$potTier}_{$perfTier}";
        return self::NINE_BOX_LABELS[$key] ?? 'Unclassified';
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $year = $this->request->getGet('year') ?? date('Y');

        $rows = $db->table('talent_assessments ta')
            ->select('ta.*, e.full_name, e.employee_number, p.name as position_name')
            ->join('employees e', 'e.id = ta.employee_id')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('ta.assessment_year', (int) $year)
            ->orderBy('ta.potential_score', 'DESC')
            ->orderBy('ta.performance_score', 'DESC')
            ->get()->getResultArray();

        return $this->ok($rows);
    }

    public function upsert()
    {
        $data = $this->getJsonInput();
        $db = \Config\Database::connect();

        $employeeId = (int) $data['employee_id'];
        $year       = (int) ($data['assessment_year'] ?? date('Y'));
        $perfScore  = (float) $data['performance_score'];
        $potScore   = (float) $data['potential_score'];
        $label      = $this->computeLabel($perfScore, $potScore);

        $existing = $db->table('talent_assessments')
            ->where('employee_id', $employeeId)
            ->where('assessment_year', $year)
            ->get()->getRowArray();

        $record = [
            'employee_id'       => $employeeId,
            'assessment_year'   => $year,
            'performance_score' => $perfScore,
            'potential_score'   => $potScore,
            'nine_box_label'    => $label,
            'notes'             => $data['notes'] ?? null,
            'assessed_by'       => $this->userId(),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->table('talent_assessments')->where('id', $existing['id'])->update($record);
            return $this->ok(['id' => $existing['id'], 'label' => $label], 'Assessment updated');
        } else {
            $record['created_at'] = date('Y-m-d H:i:s');
            $db->table('talent_assessments')->insert($record);
            return $this->ok(['id' => $db->insertID(), 'label' => $label], 'Assessment created', 201);
        }
    }

    public function delete($id)
    {
        $db = \Config\Database::connect();
        $db->table('talent_assessments')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }
}
