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