import { api } from '@api/client';

/**
 * Employee Tasks — onboarding & offboarding checklist.
 *
 * Backend `EmployeeTaskController::index` sudah otomatis scope ke task
 * milik karyawan login (atau yang di-assign ke saya) kalau bukan HR.
 *
 * Mobile pakai untuk:
 *   - Karyawan baru: lihat checklist onboarding (assigned to me)
 *   - Buddy/HR yang ditugaskan handle task: lihat & complete
 *   - Karyawan resign: track checklist offboarding-nya
 *
 * Endpoint:
 *   GET   /employee-tasks                   List task (otomatis filter)
 *   POST  /employee-tasks/{id}/complete     Mark task selesai (note opsional)
 */

export type TaskType = 'ONBOARD' | 'OFFBOARD';
export type TaskStatus = 'PENDING' | 'IN_PROGRESS' | 'COMPLETED' | 'SKIPPED' | 'CANCELLED';

export interface EmployeeTask {
  id: number;
  employee_id: number;
  employee_name: string | null;
  type: TaskType;
  title: string;
  description: string | null;
  due_date: string | null;
  status: TaskStatus;
  sort_order: number;
  assignee_user_id: number | null;
  assignee_name: string | null;
  completed_at: string | null;
  created_at: string;
}

export interface PaginatedTasks {
  data: EmployeeTask[];
  total: number;
  page: number;
  per_page: number;
}

export const TasksApi = {
  /**
   * List task. Tanpa filter `assigned_to_me` artinya semua task yang
   * kena ke saya (sebagai employee atau assignee). Pagination ada di
   * backend response — di sini kita unwrap data array supaya UI tetap
   * sederhana.
   */
  async my(opts?: { type?: TaskType; status?: TaskStatus }): Promise<EmployeeTask[]> {
    const parts = ['per_page=100'];
    if (opts?.type) parts.push(`type=${encodeURIComponent(opts.type)}`);
    if (opts?.status) parts.push(`status=${encodeURIComponent(opts.status)}`);
    // Backend response shape: { status, data: [...], pagination: {...} }
    const resp = await api.get<EmployeeTask[] | PaginatedTasks>(`/employee-tasks?${parts.join('&')}`);
    if (Array.isArray(resp)) return resp;
    return (resp as PaginatedTasks).data ?? [];
  },

  complete(id: number, note?: string): Promise<EmployeeTask> {
    return api.post<EmployeeTask>(`/employee-tasks/${id}/complete`, note ? { note } : {});
  },
};