import { compareSemver } from '../versionCheck';

/**
 * Test untuk compareSemver — pure function, no native deps. Yang
 * di-test adalah edge case yang biasanya jadi bug di gate logic
 * (mis. "0.10.0" salah dianggap < "0.2.0" kalau pakai string compare).
 */
describe('compareSemver', () => {
  it('returns 0 when versions equal', () => {
    expect(compareSemver('1.2.3', '1.2.3')).toBe(0);
  });

  it('compares major version first', () => {
    expect(compareSemver('2.0.0', '1.99.99')).toBeGreaterThan(0);
    expect(compareSemver('1.99.99', '2.0.0')).toBeLessThan(0);
  });

  it('compares minor version when major equal', () => {
    expect(compareSemver('1.10.0', '1.2.0')).toBeGreaterThan(0);
    expect(compareSemver('1.2.0', '1.10.0')).toBeLessThan(0);
  });

  it('compares patch when major+minor equal', () => {
    expect(compareSemver('1.2.10', '1.2.2')).toBeGreaterThan(0);
  });

  it('treats missing segments as zero', () => {
    expect(compareSemver('1.0', '1.0.0')).toBe(0);
    expect(compareSemver('2', '2.0.0')).toBe(0);
  });

  it('treats non-numeric segments as zero (not NaN)', () => {
    // "1.alpha.0" → [1, 0, 0]
    expect(compareSemver('1.alpha.0', '1.0.0')).toBe(0);
  });

  it('handles real mobile version case correctly', () => {
    // Skenario: backend min_supported = "0.2.0", current = "0.1.5".
    // Mobile harus force-update (current < min).
    expect(compareSemver('0.1.5', '0.2.0')).toBeLessThan(0);
    // Sebaliknya, kalau current = "0.2.5" >= "0.2.0" → ok.
    expect(compareSemver('0.2.5', '0.2.0')).toBeGreaterThan(0);
  });
});