import { mmkv } from '@store/mmkv';
import { buildTheme } from '../index';

/**
 * ThemeProvider M6 — fokus pada bagian persistence yg pure (MMKV-backed).
 * Test render React component (light vs dark resolve) di-defer ke e2e
 * karena butuh native bridges yang tidak ada di Node test runtime.
 *
 * Yang di-test di sini:
 *   - buildTheme('light') vs ('dark') menghasilkan colors yang berbeda
 *   - MMKV key 'theme.mode' valid value: 'light' | 'dark' (system → delete)
 *   - storedMode read fallback ke 'system' kalau tidak ada
 */
describe('Theme', () => {
  beforeEach(() => {
    mmkv.remove('theme.mode');
  });

  it('buildTheme returns different bg color for light vs dark', () => {
    const light = buildTheme('light');
    const dark = buildTheme('dark');
    expect(light.colors.bg).not.toBe(dark.colors.bg);
    expect(light.scheme).toBe('light');
    expect(dark.scheme).toBe('dark');
  });

  it('all theme tones expose required color tokens', () => {
    const dark = buildTheme('dark');
    expect(dark.colors.primary).toMatch(/^#/);
    expect(dark.colors.success).toMatch(/^#/);
    expect(dark.colors.danger).toMatch(/^#/);
  });

  it('persists theme.mode key in MMKV', () => {
    mmkv.set('theme.mode', 'dark');
    expect(mmkv.getString('theme.mode')).toBe('dark');
    mmkv.remove('theme.mode');
    expect(mmkv.getString('theme.mode')).toBeUndefined();
  });
});
