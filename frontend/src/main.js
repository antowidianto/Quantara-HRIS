import { createApp } from 'vue'
import { createPinia } from 'pinia'
import Toast, { POSITION } from 'vue-toastification'
import 'vue-toastification/dist/index.css'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
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