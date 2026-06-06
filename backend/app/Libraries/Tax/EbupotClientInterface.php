<?php

namespace App\Libraries\Tax;

interface EbupotClientInterface
{
    /**
     * Mengirim data potongan pajak massal untuk suatu periode (batch).
     * @param array $payslips Array data slip gaji dengan detail PPh 21
     * @return string ID Referensi/Batch ID dari PJAP/DJP
     */
    public function uploadBuktiPotong(array $payslips): string;

    /**
     * Mengecek status pemrosesan e-Bupot.
     * @param string $batchId
     * @return array [ 'status' => 'IN_PROGRESS|COMPLETED|FAILED', 'errors' => [] ]
     */
    public function getUploadStatus(string $batchId): array;
}
