import { ref } from 'vue'

/** Prevent duplicate mutations and always release the lock after failures. */
export function useAsyncActions() {
  const pending = ref({})

  function isActionPending(key) {
    return pending.value[key] === true
  }

  async function runAction(key, action) {
    if (isActionPending(key)) return { skipped: true }
    pending.value = { ...pending.value, [key]: true }
    try {
      return { skipped: false, value: await action() }
    } finally {
      const next = { ...pending.value }
      delete next[key]
      pending.value = next
    }
  }

  return { pending, isActionPending, runAction }
}

/** Only the newest request may update visible state. */
export function useLatestRequest() {
  let sequence = 0

  async function runLatest(task, onSuccess, onError = () => {}) {
    const requestId = ++sequence
    try {
      const value = await task()
      if (requestId !== sequence) return { stale: true }
      onSuccess(value)
      return { stale: false, value }
    } catch (error) {
      if (requestId !== sequence) return { stale: true, error }
      onError(error)
      return { stale: false, error }
    }
  }

  function invalidate() {
    sequence += 1
  }

  return { runLatest, invalidate }
}
