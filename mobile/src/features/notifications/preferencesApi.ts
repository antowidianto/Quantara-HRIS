import { api } from '@api/client';

/**
 * NotificationPreferences — Sprint M6.
 *
 * Mobile self-service untuk per-channel mute. Backend
 * `NotificationPreferenceController::index/update` sudah ada (lihat
 * Routes.php `/notification-preferences`). Mobile cukup wrap.
 *
 * Channels yang valid: `in_app`, `email`, `whatsapp`, `web_push`.
 * Backend tidak akan mengizinkan `in_app` di-disable (silently ignore)
 * — UI mobile honor itu juga (toggle disabled, label "selalu aktif").
 */

export type NotificationChannel = 'in_app' | 'email' | 'whatsapp' | 'web_push';

export interface NotificationTypeMeta {
  key: string;
  label: string;
}

export interface NotificationPreferenceRow {
  notif_type: string;
  channels: Partial<Record<NotificationChannel, boolean>>;
}

export interface QuietHours {
  /** "HH:MM" format atau null kalau DND mati. */
  start: string | null;
  end: string | null;
}

export interface NotificationPreferencesResponse {
  preferences: NotificationPreferenceRow[];
  available_channels: NotificationChannel[];
  available_types: NotificationTypeMeta[];
  /** M7: window quiet hours user. Null jika kolom belum di-migrate. */
  quiet_hours?: QuietHours;
}

export interface PreferenceUpdate {
  notif_type: string;
  channel: NotificationChannel;
  enabled: boolean;
}

export const NotificationPrefsApi = {
  list(): Promise<NotificationPreferencesResponse> {
    return api.get<NotificationPreferencesResponse>('/notification-preferences');
  },

  /** Bulk update — backend menerima array `preferences`. */
  update(items: PreferenceUpdate[]): Promise<{ updated: number }> {
    return api.put<{ updated: number }>('/notification-preferences', {
      preferences: items,
    });
  },

  /** M7 — DND quiet-hours endpoint. */
  getQuietHours(): Promise<QuietHours> {
    return api.get<QuietHours>('/notification-preferences/quiet-hours');
  },
  setQuietHours(start: string | null, end: string | null): Promise<QuietHours> {
    return api.put<QuietHours>('/notification-preferences/quiet-hours', { start, end });
  },
};
