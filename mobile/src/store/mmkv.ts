import { createMMKV } from 'react-native-mmkv';

/**
 * MMKV — fast key-value untuk preferensi non-rahasia.
 * Token rahasia tetap di Keychain (lihat secureStorage.ts).
 *
 * Contoh isi:
 *   - last_sync_at         (epoch ms attendance/leaves cache)
 *   - language             ('id' | 'en')
 *   - theme_override       ('light' | 'dark' | 'system')
 *   - notification_prefs   (JSON per kategori)
 */
export const mmkv = createMMKV({ id: 'hris.prefs' });

/** Outbox storage terpisah supaya sweep cepat tanpa load preferensi. */
export const mmkvOutbox = createMMKV({ id: 'hris.outbox' });
