<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPayrollVarianceReview extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('payroll_periods')) {
            return;
        }
        $fields = $this->db->getFieldNames('payroll_periods');
        $columns = [
            'variance_json' => ['type' => 'TEXT', 'null' => true],
            'variance_requires_review' => ['type' => 'TINYINT', 'default' => 0],
            'variance_reviewed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'variance_reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'variance_review_note' => ['type' => 'TEXT', 'null' => true],
        ];
        foreach ($columns as $name => $definition) {
            if (! in_array($name, $fields, true)) {
                $this->forge->addColumn('payroll_periods', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        foreach (['variance_review_note', 'variance_reviewed_at', 'variance_reviewed_by', 'variance_requires_review', 'variance_json'] as $column) {
            if ($this->db->fieldExists($column, 'payroll_periods')) {
                $this->forge->dropColumn('payroll_periods', $column);
            }
        }
    }
}
