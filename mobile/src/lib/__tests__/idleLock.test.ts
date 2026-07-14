import { IdleLock } from '../idleLock';
import { mmkv } from '@store/mmkv';

/**
 * IdleLock test — fokus pada threshold storage + lock/unlock state.
 * AppState listener sengaja tidak di-trigger di sini (kena RN Bridge);
 * itu di-cover di e2e.
 */
describe('IdleLock', () => {
  beforeEach(() => {
    mmkv.remove('idle.threshold_sec');
    mmkv.remove('idle.locked');
    mmkv.remove('idle.last_background_at');
  });

  it('returns default threshold (300s) when not configured', () => {
    expect(IdleLock.getThresholdSec()).toBe(300);
  });

  it('clamps threshold to max 1 hour for security audit guard', () => {
    IdleLock.setThresholdSec(60 * 60 * 24); // 24 jam
    expect(IdleLock.getThresholdSec()).toBe(3600); // capped at 1h
  });

  it('falls back to default for non-positive input', () => {
    IdleLock.setThresholdSec(0);
    expect(IdleLock.getThresholdSec()).toBe(300);
    IdleLock.setThresholdSec(-10);
    expect(IdleLock.getThresholdSec()).toBe(300);
  });

  it('lock + unlock cycles flag correctly', () => {
    expect(IdleLock.isLocked()).toBe(false);
    IdleLock.lock();
    expect(IdleLock.isLocked()).toBe(true);
    IdleLock.unlock();
    expect(IdleLock.isLocked()).toBe(false);
  });

  it('persists custom threshold to mmkv', () => {
    IdleLock.setThresholdSec(600);
    expect(IdleLock.getThresholdSec()).toBe(600);
    expect(mmkv.getString('idle.threshold_sec')).toBe('600');
  });
});
