<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

final class OnlineTestBankImportParser
{
    /** @return list<array{header:string, required:bool, width:int, help:string}> */
    public function columns(): array
    {
        return [
            ['header' => 'category', 'required' => false, 'width' => 18, 'help' => 'Kategori bank soal. Contoh: GENERAL, TPA_NUMERIK, ENGLISH, DISC.'],
            ['header' => 'difficulty', 'required' => false, 'width' => 16, 'help' => 'EASY, MEDIUM, atau HARD. Default MEDIUM.'],
            ['header' => 'question_type', 'required' => true, 'width' => 22, 'help' => 'MCQ_SINGLE, MCQ_MULTI, TRUE_FALSE, LIKERT_1_5, NUMERIC, SHORT_ANSWER.'],
            ['header' => 'prompt', 'required' => true, 'width' => 56, 'help' => 'Isi soal atau pernyataan.'],
            ['header' => 'choices', 'required' => false, 'width' => 48, 'help' => 'Untuk MCQ: A=Pilihan A|B=Pilihan B atau JSON array. Kosong untuk non-MCQ.'],
            ['header' => 'correct_answer', 'required' => false, 'width' => 22, 'help' => 'MCQ_SINGLE A, MCQ_MULTI A,C, TRUE_FALSE true/false, NUMERIC angka.'],
            ['header' => 'disc_weight', 'required' => false, 'width' => 16, 'help' => 'D/I/S/C untuk LIKERT_1_5 DISC.'],
            ['header' => 'explanation', 'required' => false, 'width' => 48, 'help' => 'Pembahasan atau referensi jawaban.'],
            ['header' => 'tags', 'required' => false, 'width' => 28, 'help' => 'Pisahkan dengan koma atau pipe. Contoh: verbal,sinonim.'],
        ];
    }

    /**
     * @param list<array<int, mixed>> $rows
     * @return array{rows:list<array<string, mixed>>, errors:list<array{row:int, message:string}>}
     */
    public function parseRows(array $rows): array
    {
        if (count($rows) < 2) {
            throw new \RuntimeException('File tidak berisi data soal.');
        }

        $headers = array_map($this->normalizeHeader(...), array_shift($rows));
        $validRows = [];
        $errors = [];

        foreach ($rows as $idx => $row) {
            $line = $idx + 2;
            if (! array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $columnIndex => $header) {
                if ($header !== '') {
                    $assoc[$header] = $row[$columnIndex] ?? null;
                }
            }
            try {
                $validRows[] = $this->normalizePayload($assoc);
            } catch (\RuntimeException $exception) {
                $errors[] = ['row' => $line, 'message' => $exception->getMessage()];
            }
        }

        return ['rows' => $validRows, 'errors' => $errors];
    }

    private function normalizeHeader(mixed $header): string
    {
        return strtolower(trim(str_replace('*', '', (string) $header)));
    }

    /** @param array<string, mixed> $row */
    private function normalizePayload(array $row): array
    {
        $type = strtoupper(trim((string) ($row['question_type'] ?? '')));
        if ($type === '' || ! in_array($type, OnlineTestService::ALL_TYPES, true)) {
            throw new \RuntimeException('question_type tidak valid');
        }
        $prompt = trim((string) ($row['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \RuntimeException('prompt wajib diisi');
        }
        $difficulty = strtoupper(trim((string) ($row['difficulty'] ?? 'MEDIUM')));
        if (! in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $difficulty = 'MEDIUM';
        }
        $category = strtoupper(trim((string) ($row['category'] ?? 'GENERAL'))) ?: 'GENERAL';
        $discWeight = strtoupper(trim((string) ($row['disc_weight'] ?? '')));
        if (! in_array($discWeight, ['D', 'I', 'S', 'C'], true)) {
            $discWeight = null;
        }
        $choices = $this->parseChoices((string) ($row['choices'] ?? ''));
        $correctAnswer = $this->parseCorrectAnswer($type, (string) ($row['correct_answer'] ?? ''));
        $tags = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            preg_split('/[,|]/', (string) ($row['tags'] ?? '')) ?: [],
        )));

        return [
            'category' => $category,
            'difficulty' => $difficulty,
            'question_type' => $type,
            'prompt' => $prompt,
            'choices' => $choices !== [] ? json_encode($choices, JSON_UNESCAPED_UNICODE) : null,
            'correct_answer' => $correctAnswer !== null ? json_encode($correctAnswer, JSON_UNESCAPED_UNICODE) : null,
            'disc_weight' => $discWeight,
            'explanation' => trim((string) ($row['explanation'] ?? '')) ?: null,
            'tags' => $tags !== [] ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /** @return list<array{key:string, text:string}> */
    private function parseChoices(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $choices = [];
            foreach ($decoded as $idx => $item) {
                if (is_array($item)) {
                    $text = trim((string) ($item['text'] ?? ''));
                    if ($text !== '') {
                        $choices[] = ['key' => trim((string) ($item['key'] ?? chr(65 + $idx))), 'text' => $text];
                    }
                } else {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $choices[] = ['key' => chr(65 + $idx), 'text' => $text];
                    }
                }
            }
            return $choices;
        }

        $choices = [];
        foreach (explode('|', $raw) as $idx => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode('=', $part, 2);
            $choices[] = count($segments) === 2
                ? ['key' => trim($segments[0]), 'text' => trim($segments[1])]
                : ['key' => chr(65 + $idx), 'text' => $part];
        }
        return array_values(array_filter($choices, static fn (array $choice): bool => $choice['key'] !== '' && $choice['text'] !== ''));
    }

    private function parseCorrectAnswer(string $type, string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($type === 'TRUE_FALSE') {
            return in_array(strtolower($raw), ['1', 'true', 'ya', 'yes', 'benar'], true);
        }
        if ($type === 'NUMERIC') {
            return is_numeric($raw) ? (float) $raw : $raw;
        }
        if ($type === 'MCQ_MULTI') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        return $raw;
    }
}
