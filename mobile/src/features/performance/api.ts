import { api } from '@api/client';

/**
 * Performance — read-mostly view di mobile.
 *
 * Mobile employee hanya butuh:
 *   - Lihat goals tahun aktif + progres-nya
 *   - Submit check-in singkat (progress %, note < 280 char)
 *   - Lihat list review yang ditujukan ke saya + acknowledge final review
 *
 * Form panjang (essay self-assessment 500+ chars, peer rating matrix)
 * tetap di web — mobile hanya tampilkan summary + redirect.
 *
 * Backend endpoint:
 *   GET  /performance/my                       Goals + reviews milik saya
 *   POST /goals/{id}/check-in                  Submit check-in goal
 *   POST /performance-reviews/{id}/acknowledge Acknowledge review yang sudah final
 */

export interface PerformanceGoal {
  id: number;
  cycle_id: number;
  cycle_name: string;
  cycle_status: string;
  title: string;
  description: string | null;
  weight: number;
  target: string | null;
  progress: number; // 0–100
  status: 'DRAFT' | 'PENDING' | 'APPROVED' | 'REJECTED' | 'ACHIEVED' | 'MISSED';
  due_date: string | null;
  last_checkin_at: string | null;
}

export interface PerformanceReview {
  id: number;
  cycle_id: number;
  cycle_name: string;
  reviewer_id: number | null;
  status: 'DRAFT' | 'IN_PROGRESS' | 'SUBMITTED' | 'ACKNOWLEDGED' | 'FINALIZED';
  overall_rating: number | null;
  overall_label: string | null;
  acknowledged_at: string | null;
  submitted_at: string | null;
}

export interface PerformanceMyResponse {
  goals: PerformanceGoal[];
  reviews: PerformanceReview[];
}

export interface CheckInInput {
  progress: number; // 0–100
  note?: string;
}

export const PerformanceApi = {
  my(cycleId?: number): Promise<PerformanceMyResponse> {
    const q = cycleId ? `?cycle_id=${cycleId}` : '';
    return api.get<PerformanceMyResponse>(`/performance/my${q}`);
  },

  checkIn(goalId: number, input: CheckInInput): Promise<PerformanceGoal> {
    return api.post<PerformanceGoal>(`/goals/${goalId}/check-in`, input);
  },

  acknowledgeReview(reviewId: number, note?: string): Promise<PerformanceReview> {
    return api.post<PerformanceReview>(
      `/performance-reviews/${reviewId}/acknowledge`,
      note ? { note } : {},
    );
  },
};