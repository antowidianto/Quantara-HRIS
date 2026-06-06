# Background Job & Queue System

This project implements a custom database-backed Queue System for CodeIgniter 4, inspired by Laravel's Queue and Horizon. It is designed to handle heavy, time-consuming tasks (such as Payroll calculations, generating reports, sending mass emails, etc.) in the background so the main API response remains fast and prevents timeouts.

## 1. Setup & Installation

Before using the Queue system, ensure the required database tables (`jobs` and `failed_jobs`) exist.
Run the database migration via Spark:

```bash
cd backend
php spark migrate
```

## 2. Creating a Job

A Job is a class that encapsulates a specific background task. All jobs must reside in the `app/Jobs/` directory and must extend the `App\Libraries\Queue\BaseJob` class.

**Example: `app/Jobs/SendWelcomeEmailJob.php`**

```php
<?php

namespace App\Jobs;

use App\Libraries\Queue\BaseJob;

class SendWelcomeEmailJob extends BaseJob
{
    /**
     * Maximum number of attempts before the job is marked as failed.
     * Default is 3.
     */
    public int $tries = 3;

    /**
     * Execute the job logic
     */
    public function handle(): void
    {
        // 1. Retrieve the parameters passed when dispatching the job
        $email = $this->data['email'] ?? null;
        $name = $this->data['name'] ?? 'User';

        if (! $email) {
            throw new \Exception('Email address is required.');
        }

        // 2. Perform the heavy task (e.g., SMTP, HTTP Request, PDF Generation)
        // \Config\Services::email()->setTo($email)->send();
        
        log_message('info', "Welcome email sent to {$email}");
    }
}
```

## 3. Dispatching (Pushing) Jobs to the Queue

To add a task to the queue from any Controller or Service, instantiate the `QueueService` and call the `push()` method.

**Example in a Controller/Service:**

```php
use App\Libraries\Queue\QueueService;
use App\Jobs\SendWelcomeEmailJob;

$queueService = new QueueService();

// Basic Dispatch
$queueService->push(SendWelcomeEmailJob::class, [
    'email' => 'new.employee@example.com',
    'name'  => 'John Doe'
]);

// Dispatch to a specific queue pipe (e.g., 'emails' instead of 'default')
$queueService->push(SendWelcomeEmailJob::class, ['email' => 'a@b.com'], 'emails');

// Dispatch with a Delay (e.g., execute after 60 seconds)
$queueService->push(SendWelcomeEmailJob::class, ['email' => 'a@b.com'], 'default', 60);
```

## 4. Running the Queue Worker

Jobs will sit in the `jobs` table until a worker processes them. To start processing jobs, you need to run the Queue Worker daemon via Spark.

**Running locally (Development):**
```bash
php spark queue:work
```
Or for a specific queue:
```bash
php spark queue:work emails
```

**Running in Production (Using Supervisor):**
In a production server (Ubuntu/Debian), you must keep the worker running permanently in the background. Use Supervisor to manage this process.

1. Install Supervisor: `sudo apt-get install supervisor`
2. Create a configuration file: `sudo nano /etc/supervisor/conf.d/hris-worker.conf`
3. Add the following config:

```ini
[program:hris-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/backend/spark queue:work default
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/backend/writable/logs/worker.log
```

4. Apply changes:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start hris-worker:*
```

## 5. Handling Failures & Retries

- **Retries:** If an exception is thrown inside the `handle()` method of your Job, the worker will automatically release it back to the queue and try again until it reaches the `$this->tries` limit.
- **Failed Jobs:** If a job fails more times than its `$tries` limit, it will automatically be moved from the `jobs` table to the `failed_jobs` table. You can inspect the `failed_jobs.exception` column in your database to see the exact stack trace and fix the issue.