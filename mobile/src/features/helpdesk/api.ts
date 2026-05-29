import { api } from '@api/client';

export interface HelpdeskTicket {
  id: number;
  subject: string;
  category: string;
  priority: 'LOW' | 'NORMAL' | 'HIGH' | 'URGENT';
  status: 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED' | 'REOPENED';
  description: string;
  created_at: string;
  updated_at: string;
  sla_due_at?: string | null;
  comments_count?: number;
}

export interface HelpdeskCreateInput {
  subject: string;
  category: string;
  priority?: HelpdeskTicket['priority'];
  description: string;
}

export const HelpdeskApi = {
  list(): Promise<HelpdeskTicket[]> {
    return api.get<HelpdeskTicket[]>('/helpdesk/tickets?my=1');
  },
  show(id: number): Promise<HelpdeskTicket> {
    return api.get<HelpdeskTicket>(`/helpdesk/tickets/${id}`);
  },
  create(input: HelpdeskCreateInput): Promise<HelpdeskTicket> {
    return api.post<HelpdeskTicket>('/helpdesk/tickets', input);
  },
  addComment(id: number, body: string): Promise<unknown> {
    return api.post(`/helpdesk/tickets/${id}/comments`, { body });
  },
  close(id: number): Promise<unknown> {
    return api.post(`/helpdesk/tickets/${id}/close`);
  },
  reopen(id: number): Promise<unknown> {
    return api.post(`/helpdesk/tickets/${id}/reopen`);
  },
};