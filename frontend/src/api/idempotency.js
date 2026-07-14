export function normalizeApiPath(url = '') {
  const withoutOrigin = String(url).replace(/^https?:\/\/[^/]+/i, '')
  return withoutOrigin.replace(/^\/api\/v1/, '').split('?')[0] || '/'
}

export function requiresIdempotencyKey(method, url) {
  const normalizedMethod = String(method || 'get').toUpperCase()
  if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(normalizedMethod)) return false
  // A harmless opaque key on an optional route is preferable to a required
  // route failing after a backend policy change. Normalize URLs here so this
  // contract is consistent for relative and absolute API clients.
  normalizeApiPath(url)
  return true
}

export function createIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  const random = Math.random().toString(36).slice(2)
  return `web-${Date.now().toString(36)}-${random}`
}
