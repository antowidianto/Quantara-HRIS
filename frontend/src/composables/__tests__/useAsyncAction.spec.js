import { describe, expect, it, vi } from 'vitest'
import { useAsyncActions, useLatestRequest } from '../useAsyncAction'

const deferred = () => {
  let resolve
  let reject
  const promise = new Promise((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}

describe('useAsyncActions', () => {
  it('coalesces double-click mutations into one request', async () => {
    const gate = deferred()
    const action = vi.fn(() => gate.promise)
    const { runAction, isActionPending } = useAsyncActions()

    const first = runAction('finalize', action)
    const second = await runAction('finalize', action)
    expect(second).toEqual({ skipped: true })
    expect(action).toHaveBeenCalledTimes(1)
    expect(isActionPending('finalize')).toBe(true)

    gate.resolve('ok')
    await expect(first).resolves.toEqual({ skipped: false, value: 'ok' })
    expect(isActionPending('finalize')).toBe(false)
  })

  it('releases a failed action so the user can retry', async () => {
    const action = vi.fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce('recovered')
    const { runAction, isActionPending } = useAsyncActions()

    await expect(runAction('finalize', action)).rejects.toThrow('network')
    expect(isActionPending('finalize')).toBe(false)
    await expect(runAction('finalize', action)).resolves.toEqual({
      skipped: false,
      value: 'recovered',
    })
  })
})

describe('useLatestRequest', () => {
  it('ignores a stale response that finishes after a newer response', async () => {
    const oldRequest = deferred()
    const newRequest = deferred()
    const apply = vi.fn()
    const { runLatest } = useLatestRequest()

    const oldRun = runLatest(() => oldRequest.promise, apply)
    const newRun = runLatest(() => newRequest.promise, apply)
    newRequest.resolve('new')
    await newRun
    oldRequest.resolve('old')
    await expect(oldRun).resolves.toEqual({ stale: true })
    expect(apply).toHaveBeenCalledOnce()
    expect(apply).toHaveBeenCalledWith('new')
  })

  it('reports the current request error but suppresses stale errors', async () => {
    const oldRequest = deferred()
    const onError = vi.fn()
    const { runLatest } = useLatestRequest()
    const oldRun = runLatest(() => oldRequest.promise, vi.fn(), onError)
    await runLatest(() => Promise.reject(new Error('current')), vi.fn(), onError)
    oldRequest.reject(new Error('stale'))
    await oldRun

    expect(onError).toHaveBeenCalledOnce()
    expect(onError.mock.calls[0][0].message).toBe('current')
  })
})
