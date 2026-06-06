<?php

namespace App\Libraries\Attendance;

class LivenessServiceStub implements LivenessServiceInterface
{
    /**
     * Stub implementation of Liveness Detection.
     * This will always return true for now.
     * The client should replace this with a real implementation (e.g. AWS Rekognition, Verihubs, etc).
     */
    public function verifyLiveness(string $imagePath): array
    {
        // TODO: Replace with actual API call to third party service
        return [
            'is_live' => true,
            'confidence_score' => 99.9,
            'error_message' => null
        ];
    }
}
