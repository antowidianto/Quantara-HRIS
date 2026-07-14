import { requiresIdempotencyKey } from '../idempotencyPolicy';

describe('mobile idempotency policy', () => {
  it.each(['post', 'PUT', 'patch', 'DELETE'])('keys %s mutations', (method) => {
    expect(requiresIdempotencyKey(method)).toBe(true);
  });

  it('does not key reads', () => {
    expect(requiresIdempotencyKey('get')).toBe(false);
  });
});
