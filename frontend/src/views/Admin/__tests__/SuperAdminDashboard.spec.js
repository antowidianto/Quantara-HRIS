import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import SuperAdminDashboard from '../SuperAdminDashboard.vue'

const push = vi.fn()
const replace = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push, replace }),
}))

vi.mock('vue-toastification', () => ({
  useToast: () => ({ error: vi.fn() }),
}))

vi.mock('@/api/client', () => ({
  default: { get: vi.fn() },
}))

const overview = {
  status: 'DEGRADED',
  snapshot_at: '2026-07-26T10:00:00+07:00',
  refresh_after_seconds: 30,
  health: {
    database: { ok: true, duration_ms: 2 },
    queue: { ok: false, duration_ms: 4, detail: { pending: 2, failed: 1 } },
  },
  tenants: { total: 4, active: 3, trial: 1, employees_active: 70, users_active: 68, locked_users: 1 },
  workflows: { pending: 6, stuck_24h: 2 },
  payroll: { in_process: 2, failed: 1, pending_approval: 1 },
  security: { failed_logins_1h: 3, critical_audit_24h: 2, rbac_pending: 1 },
  queues: {
    jobs: { PENDING: 2, RUNNING: 0, DONE: 10, FAILED: 1 },
    business_jobs: { RUNNING: 0, SUCCESS: 5, FAILED: 0, STUCK: 0 },
    email: { QUEUED: 1, SENDING: 0, SENT: 10, FAILED: 0 },
    webhooks: { QUEUED: 0, SENDING: 0, DELIVERED: 4, FAILED: 0 },
    approval_outbox: { PENDING: 0, PROCESSING: 0, PUBLISHED: 6, FAILED: 0 },
  },
  business_flows: {
    attendance: { changes_pending: 1, wfh_pending: 1, overtime_pending: 0 },
    leave: { pending: 2 },
    recruitment: { open_jobs: 2, active_candidates: 8, offers_pending: 1 },
    employee_lifecycle: { onboarding_active: 2, resignations_pending: 1, probation_due_30d: 4 },
    service: { tickets_open: 3, tickets_overdue: 1 },
  },
  alerts: [{ severity: 'CRITICAL', title: 'Background job gagal', detail: '1 item', link: '/admin/operations' }],
  tenant_risks: [{ company_id: 2, company_code: 'QNS', company_name: 'PT Quantara', risk_score: 8 }],
  recent_failures: [],
}

describe('SuperAdminDashboard', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    push.mockReset()
    replace.mockReset()
    api.get.mockReset()
    api.get.mockResolvedValue({ data: { data: overview } })
    const auth = useAuthStore()
    auth.role = { id: 1, slug: 'super_admin', name: 'Super Admin' }
    auth.user = { id: 1, name: 'Platform Admin' }
    auth.accessToken = 'token'
  })

  it('renders cross-tenant technical and business monitoring data', async () => {
    const wrapper = mount(SuperAdminDashboard)
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/admin/operations/overview')
    expect(wrapper.text()).toContain('Super Admin Operations')
    expect(wrapper.text()).toContain('DEGRADED')
    expect(wrapper.text()).toContain('Background job gagal')
    expect(wrapper.text()).toContain('Operational Pipelines')
    expect(wrapper.text()).toContain('Business Flow Monitor')
    expect(wrapper.text()).toContain('PT Quantara')

    wrapper.unmount()
  })

  it('redirects non-super-admin users without loading platform metrics', async () => {
    const auth = useAuthStore()
    auth.role = { id: 4, slug: 'employee', name: 'Employee' }
    const wrapper = mount(SuperAdminDashboard)
    await flushPromises()

    expect(replace).toHaveBeenCalledWith('/dashboard')
    expect(api.get).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
