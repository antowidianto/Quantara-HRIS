<?php

namespace App\Controllers\Api\V1\HR;

use App\Controllers\Api\V1\ApiController;

/**
 * Succession Planning
 * 
 * HR menandai posisi krusial → assign kandidat pengganti →
 * menilai readiness (READY_NOW / READY_1_2Y / NOT_READY).
 */
class SuccessionPlanController extends ApiController
{
    // ─── Critical Positions ─────────────────────────────────────

    public function positions()
    {
        $db = \Config\Database::connect();
        $rows = $db->table('critical_positions cp')
            ->select('cp.*, p.name as position_name, e.full_name as incumbent_name')
            ->join('positions p', 'p.id = cp.position_id')
            ->join('employees e', 'e.id = cp.incumbent_id', 'left')
            ->orderBy('cp.risk_level', 'DESC')
            ->get()->getResultArray();

        // Attach candidates count
        foreach ($rows as &$r) {
            $r['candidates_count'] = $db->table('succession_candidates')
                ->where('critical_position_id', $r['id'])
                ->countAllResults();
        }

        return $this->ok($rows);
    }

    public function showPosition($id)
    {
        $db = \Config\Database::connect();
        $pos = $db->table('critical_positions cp')
            ->select('cp.*, p.name as position_name, e.full_name as incumbent_name')
            ->join('positions p', 'p.id = cp.position_id')
            ->join('employees e', 'e.id = cp.incumbent_id', 'left')
            ->where('cp.id', (int) $id)
            ->get()->getRowArray();
        if (! $pos) return $this->failNotFound();

        $candidates = $db->table('succession_candidates sc')
            ->select('sc.*, e.full_name as candidate_name, e.employee_number, 
                      ta.performance_score, ta.potential_score, ta.nine_box_label')
            ->join('employees e', 'e.id = sc.candidate_id')
            ->join('talent_assessments ta', 'ta.employee_id = sc.candidate_id AND ta.assessment_year = ' . date('Y'), 'left')
            ->where('sc.critical_position_id', (int) $id)
            ->orderBy("FIELD(sc.readiness, 'READY_NOW', 'READY_1_2Y', 'NOT_READY')")
            ->get()->getResultArray();

        $pos['candidates'] = $candidates;
        return $this->ok($pos);
    }

    public function createPosition()
    {
        $data = $this->getJsonInput();
        $data['company_id'] = $this->tenant()->getCompanyId();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('critical_positions')->insert($data);
        return $this->ok(['id' => $db->insertID()], 'Critical position created', 201);
    }

    public function updatePosition($id)
    {
        $data = $this->getJsonInput();
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $db->table('critical_positions')->where('id', (int) $id)->update($data);
        return $this->ok(null, 'Updated');
    }

    public function deletePosition($id)
    {
        $db = \Config\Database::connect();
        $db->table('succession_candidates')->where('critical_position_id', (int) $id)->delete();
        $db->table('critical_positions')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }

    // ─── Candidates ─────────────────────────────────────────────

    public function addCandidate()
    {
        $data = $this->getJsonInput();
        $data['readiness'] = $data['readiness'] ?? 'NOT_READY';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('succession_candidates')->insert($data);
        return $this->ok(['id' => $db->insertID()], 'Candidate added', 201);
    }

    public function updateCandidate($id)
    {
        $data = $this->getJsonInput();
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $db->table('succession_candidates')->where('id', (int) $id)->update($data);
        return $this->ok(null, 'Candidate updated');
    }

    public function removeCandidate($id)
    {
        $db = \Config\Database::connect();
        $db->table('succession_candidates')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Removed');
    }
}
