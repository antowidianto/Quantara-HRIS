import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, toastError } = vi.hoisted(() => ({
  get: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/client', () => ({ default: { get, delete: vi.fn() } }))
vi.mock('vue-toastification', () => ({ useToast: () => ({ error: toastError, success: vi.fn() }) }))
vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn() }))

import { useResource } from '../useResource'

const deferred = () => {
  let resolve
  let reject
  const promise = new Promise((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}

describe('useResource states', () => {
  beforeEach(() => vi.clearAllMocks())

  it('moves through loading, empty, and ready states', async () => {
    const first = deferred()
    get.mockReturnValueOnce(first.promise)
    const resource = useResource('/employees')
    const pending = resource.fetch()
    expect(resource.state.value).toBe('loading')
    first.resolve({ data: { data: [] } })
    await pending
    expect(resource.state.value).toBe('empty')

    get.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } })
    await resource.fetch()
    expect(resource.state.value).toBe('ready')
  })

  it('keeps existing rows as partial data when refresh fails', async () => {
    get.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } })
    const resource = useResource('/employees')
    await resource.fetch()
    get.mockRejectedValueOnce({ response: { data: { message: 'offline' } } })
    await resource.fetch()
    expect(resource.items.value).toEqual([{ id: 1 }])
    expect(resource.state.value).toBe('partial')
    expect(toastError).toHaveBeenCalledWith('offline')
  })

  it('exposes a failure without data and suppresses stale responses', async () => {
    const oldRequest = deferred()
    get.mockReturnValueOnce(oldRequest.promise).mockRejectedValueOnce(new Error('current failure'))
    const resource = useResource('/employees')
    const oldRun = resource.fetch({ search: 'old' })
    await resource.fetch({ search: 'new' })
    oldRequest.resolve({ data: { data: [{ id: 'stale' }] } })
    await expect(oldRun).resolves.toEqual({ stale: true })
    expect(resource.items.value).toEqual([])
    expect(resource.state.value).toBe('failure')
  })
})
