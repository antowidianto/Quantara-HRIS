import { api } from '@api/client';

export interface ApprovalInboxItem {
  id: number;
  module: string;
  subject: string;
  requester: string;
  requested_at: string;
  detail_url?: string;
  amount?: number | null;
  start_date?: string;
  end_date?: string;
}

export const ApprovalsApi = {
  inbox(): Promise<ApprovalInboxItem[]> {
    return api.get<ApprovalInboxItem[]>('/approval-requests/inbox');
  },
  approve(id: number, note?: string): Promise<unknown> {
    return api.post(`/approval-requests/${id}/approve`, { note });
  },
  reject(id: number, note?: string): Promise<unknown> {
    return api.post(`/approval-requests/${id}/reject`, { note });
  },
};