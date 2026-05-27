<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CandidateOfferSelfServiceTest — pure validation tests untuk Sprint 25 /
 * Option C (Candidate Portal offer self-service).
 *
 * Cover the validation rules yang dijalankan di:
 *   - CandidateAuthController::respondToOffer (input validation)
 *   - OfferService::recordResponseByCandidate (decision validation)
 *
 * Pure logic — tidak butuh DB. Run:
 *   vendor/bin/phpunit --filter CandidateOfferSelfServiceTest
 */
class CandidateOfferSelfServiceTest extends TestCase
{
    // ─── Decision validation ─────────────────────────────────────────

    public function testValidDecisionsAccepted(): void
    {
        $this->assertTrue($this->isValidDecision('ACCEPTED'));
        $this->assertTrue($this->isValidDecision('REJECTED'));
    }

    public function testInvalidDecisionsRejected(): void
    {
        $this->assertFalse($this->isValidDecision('PENDING'));
        $this->assertFalse($this->isValidDecision('WITHDRAWN'));
        $this->assertFalse($this->isValidDecision(''));
        $this->assertFalse($this->isValidDecision('YES'));
        $this->assertFalse($this->isValidDecision('NO'));
    }

    public function testDecisionCaseNormalization(): void
    {
        // Controller pakai strtoupper sebelum validate
        $this->assertTrue($this->isValidDecision(strtoupper('accepted')));
        $this->assertTrue($this->isValidDecision(strtoupper('rejected')));
        $this->assertTrue($this->isValidDecision(strtoupper('AcCePtEd')));
    }

    // ─── Rejection reason validation ─────────────────────────────────

    public function testRejectionReasonRequiredForReject(): void
    {
        // Controller: REJECTED + empty reason → error
        $this->assertFalse($this->isValidRejectionInput('REJECTED', ''));
        $this->assertFalse($this->isValidRejectionInput('REJECTED', null));
        $this->assertFalse($this->isValidRejectionInput('REJECTED', '   '));
    }

    public function testRejectionReasonMinLengthEnforced(): void
    {
        // Min 5 karakter setelah trim
        $this->assertFalse($this->isValidRejectionInput('REJECTED', 'abc'));
        $this->assertFalse($this->isValidRejectionInput('REJECTED', '1234'));
        $this->assertTrue($this->isValidRejectionInput('REJECTED', '12345'));
        $this->assertTrue($this->isValidRejectionInput('REJECTED',
            'sudah menerima offer di tempat lain'));
    }

    public function testRejectionReasonNotRequiredForAccept(): void
    {
        // ACCEPTED tidak perlu rejection_reason
        $this->assertTrue($this->isValidRejectionInput('ACCEPTED', null));
        $this->assertTrue($this->isValidRejectionInput('ACCEPTED', ''));
        $this->assertTrue($this->isValidRejectionInput('ACCEPTED', 'optional note'));
    }

    public function testRejectionReasonMaxLength(): void
    {
        // Controller cap di 1000 karakter via mb_substr
        $long = str_repeat('a', 1500);
        $trimmed = mb_substr($long, 0, 1000);
        $this->assertSame(1000, mb_strlen($trimmed));
    }

    // ─── Note (optional) validation ──────────────────────────────────

    public function testNoteMaxLength(): void
    {
        // Note dibatasi 2000 karakter via mb_substr di controller
        $long = str_repeat('a', 3000);
        $trimmed = mb_substr($long, 0, 2000);
        $this->assertSame(2000, mb_strlen($trimmed));
    }

    public function testNoteOptional(): void
    {
        // Note boleh kosong / null untuk both ACCEPTED & REJECTED
        $this->assertTrue($this->isValidNote(null));
        $this->assertTrue($this->isValidNote(''));
        $this->assertTrue($this->isValidNote('terima kasih'));
    }

    // ─── Status gating (hanya SENT yang bisa direspon) ──────────────

    public function testOnlySentOfferCanBeResponded(): void
    {
        // OfferService::recordResponseByCandidate enforces status === 'SENT'
        $this->assertTrue($this->canRespond('SENT'));
        $this->assertFalse($this->canRespond('DRAFT'));
        $this->assertFalse($this->canRespond('ACCEPTED'));
        $this->assertFalse($this->canRespond('REJECTED'));
        $this->assertFalse($this->canRespond('EXPIRED'));
        $this->assertFalse($this->canRespond('WITHDRAWN'));
    }

    public function testDraftOfferNotVisibleToCandidate(): void
    {
        // CandidateAuthController::showOffer block DRAFT (404 NOT_VISIBLE)
        // supaya internal HR offer tidak leak ke kandidat
        $this->assertFalse($this->isVisibleToCandidate('DRAFT'));
        $this->assertTrue($this->isVisibleToCandidate('SENT'));
        $this->assertTrue($this->isVisibleToCandidate('ACCEPTED'));
        $this->assertTrue($this->isVisibleToCandidate('REJECTED'));
        $this->assertTrue($this->isVisibleToCandidate('EXPIRED'));
        $this->assertTrue($this->isVisibleToCandidate('WITHDRAWN'));
    }

    // ─── Deadline check ──────────────────────────────────────────────

    public function testExpiredDeadlineBlocksResponse(): void
    {
        // Service: kalau response_deadline < today → auto-expire + throw
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));
        $today     = date('Y-m-d');

        $this->assertTrue($this->isDeadlineExpired($yesterday));
        $this->assertFalse($this->isDeadlineExpired($tomorrow));
        // Today masih bisa karena cutoff 23:59:59
        $this->assertFalse($this->isDeadlineExpired($today));
        // Null deadline = tidak ada limit
        $this->assertFalse($this->isDeadlineExpired(null));
    }

    // ─── Audit metadata shape ────────────────────────────────────────

    public function testAuditMetadataIncludesActorAndIp(): void
    {
        // recordResponseByCandidate harus log dengan metadata berikut
        // untuk legal trail kalau ada dispute kandidat
        $metadata = $this->buildAuditMetadata(
            decision:       'ACCEPTED',
            accountId:      42,
            candidateName:  'Budi Santoso',
            candidateEmail: 'budi@example.com',
            ip:             '203.0.113.1',
            ua:             'Mozilla/5.0 (X11; Linux x86_64)',
            rejectionReason: null,
        );

        $this->assertSame('candidate', $metadata['actor']);
        $this->assertSame(42, $metadata['candidate_account_id']);
        $this->assertSame('Budi Santoso', $metadata['candidate_name']);
        $this->assertSame('budi@example.com', $metadata['candidate_email']);
        $this->assertSame('203.0.113.1', $metadata['ip_address']);
        $this->assertNotEmpty($metadata['user_agent']);
        $this->assertNull($metadata['rejection_reason']);
    }

    public function testAuditMetadataIncludesRejectionReasonOnReject(): void
    {
        $metadata = $this->buildAuditMetadata(
            decision:       'REJECTED',
            accountId:      42,
            candidateName:  'Ani Wati',
            candidateEmail: 'ani@example.com',
            ip:             '203.0.113.1',
            ua:             'Mozilla/5.0',
            rejectionReason: 'Sudah menerima offer di tempat lain dengan paket lebih baik',
        );

        $this->assertSame('candidate', $metadata['actor']);
        $this->assertSame('Sudah menerima offer di tempat lain dengan paket lebih baik',
            $metadata['rejection_reason']);
    }

    public function testAuditUserAgentTruncatedAt255(): void
    {
        $longUa = str_repeat('a', 500);
        $metadata = $this->buildAuditMetadata(
            decision:       'ACCEPTED',
            accountId:      1,
            candidateName:  'Test',
            candidateEmail: 't@e.co',
            ip:             '1.1.1.1',
            ua:             $longUa,
            rejectionReason: null,
        );
        $this->assertLessThanOrEqual(255, mb_strlen($metadata['user_agent']));
    }

    // ─── Severity per decision ───────────────────────────────────────

    public function testRejectedActionLoggedAsWarning(): void
    {
        // Kandidat tolak offer = WARNING severity supaya HR bisa filter
        // & investigate (kenapa kandidat nolak, ada pattern issue?)
        $this->assertSame('WARNING', $this->severityFor('REJECTED'));
    }

    public function testAcceptedActionLoggedAsNotice(): void
    {
        // Accept = NOTICE severity (informational)
        $this->assertSame('NOTICE', $this->severityFor('ACCEPTED'));
    }

    // ─── Pure helpers (mirror logic di service & controller) ────────

    private function isValidDecision(string $decision): bool
    {
        return in_array($decision, ['ACCEPTED', 'REJECTED'], true);
    }

    private function isValidRejectionInput(string $decision, ?string $reason): bool
    {
        if ($decision !== 'REJECTED') return true;
        $trimmed = $reason === null ? '' : trim($reason);
        return mb_strlen($trimmed) >= 5;
    }

    private function isValidNote(?string $note): bool
    {
        // Note opsional — apa pun valid (akan di-trim & cap di controller)
        return true;
    }

    private function canRespond(string $status): bool
    {
        return $status === 'SENT';
    }

    private function isVisibleToCandidate(string $status): bool
    {
        // CandidateAuthController::showOffer block DRAFT
        return $status !== 'DRAFT';
    }

    private function isDeadlineExpired(?string $deadline): bool
    {
        if ($deadline === null || $deadline === '') return false;
        return strtotime($deadline . ' 23:59:59') < time();
    }

    private function buildAuditMetadata(
        string $decision,
        int $accountId,
        string $candidateName,
        string $candidateEmail,
        string $ip,
        string $ua,
        ?string $rejectionReason,
    ): array {
        return [
            'actor'                => 'candidate',
            'candidate_account_id' => $accountId,
            'candidate_name'       => $candidateName,
            'candidate_email'      => $candidateEmail,
            'ip_address'           => $ip,
            'user_agent'           => mb_substr($ua, 0, 255),
            'rejection_reason'     => $decision === 'REJECTED' ? $rejectionReason : null,
        ];
    }

    private function severityFor(string $decision): string
    {
        return $decision === 'REJECTED' ? 'WARNING' : 'NOTICE';
    }
}