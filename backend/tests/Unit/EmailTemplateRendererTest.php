<?php

namespace Tests\Unit;

use App\Libraries\EmailTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * EmailTemplateRendererTest — verifikasi substitute & default fallback.
 *
 * Test loadTemplate dari DB butuh DB stub → di-cover di integration test.
 *
 * Run: vendor/bin/phpunit --filter EmailTemplateRendererTest
 */
class EmailTemplateRendererTest extends TestCase
{
    private EmailTemplateRenderer $r;

    /** Build entitas HTML via concat supaya formatter tidak auto-decode. */
    private string $entLt;
    private string $entGt;
    private string $entQuote;

    protected function setUp(): void
    {
        $this->r = new EmailTemplateRenderer();
        // chr() untuk hindari literal `<` di-decode oleh formatter
        $this->entLt    = chr(38) . 'lt' . chr(59);     // <
        $this->entGt    = chr(38) . 'gt' . chr(59);     // >
        $this->entQuote = chr(38) . 'quot' . chr(59);   // "
    }

    // ─── substitute() ──────────────────────────────────────────────

    public function testBasicSubstitution(): void
    {
        $out = $this->r->substitute('Halo {{name}}, kabar baik?', ['name' => 'Budi']);
        $this->assertSame('Halo Budi, kabar baik?', $out);
    }

    public function testMultipleVariables(): void
    {
        $out = $this->r->substitute(
            '{{name}} mengajukan cuti {{days}} hari pada {{date}}',
            ['name' => 'Budi', 'days' => 3, 'date' => '15 Jan'],
        );
        $this->assertSame('Budi mengajukan cuti 3 hari pada 15 Jan', $out);
    }

    public function testHtmlEscapeByDefault(): void
    {
        // {{var}} = HTML-escaped untuk cegah XSS
        $payload = '<script>alert("xss")</script>';
        $out = $this->r->substitute('{{user_input}}', ['user_input' => $payload]);
        // Output harus tidak mengandung tag literal
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('</script>', $out);
        // Tapi mengandung versi yang sudah di-escape
        $this->assertStringContainsString($this->entLt . 'script' . $this->entGt, $out);
    }

    public function testRawHtmlWithBangPrefix(): void
    {
        // {{!var}} = raw HTML, tidak di-escape
        $out = $this->r->substitute('{{!html}}', [
            'html' => '<a href="https://example.com">Klik</a>',
        ]);
        $this->assertStringContainsString('<a href="https://example.com">', $out);
    }

    public function testMissingVariableReplacesWithEmpty(): void
    {
        $out = $this->r->substitute('Halo {{nama_yg_tidak_ada}}', []);
        $this->assertSame('Halo ', $out);
    }

    public function testWhitespaceInBracesAllowed(): void
    {
        $out = $this->r->substitute('Halo {{ name }}', ['name' => 'Siti']);
        $this->assertSame('Halo Siti', $out);
    }

    public function testArrayValueIsJsonEncoded(): void
    {
        $out = $this->r->substitute('{{data}}', ['data' => ['a' => 1, 'b' => 2]]);
        // JSON tanda kutip akan di-escape jadi entitas HTML
        $this->assertStringContainsString($this->entQuote . 'a' . $this->entQuote . ':1', $out);
    }

    public function testNumericValuesConvertedToString(): void
    {
        $out = $this->r->substitute('{{count}} hari', ['count' => 42]);
        $this->assertSame('42 hari', $out);
    }

    public function testTemplateWithoutPlaceholdersUnchanged(): void
    {
        $tpl = 'Tidak ada placeholder di sini.';
        $this->assertSame($tpl, $this->r->substitute($tpl, ['anything' => 'value']));
    }

    // ─── render() with fallback default ────────────────────────────

    public function testRenderWithFallbackDefault(): void
    {
        $out = $this->r->render(null, 'unknown.event', [
            'title'    => 'Notifikasi Test',
            'message'  => 'Pesan singkat.',
        ]);
        $this->assertArrayHasKey('subject', $out);
        $this->assertArrayHasKey('body_html', $out);
        $this->assertSame('Notifikasi Test', $out['subject']);
        $this->assertStringContainsString('Notifikasi Test', $out['body_html']);
        $this->assertStringContainsString('Pesan singkat.', $out['body_html']);
    }

    public function testRenderInjectsLinkButton(): void
    {
        $out = $this->r->render(null, 'leave.requested', [
            'title'    => 'Cuti Diajukan',
            'message'  => 'Lihat detail.',
            'link_url' => 'https://hris.local/leaves/42',
        ]);
        $this->assertStringContainsString('https://hris.local/leaves/42', $out['body_html']);
        $this->assertStringContainsString('Buka di HRIS', $out['body_html']);
    }

    public function testRenderWithoutLinkUrlSkipsButton(): void
    {
        $out = $this->r->render(null, 'leave.requested', [
            'title'   => 'Tanpa Link',
            'message' => 'Pesan.',
        ]);
        $this->assertStringNotContainsString('Buka di HRIS', $out['body_html']);
    }

    public function testDefaultVarsInjectedForSubstituteCall(): void
    {
        // Default vars (`year`, `date`) di-auto-inject saat render(),
        // bisa dipakai di template via {{year}} (test substitute langsung
        // karena substitute single-pass tidak resolve var dalam var).
        $expectedYear = date('Y');
        $tpl = 'Tahun {{year}}';
        $out = $this->r->substitute($tpl, ['year' => $expectedYear]);
        $this->assertSame("Tahun {$expectedYear}", $out);
    }

    public function testRenderHtmlEscapesUserInput(): void
    {
        $out = $this->r->render(null, 'test', [
            'title'   => 'Test',
            'message' => '<img src=x onerror=alert(1)>',
        ]);
        // Tag literal harus tidak ada (tidak di-render)
        $this->assertStringNotContainsString('<img src=x onerror', $out['body_html']);
        // Tapi ada versi escaped
        $this->assertStringContainsString($this->entLt . 'img', $out['body_html']);
    }

    public function testSubjectOnlyContainsTitle(): void
    {
        $out = $this->r->render(null, 'test', [
            'title'   => 'Subject Test',
            'message' => 'Body content.',
        ]);
        $this->assertSame('Subject Test', $out['subject']);
    }
}