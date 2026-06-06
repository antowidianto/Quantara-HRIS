<?php

namespace App\Jobs;

use App\Libraries\Queue\BaseJob;

class ExampleEmailJob extends BaseJob
{
    /**
     * Set max retries for this specific job
     */
    public int $tries = 3;

    /**
     * Execute the job logic
     */
    public function handle(): void
    {
        // Data can be accessed via $this->data
        $email = $this->data['email'] ?? null;
        $subject = $this->data['subject'] ?? 'Default Subject';
        $message = $this->data['message'] ?? 'Default Message';

        if (! $email) {
            throw new \Exception('Email address is required for this job.');
        }

        // Simulate a long-running process like sending an email via SMTP
        sleep(2); // Simulate network delay

        // Suppose we have an Email Service here...
        // \Config\Services::email()->setTo($email)->setSubject($subject)->setMessage($message)->send();
        
        // Log to CI4 Logger just for example verification
        log_message('info', "Mock Email successfully sent to {$email} with subject: {$subject}");
    }
}