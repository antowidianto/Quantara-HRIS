export function resolveNavigation(to, auth, featureMap = null, subscriptionStatus = null) {
  if (to.meta.requiresAuth && ! auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'dashboard' }

  const permissions = Array.isArray(to.meta.permissions)
    ? to.meta.permissions
    : (to.meta.permission ? [to.meta.permission] : [])
  if (permissions.some(permission => ! auth.can(permission))) return { name: 'dashboard' }

  if (to.meta.feature && !featureMap?.[to.meta.feature]?.enabled) {
    return { name: 'dashboard', query: { unavailable: to.meta.feature } }
  }
  if (to.meta.requiresWritableSubscription
      && ['SUSPENDED', 'EXPIRED', 'CANCELLED'].includes(subscriptionStatus)) {
    return { name: 'dashboard', query: { subscription: 'read_only' } }
  }
  return true
}
