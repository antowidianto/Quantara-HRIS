import { api, apiClient } from '@api/client';
import { Outbox } from '@api/outbox';

export interface AttendanceRecord {
  id: number;
  work_date: string;
  clock_in_at: string | null;
  clock_out_at: string | null;
  status: string;
  late_minutes?: number;
  early_leave_minutes?: number;
  selfie_url?: string | null;
}

export interface ClockPayload {
  latitude: number;
  longitude: number;
  accuracy_meters?: number;
  work_location_id?: number | null;
  note?: string;
  client_at: string; // ISO 8601
}

const buildFormData = (payload: ClockPayload, selfieUri: string): FormData => {
  const fd = new FormData();
  fd.append('latitude', String(payload.latitude));
  fd.append('longitude', String(payload.longitude));
  if (payload.accuracy_meters !== undefined) {
    fd.append('accuracy_meters', String(payload.accuracy_meters));
  }
  if (payload.work_location_id) {
    fd.append('work_location_id', String(payload.work_location_id));
  }
  if (payload.note) fd.append('note', payload.note);
  fd.append('client_at', payload.client_at);
  fd.append('selfie', {
    uri: selfieUri,
    type: 'image/jpeg',
    name: 'selfie.jpg',
  } as unknown as Blob);
  return fd;
};

export const AttendanceApi = {
  async my(from?: string, to?: string): Promise<AttendanceRecord[]> {
    const params = new URLSearchParams();
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    const qs = params.toString() ? `?${params.toString()}` : '';
    return api.get<AttendanceRecord[]>(`/attendance/my${qs}`);
  },

  /**
   * Clock-in dengan multipart selfie + GPS. Pakai outbox kalau offline:
   * UI dapat respon "queued" instan, sweep akan submit saat online.
   */
  async clockIn(payload: ClockPayload, selfieUri: string, isOnline: boolean): Promise<{ queued: boolean }> {
    if (!isOnline) {
      Outbox.enqueue('clock-in', '/attendance/clock-in', payload, selfieUri);
      return { queued: true };
    }
    try {
      const fd = buildFormData(payload, selfieUri);
      await apiClient.post('/attendance/clock-in', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return { queued: false };
    } catch (e) {
      // Network/5xx: simpan di outbox supaya retry otomatis
      const err = e as { httpStatus?: number; isNetwork?: boolean };
      if (err.isNetwork || (err.httpStatus !== undefined && err.httpStatus >= 500)) {
        Outbox.enqueue('clock-in', '/attendance/clock-in', payload, selfieUri);
        return { queued: true };
      }
      throw e;
    }
  },

  async clockOut(payload: ClockPayload, selfieUri: string, isOnline: boolean): Promise<{ queued: boolean }> {
    if (!isOnline) {
      Outbox.enqueue('clock-out', '/attendance/clock-out', payload, selfieUri);
      return { queued: true };
    }
    try {
      const fd = buildFormData(payload, selfieUri);
      await apiClient.post('/attendance/clock-out', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return { queued: false };
    } catch (e) {
      const err = e as { httpStatus?: number; isNetwork?: boolean };
      if (err.isNetwork || (err.httpStatus !== undefined && err.httpStatus >= 500)) {
        Outbox.enqueue('clock-out', '/attendance/clock-out', payload, selfieUri);
        return { queued: true };
      }
      throw e;
    }
  },
};