const CRITICAL_MUTATION_PATHS = [
  /^\/attendance\/clock-(in|out)\/?$/,
  /^\/leaves\/?$/,
  /^\/overtimes\/?$/,
  /^\/reimbursements\/?$/,
  /^\/loans\/?$/,
  /^\/business-trips\/?$/,
  /^\/approval-requests\/\d+\/(approve|reject)\/?$/,
  /^\/approval-requests\/bulk-(approve|reject)\/?$/,
  /^\/payroll-periods\/\d+\/(calculate|finalize|disburse)\/?$/,
  /^\/bonus-runs\/?$/,
  /^\/bonus-runs\/\d+\/(calculate|approve|mark-paid|cancel)\/?$/,
  /^\/bonus-runs\/\d+\/recipients\/include-all\/?$/,
  /^\/careers\/account\/offers\/\d+\/respond\/?$/,
  /^\/offers\/\d+\/respond\/?$/,
]

export function normalizeApiPath(url = '') {
  const withoutOrigin = String(url).replace(/^https?:\/\/[^/]+/i, '')
  return withoutOrigin.replace(/^\/api\/v1/, '').split('?')[0] || '/'
}

export function requiresIdempotencyKey(method, url) {
  const normalizedMethod = String(method || 'get').toUpperCase()
  if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(normalizedMethod)) return false
  const path = normalizeApiPath(url)
  return CRITICAL_MUTATION_PATHS.some((pattern) => pattern.test(path))
}

export function createIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  const random = Math.random().toString(36).slice(2)
  return `web-${Date.now().toString(36)}-${random}`
}
