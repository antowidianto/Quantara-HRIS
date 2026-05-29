import { api } from '@api/client';

export interface LeaveBalance {
  leave_type_id: number;
  leave_type: string;
  quota: number;
  used: number;
  pending: number;
  available: number;
}

export interface LeaveRecord {
  id: number;
  leave_type: string;
  leave_type_id: number;
  start_date: string;
  end_date: string;
  days: number;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
  reason: string;
  is_half_day?: boolean;
  half_day_part?: 'MORNING' | 'AFTERNOON';
  created_at: string;
}

export interface LeaveType {
  id: number;
  name: string;
  is_paid: boolean;
  max_days?: number | null;
}

export interface LeaveCreateInput {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  reason: string;
  is_half_day?: boolean;
  half_day_part?: 'MORNING' | 'AFTERNOON';
  attachment_path?: string;
}

export const LeaveApi = {
  async my(): Promise<LeaveRecord[]> {
    return api.get<LeaveRecord[]>('/leaves?my=1');
  },
  async balance(employeeId: number): Promise<LeaveBalance[]> {
    return api.get<LeaveBalance[]>(`/leave-balances/${employeeId}`);
  },
  async types(): Promise<LeaveType[]> {
    return api.get<LeaveType[]>('/leave-types/for-me');
  },
  async create(input: LeaveCreateInput): Promise<LeaveRecord> {
    return api.post<LeaveRecord>('/leaves', input);
  },
  async cancel(id: number, reason?: string): Promise<LeaveRecord> {
    return api.post<LeaveRecord>(`/leaves/${id}/cancel`, { reason });
  },
};