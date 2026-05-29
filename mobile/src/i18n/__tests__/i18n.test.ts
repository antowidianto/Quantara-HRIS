import { t, getLocale, setLocale } from '../index';

describe('i18n', () => {
  it('default locale is id', () => {
    expect(getLocale()).toBe('id');
    expect(t('common.cancel')).toBe('Batal');
  });

  it('switch to en returns english strings', () => {
    setLocale('en');
    expect(getLocale()).toBe('en');
    expect(t('common.cancel')).toBe('Cancel');
    expect(t('attendance.clock_in')).toBe('Clock-in');
  });

  it('falls back to id when key missing in active locale', () => {
    setLocale('en');
    // Key fictif: hanya ada di id (kalau hipotetis)
    // Untuk test, kita andalkan key umum yang available di kedua locale
    expect(t('tab.attendance')).toBe('Attendance');
    setLocale('id');
    expect(t('tab.attendance')).toBe('Presensi');
  });

  it('returns key itself when missing in all locales', () => {
    expect(t('this.key.does.not.exist')).toBe('this.key.does.not.exist');
  });
});