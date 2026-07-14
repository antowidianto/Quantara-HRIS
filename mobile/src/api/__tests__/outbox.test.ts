import { Outbox } from '../outbox';
import { mmkvOutbox } from '@store/mmkv';
import { apiClient } from '../client';

jest.mock('../client', () => ({
  apiClient: { post: jest.fn() },
}));

/**
 * Outbox unit test — Mobile MOB-3.
 *
 * Pure logic test (storage di-mock di jest.setup.js — Map-based MMKV).
 * Tidak menyentuh axios di sini; replay path di-test di integration test
 * terpisah.
 */
describe('Outbox', () => {
  beforeEach(() => {
    // Hapus semua entry sebelum tiap test
    mmkvOutbox.getAllKeys().forEach((k: string) => mmkvOutbox.remove(k));
  });

  it('enqueue adds entry with idempotency key + count increments', () => {
    expect(Outbox.count()).toBe(0);
    const e = Outbox.enqueue('clock-in', '/attendance/clock-in', { latitude: 1, longitude: 2 });
    expect(e.idempotencyKey).toBeTruthy();
    expect(e.attempts).toBe(0);
    expect(Outbox.count()).toBe(1);
  });

  it('list returns entries sorted by createdAt asc', async () => {
    const a = Outbox.enqueue('clock-in', '/attendance/clock-in', { i: 1 });
    // Pastikan timestamp berbeda
    await new Promise((r) => setTimeout(r, 5));
    const b = Outbox.enqueue('clock-out', '/attendance/clock-out', { i: 2 });
    const items = Outbox.list();
    expect(items[0]?.id).toBe(a.id);
    expect(items[1]?.id).toBe(b.id);
  });

  it('remove deletes single entry', () => {
    const e = Outbox.enqueue('leave-create', '/leaves', { type: 'annual' });
    expect(Outbox.count()).toBe(1);
    Outbox.remove(e.id);
    expect(Outbox.count()).toBe(0);
  });

  it('different idempotency keys for different enqueues', () => {
    const a = Outbox.enqueue('clock-in', '/attendance/clock-in', { i: 1 });
    const b = Outbox.enqueue('clock-in', '/attendance/clock-in', { i: 2 });
    expect(a.idempotencyKey).not.toBe(b.idempotencyKey);
  });

  it('replays a network failure with the same key and removes it only after success', async () => {
    const post = apiClient.post as jest.Mock;
    post.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ status: 200 });
    const entry = Outbox.enqueue('clock-out', '/attendance/clock-out', { latitude: -6.2 });

    expect(await Outbox.tryReplay(entry)).toBe(false);
    const queued = Outbox.list()[0];
    expect(queued?.attempts).toBe(1);
    expect(queued?.idempotencyKey).toBe(entry.idempotencyKey);

    expect(await Outbox.tryReplay(queued!)).toBe(true);
    expect(Outbox.count()).toBe(0);
    expect(post).toHaveBeenNthCalledWith(1, entry.path, entry.payload, {
      idempotencyKey: entry.idempotencyKey,
    });
    expect(post).toHaveBeenNthCalledWith(2, entry.path, entry.payload, {
      idempotencyKey: entry.idempotencyKey,
    });
  });
});
