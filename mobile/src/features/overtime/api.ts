import { api } from '@api/client';

export interface OvertimeRecord {
  id: number;
  work_date: string;
  start_time: string;
  end_time: string;
  hours: number;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED' | 'REALIZED';
  reason: string;
  estimated_amount?: number;
  realized_at?: string | null;
}

export interface OvertimeCreateInput {
  work_date: string;
  start_time: string;
  end_time: string;
  reason: string;
}

export const OvertimeApi = {
  my(): Promise<OvertimeRecord[]> {
    return api.get<OvertimeRecord[]>('/overtimes?my=1');
  },
  create(input: OvertimeCreateInput): Promise<OvertimeRecord> {
    return api.post<OvertimeRecord>('/overtimes', input);
  },
  /**
   * Pre-approval flow (Sprint H1.6) — buat plan dulu, baru realize saat
   * lembur sebenarnya selesai.
   */
  plan(input: OvertimeCreateInput): Promise<OvertimeRecord> {
    return api.post<OvertimeRecord>('/overtimes/plan', input);
  },
  realize(id: number, actualHours: number): Promise<OvertimeRecord> {
    return api.post<OvertimeRecord>(`/overtimes/${id}/realize`, { actual_hours: actualHours });
  },
};