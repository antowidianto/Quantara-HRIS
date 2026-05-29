import { api } from '@api/client';

export interface NotificationItem {
  id: number;
  category: string;
  title: string;
  body: string;
  is_read: boolean;
  created_at: string;
  data?: Record<string, unknown>;
}

export const NotificationsApi = {
  list(): Promise<NotificationItem[]> {
    return api.get<NotificationItem[]>('/notifications');
  },
  unreadCount(): Promise<{ count: number }> {
    return api.get<{ count: number }>('/notifications/unread-count');
  },
  markRead(id: number): Promise<unknown> {
    return api.post(`/notifications/${id}/read`);
  },
  markAllRead(): Promise<unknown> {
    return api.post('/notifications/read-all');
  },
};