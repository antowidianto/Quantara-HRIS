<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckHelpdeskSla (Sprint 8 / H1.7)
 *
 * Cron yang dijalankan tiap jam untuk:
 *   1. Warning H-2 jam: notifikasi ke assignee/agen kalau SLA akan habis.
 *      Ditandai `near_sla_notified_at` supaya tidak duplikat.
 *   2. Eskalasi setelah SLA terlampaui:
 *      - Cari ticket yang `sla_due_at < NOW()` & `escalated_at IS NULL`
 *        & status masih OPEN/IN_PROGRESS/REOPENED.
 *      - Eskalasi ke supervisor agent (kalau ada) atau HR Manager (role 2).
 *      - Set `escalated_at` + `escalated_to_user_id`.
 *      - Audit log severity WARNING.
 *
 * Pemakaian:
 *   php spark helpdesk:check-sla
 *   php spark helpdesk:check-sla --dry-run
 *   php spark helpdesk:check-sla --company 1
 *
 * Cron (tiap jam):
 *   0 * * * *  cd /path/to/backend && php spark helpdesk:check-sla >> writable/logs/helpdesk-sla.log 2>&1
 *
 * Lihat docs/QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md item H1.7.
 */
class CheckHelpdeskSla extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'helpdesk:check-sla';
    protected $description = 'Scan ticket helpdesk: kirim warning H-2 jam & eskalasi ticket overdue.';
    protected $usage       = 'helpdesk:check-sla [--company N] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--company' => 'Filter ke 1 company (untuk testing).',
        '--dry-run' => 'Tampilkan hasil tanpa kirim notifikasi/eskalasi.',
    ];

    /**
     * Window warning sebelum SLA habis.
     * Default 2 jam — bisa di-override pakai --hours tapi kita tetap tiap jam
     * cukup, jadi 2 jam aman.
     */
    private const WARNING_WINDOW_HOURS = 2;

    public function run(array $params)
    {
        $companyFilter = CLI::getOption('company');
        $companyId = $companyFilter !== null ? (int) $companyFilter : null;
        $dryRun = (bool) CLI::getOption('dry-run');

        $db = \Config\Database::connect();

        if (! $db->tableExists('hr_tickets')) {
            CLI::write('Tabel hr_tickets tidak ada — skip.', 'yellow');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $warnThreshold = date('Y-m-d H:i:s', time() + (self::WARNING_WINDOW_HOURS * 3600));

        CLI::write(str_repeat('=', 70), 'green');
        CLI::write("Helpdesk SLA Scanner @ {$now}" . ($dryRun ? ' (DRY-RUN)' : ''), 'green');
        CLI::write(str_repeat('=', 70), 'green');

        // ─── Phase 1: Near-SLA warnings ─────────────────────────────
        $warnQ = $db->table('hr_tickets')
            ->where('deleted_at IS NULL')
            ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'REOPENED'])
            ->where('sla_due_at IS NOT NULL')
            ->where('sla_due_at >', $now)
            ->where('sla_due_at <=', $warnThreshold)
            ->where('near_sla_notified_at IS NULL');
        if ($companyId) $warnQ->where('company_id', $companyId);
        $nearRows = $warnQ->get()->getResultArray();

        CLI::write("\n▶ Near-SLA (akan habis dalam " . self::WARNING_WINDOW_HOURS . " jam): " . count($nearRows), 'cyan');
        $warnedCount = 0;
        foreach ($nearRows as $t) {
            $hoursLeft = round((strtotime($t['sla_due_at']) - time()) / 3600, 1);
            CLI::write(sprintf(
                '  - %s | %s | sla_due_at %s (%.1f jam lagi)',
                $t['ticket_number'], substr($t['subject'], 0, 40),
                $t['sla_due_at'], $hoursLeft,
            ), 'yellow');

            if ($dryRun) continue;
            $this->notifyNearSla($t, $hoursLeft);
            $db->table('hr_tickets')->where('id', (int) $t['id'])->update([
                'near_sla_notified_at' => $now,
                'updated_at'           => $now,
            ]);
            $warnedCount++;
        }

        // ─── Phase 2: Overdue → eskalasi ────────────────────────────
        $overQ = $db->table('hr_tickets')
            ->where('deleted_at IS NULL')
            ->whereIn('status', ['OPEN', 'IN_PROGRESS', 'REOPENED'])
            ->where('sla_due_at IS NOT NULL')
            ->where('sla_due_at <', $now)
            ->where('escalated_at IS NULL');
        if ($companyId) $overQ->where('company_id', $companyId);
        $overdueRows = $overQ->get()->getResultArray();

        CLI::write("\n▶ Overdue (SLA terlampaui, perlu eskalasi): " . count($overdueRows), 'magenta');
        $escalatedCount = 0;
        foreach ($overdueRows as $t) {
            $hoursOver = round((time() - strtotime($t['sla_due_at'])) / 3600, 1);
            CLI::write(sprintf(
                '  - %s | %s | overdue %.1f jam (priority=%s)',
                $t['ticket_number'], substr($t['subject'], 0, 40),
                $hoursOver, $t['priority'] ?? '?',
            ), 'red');

            if ($dryRun) continue;
            $supervisorUserId = $this->resolveEscalationTarget($t);
            if ($supervisorUserId === null) {
                CLI::write('    [WARN] Tidak ada supervisor target — eskalasi di-skip.', 'yellow');
                continue;
            }
            $db->table('hr_tickets')->where('id', (int) $t['id'])->update([
                'escalated_at'         => $now,
                'escalated_to_user_id' => $supervisorUserId,
                'updated_at'           => $now,
            ]);
            $this->notifyEscalation($t, $supervisorUserId, $hoursOver);

            try {
                Services::audit()->forModule('HELPDESK')->log(
                    action: 'ticket.sla_breach',
                    entity: 'hr_tickets',
                    entityId: (int) $t['id'],
                    after: [
                        'escalated_to_user_id' => $supervisorUserId,
                        'hours_over'           => $hoursOver,
                    ],
                    description: sprintf(
                        'Ticket %s SLA breach (%.1f jam overdue) → eskalasi ke user #%d',
                        $t['ticket_number'], $hoursOver, $supervisorUserId
                    ),
                    severity: 'WARNING',
                );
            } catch (\Throwable $e) {}

            $escalatedCount++;
        }

        CLI::write("\n" . str_repeat('=', 70), 'green');
        CLI::write(sprintf(
            'Selesai. %d warning H-%dh dikirim, %d ticket di-eskalasi.',
            $warnedCount, self::WARNING_WINDOW_HOURS, $escalatedCount
        ), 'green');
        CLI::write(str_repeat('=', 70) . "\n", 'green');
    }

    /**
     * Tentukan user target eskalasi:
     *   1. Manager (role 3) di company yang sama jika assignee adalah agent biasa.
     *   2. Fallback ke HR Admin (role 2) kalau tidak ada manager.
     */
    private function resolveEscalationTarget(array $ticket): ?int
    {
        $db = \Config\Database::connect();

        // Cari Manager dulu (role 3)
        $manager = $db->table('users')
            ->where('company_id', (int) $ticket['company_id'])
            ->where('role_id', 3)
            ->where('is_active', 1)
            ->limit(1)->get()->getRowArray();
        if ($manager) {
            $managerId = (int) $manager['id'];
            // Hindari eskalasi ke user yang sama dengan assignee (loop tidak berguna)
            if ((int) ($ticket['assignee_user_id'] ?? 0) !== $managerId) {
                return $managerId;
            }
        }

        // Fallback HR Admin
        $hr = $db->table('users')
            ->where('company_id', (int) $ticket['company_id'])
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->limit(1)->get()->getRowArray();
        if ($hr) {
            $hrId = (int) $hr['id'];
            if ((int) ($ticket['assignee_user_id'] ?? 0) !== $hrId) {
                return $hrId;
            }
        }
        return null;
    }

    private function notifyNearSla(array $ticket, float $hoursLeft): void
    {
        $userId = (int) ($ticket['assignee_user_id'] ?? 0);
        if ($userId <= 0) return;

        try {
            Services::notification()->send(
                $userId,
                (int) $ticket['company_id'],
                'helpdesk.sla_warning',
                sprintf('⏰ Ticket %s akan lewat SLA dalam %.1f jam', $ticket['ticket_number'], $hoursLeft),
                sprintf('Ticket "%s" (%s) belum selesai dan SLA akan habis. Mohon segera tindak lanjuti.',
                    $ticket['subject'], $ticket['priority']
                ),
                ['ticket_id' => (int) $ticket['id']],
                '/helpdesk/tickets/' . (int) $ticket['id'],
                true
            );
        } catch (\Throwable $e) {
            log_message('warning', '[CheckHelpdeskSla] near-sla notify failed: ' . $e->getMessage());
        }
    }

    private function notifyEscalation(array $ticket, int $supervisorUserId, float $hoursOver): void
    {
        try {
            Services::notification()->send(
                $supervisorUserId,
                (int) $ticket['company_id'],
                'helpdesk.escalated',
                sprintf('🚨 Ticket %s di-eskalasi (overdue %.1f jam)', $ticket['ticket_number'], $hoursOver),
                sprintf('Ticket "%s" (%s) telah melewati SLA. Mohon tinjau & ambil tindakan.',
                    $ticket['subject'], $ticket['priority']
                ),
                ['ticket_id' => (int) $ticket['id'], 'escalated' => true],
                '/helpdesk/tickets/' . (int) $ticket['id'],
                true
            );
        } catch (\Throwable $e) {
            log_message('warning', '[CheckHelpdeskSla] escalation notify failed: ' . $e->getMessage());
        }

        // Inform assignee asli juga supaya mereka tahu sudah di-eskalasi.
        $assigneeId = (int) ($ticket['assignee_user_id'] ?? 0);
        if ($assigneeId > 0 && $assigneeId !== $supervisorUserId) {
            try {
                Services::notification()->send(
                    $assigneeId,
                    (int) $ticket['company_id'],
                    'helpdesk.escalated_yours',
                    sprintf('Ticket %s di-eskalasi karena melewati SLA', $ticket['ticket_number']),
                    'Ticket yang Anda tangani sudah melewati SLA dan diteruskan ke supervisor.',
                    ['ticket_id' => (int) $ticket['id']],
                    '/helpdesk/tickets/' . (int) $ticket['id'],
                    false
                );
            } catch (\Throwable $e) {}
        }
    }
}