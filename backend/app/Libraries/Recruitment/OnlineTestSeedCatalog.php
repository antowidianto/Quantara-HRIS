<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

final class OnlineTestSeedCatalog
{
    public function forTemplate(string $template): array
    {
        return match ($template) {
            'TPA' => [
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_VERBAL',
                 'prompt' => 'PUSAKA = ?',
                 'choices' => [['key'=>'A','text'=>'Modal'],['key'=>'B','text'=>'Warisan'],['key'=>'C','text'=>'Hadiah'],['key'=>'D','text'=>'Pemberian']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_VERBAL',
                 'prompt' => 'KAPAL : LAUT = ? : DARAT',
                 'choices' => [['key'=>'A','text'=>'Mobil'],['key'=>'B','text'=>'Roda'],['key'=>'C','text'=>'Pesawat'],['key'=>'D','text'=>'Pelabuhan']],
                 'correct_answer' => 'A', 'points' => 1],
                ['question_type' => 'NUMERIC', 'category' => 'TPA_NUMERIK',
                 'prompt' => 'Sebuah barang dijual Rp 120.000 dengan untung 20%. Berapa harga belinya?',
                 'correct_answer' => 100000, 'points' => 1,
                 'explanation' => '120.000 / 1,2 = 100.000'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_NUMERIK',
                 'prompt' => 'Lanjutan deret: 2, 6, 12, 20, 30, ...',
                 'choices' => [['key'=>'A','text'=>'40'],['key'=>'B','text'=>'42'],['key'=>'C','text'=>'44'],['key'=>'D','text'=>'46']],
                 'correct_answer' => 'B', 'points' => 1, 'explanation' => 'Selisih bertambah 4,6,8,10,12 → +12 = 42'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_LOGIKA',
                 'prompt' => 'Semua ikan berenang. Beberapa ikan bermigrasi. Maka:',
                 'choices' => [
                     ['key'=>'A','text'=>'Semua yang berenang adalah ikan'],
                     ['key'=>'B','text'=>'Beberapa yang berenang bermigrasi'],
                     ['key'=>'C','text'=>'Semua ikan bermigrasi'],
                     ['key'=>'D','text'=>'Tidak ada kesimpulan yang sah'],
                 ],
                 'correct_answer' => 'B', 'points' => 1],
            ],
            'ENGLISH' => [
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'She ___ to the office every day.',
                 'choices' => [['key'=>'A','text'=>'go'],['key'=>'B','text'=>'goes'],['key'=>'C','text'=>'going'],['key'=>'D','text'=>'gone']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'The opposite of "expand" is:',
                 'choices' => [['key'=>'A','text'=>'extend'],['key'=>'B','text'=>'enlarge'],['key'=>'C','text'=>'shrink'],['key'=>'D','text'=>'broaden']],
                 'correct_answer' => 'C', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'If I ___ rich, I would travel the world.',
                 'choices' => [['key'=>'A','text'=>'am'],['key'=>'B','text'=>'will be'],['key'=>'C','text'=>'were'],['key'=>'D','text'=>'be']],
                 'correct_answer' => 'C', 'points' => 1, 'explanation' => 'Type 2 conditional: If + were + would'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => '"I have been working here ___ 2020."',
                 'choices' => [['key'=>'A','text'=>'for'],['key'=>'B','text'=>'since'],['key'=>'C','text'=>'in'],['key'=>'D','text'=>'at']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'TRUE_FALSE', 'category' => 'ENGLISH',
                 'prompt' => '"Children" adalah bentuk plural yang benar dari "child".',
                 'correct_answer' => true, 'points' => 1],
            ],
            'DISC' => [
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya senang mengambil keputusan cepat dan bertindak segera.',
                 'disc_weight' => 'D', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya menikmati berinteraksi dengan banyak orang setiap hari.',
                 'disc_weight' => 'I', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya lebih nyaman dengan rutinitas yang stabil dan dapat diprediksi.',
                 'disc_weight' => 'S', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya selalu memeriksa detail dan memastikan akurasi sebelum menyelesaikan pekerjaan.',
                 'disc_weight' => 'C', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya berani memimpin tim dalam situasi sulit.',
                 'disc_weight' => 'D', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya mudah memotivasi orang lain dengan antusiasme saya.',
                 'disc_weight' => 'I', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya pendengar yang baik dan sabar terhadap rekan kerja.',
                 'disc_weight' => 'S', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya teliti dan kritis terhadap kualitas hasil kerja.',
                 'disc_weight' => 'C', 'points' => 1],
            ],
            default => [],
        };
    }
}
