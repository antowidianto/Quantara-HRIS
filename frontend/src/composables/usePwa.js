/**
 * usePwa — composable untuk manage PWA features:
 *   - Register service worker
 *   - Detect online/offline status
 *   - Detect installable prompt + trigger install
 *   - Listen ke message dari SW (queue flushed, dll)
 *   - Manual flush queue saat user klik retry
 *   - Notify user kalau ada update SW baru
 *
 * Usage di App.vue:
 *   import { usePwa } from '@/composables/usePwa'
 *   const pwa = usePwa()
 *   onMounted(() => pwa.register())
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { createIdempotencyKey } from '@/api/idempotency'

const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const installEvent = ref(null)
const updateAvailable = ref(false)
const queueStatus = ref({ success: 0, failed: 0, remaining: 0, ts: null })
let registration = null

export function usePwa() {
  function register() {
    if (! ('serviceWorker' in navigator)) return Promise.resolve(null)
    if (! import.meta.env.PROD && ! import.meta.env.VITE_PWA_DEV) {
      // SW di-disable di dev kecuali env opt-in
      return Promise.resolve(null)
    }
    return navigator.serviceWorker.register('/sw.js', { scope: '/' })
      .then((reg) => {
        registration = reg
        // Cek update setiap 1 jam
        setInterval(() => reg.update().catch(() => {}), 3600 * 1000)
        // Detect updatefound
        reg.addEventListener('updatefound', () => {
          const newSw = reg.installing
          if (! newSw) return
          newSw.addEventListener('statechange', () => {
            if (newSw.state === 'installed' && navigator.serviceWorker.controller) {
              updateAvailable.value = true
            }
          })
        })
        return reg
      })
      .catch((e) => {
        console.warn('[PWA] SW register failed:', e)
        return null
      })
  }

  function applyUpdate() {
    if (! registration?.waiting) return
    registration.waiting.postMessage({ type: 'skip-waiting' })
    // Reload setelah controller change
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      window.location.reload()
    }, { once: true })
  }

  function flushQueue() {
    if (! navigator.serviceWorker?.controller) return
    navigator.serviceWorker.controller.postMessage({ type: 'flush-queue' })
  }

  // Install prompt
  function onBeforeInstallPrompt(e) {
    e.preventDefault()
    installEvent.value = e
  }

  function promptInstall() {
    if (! installEvent.value) return Promise.resolve(false)
    installEvent.value.prompt()
    return installEvent.value.userChoice.then((c) => {
      installEvent.value = null
      return c.outcome === 'accepted'
    })
  }

  // Online/offline listeners
  function onOnline() {
    isOnline.value = true
    flushQueue() // auto-replay saat online
  }
  function onOffline() { isOnline.value = false }

  // SW message listener
  function onSwMessage(e) {
    if (e.data?.type === 'queue-flushed') {
      queueStatus.value = {
        success: e.data.success,
        failed: e.data.failed,
        remaining: e.data.remaining,
        ts: Date.now(),
      }
    }
  }

  onMounted(() => {
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    if (navigator.serviceWorker) {
      navigator.serviceWorker.addEventListener('message', onSwMessage)
    }
  })
  onUnmounted(() => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    if (navigator.serviceWorker) {
      navigator.serviceWorker.removeEventListener('message', onSwMessage)
    }
  })

  return {
    isOnline,
    updateAvailable,
    canInstall: installEvent,
    queueStatus,
    register,
    applyUpdate,
    flushQueue,
    promptInstall,
  }
}

// ─── Web Push subscription helper ──────────────────────────────────

/**
 * Subscribe ke push notifications, kirim subscription ke backend.
 * VAPID public key disuplai dari backend setting.
 */
export async function subscribePush(vapidPublicKey, postEndpoint) {
  if (! ('serviceWorker' in navigator) || ! ('PushManager' in window)) {
    throw new Error('Browser tidak support push notification')
  }
  const reg = await navigator.serviceWorker.ready
  const existing = await reg.pushManager.getSubscription()
  if (existing) return existing

  const perm = await Notification.requestPermission()
  if (perm !== 'granted') throw new Error('Permission notif ditolak')

  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
  })

  if (postEndpoint) {
    await fetch(postEndpoint, {
      method: 'POST',
      credentials: 'include',
      headers: { 'content-type': 'application/json', 'Idempotency-Key': createIdempotencyKey() },
      body: JSON.stringify(sub.toJSON()),
    })
  }
  return sub
}

export async function unsubscribePush() {
  const reg = await navigator.serviceWorker.ready
  const sub = await reg.pushManager.getSubscription()
  if (! sub) return false
  return sub.unsubscribe()
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const out = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i)
  return out
}
