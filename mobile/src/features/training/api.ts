import { api } from '@api/client';

/**
 * Training — Tier 3.
 *
 * Mobile pakai 2 view utama:
 *   - Catalog (POSTED): training yang bisa di-register
 *   - Riwayat saya: registration + status
 *
 * Backend endpoint:
 *   GET  /trainings                           List training (status=POSTED filter)
 *   POST /trainings/{id}/register             Self-register
 *   GET  /trainings/{id}/registrations        Detail registrations (admin/manager)
 *   POST /training-registrations/{id}/cancel  Cancel sendiri
 *
 * Catatan: backend belum punya `/trainings/me/registrations`. Untuk
 * mobile fast-path, kita filter `/trainings?registrant=me` agar API
 * server-side sengaja diberi shortcut. Kalau belum tersedia, fallback
 * ke `/trainings` lalu filter client-side berdasarkan registration_status.
 */

export interface TrainingItem {
  id: number;
  title: string;
  category: string | null;
  trainer: string | null;
  location: string | null;
  start_date: string | null;
  end_date: string | null;
  capacity: number | null;
  registered_count: number;
  status: 'DRAFT' | 'POSTED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
  registration_status?: 'PENDING' | 'APPROVED' | 'REJECTED' | 'COMPLETED' | 'CANCELLED' | null;
  registration_id?: number | null;
}

export interface TrainingCertificate {
  id: number;
  employee_id: number;
  certificate_name: string;
  issuer: string | null;
  issued_on: string | null;
  expires_on: string | null;
}

export const TrainingApi = {
  /** Listing — backend sudah scope ke perusahaan & role-aware. */
  catalog(): Promise<TrainingItem[]> {
    return api.get<TrainingItem[]>('/trainings?status=POSTED');
  },

  /**
   * Riwayat training milik karyawan login. Mobile-only shortcut.
   * Kalau backend belum punya `?registrant=me`, response akan tetap
   * array (mungkin tidak ter-filter). UI handle masing-masing case.
   */
  myRegistrations(): Promise<TrainingItem[]> {
    return api.get<TrainingItem[]>('/trainings?registrant=me');
  },

  register(trainingId: number): Promise<{ id: number; status: string }> {
    return api.post<{ id: number; status: string }>(
      `/trainings/${trainingId}/register`,
      {},
    );
  },

  cancelRegistration(regId: number, reason?: string): Promise<{ id: number; status: string }> {
    return api.post<{ id: number; status: string }>(
      `/training-registrations/${regId}/cancel`,
      reason ? { reason } : {},
    );
  },

  myCertificates(): Promise<TrainingCertificate[]> {
    return api.get<TrainingCertificate[]>('/certificates?mine=1');
  },
};