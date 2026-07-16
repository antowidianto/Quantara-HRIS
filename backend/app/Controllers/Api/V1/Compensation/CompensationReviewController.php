<?php

namespace App\Controllers\Api\V1\Compensation;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Compensation\CompensationReviewService;

final class CompensationReviewController extends ApiController
{
    private function service(): CompensationReviewService { return new CompensationReviewService(); }
    private function company(): ?int { return $this->requireCompanyId(); }
    private function error(\Throwable $e) { return $this->fail($e->getMessage(), $e instanceof \InvalidArgumentException ? 422 : 409, 'COMPENSATION_REVIEW_REJECTED'); }

    public function index() { $company = $this->company(); if ($company === null) return $this->response; try { return $this->ok($this->service()->cycles($company)); } catch (\Throwable $e) { return $this->error($e); } }
    public function show(int $id) { $company = $this->company(); if ($company === null) return $this->response; try { return $this->ok($this->service()->detail($company, $id)); } catch (\Throwable $e) { return $this->error($e); } }
    public function create() { $company = $this->company(); if ($company === null) return $this->response; try { return $this->ok($this->service()->createCycle($company, $this->getJsonInput(), $this->userId()), 'Cycle created.', 201); } catch (\Throwable $e) { return $this->error($e); } }
    public function budgets(int $id) { $company = $this->company(); if ($company === null) return $this->response; $input = $this->getJsonInput(); try { return $this->ok($this->service()->replaceBudgets($company, $id, is_array($input['budgets'] ?? null) ? $input['budgets'] : []), 'Budgets saved.'); } catch (\Throwable $e) { return $this->error($e); } }
    public function open(int $id) { $company = $this->company(); if ($company === null) return $this->response; try { return $this->ok($this->service()->openCycle($company, $id), 'Cycle opened.'); } catch (\Throwable $e) { return $this->error($e); } }
    public function propose(int $id) { $company = $this->company(); if ($company === null) return $this->response; $input = $this->getJsonInput(); try { return $this->ok($this->service()->propose($company, $id, (int) ($input['employee_id'] ?? 0), (float) ($input['proposed_salary'] ?? -1), isset($input['performance_score']) ? (float) $input['performance_score'] : null, isset($input['calibration_input']) ? (string) $input['calibration_input'] : null, $this->userId()), 'Proposal submitted.', 201); } catch (\Throwable $e) { return $this->error($e); } }
    public function letter(int $id) { $company = $this->company(); if ($company === null) return $this->response; try { return $this->ok($this->service()->letter($company, $id)); } catch (\Throwable $e) { return $this->error($e); } }
}
