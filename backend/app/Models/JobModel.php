<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    protected $table            = 'jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    
    // Allowed fields for insert/update
    protected $allowedFields    = [
        'queue',
        'payload',
        'attempts',
        'reserved_at',
        'available_at',
        'created_at',
    ];

    // Dates
    protected $useTimestamps = false; // We use custom integer timestamps for Jobs

    /**
     * Get the next available job from the queue and reserve it.
     * 
     * @param string $queue
     * @return array|null
     */
    public function pop(string $queue = 'default'): ?array
    {
        $now = time();

        $this->db->transStart();

        // Find an available job
        $job = $this->where('queue', $queue)
            ->groupStart()
                ->where('reserved_at', null)
                ->orWhere('reserved_at <=', $now - 90) // Retry reserved jobs after 90 seconds timeout
            ->groupEnd()
            ->where('available_at <=', $now)
            ->orderBy('id', 'ASC')
            ->first();

        if ($job) {
            // Reserve the job
            $this->update($job['id'], [
                'reserved_at' => $now,
                'attempts'    => $job['attempts'] + 1,
            ]);
            
            $job['attempts'] += 1;
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return null;
        }

        return $job;
    }

    /**
     * Mark a job as failed and move it to failed_jobs table
     */
    public function failJob(int $id, \Throwable $exception, string $connection = 'database'): void
    {
        $job = $this->find($id);
        
        if ($job) {
            $this->db->transStart();
            
            $this->db->table('failed_jobs')->insert([
                'connection' => $connection,
                'queue'      => $job['queue'],
                'payload'    => $job['payload'],
                'exception'  => (string) $exception,
                'failed_at'  => date('Y-m-d H:i:s'),
            ]);
            
            $this->delete($id);
            
            $this->db->transComplete();
        }
    }
}