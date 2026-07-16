<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Import\XlsxImportService;
use App\Libraries\Recruitment\OnlineTestAdminException;
use App\Libraries\Recruitment\OnlineTestAdminService;
use App\Libraries\Recruitment\OnlineTestBankImportParser;
use App\Libraries\Recruitment\OnlineTestSeedCatalog;
use App\Repositories\Recruitment\OnlineTestRepository;
use Config\Services;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * OnlineTestController — admin (HR) endpoint untuk mengelola test online,
 * question bank, assignments, dan grading.
 *
 * Permissions:
 *   online_tests.view   — list & show
 *   online_tests.manage — create/update/delete tests, questions, assign
 *   online_tests.grade  — manual grading essay/short answer
 *
 * Routes (lihat Routes.php):
 *   Tests:
 *     GET    /online-tests
 *     POST   /online-tests
 *     GET    /online-tests/(:num)
 *     PUT    /online-tests/(:num)
 *     DELETE /online-tests/(:num)
 *     POST   /online-tests/(:num)/seed-template — seed soal default by category
 *
 *   Questions per test:
 *     GET    /online-tests/(:num)/questions
 *     POST   /online-tests/(:num)/questions
 *     PUT    /online-test-questions/(:num)
 *     DELETE /online-test-questions/(:num)
 *     POST   /online-tests/(:num)/import-from-bank
 *
 *   Question bank:
 *     GET    /online-test-questions-bank
 *     POST   /online-test-questions-bank
 *     PUT    /online-test-questions-bank/(:num)
 *     DELETE /online-test-questions-bank/(:num)
 *     GET    /online-test-questions-bank/categories
 *
 *   Assignments:
 *     GET    /online-test-assignments
 *     POST   /online-test-assignments              (HR assign ke kandidat)
 *     GET    /online-test-assignments/(:num)
 *     PUT    /online-test-assignments/(:num)
 *     DELETE /online-test-assignments/(:num)
 *     POST   /online-test-assignments/(:num)/cancel
 *
 *   Attempts/grading:
 *     GET    /online-test-attempts/(:num)          (HR view, full answers)
 *     POST   /online-test-attempts/(:num)/grade    (manual grade essay)
 */
class OnlineTestController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.view') || $t->hasPermission('online_tests.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.manage');
    }

    private function canGrade(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.grade') || $t->hasPermission('online_tests.manage');
    }

    private function repository(): OnlineTestRepository
    {
        return new OnlineTestRepository();
    }

    private function adminService(): OnlineTestAdminService
    {
        return new OnlineTestAdminService();
    }

    // ─── Tests CRUD ─────────────────────────────────────────────────

    public function listTests()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $cat = (string) ($this->request->getGet('category') ?? '');
        $result = $this->repository()->paginateTests($companyId, $page, $perPage, $cat);
        return $this->paginated($result['rows'], $result['total'], $page, $perPage);
    }

    public function showTest($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $repository = $this->repository();
        $row = $repository->findTest((int) $id, $companyId);
        if (! $row) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $questionCount = $repository->countQuestions((int) $row['id'], $companyId);
        $row['question_count'] = $questionCount;
        $row['pool_filters'] = $row['pool_filters'] ? json_decode((string) $row['pool_filters'], true) : null;
        return $this->ok($row);
    }

    public function createTest()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'name'             => 'required|max_length[200]',
            'duration_minutes' => 'required|integer|greater_than[0]',
            'category'         => 'permit_empty|in_list[TPA,ENGLISH,DISC,TECHNICAL,CUSTOM]',
            'passing_score_pct'=> 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[100]',
            'max_attempts'     => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        try {
            $result = $this->adminService()->createTest($companyId, $this->userId(), $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok($result, 'Test created', 201);
    }

    public function updateTest($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'name' => 'permit_empty|max_length[200]',
            'category' => 'permit_empty|in_list[TPA,ENGLISH,DISC,TECHNICAL,CUSTOM]',
            'duration_minutes' => 'permit_empty|integer|greater_than[0]',
            'passing_score_pct' => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[100]',
            'max_attempts' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        ]);
        if (! $valid) return null;
        try {
            $this->adminService()->updateTest((int) $id, $companyId, $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Updated');
    }

    public function deleteTest($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $mode = $this->adminService()->deleteTest((int) $id, $companyId);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, $mode === 'soft' ? 'Soft-deleted (ada assignment terkait)' : 'Deleted');
    }

    /**
     * Seed soal default by category. Hanya untuk tes yang BARU dibuat
     * (tidak punya soal). Pakai untuk bootstrap tes TPA / English / DISC
     * tanpa harus input manual.
     */
    public function seedTemplate($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->repository()->findTest((int) $id, $companyId);
        if (! $row) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $body = $this->getJsonInput();
        $template = strtoupper((string) ($body['template'] ?? $row['category'] ?? 'CUSTOM'));
        $seeds = (new OnlineTestSeedCatalog())->forTemplate($template);
        try {
            $seeded = $this->adminService()->seedQuestions((int) $id, $companyId, $seeds);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(['seeded' => $seeded], 'Template soal berhasil di-seed');
    }

    // ─── Questions per test ────────────────────────────────────────

    public function listQuestions($testId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $test = $this->repository()->findTest((int) $testId, $companyId);
        if (! $test) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $rows = $this->repository()->listQuestions((int) $testId, $companyId);
        foreach ($rows as &$r) {
            $r['choices']        = $r['choices'] ? json_decode((string) $r['choices'], true) : null;
            $r['correct_answer'] = $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null;
        }
        unset($r);
        return $this->ok($rows);
    }

    public function createQuestion($testId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'prompt'        => 'required',
            'question_type' => 'required|in_list[' . implode(',', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES) . ']',
            'points' => 'permit_empty|numeric|greater_than[0]',
        ]);
        if (! $valid) return null;
        try {
            $id = $this->adminService()->createQuestion((int) $testId, $companyId, $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(['id' => $id], 'Question added', 201);
    }

    public function updateQuestion($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'prompt' => 'permit_empty',
            'question_type' => 'permit_empty|in_list[' . implode(',', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES) . ']',
            'points' => 'permit_empty|numeric|greater_than[0]',
            'sort_order' => 'permit_empty|integer|greater_than_equal_to[0]',
        ]);
        if (! $valid) return null;
        try {
            $this->adminService()->updateQuestion((int) $id, $companyId, $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Updated');
    }

    public function deleteQuestion($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $this->adminService()->deleteQuestion((int) $id, $companyId);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Deleted');
    }

    public function importFromBank($testId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $ids = $body['bank_question_ids'] ?? [];
        if (! is_array($ids) || empty($ids)) {
            return $this->fail('bank_question_ids wajib (array of int)', 422);
        }
        try {
            $imported = $this->adminService()->importQuestionsFromBank((int) $testId, $companyId, $ids);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(['imported' => $imported], 'Soal di-import dari bank', 201);
    }

    // ─── Question Bank ─────────────────────────────────────────────

    public function bankCategories()
    {
        return $this->ok([
            'test_categories'     => \App\Libraries\Recruitment\OnlineTestService::CATEGORIES,
            'question_categories' => \App\Libraries\Recruitment\OnlineTestService::QUESTION_BANK_CATEGORIES,
            'question_types'      => \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES,
        ]);
    }

    public function bankTemplate()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $columns = (new OnlineTestBankImportParser())->columns();
        $ss = new Spreadsheet();
        $data = $ss->getActiveSheet();
        $data->setTitle('Data');

        foreach ($columns as $idx => $column) {
            $cell = chr(65 + $idx) . '1';
            $data->setCellValue($cell, $column['header'] . (! empty($column['required']) ? ' *' : ''));
            $data->getColumnDimension(chr(65 + $idx))->setWidth($column['width'] ?? 22);
        }
        $data->fromArray([
            'GENERAL',
            'MEDIUM',
            'MCQ_SINGLE',
            'Jika 2 + 3 = ..., pilih jawaban yang benar.',
            'A=5|B=6|C=7|D=8',
            'A',
            '',
            '2 + 3 sama dengan 5.',
            'contoh,aritmatika',
        ], null, 'A2');
        $data->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $data->getStyle('A2:I2')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
        $data->freezePane('A2');

        $ref = $ss->createSheet();
        $ref->setTitle('Referensi');
        $ref->setCellValue('A1', 'Referensi Template Bank Soal');
        $ref->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 14]]);
        $ref->setCellValue('A3', 'Kolom');
        $ref->setCellValue('B3', 'Wajib');
        $ref->setCellValue('C3', 'Keterangan');
        $ref->getStyle('A3:C3')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E7FF']],
        ]);
        $row = 4;
        foreach ($columns as $column) {
            $ref->setCellValue("A{$row}", $column['header']);
            $ref->setCellValue("B{$row}", ! empty($column['required']) ? 'Ya' : '-');
            $ref->setCellValue("C{$row}", $column['help']);
            $ref->getStyle("C{$row}")->getAlignment()->setWrapText(true);
            $row++;
        }

        $row += 2;
        $ref->setCellValue("A{$row}", 'Question Type');
        $ref->setCellValue("B{$row}", implode(', ', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES));
        $row++;
        $ref->setCellValue("A{$row}", 'Difficulty');
        $ref->setCellValue("B{$row}", 'EASY, MEDIUM, HARD');
        $row++;
        $ref->setCellValue("A{$row}", 'Category');
        $ref->setCellValue("B{$row}", implode(', ', array_keys(\App\Libraries\Recruitment\OnlineTestService::QUESTION_BANK_CATEGORIES)));
        $row++;
        $ref->setCellValue("A{$row}", 'DISC Weight');
        $ref->setCellValue("B{$row}", 'Isi D, I, S, atau C hanya untuk tipe LIKERT_1_5');
        $row++;
        $ref->setCellValue("A{$row}", 'Choices');
        $ref->setCellValue("B{$row}", 'Untuk MCQ gunakan A=Pilihan A|B=Pilihan B atau JSON array [{"key":"A","text":"Pilihan A"}]');
        $row++;
        $ref->setCellValue("A{$row}", 'Correct Answer');
        $ref->setCellValue("B{$row}", 'MCQ_SINGLE: A. MCQ_MULTI: A,C. TRUE_FALSE: true/false. NUMERIC: angka.');
        $ref->getColumnDimension('A')->setWidth(24);
        $ref->getColumnDimension('B')->setWidth(120);
        $ref->getColumnDimension('C')->setWidth(80);
        $ref->getStyle("A1:C{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $ref->getStyle("B1:C{$row}")->getAlignment()->setWrapText(true);

        $tmpDir = WRITEPATH . 'imports/templates';
        if (! is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $path = $tmpDir . DIRECTORY_SEPARATOR . 'online-test-question-bank-template-' . date('YmdHis') . '.xlsx';
        (new XlsxWriter($ss))->save($path);
        $ss->disconnectWorksheets();

        $filename = 'online-test-question-bank-template.xlsx';
        $body = file_get_contents($path);
        @unlink($path);

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->setHeader('X-Filename', $filename)
            ->setBody($body);
    }

    public function importBankTemplate()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File upload tidak valid: ' . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        if ($file->getSize() > XlsxImportService::MAX_BYTES) {
            return $this->fail('Ukuran file melebihi batas 10 MB', 413);
        }
        $ext = strtolower(pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: '');
        if ($ext !== 'xlsx') {
            return $this->fail('Gunakan file .xlsx dari template bank soal.', 415);
        }

        try {
            $ss = IOFactory::load($file->getTempName());
            $rowsRaw = $ss->getSheet(0)->toArray(null, true, true, false);
            $ss->disconnectWorksheets();
        } catch (\Throwable $e) {
            return $this->fail('Gagal membaca Excel: ' . $e->getMessage(), 422);
        }

        try {
            $parsed = (new OnlineTestBankImportParser())->parseRows($rowsRaw);
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }
        $inserted = $this->adminService()->importBankRows($companyId, $this->userId(), $parsed['rows']);

        return $this->ok([
            'inserted' => $inserted,
            'errors' => $parsed['errors'],
        ], $parsed['errors'] ? 'Import selesai dengan sebagian error' : 'Import bank soal berhasil');
    }

    public function listBank()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $cat = (string) ($this->request->getGet('category') ?? '');
        $diff = (string) ($this->request->getGet('difficulty') ?? '');
        $type = (string) ($this->request->getGet('question_type') ?? '');
        $q = trim((string) ($this->request->getGet('q') ?? ''));

        $result = $this->repository()->paginateBank($companyId, $page, $perPage, [
            'category' => $cat, 'difficulty' => $diff, 'question_type' => $type, 'q' => $q,
        ]);
        $rows = $result['rows'];
        foreach ($rows as &$r) {
            $r['choices']        = $r['choices'] ? json_decode((string) $r['choices'], true) : null;
            $r['correct_answer'] = $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null;
            $r['tags']           = $r['tags'] ? json_decode((string) $r['tags'], true) : [];
        }
        unset($r);
        return $this->paginated($rows, $result['total'], $page, $perPage);
    }

    public function createBank()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'prompt'        => 'required',
            'question_type' => 'required|in_list[' . implode(',', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES) . ']',
        ]);
        if (! $valid) return null;
        try {
            $id = $this->adminService()->createBankQuestion($companyId, $this->userId(), $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(['id' => $id], 'Bank question added', 201);
    }

    public function updateBank($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        try {
            $this->adminService()->updateBankQuestion((int) $id, $companyId, $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Updated');
    }

    public function deleteBank($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $this->adminService()->deleteBankQuestion((int) $id, $companyId);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Deleted');
    }

    // ─── Assignments ───────────────────────────────────────────────

    public function listAssignments()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = (string) ($this->request->getGet('status') ?? '');
        $candidateId = (int) ($this->request->getGet('candidate_id') ?? 0);
        $testId = (int) ($this->request->getGet('online_test_id') ?? 0);
        $result = $this->repository()->paginateAssignments($companyId, $page, $perPage, [
            'status' => $status,
            'candidate_id' => $candidateId,
            'online_test_id' => $testId,
        ]);
        return $this->paginated($result['rows'], $result['total'], $page, $perPage);
    }

    public function showAssignment($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $repository = $this->repository();
        $row = $repository->findAssignment((int) $id, $companyId);
        if (! $row) {
            return $this->fail('Assignment tidak ditemukan', 404);
        }
        $candidate = $repository->findCandidate((int) $row['candidate_id'], $companyId);
        $test = $repository->findTest((int) $row['online_test_id'], $companyId);
        $attempts = $repository->listAttempts((int) $row['id'], $companyId);
        return $this->ok([
            'assignment' => $row,
            'candidate'  => $candidate,
            'test'       => $test,
            'attempts'   => $attempts,
        ]);
    }

    public function createAssignment()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'online_test_id' => 'required|integer',
            'candidate_id'   => 'required|integer',
            'available_from' => 'permit_empty|valid_date',
            'due_at' => 'permit_empty|valid_date',
            'max_attempts' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        ]);
        if (! $valid) return null;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $created = $this->adminService()->createAssignment($companyId, $this->userId(), $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        $aid = $created['id'];
        $candidate = $created['candidate'];
        $test = $created['test'];
        $insert = $created['assignment'];

        // Webhook outbound
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::ONLINE_TEST_ASSIGNED,
            $companyId,
            [
                'assignment_id'        => $aid,
                'candidate_id'         => (int) $body['candidate_id'],
                'candidate_account_id' => $candidate['candidate_account_id'] ?? null,
                'online_test_id'       => (int) $body['online_test_id'],
                'test_name'            => $test['name'],
                'test_category'        => $test['category'],
                'invitation_token'     => $insert['invitation_token'],
                'due_at'               => $insert['due_at'],
                'assigned_by'          => $this->userId(),
                'assigned_at'          => $created['created_at'],
            ],
        );

        // Email kandidat (optional, kalau punya account)
        if (! empty($candidate['candidate_account_id']) && ! empty($candidate['email'])) {
            try {
                $body = '<p>Halo, ' . htmlspecialchars((string) $candidate['full_name']) . '.</p>'
                    . '<p>Tim recruitment kami menugaskan Anda untuk mengikuti tes online '
                    . '<strong>' . htmlspecialchars((string) $test['name']) . '</strong>.</p>'
                    . '<p>Login ke akun career portal Anda untuk mulai mengerjakan.</p>';
                Services::notification()->queueEmail(
                    $companyId, (string) $candidate['email'],
                    (string) $candidate['full_name'],
                    'Tugas Tes Online — ' . $test['name'], $body,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Email assign test failed: ' . $e->getMessage());
            }
        }

        return $this->ok(['id' => $aid, 'invitation_token' => $created['invitation_token']], 'Assignment created', 201);
    }

    public function updateAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'available_from' => 'permit_empty|valid_date',
            'due_at' => 'permit_empty|valid_date',
            'max_attempts' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
        ]);
        if (! $valid) return null;
        try {
            $this->adminService()->updateAssignment((int) $id, $companyId, $body);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Updated');
    }

    public function cancelAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $this->adminService()->cancelAssignment((int) $id, $companyId);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Cancelled');
    }

    public function deleteAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $this->adminService()->deleteAssignment((int) $id, $companyId);
        } catch (OnlineTestAdminException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
        return $this->ok(null, 'Deleted');
    }

    // ─── Attempts (HR side: full view + grade) ─────────────────────

    public function showAttempt($attemptId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $repository = $this->repository();
        $row = $repository->findAttempt((int) $attemptId, $companyId);
        if (! $row) return $this->fail('Attempt tidak ditemukan', 404);
        $assign = $repository->findAssignment((int) $row['assignment_id'], $companyId);
        if (! $assign) return $this->fail('Attempt tidak ditemukan', 404);
        // HR view: include correct_answer + explanation
        $result = Services::onlineTest()->buildResultView((int) $attemptId, $companyId, true);
        $result['assignment'] = $assign;
        $candidate = $repository->findCandidate((int) $assign['candidate_id'], $companyId);
        $result['candidate'] = $candidate ? [
            'id'        => (int) $candidate['id'],
            'full_name' => $candidate['full_name'],
            'email'     => $candidate['email'],
            'phone'     => $candidate['phone'] ?? null,
        ] : null;
        return $this->ok($result);
    }

    public function gradeAnswer($attemptId = null)
    {
        if (! $this->canGrade()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'answer_id'      => 'required|integer',
            'points_awarded' => 'required|numeric|greater_than_equal_to[0]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->repository()->findAttempt((int) $attemptId, $companyId);
        if (! $row) return $this->fail('Attempt tidak ditemukan', 404);

        try {
            $result = Services::onlineTest()->gradeManualAnswer(
                (int) $attemptId,
                (int) $body['answer_id'],
                (float) $body['points_awarded'],
                $body['feedback'] ?? null,
                $this->userId(),
                $companyId,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result, 'Graded');
    }

}
