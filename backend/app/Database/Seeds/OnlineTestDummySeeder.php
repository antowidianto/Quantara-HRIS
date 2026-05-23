<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * OnlineTestDummySeeder
 *
 * Tujuan:
 *  - Isi bank soal dummy untuk modul Online Test
 *  - Buat beberapa test dummy yang langsung bisa dipakai HR:
 *      1. TPA Dasar
 *      2. English Basic
 *      3. DISC Screening
 *      4. Backend Technical Screening
 *
 * Cakupan soal:
 *  - TPA_VERBAL
 *  - TPA_NUMERIK
 *  - TPA_LOGIKA
 *  - ENGLISH
 *  - DISC
 *  - TECHNICAL
 *
 * Karakter:
 *  - Idempotent: aman dijalankan ulang
 *  - Per-company: seed untuk semua company aktif
 *
 * Jalankan:
 *   php spark db:seed OnlineTestDummySeeder
 */
class OnlineTestDummySeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');

        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()
            ->getResultArray();

        if (empty($companies)) {
            echo "  [skip] tidak ada company aktif\n";
            return;
        }

        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            echo "\n== OnlineTestDummySeeder untuk company #{$companyId} ({$company['name']}) ==\n";

            $bankIds = [];

            foreach ($this->questionBankDataset() as $question) {
                $existing = $db->table('online_test_question_bank')
                    ->where('company_id', $companyId)
                    ->where('category', $question['category'])
                    ->where('prompt', $question['prompt'])
                    ->where('deleted_at IS NULL')
                    ->get()
                    ->getRowArray();

                if ($existing) {
                    $bankIds[$question['key']] = (int) $existing['id'];
                    echo "  [skip] bank question {$question['key']} sudah ada\n";
                    continue;
                }

                $insert = [
                    'company_id'     => $companyId,
                    'category'       => $question['category'],
                    'difficulty'     => $question['difficulty'],
                    'question_type'  => $question['question_type'],
                    'prompt'         => $question['prompt'],
                    'choices'        => $question['choices'] !== null ? json_encode($question['choices'], JSON_UNESCAPED_UNICODE) : null,
                    'correct_answer' => $question['correct_answer'] !== null ? json_encode($question['correct_answer'], JSON_UNESCAPED_UNICODE) : null,
                    'disc_weight'    => $question['disc_weight'],
                    'explanation'    => $question['explanation'],
                    'tags'           => json_encode($question['tags'], JSON_UNESCAPED_UNICODE),
                    'is_active'      => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];

                $db->table('online_test_question_bank')->insert($insert);
                $bankIds[$question['key']] = (int) $db->insertID();
                echo "  [new]  bank question {$question['key']}\n";
            }

            foreach ($this->testDataset() as $test) {
                $existingTest = $db->table('online_tests')
                    ->where('company_id', $companyId)
                    ->where('code', $test['code'])
                    ->where('deleted_at IS NULL')
                    ->get()
                    ->getRowArray();

                if ($existingTest) {
                    $testId = (int) $existingTest['id'];
                    echo "  [skip] online test {$test['code']} sudah ada\n";
                } else {
                    $insertTest = [
                        'company_id'               => $companyId,
                        'code'                     => $test['code'],
                        'name'                     => $test['name'],
                        'category'                 => $test['category'],
                        'description'              => $test['description'],
                        'instructions_html'        => $test['instructions_html'],
                        'duration_minutes'         => $test['duration_minutes'],
                        'passing_score_pct'        => $test['passing_score_pct'],
                        'max_attempts'             => $test['max_attempts'],
                        'shuffle_questions'        => $test['shuffle_questions'],
                        'shuffle_choices'          => $test['shuffle_choices'],
                        'show_result_to_candidate' => $test['show_result_to_candidate'],
                        'use_pool'                 => 0,
                        'pool_filters'             => null,
                        'is_active'                => 1,
                        'is_template'              => 1,
                        'created_at'               => $now,
                        'updated_at'               => $now,
                    ];
                    $db->table('online_tests')->insert($insertTest);
                    $testId = (int) $db->insertID();
                    echo "  [new]  online test {$test['code']}\n";
                }

                $maxSort = 0;
                $existingQuestions = $db->table('online_test_questions')
                    ->where('online_test_id', $testId)
                    ->orderBy('sort_order', 'DESC')
                    ->get()
                    ->getResultArray();

                if (! empty($existingQuestions)) {
                    $maxSort = (int) ($existingQuestions[0]['sort_order'] ?? 0);
                }

                foreach ($test['question_keys'] as $qKey) {
                    if (! isset($bankIds[$qKey])) {
                        echo "  [warn] bank question key {$qKey} tidak ditemukan\n";
                        continue;
                    }

                    $bankQuestionId = (int) $bankIds[$qKey];
                    $alreadyAttached = $db->table('online_test_questions')
                        ->where('online_test_id', $testId)
                        ->where('bank_question_id', $bankQuestionId)
                        ->countAllResults();

                    if ($alreadyAttached > 0) {
                        echo "  [skip] attach {$qKey} ke {$test['code']} sudah ada\n";
                        continue;
                    }

                    $bankRow = $db->table('online_test_question_bank')
                        ->where('id', $bankQuestionId)
                        ->get()
                        ->getRowArray();

                    if (! $bankRow) {
                        echo "  [warn] bank row {$qKey} hilang\n";
                        continue;
                    }

                    $maxSort++;

                    $db->table('online_test_questions')->insert([
                        'online_test_id'  => $testId,
                        'bank_question_id'=> $bankQuestionId,
                        'question_type'   => $bankRow['question_type'],
                        'category'        => $bankRow['category'],
                        'prompt'          => $bankRow['prompt'],
                        'choices'         => $bankRow['choices'],
                        'correct_answer'  => $bankRow['correct_answer'],
                        'disc_weight'     => $bankRow['disc_weight'],
                        'points'          => $test['points_per_question'][$qKey] ?? 1.0,
                        'explanation'     => $bankRow['explanation'],
                        'sort_order'      => $maxSort,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                    echo "  [link] {$qKey} -> {$test['code']}\n";
                }
            }
        }

        echo "\n✓ OnlineTestDummySeeder selesai.\n";
    }

    /**
     * Dataset bank soal dummy.
     *
     * Key harus unique karena dipakai untuk mapping ke test.
     */
    private function questionBankDataset(): array
    {
        return [
            // ─── TPA Verbal ─────────────────────────────────────────
            [
                'key' => 'TPAV01',
                'category' => 'TPA_VERBAL',
                'difficulty' => 'EASY',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Sinonim dari kata "cermat" adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Teliti'],
                    ['key' => 'B', 'text' => 'Keras'],
                    ['key' => 'C', 'text' => 'Lambat'],
                    ['key' => 'D', 'text' => 'Rumit'],
                ],
                'correct_answer' => 'A',
                'disc_weight' => null,
                'explanation' => 'Cermat berarti teliti dan hati-hati.',
                'tags' => ['verbal', 'sinonim', 'dasar'],
            ],
            [
                'key' => 'TPAV02',
                'category' => 'TPA_VERBAL',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Antonim dari kata "stabil" adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Mantap'],
                    ['key' => 'B', 'text' => 'Goyah'],
                    ['key' => 'C', 'text' => 'Diam'],
                    ['key' => 'D', 'text' => 'Aman'],
                ],
                'correct_answer' => 'B',
                'disc_weight' => null,
                'explanation' => 'Stabil berlawanan makna dengan goyah/tidak tetap.',
                'tags' => ['verbal', 'antonim'],
            ],
            [
                'key' => 'TPAV03',
                'category' => 'TPA_VERBAL',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'ANALIS : DATA = DOKTER : ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Pasien'],
                    ['key' => 'B', 'text' => 'Obat'],
                    ['key' => 'C', 'text' => 'Rumah sakit'],
                    ['key' => 'D', 'text' => 'Perawat'],
                ],
                'correct_answer' => 'A',
                'disc_weight' => null,
                'explanation' => 'Analis bekerja dengan data; dokter bekerja dengan pasien.',
                'tags' => ['verbal', 'analogi'],
            ],
            [
                'key' => 'TPAV04',
                'category' => 'TPA_VERBAL',
                'difficulty' => 'HARD',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Pernyataan: Semua supervisor adalah karyawan. Sebagian karyawan adalah trainer. Kesimpulan yang pasti benar adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Semua trainer adalah supervisor'],
                    ['key' => 'B', 'text' => 'Sebagian supervisor adalah trainer'],
                    ['key' => 'C', 'text' => 'Supervisor termasuk karyawan'],
                    ['key' => 'D', 'text' => 'Tidak ada trainer yang menjadi supervisor'],
                ],
                'correct_answer' => 'C',
                'disc_weight' => null,
                'explanation' => 'Yang pasti hanya supervisor adalah bagian dari karyawan.',
                'tags' => ['verbal', 'logika verbal', 'silogisme'],
            ],

            // ─── TPA Numerik ────────────────────────────────────────
            [
                'key' => 'TPAN01',
                'category' => 'TPA_NUMERIK',
                'difficulty' => 'EASY',
                'question_type' => 'NUMERIC',
                'prompt' => 'Jika 25% dari suatu angka adalah 45, berapakah angka tersebut?',
                'choices' => null,
                'correct_answer' => 180,
                'disc_weight' => null,
                'explanation' => '45 ÷ 25% = 45 ÷ 0,25 = 180.',
                'tags' => ['numerik', 'persentase'],
            ],
            [
                'key' => 'TPAN02',
                'category' => 'TPA_NUMERIK',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Lanjutan deret: 3, 6, 12, 24, ...',
                'choices' => [
                    ['key' => 'A', 'text' => '30'],
                    ['key' => 'B', 'text' => '36'],
                    ['key' => 'C', 'text' => '48'],
                    ['key' => 'D', 'text' => '54'],
                ],
                'correct_answer' => 'C',
                'disc_weight' => null,
                'explanation' => 'Pola dikali 2.',
                'tags' => ['numerik', 'deret'],
            ],
            [
                'key' => 'TPAN03',
                'category' => 'TPA_NUMERIK',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Sebuah barang dibeli Rp200.000 dan dijual dengan untung 15%. Harga jualnya adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Rp215.000'],
                    ['key' => 'B', 'text' => 'Rp225.000'],
                    ['key' => 'C', 'text' => 'Rp230.000'],
                    ['key' => 'D', 'text' => 'Rp240.000'],
                ],
                'correct_answer' => 'C',
                'disc_weight' => null,
                'explanation' => '200.000 × 115% = 230.000.',
                'tags' => ['numerik', 'laba rugi'],
            ],
            [
                'key' => 'TPAN04',
                'category' => 'TPA_NUMERIK',
                'difficulty' => 'HARD',
                'question_type' => 'NUMERIC',
                'prompt' => 'Rata-rata nilai 4 orang adalah 78. Jika tiga orang nilainya 80, 76, dan 82, nilai orang keempat adalah ...',
                'choices' => null,
                'correct_answer' => 74,
                'disc_weight' => null,
                'explanation' => 'Total nilai = 4 × 78 = 312. Jumlah tiga orang = 238. Sisa = 74.',
                'tags' => ['numerik', 'rata-rata'],
            ],

            // ─── TPA Logika ─────────────────────────────────────────
            [
                'key' => 'TPAL01',
                'category' => 'TPA_LOGIKA',
                'difficulty' => 'EASY',
                'question_type' => 'TRUE_FALSE',
                'prompt' => 'Jika semua admin dapat mengakses sistem, dan Rina adalah admin, maka Rina dapat mengakses sistem.',
                'choices' => null,
                'correct_answer' => true,
                'disc_weight' => null,
                'explanation' => 'Ini bentuk deduksi langsung.',
                'tags' => ['logika', 'deduksi'],
            ],
            [
                'key' => 'TPAL02',
                'category' => 'TPA_LOGIKA',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Jika hari ini bukan Senin dan bukan Selasa, serta kemarin adalah Minggu, maka hari ini adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Senin'],
                    ['key' => 'B', 'text' => 'Rabu'],
                    ['key' => 'C', 'text' => 'Kamis'],
                    ['key' => 'D', 'text' => 'Jumat'],
                ],
                'correct_answer' => 'A',
                'disc_weight' => null,
                'explanation' => 'Jika kemarin Minggu, hari ini Senin. Soal ini sengaja jebakan konsistensi logika.',
                'tags' => ['logika', 'hari'],
            ],
            [
                'key' => 'TPAL03',
                'category' => 'TPA_LOGIKA',
                'difficulty' => 'HARD',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Semua pelamar yang lolos tes akan diinterview. Sebagian pelamar tidak diinterview. Kesimpulan yang pasti benar adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'Sebagian pelamar gagal tes'],
                    ['key' => 'B', 'text' => 'Semua yang tidak diinterview pasti gagal tes'],
                    ['key' => 'C', 'text' => 'Tidak ada pelamar yang lolos tes'],
                    ['key' => 'D', 'text' => 'Semua yang diinterview pasti lolos tes'],
                ],
                'correct_answer' => 'B',
                'disc_weight' => null,
                'explanation' => 'Kontraposisi dari “lolos tes → diinterview” adalah “tidak diinterview → tidak lolos tes”.',
                'tags' => ['logika', 'kontraposisi'],
            ],

            // ─── English ────────────────────────────────────────────
            [
                'key' => 'ENG01',
                'category' => 'ENGLISH',
                'difficulty' => 'EASY',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'Choose the correct sentence:',
                'choices' => [
                    ['key' => 'A', 'text' => 'She go to office every day.'],
                    ['key' => 'B', 'text' => 'She goes to the office every day.'],
                    ['key' => 'C', 'text' => 'She going to office every day.'],
                    ['key' => 'D', 'text' => 'She gone to office every day.'],
                ],
                'correct_answer' => 'B',
                'disc_weight' => null,
                'explanation' => 'Simple present tense for third-person singular uses “goes”.',
                'tags' => ['english', 'grammar', 'simple present'],
            ],
            [
                'key' => 'ENG02',
                'category' => 'ENGLISH',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'The antonym of "increase" is ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'reduce'],
                    ['key' => 'B', 'text' => 'improve'],
                    ['key' => 'C', 'text' => 'accelerate'],
                    ['key' => 'D', 'text' => 'expand'],
                ],
                'correct_answer' => 'A',
                'disc_weight' => null,
                'explanation' => 'Increase berlawanan dengan reduce/decrease.',
                'tags' => ['english', 'vocabulary'],
            ],
            [
                'key' => 'ENG03',
                'category' => 'ENGLISH',
                'difficulty' => 'MEDIUM',
                'question_type' => 'TRUE_FALSE',
                'prompt' => '“Children” is the correct plural form of “child”.',
                'choices' => null,
                'correct_answer' => true,
                'disc_weight' => null,
                'explanation' => 'Plural irregular dari child adalah children.',
                'tags' => ['english', 'plural noun'],
            ],
            [
                'key' => 'ENG04',
                'category' => 'ENGLISH',
                'difficulty' => 'HARD',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'If I ___ more time, I would learn Japanese.',
                'choices' => [
                    ['key' => 'A', 'text' => 'have'],
                    ['key' => 'B', 'text' => 'had'],
                    ['key' => 'C', 'text' => 'will have'],
                    ['key' => 'D', 'text' => 'am having'],
                ],
                'correct_answer' => 'B',
                'disc_weight' => null,
                'explanation' => 'Conditional type 2 menggunakan “if + past simple”.',
                'tags' => ['english', 'conditional'],
            ],

            // ─── DISC / Likert ──────────────────────────────────────
            [
                'key' => 'DISC01',
                'category' => 'DISC',
                'difficulty' => 'EASY',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya suka mengambil keputusan dengan cepat walau informasi belum lengkap.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'D',
                'explanation' => null,
                'tags' => ['disc', 'dominance'],
            ],
            [
                'key' => 'DISC02',
                'category' => 'DISC',
                'difficulty' => 'EASY',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya mudah membangun relasi dan bersemangat saat bertemu orang baru.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'I',
                'explanation' => null,
                'tags' => ['disc', 'influence'],
            ],
            [
                'key' => 'DISC03',
                'category' => 'DISC',
                'difficulty' => 'EASY',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya lebih nyaman bekerja dalam ritme yang stabil dan minim perubahan mendadak.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'S',
                'explanation' => null,
                'tags' => ['disc', 'steadiness'],
            ],
            [
                'key' => 'DISC04',
                'category' => 'DISC',
                'difficulty' => 'EASY',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya teliti terhadap detail dan merasa tidak nyaman jika ada data yang tidak akurat.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'C',
                'explanation' => null,
                'tags' => ['disc', 'conscientiousness'],
            ],
            [
                'key' => 'DISC05',
                'category' => 'DISC',
                'difficulty' => 'MEDIUM',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Dalam diskusi tim, saya cenderung mengambil alih arah pembicaraan.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'D',
                'explanation' => null,
                'tags' => ['disc', 'leadership'],
            ],
            [
                'key' => 'DISC06',
                'category' => 'DISC',
                'difficulty' => 'MEDIUM',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya merasa termotivasi jika pekerjaan memberi kesempatan tampil dan meyakinkan orang lain.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'I',
                'explanation' => null,
                'tags' => ['disc', 'communication'],
            ],
            [
                'key' => 'DISC07',
                'category' => 'DISC',
                'difficulty' => 'MEDIUM',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya sabar mendampingi rekan kerja yang membutuhkan bantuan berulang kali.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'S',
                'explanation' => null,
                'tags' => ['disc', 'support'],
            ],
            [
                'key' => 'DISC08',
                'category' => 'DISC',
                'difficulty' => 'MEDIUM',
                'question_type' => 'LIKERT_1_5',
                'prompt' => 'Saya selalu memeriksa kembali pekerjaan sebelum diserahkan.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => 'C',
                'explanation' => null,
                'tags' => ['disc', 'quality'],
            ],

            // ─── Technical Backend / IT ─────────────────────────────
            [
                'key' => 'TECH01',
                'category' => 'TECHNICAL',
                'difficulty' => 'EASY',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'HTTP status code untuk resource yang berhasil dibuat adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => '200'],
                    ['key' => 'B', 'text' => '201'],
                    ['key' => 'C', 'text' => '204'],
                    ['key' => 'D', 'text' => '302'],
                ],
                'correct_answer' => 'B',
                'disc_weight' => null,
                'explanation' => '201 Created dipakai saat resource baru berhasil dibuat.',
                'tags' => ['technical', 'backend', 'http'],
            ],
            [
                'key' => 'TECH02',
                'category' => 'TECHNICAL',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_SINGLE',
                'prompt' => 'SQL command untuk mengambil seluruh data dari tabel employees adalah ...',
                'choices' => [
                    ['key' => 'A', 'text' => 'GET * FROM employees'],
                    ['key' => 'B', 'text' => 'SELECT ALL FROM employees'],
                    ['key' => 'C', 'text' => 'SELECT * FROM employees'],
                    ['key' => 'D', 'text' => 'SHOW employees'],
                ],
                'correct_answer' => 'C',
                'disc_weight' => null,
                'explanation' => 'Sintaks yang benar adalah SELECT * FROM employees.',
                'tags' => ['technical', 'sql'],
            ],
            [
                'key' => 'TECH03',
                'category' => 'TECHNICAL',
                'difficulty' => 'MEDIUM',
                'question_type' => 'MCQ_MULTI',
                'prompt' => 'Manakah yang termasuk praktik keamanan aplikasi web yang baik?',
                'choices' => [
                    ['key' => 'A', 'text' => 'Validasi input user'],
                    ['key' => 'B', 'text' => 'Simpan password plaintext'],
                    ['key' => 'C', 'text' => 'Gunakan prepared statement'],
                    ['key' => 'D', 'text' => 'Expose stack trace ke user final'],
                ],
                'correct_answer' => ['A', 'C'],
                'disc_weight' => null,
                'explanation' => 'Validasi input dan prepared statement mengurangi risiko injection.',
                'tags' => ['technical', 'security'],
            ],
            [
                'key' => 'TECH04',
                'category' => 'TECHNICAL',
                'difficulty' => 'HARD',
                'question_type' => 'SHORT_ANSWER',
                'prompt' => 'Jelaskan perbedaan antara authentication dan authorization dalam aplikasi web.',
                'choices' => null,
                'correct_answer' => null,
                'disc_weight' => null,
                'explanation' => 'Authentication = siapa usernya; Authorization = apa yang boleh dia akses.',
                'tags' => ['technical', 'backend', 'essay'],
            ],
        ];
    }

    /**
     * Dataset test dummy.
     */
    private function testDataset(): array
    {
        return [
            [
                'code' => 'TPA-DUMMY-001',
                'name' => 'TPA Dasar — Screening Kandidat',
                'category' => 'TPA',
                'description' => 'Tes Potensi Akademik dasar untuk screening verbal, numerik, dan logika.',
                'instructions_html' => '<p>Jawab seluruh soal sebaik mungkin. Fokus pada akurasi dan manajemen waktu.</p>',
                'duration_minutes' => 30,
                'passing_score_pct' => 60,
                'max_attempts' => 1,
                'shuffle_questions' => 1,
                'shuffle_choices' => 0,
                'show_result_to_candidate' => 1,
                'question_keys' => ['TPAV01', 'TPAV02', 'TPAV03', 'TPAV04', 'TPAN01', 'TPAN02', 'TPAN03', 'TPAN04', 'TPAL01', 'TPAL02', 'TPAL03'],
                'points_per_question' => [],
            ],
            [
                'code' => 'ENG-DUMMY-001',
                'name' => 'English Basic Test',
                'category' => 'ENGLISH',
                'description' => 'Tes kemampuan Bahasa Inggris dasar untuk grammar dan vocabulary.',
                'instructions_html' => '<p>Pilih jawaban yang paling tepat. Gunakan pemahaman grammar dan vocabulary dasar.</p>',
                'duration_minutes' => 20,
                'passing_score_pct' => 70,
                'max_attempts' => 1,
                'shuffle_questions' => 1,
                'shuffle_choices' => 0,
                'show_result_to_candidate' => 1,
                'question_keys' => ['ENG01', 'ENG02', 'ENG03', 'ENG04'],
                'points_per_question' => [],
            ],
            [
                'code' => 'DISC-DUMMY-001',
                'name' => 'DISC Screening Dummy',
                'category' => 'DISC',
                'description' => 'Tes kepribadian sederhana berbasis dimensi DISC.',
                'instructions_html' => '<p>Beri nilai 1–5 sesuai seberapa cocok pernyataan dengan diri Anda.</p>',
                'duration_minutes' => 15,
                'passing_score_pct' => 0,
                'max_attempts' => 1,
                'shuffle_questions' => 1,
                'shuffle_choices' => 0,
                'show_result_to_candidate' => 1,
                'question_keys' => ['DISC01', 'DISC02', 'DISC03', 'DISC04', 'DISC05', 'DISC06', 'DISC07', 'DISC08'],
                'points_per_question' => [],
            ],
            [
                'code' => 'TECH-DUMMY-001',
                'name' => 'Backend Technical Screening',
                'category' => 'TECHNICAL',
                'description' => 'Tes teknis dasar untuk posisi backend / software engineer.',
                'instructions_html' => '<p>Soal terdiri dari pilihan ganda dan essay singkat.</p>',
                'duration_minutes' => 25,
                'passing_score_pct' => 65,
                'max_attempts' => 1,
                'shuffle_questions' => 1,
                'shuffle_choices' => 0,
                'show_result_to_candidate' => 1,
                'question_keys' => ['TECH01', 'TECH02', 'TECH03', 'TECH04'],
                'points_per_question' => ['TECH04' => 2.0],
            ],
        ];
    }
}