import { describe, expect, it, vi } from 'vitest'
import { resolveNavigation } from '../guard'

const auth = (authenticated = true, allowed = [], isSuperAdmin = false, user = null) => ({
  isAuthenticated: authenticated,
  isSuperAdmin,
  user,
  can: vi.fn(permission => allowed.includes(permission)),
})
const to = (meta, fullPath = '/payroll/periods') => ({ meta, fullPath })

describe('route guard policy', () => {
  it('redirects unauthenticated users and preserves destination', () => {
    expect(resolveNavigation(to({ requiresAuth: true }), auth(false))).toEqual({
      name: 'login', query: { redirect: '/payroll/periods' },
    })
  })

  it('blocks routes when any required permission is missing', () => {
    expect(resolveNavigation(to({ permissions: ['payroll.view', 'payroll.calculate'] }), auth(true, ['payroll.view'])))
      .toEqual({ name: 'dashboard' })
  })

  it('requires at least one permission for permissionAny routes', () => {
    expect(resolveNavigation(
      to({ permissionAny: ['hr_analytics.view', 'compliance.report.view'] }),
      auth(true, ['employee.view']),
    )).toEqual({ name: 'dashboard' })
    expect(resolveNavigation(
      to({ permissionAny: ['hr_analytics.view', 'compliance.report.view'] }),
      auth(true, ['compliance.report.view']),
    )).toBe(true)
  })

  it('blocks super-admin-only routes for tenant users', () => {
    expect(resolveNavigation(to({ superAdminOnly: true }), auth(true))).toEqual({ name: 'dashboard' })
    expect(resolveNavigation(to({ superAdminOnly: true }), auth(true, [], true))).toBe(true)
  })

  it('blocks employee-only routes when the account has no employee identity', () => {
    expect(resolveNavigation(to({ requiresEmployee: true }), auth(true, [], false, {})))
      .toEqual({ name: 'dashboard' })
    expect(resolveNavigation(to({ requiresEmployee: true }), auth(true, [], false, { employee_id: 42 })))
      .toBe(true)
  })

  it('fails closed for disabled or unloaded subscription features', () => {
    expect(resolveNavigation(to({ feature: 'integration.erp' }), auth(), {})).toEqual({
      name: 'dashboard', query: { unavailable: 'integration.erp' },
    })
  })

  it('blocks write screens for suspended tenants', () => {
    expect(resolveNavigation(to({ requiresWritableSubscription: true }), auth(), null, 'SUSPENDED')).toEqual({
      name: 'dashboard', query: { subscription: 'read_only' },
    })
  })

  it('allows a fully authorized active tenant', () => {
    expect(resolveNavigation(
      to({ permission: 'erp.journal.view', feature: 'integration.erp', requiresWritableSubscription: true }),
      auth(true, ['erp.journal.view']),
      { 'integration.erp': { enabled: true } },
      'ACTIVE',
    )).toBe(true)
  })
})
