function normalizePath(path) {
  const value = String(path || '').trim()
  if (value === '/') return value
  return value.replace(/\/+$/, '')
}

const IDENTITY_QUERY_KEYS = new Set(['scope', 'tab', 'view', 'mode'])

function collectItems(nodes, output = []) {
  for (const node of nodes || []) {
    if (node?.type === 'ITEM' && node.route_path) output.push(node)
    if (node?.children?.length) collectItems(node.children, output)
  }
  return output
}

function candidateFor(node, route) {
  const [rawTarget, queryString = ''] = String(node.route_path).split('?')
  const target = normalizePath(rawTarget)
  const currentPath = normalizePath(route?.path)
  if (! target.startsWith('/')) return null
  if (currentPath !== target && ! currentPath.startsWith(`${target}/`)) return null

  // Query parameters embedded in a menu URL are part of its identity.
  // This handles same-path menus such as /overtimes and
  // /overtimes?scope=mine without activating both.
  const requiredQuery = [...new URLSearchParams(queryString).entries()]
    .filter(([key]) => IDENTITY_QUERY_KEYS.has(key))
  for (const [key, expected] of requiredQuery) {
    const actual = route?.query?.[key]
    if (Array.isArray(actual) ? ! actual.map(String).includes(expected) : String(actual ?? '') !== expected) {
      return null
    }
  }

  return {
    node,
    pathLength: target.length,
    querySpecificity: requiredQuery.length,
    exact: currentPath === target ? 1 : 0,
  }
}

/**
 * Return the single most-specific sidebar ITEM for the current route.
 * Longest path wins, followed by query-specific and exact matches.
 */
export function findActiveMenuCode(tree, route) {
  const candidates = collectItems(tree)
    .map(node => candidateFor(node, route))
    .filter(Boolean)
    .sort((a, b) =>
      b.pathLength - a.pathLength
      || b.querySpecificity - a.querySpecificity
      || b.exact - a.exact
    )

  return candidates[0]?.node?.code || null
}
