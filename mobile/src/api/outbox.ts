import { v4 as uuidv4 } from 'uuid';
import { mmkvOutbox } from '@store/mmkv';
import { apiClient } from './client';

/**
 * Offline outbox — Mobile.
 *
 * Pola: client mobile sering offline saat clock-in di lapangan, naik
 * lift, masuk gudang, dll. Daripada UI bilang "tidak ada signal" lalu
 * data hilang, kita simpan request di MMKV sebagai pending entry,
 * lalu replay saat NetInfo bilang online.
 *
 * Anti-duplikat di sisi server: setiap entry punya `idempotencyKey`
 * yang sudah di-generate di sisi client. Kalau sweep replay 3x karena
 * jaringan flaky, server hanya proses sekali (lihat IdempotencyFilter
 * di backend).
 *
 * Konvensi storage:
 *   - 1 key MMKV per entry, key = `outbox.<id>`
 *   - value = JSON `OutboxEntry` (lihat di bawah)
 *   - Untuk multipart (clock-in selfie), kita simpan path file lokal,
 *     bukan binary di MMKV (MMKV tidak cocok untuk blob besar).
 */

export type OutboxKind = 'clock-in' | 'clock-out' | 'leave-create';

export interface OutboxEntry<TPayload = unknown> {
  id: string;
  kind: OutboxKind;
  /** Endpoint relatif ke baseURL, ex: '/attendance/clock-in' */
  path: string;
  /** Body JSON / form fields. File path disimpan terpisah di selfieUri. */
  payload: TPayload;
  /** Opsional: path file lokal untuk multipart upload. */
  selfieUri?: string;
  idempotencyKey: string;
  createdAt: number;
  attempts: number;
  lastError?: string;
}

const OUTBOX_PREFIX = 'outbox.';

const allKeys = (): string[] =>
  mmkvOutbox.getAllKeys().filter((k: string) => k.startsWith(OUTBOX_PREFIX));

export const Outbox = {
  /** Tulis entry baru. Return idempotencyKey supaya UI bisa show "queued, akan disubmit otomatis". */
  enqueue<T>(kind: OutboxKind, path: string, payload: T, selfieUri?: string): OutboxEntry<T> {
    const entry: OutboxEntry<T> = {
      id: uuidv4(),
      kind,
      path,
      payload,
      selfieUri,
      idempotencyKey: uuidv4(),
      createdAt: Date.now(),
      attempts: 0,
    };
    mmkvOutbox.set(OUTBOX_PREFIX + entry.id, JSON.stringify(entry));
    return entry;
  },

  list(): OutboxEntry[] {
    return allKeys()
      .map((k) => {
        const raw = mmkvOutbox.getString(k);
        return raw ? (JSON.parse(raw) as OutboxEntry) : null;
      })
      .filter((e): e is OutboxEntry => e !== null)
      .sort((a, b) => a.createdAt - b.createdAt);
  },

  count(): number {
    return allKeys().length;
  },

  remove(id: string): void {
    mmkvOutbox.remove(OUTBOX_PREFIX + id);
  },

  /**
   * Coba replay 1 entry. Return true kalau sukses (entry dihapus).
   * Kalau 4xx selain 401/409 — anggap permanent failure, tetap hapus
   * supaya tidak ngeloop. UI sudah punya catatan local.
   */
  async tryReplay(entry: OutboxEntry): Promise<boolean> {
    try {
      if (entry.selfieUri) {
        // Multipart: rebuild FormData dari payload + file path.
        const fd = new FormData();
        for (const [k, v] of Object.entries(entry.payload as Record<string, unknown>)) {
          fd.append(k, v == null ? '' : String(v));
        }
        // RN FormData file shape
        fd.append('selfie', {
          uri: entry.selfieUri,
          type: 'image/jpeg',
          name: 'selfie.jpg',
        } as unknown as Blob);
        await apiClient.post(entry.path, fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
          idempotencyKey: entry.idempotencyKey,
        });
      } else {
        await apiClient.post(entry.path, entry.payload, {
          idempotencyKey: entry.idempotencyKey,
        });
      }
      Outbox.remove(entry.id);
      return true;
    } catch (e: unknown) {
      const updated: OutboxEntry = {
        ...entry,
        attempts: entry.attempts + 1,
        lastError: e instanceof Error ? e.message : String(e),
      };
      mmkvOutbox.set(OUTBOX_PREFIX + entry.id, JSON.stringify(updated));

      // Permanent failure: 4xx (selain 409 in-flight) → drop entry,
      // user akan lihat di history. 5xx / network → keep & retry.
      const status = (e as { httpStatus?: number }).httpStatus ?? 0;
      if (status >= 400 && status < 500 && status !== 409 && status !== 401) {
        Outbox.remove(entry.id);
        return false;
      }
      return false;
    }
  },

  /**
   * Sweep semua entry. Dipanggil saat NetInfo bilang online atau di
   * AppState change ke active. Return jumlah yang sukses replay.
   */
  async sweep(): Promise<{ sent: number; failed: number; remaining: number }> {
    let sent = 0;
    let failed = 0;
    const entries = Outbox.list();
    for (const entry of entries) {
      const ok = await Outbox.tryReplay(entry);
      if (ok) sent++;
      else failed++;
    }
    return { sent, failed, remaining: Outbox.count() };
  },
};
