<?php

namespace App\Libraries\Tax\Providers;

use App\Libraries\Tax\EbupotClientInterface;

class MockEbupotService implements EbupotClientInterface
{
    public function uploadBuktiPotong(array $payslips): string
    {
        // Simulasi hit API ke PJAP
        log_message('info', 'MockEbupotService: Mengirim ' . count($payslips) . ' data bupot ke PJAP...');
        
        // Generate random batch id
        $batchId = 'EBP-' . date('YmdHis') . '-' . rand(1000, 9999);
        return $batchId;
    }

    public function getUploadStatus(string $batchId): array
    {
        // Simulasi status: otomatis COMPLETED jika dipanggil (atau random fail)
        return [
            'status' => 'COMPLETED', // IN_PROGRESS, COMPLETED, FAILED
            'errors' => []
        ];
    }
}
