import { describe, expect, it } from 'vitest'
import { findActiveMenuCode } from '../menuRouteMatcher'

const item = (code, routePath) => ({ type: 'ITEM', code, route_path: routePath, children: [] })
const tree = [{
  type: 'SECTION',
  code: 'root',
  children: [
    item('attendance.team', '/attendance'),
    item('attendance.self', '/attendance/my'),
    item('payslip.team', '/payslips'),
    item('payslip.self', '/payslips/my'),
    item('overtime.team', '/overtimes'),
    item('overtime.self', '/overtimes?scope=mine'),
    item('leave.self', '/leaves?scope=mine'),
    item('leave.team', '/leaves?scope=all&status=PENDING'),
    item('pulse.self', '/hr/pulse-survey?tab=respond'),
    item('pulse.manage', '/hr/pulse-survey?tab=manage'),
  ],
}]

describe('sidebar menu route matching', () => {
  it('prefers own attendance over the shorter team route', () => {
    expect(findActiveMenuCode(tree, { path: '/attendance/my', query: {} }))
      .toBe('attendance.self')
  })

  it('keeps detail pages under the most-specific menu', () => {
    expect(findActiveMenuCode(tree, { path: '/payslips/my/100', query: {} }))
      .toBe('payslip.self')
    expect(findActiveMenuCode(tree, { path: '/attendance/100', query: {} }))
      .toBe('attendance.team')
  })

  it('uses query identity when two menus share the same path', () => {
    expect(findActiveMenuCode(tree, { path: '/overtimes', query: { scope: 'mine' } }))
      .toBe('overtime.self')
    expect(findActiveMenuCode(tree, { path: '/overtimes', query: {} }))
      .toBe('overtime.team')
    expect(findActiveMenuCode(tree, { path: '/leaves', query: { scope: 'mine' } }))
      .toBe('leave.self')
    expect(findActiveMenuCode(tree, { path: '/leaves', query: { scope: 'all', status: 'APPROVED' } }))
      .toBe('leave.team')
    expect(findActiveMenuCode(tree, { path: '/hr/pulse-survey', query: { tab: 'manage' } }))
      .toBe('pulse.manage')
  })

  it('returns no active item for an unrelated route', () => {
    expect(findActiveMenuCode(tree, { path: '/dashboard', query: {} })).toBeNull()
  })
})
