/**
 * Idempotency is a transport contract: all operations that can change server
 * state carry a key, whether or not the current backend route enforces it.
 */
export const requiresIdempotencyKey = (method?: string): boolean =>
  ['POST', 'PUT', 'PATCH', 'DELETE'].includes((method ?? 'GET').toUpperCase());
