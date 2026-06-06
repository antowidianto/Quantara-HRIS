<?php

namespace App\Controllers\Api\V1\HR;

use App\Controllers\Api\V1\ApiController;

/**
 * 360-Degree Feedback Review
 * 
 * Flow: HR membuat Cycle → Nominasi reviewer (Self/Superior/Peer/Subordinate) →
 * Reviewer isi feedback → HR/Admin melihat agregat skor per karyawan.
 */
class Review360Controller extends ApiController
{
    // ─── Cycles ─────────────────────────────────────────────────

    public function cycles()
    {
        $db = \Config\Database::connect();
        $rows = $db->table('review_360_cycles')
            ->orderBy('created_at', 'DESC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function showCycle($id)
    {
        $db = \Config\Database::connect();
        $cycle = $db->table('review_360_cycles')->where('id', (int) $id)->get()->getRowArray();
        if (! $cycle) return $this->failNotFound('Cycle not found');

        // Ambil nominations + reviewee/reviewer names
        $nominations = $db->table('review_360_nominations n')
            ->select('n.*, re.full_name as reviewee_name, rr.full_name as reviewer_name')
            ->join('employees re', 're.id = n.reviewee_id')
            ->join('employees rr', 'rr.id = n.reviewer_id')
            ->where('n.cycle_id', (int) $id)
            ->get()->getResultArray();

        $cycle['nominations'] = $nominations;
        return $this->ok($cycle);
    }

    public function createCycle()
    {
        $data = $this->getJsonInput();
        $data['company_id'] = $this->tenant()->getCompanyId();
        $data['status'] = 'DRAFT';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('review_360_cycles')->insert($data);
        return $this->ok(['id' => $db->insertID()], 'Cycle created', 201);
    }

    public function updateCycle($id)
    {
        $data = $this->getJsonInput();
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $db->table('review_360_cycles')->where('id', (int) $id)->update($data);
        return $this->ok(null, 'Cycle updated');
    }

    // ─── Nominations ────────────────────────────────────────────

    public function addNomination()
    {
        $data = $this->getJsonInput();
        $data['status'] = 'PENDING';
        $data['created_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('review_360_nominations')->insert($data);
        return $this->ok(['id' => $db->insertID()], 'Nomination added', 201);
    }

    public function removeNomination($id)
    {
        $db = \Config\Database::connect();
        $db->table('review_360_nominations')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Nomination removed');
    }

    // ─── Submit Feedback ────────────────────────────────────────

    public function submitFeedback($nominationId)
    {
        $data = $this->getJsonInput();
        $db = \Config\Database::connect();

        $nomination = $db->table('review_360_nominations')
            ->where('id', (int) $nominationId)->get()->getRowArray();
        if (! $nomination) return $this->failNotFound();
        if ($nomination['status'] === 'SUBMITTED') {
            return $this->fail('Feedback sudah pernah disubmit', 422);
        }

        // Responses berupa array: [{competency, score, comment}, ...]
        $responses = $data['responses'] ?? [];
        foreach ($responses as $r) {
            $db->table('review_360_responses')->insert([
                'nomination_id' => (int) $nominationId,
                'competency'    => $r['competency'],
                'score'         => $r['score'],
                'comment'       => $r['comment'] ?? null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        $db->table('review_360_nominations')
            ->where('id', (int) $nominationId)
            ->update(['status' => 'SUBMITTED']);

        return $this->ok(null, 'Feedback submitted');
    }

    // ─── Aggregate (HR view) ────────────────────────────────────

    public function aggregateScores($cycleId)
    {
        $db = \Config\Database::connect();

        // Ambil rata-rata skor per reviewee, per relationship type, per competency
        $rows = $db->query("
            SELECT 
                n.reviewee_id,
                e.full_name AS reviewee_name,
                n.relationship,
                r.competency,
                AVG(r.score) AS avg_score,
                COUNT(r.id) AS response_count
            FROM review_360_responses r
            JOIN review_360_nominations n ON n.id = r.nomination_id
            JOIN employees e ON e.id = n.reviewee_id
            WHERE n.cycle_id = ?
            GROUP BY n.reviewee_id, e.full_name, n.relationship, r.competency
            ORDER BY n.reviewee_id, n.relationship, r.competency
        ", [(int) $cycleId])->getResultArray();

        return $this->ok($rows);
    }
}
