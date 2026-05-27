import { createApp } from 'vue'
import { createPinia } from 'pinia'
import Toast, { POSITION } from 'vue-toastification'
import 'vue-toastification/dist/index.css'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { i18nPlugin } from './locales'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
// i18n — register $t global property dan inject('i18n') untuk composable.
// Ringan (~1 kB), reactive, persist ke localStorage. Lihat src/locales/index.js.
app.use(i18nPlugin)
app.use(Toast, {
  position: POSITION.TOP_RIGHT,
  timeout: 3500,
  hideProgressBar: false,
  closeOnClick: true,
  pauseOnHover: true,
})

// Initialize auth store before router so guards can use it
const auth = useAuthStore()
auth.bootstrap().finally(() => {
  app.use(router)
  app.mount('#app')
})

// ─── PWA Service Worker (H2.1 Sprint 10) ───────────────────────────
// Register sw.js dari /public. SW kasih offline app shell + API GET cache
// + queue mutation saat offline + Web Push. Lihat public/sw.js untuk detail.
// Dilindungi flag env supaya bisa di-disable saat dev/debugging.
if (
  'serviceWorker' in navigator
  && import.meta.env.PROD
  && import.meta.env.VITE_PWA_DISABLED !== '1'
) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .then((reg) => {
        // Auto-flush queue mutation saat browser kembali online
        window.addEventListener('online', () => {
          if (reg.active) reg.active.postMessage({ type: 'flush-queue' })
        })
      })
      .catch((err) => {
        // Silent fail — PWA enhancement, bukan critical path
        console.warn('[PWA] Service worker registration failed:', err)
      })
  })

  // Listen pesan dari SW (mis. queue-flushed setelah online lagi)
  navigator.serviceWorker.addEventListener('message', (event) => {
    const data = event.data || {}
    if (data.type === 'queue-flushed' && data.success > 0) {
      // Show toast via lightweight notification (Toast may not be ready
      // saat SW message datang, jadi pakai console + custom event)
      window.dispatchEvent(new CustomEvent('pwa:queue-flushed', { detail: data }))
    }
  })
}
