<?php

namespace App\Libraries\Attendance;

interface LivenessServiceInterface
{
    /**
     * Verify if the provided image is of a live person.
     * 
     * @param string $imagePath Path to the image file or base64 data
     * @return array [
     *   'is_live' => boolean, 
     *   'confidence_score' => float, 
     *   'error_message' => string|null
     * ]
     */
    public function verifyLiveness(string $imagePath): array;
}
