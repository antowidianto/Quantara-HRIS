<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * OnlineTestExpandedBankSeeder
 *
 * Ekstensi bank soal di atas OnlineTestDummySeeder. Menambahkan ~55 soal
 * baru dan 4 paket test tambahan supaya question bank cukup kaya untuk:
 *   - Random pool tests (mode `use_pool=1`)
 *   - Multi-section TPA exam
 *   - Role-specific technical screening (Frontend, Sales, Accounting, HR)
 *   - Behavioral / Situational Judgment Test
 *
 * Idempotent + per-company. Aman dijalankan ulang.
 *
 * Jalankan:
 *   php spark db:seed OnlineTestExpandedBankSeeder
 *
 * Pra-syarat:
 *   - InitialSeeder + OnlineTestDummySeeder sudah pernah dijalankan
 *     (tidak wajib, tapi OnlineTestDummySeeder mengisi bank dasar 27
 *     soal yang dipakai sebagai keranjang awal).
 */
class OnlineTestExpandedBankSeeder extends Seeder
{
    public function run(): void
    {
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        if (empty($companies)) {
            echo "  [skip] tidak ada company aktif\n";
            return;
        }

        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            echo "\n== ExpandedBankSeeder untuk #{$companyId} ({$company['name']}) ==\n";

            // ─── Bank questions ─────────────────────────────────────
            $bankIds = [];
            foreach ($this->questionBank() as $q) {
                $existing = $db->table('online_test_question_bank')
                    ->where('company_id', $companyId)
                    ->where('category', $q['category'])
                    ->where('prompt', $q['prompt'])
                    ->where('deleted_at IS NULL')
                    ->get()->getRowArray();
                if ($existing) {
                    $bankIds[$q['key']] = (int) $existing['id'];
                    continue;
                }
                $choicesVal = $q['choices'] ?? null;
                $correctVal = $q['correct_answer'] ?? null;
                $db->table('online_test_question_bank')->insert([
                    'company_id'     => $companyId,
                    'category'       => $q['category'],
                    'difficulty'     => $q['difficulty'],
                    'question_type'  => $q['question_type'],
                    'prompt'         => $q['prompt'],
                    'choices'        => $choicesVal !== null ? json_encode($choicesVal, JSON_UNESCAPED_UNICODE) : null,
                    'correct_answer' => $correctVal !== null ? json_encode($correctVal, JSON_UNESCAPED_UNICODE) : null,
                    'disc_weight'    => $q['disc_weight'] ?? null,
                    'explanation'    => $q['explanation'] ?? null,
                    'tags'           => json_encode($q['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                    'is_active'      => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $bankIds[$q['key']] = (int) $db->insertID();
            }
            echo "  [bank] " . count($bankIds) . " soal tambahan tersedia\n";

            // ─── Tambahkan referensi soal dari OnlineTestDummySeeder
            // supaya test baru bisa pakai mix lama+baru.
            $existingDummies = $db->table('online_test_question_bank')
                ->where('company_id', $companyId)
                ->where('deleted_at IS NULL')
                ->select('id, prompt, category, tags')
                ->get()->getResultArray();
            $promptToId = [];
            foreach ($existingDummies as $r) {
                $promptToId[md5($r['category'] . '|' . $r['prompt'])] = (int) $r['id'];
            }
            $resolveByPrompt = static function (string $cat, string $prompt) use ($promptToId): ?int {
                return $promptToId[md5($cat . '|' . $prompt)] ?? null;
            };

            // Build "bank dasar" key list dari OnlineTestDummySeeder
            // sehingga test pool/random bisa baca 60+ soal sekaligus.
            // (kita tidak ulang isi soalnya — cukup catat ID-nya).

            // ─── Tests baru ─────────────────────────────────────────
            foreach ($this->tests() as $test) {
                $existing = $db->table('online_tests')
                    ->where('company_id', $companyId)
                    ->where('code', $test['code'])
                    ->where('deleted_at IS NULL')
                    ->get()->getRowArray();
                if ($existing) {
                    $testId = (int) $existing['id'];
                    echo "  [skip] test {$test['code']}\n";
                } else {
                    $db->table('online_tests')->insert([
                        'company_id'               => $companyId,
                        'code'                     => $test['code'],
                        'name'                     => $test['name'],
                        'category'                 => $test['category'],
                        'description'              => $test['description'],
                        'instructions_html'        => $test['instructions_html'],
                        'duration_minutes'         => $test['duration_minutes'],
                        'passing_score_pct'        => $test['passing_score_pct'],
                        'max_attempts'             => $test['max_attempts'],
                        'shuffle_questions'        => $test['shuffle_questions'] ?? 1,
                        'shuffle_choices'          => $test['shuffle_choices'] ?? 0,
                        'show_result_to_candidate' => $test['show_result_to_candidate'] ?? 1,
                        'use_pool'                 => $test['use_pool'] ?? 0,
                        'pool_filters'             => isset($test['pool_filters'])
                            ? json_encode($test['pool_filters'], JSON_UNESCAPED_UNICODE) : null,
                        'is_active'                => 1,
                        'is_template'              => 1,
                        'created_at'               => $now,
                        'updated_at'               => $now,
                    ]);
                    $testId = (int) $db->insertID();
                    echo "  [new]  test {$test['code']}\n";
                }

                // Skip attach manual untuk pool-based test.
                if (! empty($test['use_pool'])) continue;

                $sort = 0;
                $existingAttach = $db->table('online_test_questions')
                    ->where('online_test_id', $testId)
                    ->orderBy('sort_order', 'DESC')->get()->getResultArray();
                if ($existingAttach) $sort = (int) ($existingAttach[0]['sort_order'] ?? 0);

                foreach (($test['question_keys'] ?? []) as $qk) {
                    $bankId = $bankIds[$qk] ?? null;
                    if (! $bankId) continue;
                    $already = $db->table('online_test_questions')
                        ->where('online_test_id', $testId)
                        ->where('bank_question_id', $bankId)
                        ->countAllResults();
                    if ($already > 0) continue;

                    $bankRow = $db->table('online_test_question_bank')
                        ->where('id', $bankId)->get()->getRowArray();
                    if (! $bankRow) continue;

                    $sort++;
                    $db->table('online_test_questions')->insert([
                        'online_test_id'   => $testId,
                        'bank_question_id' => $bankId,
                        'question_type'    => $bankRow['question_type'],
                        'category'         => $bankRow['category'],
                        'prompt'           => $bankRow['prompt'],
                        'choices'          => $bankRow['choices'],
                        'correct_answer'   => $bankRow['correct_answer'],
                        'disc_weight'      => $bankRow['disc_weight'],
                        'points'           => $test['points_per_question'][$qk] ?? 1.0,
                        'explanation'      => $bankRow['explanation'],
                        'sort_order'       => $sort,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                }
            }
        }

        echo "\n✓ OnlineTestExpandedBankSeeder selesai.\n";
    }

    /**
     * Bank soal tambahan — setiap key harus unik (tidak overlap dengan
     * OnlineTestDummySeeder). Convention: prefix XXNN (mis. TPVE05).
     */
    private function questionBank(): array
    {
        return array_merge(
            $this->verbalQuestions(),
            $this->numericQuestions(),
            $this->logicQuestions(),
            $this->englishQuestions(),
            $this->discQuestions(),
            $this->generalQuestions(),
            $this->behavioralQuestions(),
            $this->technicalBackend(),
            $this->technicalFrontend(),
            $this->technicalAccounting(),
            $this->technicalHr(),
            $this->technicalSales(),
        );
    }

    // ─── TPA Verbal ─────────────────────────────────────────────────
    private function verbalQuestions(): array
    {
        return [
            ['key' => 'V05','category' => 'TPA_VERBAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Sinonim dari kata "abstrak" adalah ...',
                'choices' => [['key'=>'A','text'=>'Konkret'],['key'=>'B','text'=>'Mujarrad'],['key'=>'C','text'=>'Nyata'],['key'=>'D','text'=>'Padat']],
                'correct_answer' => 'B','tags' => ['verbal','sinonim']],
            ['key' => 'V06','category' => 'TPA_VERBAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Antonim dari kata "ekspansi" adalah ...',
                'choices' => [['key'=>'A','text'=>'Perluasan'],['key'=>'B','text'=>'Penyusutan'],['key'=>'C','text'=>'Pembangunan'],['key'=>'D','text'=>'Pertumbuhan']],
                'correct_answer' => 'B','tags' => ['verbal','antonim']],
            ['key' => 'V07','category' => 'TPA_VERBAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'PILOT : PESAWAT = NAKHODA : ...',
                'choices' => [['key'=>'A','text'=>'Laut'],['key'=>'B','text'=>'Pelabuhan'],['key'=>'C','text'=>'Kapal'],['key'=>'D','text'=>'Awak']],
                'correct_answer' => 'C','tags' => ['verbal','analogi']],
            ['key' => 'V08','category' => 'TPA_VERBAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Sinonim "pragmatis" yang paling tepat adalah ...',
                'choices' => [['key'=>'A','text'=>'Idealis'],['key'=>'B','text'=>'Praktis'],['key'=>'C','text'=>'Romantis'],['key'=>'D','text'=>'Fanatis']],
                'correct_answer' => 'B','tags' => ['verbal','sinonim']],
            ['key' => 'V09','category' => 'TPA_VERBAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Padanan kata "kontroversi" adalah ...',
                'choices' => [['key'=>'A','text'=>'Persamaan'],['key'=>'B','text'=>'Pertentangan'],['key'=>'C','text'=>'Persetujuan'],['key'=>'D','text'=>'Persaudaraan']],
                'correct_answer' => 'B','tags' => ['verbal','sinonim']],
            ['key' => 'V10','category' => 'TPA_VERBAL','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Semua dosen adalah pengajar. Sebagian pengajar mengikuti pelatihan. Kesimpulan yang pasti benar adalah ...',
                'choices' => [
                    ['key'=>'A','text'=>'Semua dosen mengikuti pelatihan'],
                    ['key'=>'B','text'=>'Sebagian dosen mengikuti pelatihan'],
                    ['key'=>'C','text'=>'Semua pengajar adalah dosen'],
                    ['key'=>'D','text'=>'Tidak ada kesimpulan pasti'],
                ],
                'correct_answer' => 'D','tags' => ['verbal','silogisme'],
                'explanation' => 'Premis tidak menjamin overlap antara "dosen" dan "yang ikut pelatihan".'],
            ['key' => 'V11','category' => 'TPA_VERBAL','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Bacalah: "Banyak perusahaan terpaksa melakukan efisiensi setelah kebijakan UMP naik 8% di awal tahun." Pernyataan yang BUKAN simpulan logis dari kalimat tersebut:',
                'choices' => [
                    ['key'=>'A','text'=>'Sebagian perusahaan melakukan efisiensi.'],
                    ['key'=>'B','text'=>'Kenaikan UMP berdampak pada kebijakan SDM perusahaan.'],
                    ['key'=>'C','text'=>'Semua perusahaan otomatis melakukan PHK.'],
                    ['key'=>'D','text'=>'UMP naik 8% di awal tahun.'],
                ],
                'correct_answer' => 'C','tags' => ['verbal','reading']],
            ['key' => 'V12','category' => 'TPA_VERBAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Antonim dari kata "transparan" adalah ...',
                'choices' => [['key'=>'A','text'=>'Jernih'],['key'=>'B','text'=>'Kabur'],['key'=>'C','text'=>'Bening'],['key'=>'D','text'=>'Jujur']],
                'correct_answer' => 'B','tags' => ['verbal','antonim']],
        ];
    }

    // ─── TPA Numerik ────────────────────────────────────────────────
    private function numericQuestions(): array
    {
        return [
            ['key' => 'N05','category' => 'TPA_NUMERIK','difficulty' => 'EASY','question_type' => 'NUMERIC',
                'prompt' => 'Berapa hasil dari 12% × 250?','correct_answer' => 30,
                'tags' => ['numerik','persentase']],
            ['key' => 'N06','category' => 'TPA_NUMERIK','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Lanjutan deret: 2, 5, 11, 23, ...',
                'choices' => [['key'=>'A','text'=>'34'],['key'=>'B','text'=>'45'],['key'=>'C','text'=>'47'],['key'=>'D','text'=>'52']],
                'correct_answer' => 'C','explanation' => 'Pola: x2 + 1.','tags' => ['numerik','deret']],
            ['key' => 'N07','category' => 'TPA_NUMERIK','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Sebuah mobil berjalan 90 km/jam selama 2,5 jam. Jarak tempuhnya adalah ...',
                'choices' => [['key'=>'A','text'=>'180 km'],['key'=>'B','text'=>'200 km'],['key'=>'C','text'=>'215 km'],['key'=>'D','text'=>'225 km']],
                'correct_answer' => 'D','tags' => ['numerik','kecepatan']],
            ['key' => 'N08','category' => 'TPA_NUMERIK','difficulty' => 'MEDIUM','question_type' => 'NUMERIC',
                'prompt' => 'Gaji pokok Rp 8.000.000. Tunjangan transport 10% dari gaji pokok. Berapa take-home tunjangan transport?',
                'correct_answer' => 800000,'tags' => ['numerik','payroll']],
            ['key' => 'N09','category' => 'TPA_NUMERIK','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Perbandingan jumlah karyawan pria : wanita = 5 : 3. Jika total karyawan 240, jumlah karyawan wanita adalah ...',
                'choices' => [['key'=>'A','text'=>'80'],['key'=>'B','text'=>'90'],['key'=>'C','text'=>'100'],['key'=>'D','text'=>'120']],
                'correct_answer' => 'B','explanation' => '3/8 × 240 = 90.','tags' => ['numerik','perbandingan']],
            ['key' => 'N10','category' => 'TPA_NUMERIK','difficulty' => 'MEDIUM','question_type' => 'NUMERIC',
                'prompt' => 'Lembur 4 jam di hari kerja biasa. Upah/jam = Rp 50.000. Sesuai PP 35/2021 jam pertama 1.5x dan jam berikutnya 2x. Berapa total upah lembur?',
                'correct_answer' => 375000,
                'explanation' => '1×(1.5×50.000) + 3×(2×50.000) = 75.000 + 300.000 = 375.000.',
                'tags' => ['numerik','lembur','pp35']],
            ['key' => 'N11','category' => 'TPA_NUMERIK','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Pekerja dapat bonus 5% dari laba bersih perusahaan tahun ini. Laba bersih Rp 200 miliar dibagi rata ke 1.000 karyawan. Bonus per karyawan adalah ...',
                'choices' => [['key'=>'A','text'=>'Rp 8.000.000'],['key'=>'B','text'=>'Rp 9.500.000'],['key'=>'C','text'=>'Rp 10.000.000'],['key'=>'D','text'=>'Rp 12.000.000']],
                'correct_answer' => 'C',
                'explanation' => '5% × 200B = 10B; 10B / 1.000 = 10jt.',
                'tags' => ['numerik','bonus']],
            ['key' => 'N12','category' => 'TPA_NUMERIK','difficulty' => 'HARD','question_type' => 'NUMERIC',
                'prompt' => 'A bekerja sendirian menyelesaikan proyek dalam 6 hari. B sendirian 12 hari. Jika dikerjakan bersama, berapa hari?',
                'correct_answer' => 4,
                'explanation' => '1/6 + 1/12 = 1/4. Jadi 4 hari.',
                'tags' => ['numerik','aritmatika']],
        ];
    }

    // ─── TPA Logika ─────────────────────────────────────────────────
    private function logicQuestions(): array
    {
        return [
            ['key' => 'L04','category' => 'TPA_LOGIKA','difficulty' => 'EASY','question_type' => 'TRUE_FALSE',
                'prompt' => 'Jika "semua karyawan tetap mendapat THR" benar, maka "ada karyawan tetap yang tidak mendapat THR" pasti salah.',
                'correct_answer' => true,'tags' => ['logika','negasi']],
            ['key' => 'L05','category' => 'TPA_LOGIKA','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Jika hari ini hujan, tim outdoor tidak bekerja. Tim outdoor bekerja. Kesimpulan yang valid:',
                'choices' => [
                    ['key'=>'A','text'=>'Hari ini hujan'],
                    ['key'=>'B','text'=>'Hari ini tidak hujan'],
                    ['key'=>'C','text'=>'Tim outdoor selalu bekerja saat hujan'],
                    ['key'=>'D','text'=>'Tidak dapat disimpulkan'],
                ],
                'correct_answer' => 'B',
                'explanation' => 'Modus tollens: ¬q maka ¬p.','tags' => ['logika','tollens']],
            ['key' => 'L06','category' => 'TPA_LOGIKA','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Lima karyawan berdiri berurutan. Andi di sebelah kiri Budi. Budi di sebelah kiri Citra. Dini di paling kanan. Eko di paling kiri. Urutan dari kiri ke kanan:',
                'choices' => [
                    ['key'=>'A','text'=>'Eko, Andi, Budi, Citra, Dini'],
                    ['key'=>'B','text'=>'Eko, Citra, Andi, Budi, Dini'],
                    ['key'=>'C','text'=>'Andi, Eko, Budi, Citra, Dini'],
                    ['key'=>'D','text'=>'Eko, Budi, Andi, Citra, Dini'],
                ],
                'correct_answer' => 'A','tags' => ['logika','spasial']],
            ['key' => 'L07','category' => 'TPA_LOGIKA','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Setiap karyawan yang kena SP3 akan masuk pertimbangan PHK. Pak Adi tidak masuk pertimbangan PHK. Maka:',
                'choices' => [
                    ['key'=>'A','text'=>'Pak Adi pasti pernah kena SP1'],
                    ['key'=>'B','text'=>'Pak Adi tidak pernah kena SP3'],
                    ['key'=>'C','text'=>'Pak Adi pernah kena SP3'],
                    ['key'=>'D','text'=>'Tidak dapat ditentukan'],
                ],
                'correct_answer' => 'B','tags' => ['logika','kontraposisi','HR']],
            ['key' => 'L08','category' => 'TPA_LOGIKA','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Tim A, B, C, D bertanding. Setiap tim main 1 kali lawan tim lain. Tim A menang 2 kali. Tim B kalah dari A. Tim C menang dari B. Maka kemungkinan tim D ...',
                'choices' => [
                    ['key'=>'A','text'=>'Pasti kalah dari A'],
                    ['key'=>'B','text'=>'Tidak bisa ditentukan dari informasi'],
                    ['key'=>'C','text'=>'Pasti menang dari C'],
                    ['key'=>'D','text'=>'Pasti seri dengan B'],
                ],
                'correct_answer' => 'B','tags' => ['logika','tournament']],
        ];
    }

    // ─── English ────────────────────────────────────────────────────
    private function englishQuestions(): array
    {
        return [
            ['key' => 'E05','category' => 'ENGLISH','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Choose the correct preposition: "She is good ___ mathematics."',
                'choices' => [['key'=>'A','text'=>'on'],['key'=>'B','text'=>'in'],['key'=>'C','text'=>'at'],['key'=>'D','text'=>'with']],
                'correct_answer' => 'C','tags' => ['english','preposition']],
            ['key' => 'E06','category' => 'ENGLISH','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => '"He ___ the project last Monday." (past tense)',
                'choices' => [['key'=>'A','text'=>'finish'],['key'=>'B','text'=>'finishes'],['key'=>'C','text'=>'finished'],['key'=>'D','text'=>'has finished']],
                'correct_answer' => 'C','tags' => ['english','tense']],
            ['key' => 'E07','category' => 'ENGLISH','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Synonym of "frequently":',
                'choices' => [['key'=>'A','text'=>'rarely'],['key'=>'B','text'=>'often'],['key'=>'C','text'=>'briefly'],['key'=>'D','text'=>'seldom']],
                'correct_answer' => 'B','tags' => ['english','vocab']],
            ['key' => 'E08','category' => 'ENGLISH','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Choose the correct passive: "They are repairing the car."',
                'choices' => [
                    ['key'=>'A','text'=>'The car is being repaired by them.'],
                    ['key'=>'B','text'=>'The car was repaired.'],
                    ['key'=>'C','text'=>'The car has repaired.'],
                    ['key'=>'D','text'=>'The car will be repaired.'],
                ],
                'correct_answer' => 'A','tags' => ['english','passive']],
            ['key' => 'E09','category' => 'ENGLISH','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Read: "The HR department announced a 10% salary increment, effective next quarter, contingent on company revenue meeting projections." Which is implied?',
                'choices' => [
                    ['key'=>'A','text'=>'The increment is guaranteed.'],
                    ['key'=>'B','text'=>'The increment depends on revenue performance.'],
                    ['key'=>'C','text'=>'The increment was already paid.'],
                    ['key'=>'D','text'=>'Only managers will receive it.'],
                ],
                'correct_answer' => 'B','tags' => ['english','reading']],
            ['key' => 'E10','category' => 'ENGLISH','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Choose the best paraphrase of "The proposal was unanimously rejected."',
                'choices' => [
                    ['key'=>'A','text'=>'Nobody rejected the proposal.'],
                    ['key'=>'B','text'=>'Only some people rejected the proposal.'],
                    ['key'=>'C','text'=>'Everyone rejected the proposal.'],
                    ['key'=>'D','text'=>'The proposal was accepted by all.'],
                ],
                'correct_answer' => 'C','tags' => ['english','reading']],
        ];
    }

    // ─── DISC tambahan ──────────────────────────────────────────────
    private function discQuestions(): array
    {
        return [
            ['key' => 'D09','category' => 'DISC','difficulty' => 'EASY','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya berani memulai konfrontasi terbuka untuk menyelesaikan masalah cepat.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'D','tags' => ['disc']],
            ['key' => 'D10','category' => 'DISC','difficulty' => 'EASY','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya cenderung antusias berbicara di depan umum.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'I','tags' => ['disc']],
            ['key' => 'D11','category' => 'DISC','difficulty' => 'EASY','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya merasa lebih nyaman bekerja di tim daripada sendirian.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'S','tags' => ['disc']],
            ['key' => 'D12','category' => 'DISC','difficulty' => 'EASY','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya selalu mencari fakta dan data sebelum mengambil keputusan.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'C','tags' => ['disc']],
            ['key' => 'D13','category' => 'DISC','difficulty' => 'MEDIUM','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya menikmati tantangan pekerjaan yang berisiko tinggi tapi berdampak besar.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'D','tags' => ['disc']],
            ['key' => 'D14','category' => 'DISC','difficulty' => 'MEDIUM','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya bisa membangun hubungan baik dengan klien atau rekan kerja dengan cepat.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'I','tags' => ['disc']],
            ['key' => 'D15','category' => 'DISC','difficulty' => 'MEDIUM','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya lebih suka mempertahankan cara kerja yang sudah terbukti daripada mencoba cara baru.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'S','tags' => ['disc']],
            ['key' => 'D16','category' => 'DISC','difficulty' => 'MEDIUM','question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya merasa tidak nyaman jika hasil kerja saya tidak mengikuti standar atau prosedur tertulis.',
                'choices' => null,'correct_answer' => null,'disc_weight' => 'C','tags' => ['disc']],
        ];
    }

    // ─── General Knowledge / Wawasan ────────────────────────────────
    private function generalQuestions(): array
    {
        return [
            ['key' => 'G01','category' => 'GENERAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Ibu kota Provinsi Jawa Barat adalah ...',
                'choices' => [['key'=>'A','text'=>'Bandung'],['key'=>'B','text'=>'Bogor'],['key'=>'C','text'=>'Bekasi'],['key'=>'D','text'=>'Cirebon']],
                'correct_answer' => 'A','tags' => ['umum','geografi']],
            ['key' => 'G02','category' => 'GENERAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'BPJS Ketenagakerjaan menyelenggarakan program berikut, KECUALI ...',
                'choices' => [['key'=>'A','text'=>'JKK'],['key'=>'B','text'=>'JHT'],['key'=>'C','text'=>'JKN'],['key'=>'D','text'=>'JP']],
                'correct_answer' => 'C',
                'explanation' => 'JKN adalah program BPJS Kesehatan, bukan Ketenagakerjaan.',
                'tags' => ['umum','HR','BPJS']],
            ['key' => 'G03','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'UU yang mengatur Cipta Kerja adalah ...',
                'choices' => [['key'=>'A','text'=>'UU 13/2003'],['key'=>'B','text'=>'UU 11/2020'],['key'=>'C','text'=>'UU 6/2023'],['key'=>'D','text'=>'UU 40/2007']],
                'correct_answer' => 'C',
                'explanation' => 'UU 6/2023 = penetapan Perppu 2/2022 tentang Cipta Kerja menjadi UU.',
                'tags' => ['umum','HR','peraturan']],
            ['key' => 'G04','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Tarif PPh 21 metode TER (PMK 168/2023) berlaku untuk masa pajak ...',
                'choices' => [['key'=>'A','text'=>'Januari–November'],['key'=>'B','text'=>'Januari–Desember'],['key'=>'C','text'=>'Hanya Desember'],['key'=>'D','text'=>'Tahunan saja']],
                'correct_answer' => 'A',
                'explanation' => 'TER dipakai Jan–Nov, Desember pakai metode tahunan untuk koreksi.',
                'tags' => ['umum','pajak']],
            ['key' => 'G05','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Jumlah hari kerja maksimum dalam seminggu menurut UU 13/2003 adalah ...',
                'choices' => [['key'=>'A','text'=>'5 hari'],['key'=>'B','text'=>'6 hari'],['key'=>'C','text'=>'7 hari'],['key'=>'D','text'=>'4 hari']],
                'correct_answer' => 'B',
                'explanation' => 'Dapat 5 hari × 8 jam atau 6 hari × 7 jam (40 jam/minggu).',
                'tags' => ['umum','HR']],
            ['key' => 'G06','category' => 'GENERAL','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Perusahaan yang termasuk Tbk wajib menyampaikan laporan keuangan kepada ...',
                'choices' => [
                    ['key'=>'A','text'=>'Disnaker dan BPJS'],
                    ['key'=>'B','text'=>'OJK dan Bursa Efek Indonesia'],
                    ['key'=>'C','text'=>'Pemerintah daerah saja'],
                    ['key'=>'D','text'=>'Hanya pemegang saham'],
                ],
                'correct_answer' => 'B',
                'explanation' => 'Tbk = perusahaan terbuka, di-supervisi OJK, listing di BEI.',
                'tags' => ['umum','korporasi']],
        ];
    }

    // ─── Behavioral / SJT ───────────────────────────────────────────
    private function behavioralQuestions(): array
    {
        return [
            ['key' => 'B01','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Anda menemukan rekan kerja melakukan kesalahan input data yang akan berdampak ke laporan keuangan. Tindakan paling tepat:',
                'choices' => [
                    ['key'=>'A','text'=>'Diam saja, bukan urusan saya'],
                    ['key'=>'B','text'=>'Sampaikan langsung kepada rekan tersebut secara baik-baik untuk diperbaiki'],
                    ['key'=>'C','text'=>'Langsung lapor ke direksi tanpa konfirmasi'],
                    ['key'=>'D','text'=>'Posting di grup WhatsApp kantor'],
                ],
                'correct_answer' => 'B','tags' => ['behavioral','SJT']],
            ['key' => 'B02','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Atasan memberi 3 tugas urgent sekaligus dengan deadline yang sama. Anda akan ...',
                'choices' => [
                    ['key'=>'A','text'=>'Kerjakan semua bersamaan tanpa konfirmasi'],
                    ['key'=>'B','text'=>'Konfirmasi prioritas dengan atasan dan komunikasikan kapasitas waktu'],
                    ['key'=>'C','text'=>'Tolak dua tugas dan kerjakan satu saja'],
                    ['key'=>'D','text'=>'Tunda semua hingga deadline berikutnya'],
                ],
                'correct_answer' => 'B','tags' => ['behavioral','SJT','time-management']],
            ['key' => 'B03','category' => 'GENERAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Customer komplain produk cacat. Anda mendapati prosedur retur belum jelas. Tindakan paling tepat:',
                'choices' => [
                    ['key'=>'A','text'=>'Tolak retur karena prosedur tidak jelas'],
                    ['key'=>'B','text'=>'Akui keluhan customer, escalate ke atasan, kembali dengan solusi'],
                    ['key'=>'C','text'=>'Janjikan apa saja yang penting customer puas'],
                    ['key'=>'D','text'=>'Suruh customer ke kantor pusat'],
                ],
                'correct_answer' => 'B','tags' => ['behavioral','SJT','customer-service']],
            ['key' => 'B04','category' => 'GENERAL','difficulty' => 'HARD','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Tim Anda mendekati deadline. Salah satu rekan absen mendadak 2 hari karena urusan keluarga. Tindakan paling tepat sebagai team lead:',
                'choices' => [
                    ['key'=>'A','text'=>'Beri SP karena absen tanpa lapor'],
                    ['key'=>'B','text'=>'Redistribusi tugas, dukung rekan, pastikan deadline tetap dengan transparansi ke stakeholder'],
                    ['key'=>'C','text'=>'Diam saja, tunggu rekan masuk'],
                    ['key'=>'D','text'=>'Geser deadline tanpa konfirmasi stakeholder'],
                ],
                'correct_answer' => 'B','tags' => ['behavioral','leadership']],
            ['key' => 'B05','category' => 'GENERAL','difficulty' => 'HARD','question_type' => 'SHORT_ANSWER',
                'prompt' => 'Ceritakan satu pengalaman Anda menyelesaikan konflik di tempat kerja. Apa konteksnya, apa yang Anda lakukan, dan hasilnya?',
                'choices' => null,'correct_answer' => null,
                'explanation' => 'Jawaban open-ended, dievaluasi berdasarkan struktur STAR (Situation, Task, Action, Result) oleh interviewer.',
                'tags' => ['behavioral','essay','STAR']],
        ];
    }

    // ─── Technical Backend ──────────────────────────────────────────
    private function technicalBackend(): array
    {
        return [
            ['key' => 'TB01','category' => 'TECHNICAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Kode HTTP 401 berarti ...',
                'choices' => [['key'=>'A','text'=>'Not Found'],['key'=>'B','text'=>'Unauthorized'],['key'=>'C','text'=>'Forbidden'],['key'=>'D','text'=>'Bad Request']],
                'correct_answer' => 'B','tags' => ['technical','backend','http']],
            ['key' => 'TB02','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Untuk mencegah SQL Injection, praktik paling efektif adalah ...',
                'choices' => [
                    ['key'=>'A','text'=>'Concatenate string SQL secara hati-hati'],
                    ['key'=>'B','text'=>'Pakai prepared statement / parameterized query'],
                    ['key'=>'C','text'=>'Filter karakter aneh manual'],
                    ['key'=>'D','text'=>'Hash semua input user'],
                ],
                'correct_answer' => 'B','tags' => ['technical','security']],
            ['key' => 'TB03','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Index pada kolom tabel SQL berguna untuk ...',
                'choices' => [
                    ['key'=>'A','text'=>'Mempercepat insert'],
                    ['key'=>'B','text'=>'Mempercepat select dengan WHERE / JOIN'],
                    ['key'=>'C','text'=>'Menghemat ruang penyimpanan'],
                    ['key'=>'D','text'=>'Mengenkripsi data'],
                ],
                'correct_answer' => 'B','tags' => ['technical','sql','performance']],
        ];
    }

    // ─── Technical Frontend ────────────────────────────────────────
    private function technicalFrontend(): array
    {
        return [
            ['key' => 'TF01','category' => 'TECHNICAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'CSS selector untuk class bernama "btn-primary" adalah ...',
                'choices' => [['key'=>'A','text'=>'#btn-primary'],['key'=>'B','text'=>'.btn-primary'],['key'=>'C','text'=>'btn-primary'],['key'=>'D','text'=>'$btn-primary']],
                'correct_answer' => 'B','tags' => ['technical','frontend','css']],
            ['key' => 'TF02','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Di Vue 3, untuk mendeklarasikan reactive state primitif kita pakai ...',
                'choices' => [['key'=>'A','text'=>'reactive()'],['key'=>'B','text'=>'ref()'],['key'=>'C','text'=>'data()'],['key'=>'D','text'=>'state()']],
                'correct_answer' => 'B','tags' => ['technical','frontend','vue3']],
            ['key' => 'TF03','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_MULTI',
                'prompt' => 'Yang termasuk best-practice frontend security:',
                'choices' => [
                    ['key'=>'A','text'=>'Sanitize HTML output untuk mencegah XSS'],
                    ['key'=>'B','text'=>'Simpan JWT di localStorage sebagai plain text'],
                    ['key'=>'C','text'=>'Pakai HTTPS untuk semua request'],
                    ['key'=>'D','text'=>'Jangan validasi input di frontend'],
                ],
                'correct_answer' => ['A','C'],'tags' => ['technical','frontend','security']],
        ];
    }

    // ─── Technical Accounting ──────────────────────────────────────
    private function technicalAccounting(): array
    {
        return [
            ['key' => 'TA01','category' => 'TECHNICAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Persamaan dasar akuntansi adalah ...',
                'choices' => [
                    ['key'=>'A','text'=>'Aset = Liabilitas + Ekuitas'],
                    ['key'=>'B','text'=>'Aset = Pendapatan – Beban'],
                    ['key'=>'C','text'=>'Liabilitas = Aset – Pendapatan'],
                    ['key'=>'D','text'=>'Ekuitas = Aset – Pendapatan'],
                ],
                'correct_answer' => 'A','tags' => ['technical','accounting']],
            ['key' => 'TA02','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Pencatatan penjualan kredit di jurnal umum:',
                'choices' => [
                    ['key'=>'A','text'=>'Dr Kas / Cr Penjualan'],
                    ['key'=>'B','text'=>'Dr Piutang / Cr Penjualan'],
                    ['key'=>'C','text'=>'Dr Penjualan / Cr Kas'],
                    ['key'=>'D','text'=>'Dr Persediaan / Cr Penjualan'],
                ],
                'correct_answer' => 'B','tags' => ['technical','accounting','jurnal']],
            ['key' => 'TA03','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'PPh Pasal 21 yang dipotong perusahaan dari gaji karyawan dilaporkan dalam SPT ...',
                'choices' => [['key'=>'A','text'=>'1771'],['key'=>'B','text'=>'1721'],['key'=>'C','text'=>'1770'],['key'=>'D','text'=>'1107']],
                'correct_answer' => 'B','tags' => ['technical','tax']],
        ];
    }

    // ─── Technical HR ──────────────────────────────────────────────
    private function technicalHr(): array
    {
        return [
            ['key' => 'TH01','category' => 'TECHNICAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Iuran BPJS Kesehatan yang menjadi beban karyawan adalah sebesar ...',
                'choices' => [['key'=>'A','text'=>'1%'],['key'=>'B','text'=>'2%'],['key'=>'C','text'=>'4%'],['key'=>'D','text'=>'5%']],
                'correct_answer' => 'A','tags' => ['technical','HR','BPJS']],
            ['key' => 'TH02','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Karyawan PKWT yang putus kontrak sebelum waktunya berhak mendapat ...',
                'choices' => [
                    ['key'=>'A','text'=>'Pesangon 1 bulan gaji'],
                    ['key'=>'B','text'=>'Uang Kompensasi sesuai PP 35/2021'],
                    ['key'=>'C','text'=>'Tidak ada'],
                    ['key'=>'D','text'=>'Bonus 1x gaji'],
                ],
                'correct_answer' => 'B','tags' => ['technical','HR','PKWT']],
            ['key' => 'TH03','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Cuti tahunan minimal sesuai UU 13/2003 adalah ...',
                'choices' => [['key'=>'A','text'=>'10 hari'],['key'=>'B','text'=>'12 hari'],['key'=>'C','text'=>'15 hari'],['key'=>'D','text'=>'20 hari']],
                'correct_answer' => 'B',
                'explanation' => '12 hari kerja setelah masa kerja 12 bulan terus-menerus.',
                'tags' => ['technical','HR','cuti']],
        ];
    }

    // ─── Technical Sales ───────────────────────────────────────────
    private function technicalSales(): array
    {
        return [
            ['key' => 'TS01','category' => 'TECHNICAL','difficulty' => 'EASY','question_type' => 'MCQ_SINGLE',
                'prompt' => 'KPI utama untuk seorang Sales Executive umumnya adalah ...',
                'choices' => [
                    ['key'=>'A','text'=>'Jumlah email terkirim'],
                    ['key'=>'B','text'=>'Jumlah penjualan / revenue achieved'],
                    ['key'=>'C','text'=>'Lama waktu di kantor'],
                    ['key'=>'D','text'=>'Jumlah meeting internal'],
                ],
                'correct_answer' => 'B','tags' => ['technical','sales','kpi']],
            ['key' => 'TS02','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_SINGLE',
                'prompt' => 'Customer keberatan dengan harga. Teknik handling objection paling tepat:',
                'choices' => [
                    ['key'=>'A','text'=>'Langsung kasih diskon agresif'],
                    ['key'=>'B','text'=>'Tanya kebutuhan, jelaskan value, baru bahas harga'],
                    ['key'=>'C','text'=>'Bilang produk kompetitor lebih jelek'],
                    ['key'=>'D','text'=>'Diam dan biarkan customer pergi'],
                ],
                'correct_answer' => 'B','tags' => ['technical','sales','objection']],
            ['key' => 'TS03','category' => 'TECHNICAL','difficulty' => 'MEDIUM','question_type' => 'MCQ_MULTI',
                'prompt' => 'Yang termasuk tahap dalam funnel sales B2B:',
                'choices' => [
                    ['key'=>'A','text'=>'Prospecting'],
                    ['key'=>'B','text'=>'Demo / presentation'],
                    ['key'=>'C','text'=>'Closing'],
                    ['key'=>'D','text'=>'Asset depreciation'],
                ],
                'correct_answer' => ['A','B','C'],'tags' => ['technical','sales','funnel']],
        ];
    }

    /**
     * Test definitions baru. Catatan: untuk pool-based test, soal
     * ditarik random saat attempt dimulai (lihat OnlineTestService).
     */
    private function tests(): array
    {
        return [
            // ── Sales Aptitude ──────────────────────────────────────
            [
                'code' => 'SALES-APT-001',
                'name' => 'Sales Aptitude — Mid Level',
                'category' => 'CUSTOM',
                'description' => 'Tes aptitude untuk posisi Sales Executive: numerik dasar + handling objection + situational.',
                'instructions_html' => '<p>Tes berlangsung 25 menit. Jawab semua soal sesuai dengan situasi sales sehari-hari.</p>',
                'duration_minutes' => 25,
                'passing_score_pct' => 60,
                'max_attempts' => 1,
                'question_keys' => ['N05','N09','TS01','TS02','TS03','B02','B03','D10'],
                'points_per_question' => ['B03' => 2.0, 'TS02' => 1.5],
            ],

            // ── Accounting Knowledge ────────────────────────────────
            [
                'code' => 'ACC-KNOW-001',
                'name' => 'Accounting Knowledge Test',
                'category' => 'TECHNICAL',
                'description' => 'Tes pengetahuan dasar akuntansi & pajak Indonesia untuk posisi Accountant.',
                'instructions_html' => '<p>Soal pilihan ganda. Pakai pengetahuan PSAK + perpajakan Indonesia.</p>',
                'duration_minutes' => 25,
                'passing_score_pct' => 70,
                'max_attempts' => 1,
                'question_keys' => ['TA01','TA02','TA03','G04','N09'],
                'points_per_question' => [],
            ],

            // ── HR Officer Knowledge ────────────────────────────────
            [
                'code' => 'HR-KNOW-001',
                'name' => 'HR Officer Knowledge',
                'category' => 'TECHNICAL',
                'description' => 'Tes pengetahuan UU Ketenagakerjaan + BPJS + payroll basics.',
                'instructions_html' => '<p>Soal pilihan ganda berdasarkan UU 13/2003, UU 6/2023, dan PP 35/2021.</p>',
                'duration_minutes' => 20,
                'passing_score_pct' => 70,
                'max_attempts' => 1,
                'question_keys' => ['TH01','TH02','TH03','G02','G03','G05','N10'],
                'points_per_question' => [],
            ],

            // ── Behavioral / SJT ────────────────────────────────────
            [
                'code' => 'BEHV-001',
                'name' => 'Behavioral Assessment (SJT)',
                'category' => 'CUSTOM',
                'description' => 'Situational Judgment Test — mengukur respon kandidat terhadap skenario kerja umum.',
                'instructions_html' => '<p>Pilih opsi yang menurut Anda paling tepat di skenario kerja sehari-hari.</p>',
                'duration_minutes' => 20,
                'passing_score_pct' => 0,
                'max_attempts' => 1,
                'question_keys' => ['B01','B02','B03','B04','B05'],
                'points_per_question' => ['B05' => 3.0],
            ],

            // ── General Aptitude (mixed pool) ───────────────────────
            // Pool-based: backend pilih random sesuai filter saat attempt dimulai.
            [
                'code' => 'GEN-APT-POOL-001',
                'name' => 'General Aptitude (Random Pool)',
                'category' => 'TPA',
                'description' => 'Random sampling 15 soal dari kategori TPA Verbal + Numerik + Logika + General.',
                'instructions_html' => '<p>Soal di-random. 30 menit, no negative marking.</p>',
                'duration_minutes' => 30,
                'passing_score_pct' => 60,
                'max_attempts' => 1,
                'use_pool' => 1,
                'pool_filters' => [
                    ['category' => 'TPA_VERBAL',  'difficulty' => null, 'count' => 4],
                    ['category' => 'TPA_NUMERIK', 'difficulty' => null, 'count' => 4],
                    ['category' => 'TPA_LOGIKA',  'difficulty' => null, 'count' => 4],
                    ['category' => 'GENERAL',     'difficulty' => null, 'count' => 3],
                ],
                'question_keys' => [],
            ],
        ];
    }
}