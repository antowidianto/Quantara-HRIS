<?php

namespace Tests\Unit;

use App\Libraries\PushLocalizer;
use PHPUnit\Framework\TestCase;

/**
 * PushLocalizer test — Sprint Mobile M7.
 *
 * Cover hanya pure function `interpolate()` di sini karena `resolve()`
 * butuh koneksi DB. End-to-end (lookup tabel) di-test lewat feature
 * test sprint berikut kalau perlu.
 */
final class PushLocalizerTest extends TestCase
{
    public function testInterpolateBasicReplacement(): void
    {
        $out = PushLocalizer::interpolate(
            'Halo {name}, cuti {date} sudah disetujui',
            ['name' => 'Budi', 'date' => '27 Mei'],
        );
        $this->assertSame('Halo Budi, cuti 27 Mei sudah disetujui', $out);
    }

    public function testInterpolateLeavesUnknownPlaceholderLiteral(): void
    {
        // Missing key di-leave-as-is supaya QA bisa lihat ada placeholder
        // yang lupa di-pass.
        $out = PushLocalizer::interpolate('Halo {name}, sisa {balance}', ['name' => 'Sari']);
        $this->assertSame('Halo Sari, sisa {balance}', $out);
    }

    public function testInterpolateEmptyTemplate(): void
    {
        $this->assertSame('', PushLocalizer::interpolate('', ['x' => 'y']));
    }

    public function testInterpolateEmptyContext(): void
    {
        $this->assertSame('Static text', PushLocalizer::interpolate('Static text', []));
    }

    public function testInterpolateScalarCoercion(): void
    {
        $out = PushLocalizer::interpolate('Hari ke-{n}, hadir={ok}', [
            'n' => 30,
            'ok' => true,
        ]);
        $this->assertSame('Hari ke-30, hadir=1', $out);
    }

    public function testInterpolateRepeatedPlaceholder(): void
    {
        $out = PushLocalizer::interpolate('{x}, {x}, {x}', ['x' => 'A']);
        $this->assertSame('A, A, A', $out);
    }
}