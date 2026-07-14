import { describe, expect, it } from 'vitest'
import { createIdempotencyKey, normalizeApiPath, requiresIdempotencyKey } from '../idempotency'

describe('web idempotency policy', () => {
  it('normalizes absolute and API-prefixed URLs', () => {
    expect(normalizeApiPath('https://hr.test/api/v1/leaves?x=1')).toBe('/leaves')
    expect(normalizeApiPath('/api/v1/payroll-periods/7/finalize')).toBe('/payroll-periods/7/finalize')
  })

  it('requires keys for every state-changing request', () => {
    expect(requiresIdempotencyKey('post', '/leaves')).toBe(true)
    expect(requiresIdempotencyKey('POST', '/approval-requests/bulk-approve')).toBe(true)
    expect(requiresIdempotencyKey('post', '/payroll-periods/7/disburse')).toBe(true)
    expect(requiresIdempotencyKey('post', '/payroll-periods/7/approve')).toBe(true)
    expect(requiresIdempotencyKey('post', '/loans')).toBe(true)
    expect(requiresIdempotencyKey('post', '/bonus-runs/7/mark-paid')).toBe(true)
    expect(requiresIdempotencyKey('post', '/careers/account/offers/7/respond')).toBe(true)
    expect(requiresIdempotencyKey('post', '/compensation-reviews')).toBe(true)
    expect(requiresIdempotencyKey('post', '/compensation-reviews/7/proposals')).toBe(true)
    expect(requiresIdempotencyKey('post', '/compensation-proposals/9/approve')).toBe(true)
    expect(requiresIdempotencyKey('put', '/helpdesk/tickets/7')).toBe(true)
    expect(requiresIdempotencyKey('patch', '/employees/7')).toBe(true)
    expect(requiresIdempotencyKey('delete', '/documents/7')).toBe(true)
    expect(requiresIdempotencyKey('get', '/payroll-periods/7/disburse-status')).toBe(false)
  })

  it('creates a non-empty opaque key', () => {
    expect(createIdempotencyKey().length).toBeGreaterThanOrEqual(8)
  })
})
