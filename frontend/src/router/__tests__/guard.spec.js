import { describe, expect, it, vi } from 'vitest'
import { resolveNavigation } from '../guard'

const auth = (authenticated = true, allowed = []) => ({
  isAuthenticated: authenticated,
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
