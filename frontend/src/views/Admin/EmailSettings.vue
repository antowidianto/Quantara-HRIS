<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/locales'
import {
  EnvelopeIcon, ServerIcon, ShieldCheckIcon, PaperAirplaneIcon,
  KeyIcon, CheckCircleIcon, XCircleIcon, ClockIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()

// Halaman Admin → Email Settings
//
// HR / IT bisa konfigurasi SMTP server lewat UI ini sebagai ganti edit
// .env. Setting di-store per-company di tabel `settings` (key prefix
// `email.*`); password di-encrypt at-rest pakai AES-256-GCM dan tidak
// pernah dikembalikan plain text ke frontend (cuma `has_password` flag).
//
// Untuk verify konfigurasi sebelum save, ada tombol "Kirim Test Email"
// yang akan kirim email test ke alamat tertentu pakai konfigurasi yang
// SEDANG di-edit (override DB), bukan setting yang sudah save.

const toast = useToast()
const auth = useAuthStore()

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const testTo = ref('')
const queueStats = ref({ queued: 0, sent: 0, failed: 0 })
const isMailpit = ref(false)
const mailpitUrl = import.meta.env.VITE_MAILPIT_URL || 'http://localhost:8025'

const form = reactive({
  host: '',
  port: 587,
  username: '',
  password: '',           // empty = pertahankan password lama
  encryption: 'tls',
  from_address: '',
  from_name: '',
})
const hasPassword = ref(false)
const source = reactive({
  host: 'env', port: 'env', username: 'env', encryption: 'env',
  from_address: 'env', from_name: 'env', password: 'env',
})

const canManage = computed(() => auth.isSuperAdmin || auth.can('settings.manage'))

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/email-settings')
    const cfg = data.data?.config || {}
    Object.assign(form, {
      host: cfg.host || '',
      port: cfg.port || 587,
      username: cfg.username || '',
      password: '',
      encryption: cfg.encryption || 'tls',
      from_address: cfg.from_address || '',
      from_name: cfg.from_name || '',
    })
    hasPassword.value = ! ! cfg.has_password
    isMailpit.value = ! ! cfg.is_mailpit
    Object.assign(source, data.data?.source || {})
    queueStats.value = data.data?.queue_stats || { queued: 0, sent: 0, failed: 0 }
  } catch (e) {
    toast.error(e.response?.data?.message || t('email_settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  if (! canManage.value) { toast.error(t('common.no_permission')); return }
  if (! form.host) { toast.error(t('email_settings.hostRequired')); return }
  if (! form.from_address) { toast.error(t('email_settings.fromAddressRequired')); return }

  saving.value = true
  try {
    const payload = { ...form }
    // Kalau password kosong dan sudah ada password tersimpan,
    // jangan kirim field password — backend akan pertahankan yang lama.
    if (! payload.password && hasPassword.value) {
      delete payload.password
    }
    await api.put('/email-settings', payload)
    toast.success(t('email_settings.saved'))
    form.password = ''
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

async function clearPassword() {
  if (! confirm(t('email_settings.confirmDeletePassword'))) return
  saving.value = true
  try {
    await api.put('/email-settings', { password: '' })
    toast.success(t('email_settings.passwordDeleted'))
    form.password = ''
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  } finally { saving.value = false }
}

async function sendTest() {
  if (! testTo.value || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testTo.value)) {
    toast.error(t('email_settings.invalidEmail'))
    return
  }
  testing.value = true
  try {
    // Pakai override config sehingga test pakai field yang sedang di-edit
    // (belum di-save). Kalau password field kosong → backend akan pakai
    // DB / env fallback (kalau ada).
    const override = { ...form }
    if (! override.password) delete override.password
    const { data } = await api.post('/email-settings/test', {
      to: testTo.value,
      override,
    })
    toast.success(data.message || t('email_settings.testSent'))
  } catch (e) {
    toast.error(e.response?.data?.message || t('email_settings.testFailed'))
  } finally { testing.value = false }
}

function presetGmail() {
  form.host = 'smtp.gmail.com'
  form.port = 587
  form.encryption = 'tls'
  toast.info(t('email_settings.gmailPresetInfo'))
}
function presetMailtrap() {
  form.host = 'sandbox.smtp.mailtrap.io'
  form.port = 587
  form.encryption = 'tls'
  toast.info(t('email_settings.mailtrapPresetInfo'))
}
function presetMailpit() {
  form.host = 'localhost'
  form.port = 1025
  form.username = ''
  form.password = ''
  form.encryption = 'none'
  if (! form.from_address) form.from_address = 'noreply@hris.local'
  if (! form.from_name) form.from_name = 'HRIS Dev'
  toast.info(t('email_settings.mailpitPresetInfo'))
}
function presetSendGrid() {
  form.host = 'smtp.sendgrid.net'
  form.port = 587
  form.encryption = 'tls'
  form.username = 'apikey'
  toast.info(t('email_settings.sendgridPresetInfo'))
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <EnvelopeIcon class="w-6 h-6 text-primary-600" />
          {{ t('email_settings.title') }}
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ t('email_settings.subtitle') }}
        </p>
      </div>
      <div class="flex items-center gap-3 text-xs">
        <div class="flex items-center gap-1.5">
          <ClockIcon class="w-4 h-4 text-amber-600" />
          <span class="text-gray-600">{{ t('email_settings.queue.queued') }}: <strong class="text-amber-700">{{ queueStats.queued }}</strong></span>
        </div>
        <div class="flex items-center gap-1.5">
          <CheckCircleIcon class="w-4 h-4 text-emerald-600" />
          <span class="text-gray-600">{{ t('email_settings.queue.sent') }}: <strong class="text-emerald-700">{{ queueStats.sent }}</strong></span>
        </div>
        <div class="flex items-center gap-1.5">
          <XCircleIcon class="w-4 h-4 text-red-600" />
          <span class="text-gray-600">{{ t('email_settings.queue.failed') }}: <strong class="text-red-700">{{ queueStats.failed }}</strong></span>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">{{ t('common.loading') }}</div>
    <div v-else-if="! canManage" class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
      {{ t('email_settings.noPermission') }}
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Form SMTP -->
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-6">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <ServerIcon class="w-5 h-5" /> {{ t('email_settings.server.title') }}
          </h2>

          <div v-if="isMailpit" class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
            <div class="font-semibold">{{ t('email_settings.mailpitActiveTitle') }}</div>
            <div class="mt-1">{{ t('email_settings.mailpitActiveHelp') }}</div>
            <a :href="mailpitUrl" target="_blank" rel="noopener"
              class="mt-2 inline-flex font-medium text-sky-700 hover:underline">
              {{ t('email_settings.openMailpit') }} ↗
            </a>
          </div>

          <!-- Quick presets -->
          <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-gray-500">Quick preset:</span>
            <button @click="presetGmail" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Gmail</button>
            <button @click="presetSendGrid" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">SendGrid</button>
            <button @click="presetMailtrap" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Mailtrap</button>
            <button @click="presetMailpit" class="px-3 py-1 border border-sky-300 text-sky-700 rounded hover:bg-sky-50">Mailpit (local)</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="md:col-span-2">
              <label class="label">{{ t('email_settings.server.host') }} *
                <span class="text-xs text-gray-400 ml-1">({{ source.host }})</span>
              </label>
              <input v-model="form.host" class="input" placeholder="smtp.gmail.com" />
            </div>
            <div>
              <label class="label">{{ t('email_settings.server.port') }} *
                <span class="text-xs text-gray-400 ml-1">({{ source.port }})</span>
              </label>
              <input v-model.number="form.port" type="number" class="input" placeholder="587" />
              <p class="text-xs text-gray-500 mt-1">{{ t('email_settings.server.portHelp') }}</p>
            </div>
            <div>
              <label class="label">{{ t('email_settings.server.encryption') }}
                <span class="text-xs text-gray-400 ml-1">({{ source.encryption }})</span>
              </label>
              <select v-model="form.encryption" class="input">
                <option value="tls">TLS (STARTTLS)</option>
                <option value="ssl">SSL (SMTPS)</option>
                <option value="none">{{ t('email_settings.server.none') }} (plain)</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="label">{{ t('email_settings.server.username') }}
                <span class="text-xs text-gray-400 ml-1">({{ source.username }})</span>
              </label>
              <input v-model="form.username" class="input" placeholder="noreply@perusahaan.id" autocomplete="off" />
            </div>
            <div class="md:col-span-2">
              <label class="label flex items-center justify-between">
                <span>{{ t('email_settings.server.password') }}
                  <span class="text-xs text-gray-400 ml-1">({{ source.password }})</span>
                </span>
                <button v-if="hasPassword" type="button" @click="clearPassword"
                  class="text-xs text-red-600 hover:underline font-normal">
                  {{ t('email_settings.server.clearPassword') }}
                </button>
              </label>
              <input v-model="form.password" type="password" class="input"
                :placeholder="hasPassword ? '•••••••• ' + t('email_settings.server.placeholderKeep') : t('email_settings.server.placeholderDefault')"
                autocomplete="new-password" />
              <p class="text-xs text-gray-500 mt-1 flex items-start gap-1">
                <KeyIcon class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                <span>{{ t('email_settings.server.passwordHelp') }}
                <a class="text-primary-600 hover:underline" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a>,
                {{ t('email_settings.server.passwordHelpSuffix') }}</span>
              </p>
            </div>
          </div>
        </div>

        <div class="card p-6">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <PaperAirplaneIcon class="w-5 h-5" /> {{ t('email_settings.identity.title') }}
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('email_settings.identity.fromAddress') }} *
                <span class="text-xs text-gray-400 ml-1">({{ source.from_address }})</span>
              </label>
              <input v-model="form.from_address" class="input" placeholder="noreply@perusahaan.id" />
            </div>
            <div>
              <label class="label">{{ t('email_settings.identity.fromName') }}
                <span class="text-xs text-gray-400 ml-1">({{ source.from_name }})</span>
              </label>
              <input v-model="form.from_name" class="input" placeholder="HRIS Perusahaan" />
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-3 flex items-start gap-1">
            <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5 text-amber-500" />
            <span>{{ t('email_settings.identity.help') }}</span>
          </p>
        </div>

        <div class="flex items-center justify-between gap-3">
          <button class="btn-secondary" @click="load" :disabled="saving">{{ t('common.reset') }}</button>
          <button class="btn-primary" @click="save" :disabled="saving">
            <CheckCircleIcon class="w-4 h-4" />
            {{ saving ? t('common.saving') : t('email_settings.saveSettings') }}
          </button>
        </div>
      </div>

      <!-- Test Email panel -->
      <div class="space-y-4">
        <div class="card p-6 sticky top-4">
          <h2 class="font-semibold mb-3 flex items-center gap-2">
            <ShieldCheckIcon class="w-5 h-5 text-emerald-600" /> {{ t('email_settings.test.title') }}
          </h2>
          <p class="text-xs text-gray-600 mb-3">
            {{ t('email_settings.test.help') }}
          </p>
          <label class="label">{{ t('email_settings.test.targetEmail') }}</label>
          <input v-model="testTo" type="email" class="input" placeholder="email.anda@example.com" />
          <button class="btn-primary w-full mt-3" @click="sendTest" :disabled="testing">
            <PaperAirplaneIcon class="w-4 h-4" />
            {{ testing ? t('common.sending') : t('email_settings.test.sendButton') }}
          </button>

          <div class="mt-5 pt-4 border-t border-gray-200">
            <h3 class="text-sm font-semibold mb-2">{{ t('email_settings.troubleshoot.title') }}</h3>
            <ul class="text-xs text-gray-600 space-y-1.5 list-disc pl-4">
              <li><strong>Connection timeout</strong> — {{ t('email_settings.troubleshoot.timeout') }}</li>
              <li><strong>Could not authenticate</strong> — {{ t('email_settings.troubleshoot.auth') }}</li>
              <li><strong>Email tidak masuk</strong> — {{ t('email_settings.troubleshoot.spam') }}</li>
              <li><strong>Rate limit exceeded</strong> — {{ t('email_settings.troubleshoot.rateLimit') }}</li>
            </ul>
          </div>
        </div>

        <div class="card p-6">
          <h3 class="text-sm font-semibold mb-2">{{ t('email_settings.queue.title') }}</h3>
          <p class="text-xs text-gray-600 mb-3">
            {{ t('email_settings.queue.help') }}
          </p>
          <div class="space-y-1.5 text-xs">
            <div class="flex items-center justify-between">
              <span class="text-amber-700">⏳ {{ t('email_settings.queue.statusQueued') }}</span>
              <strong>{{ queueStats.queued }}</strong>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-emerald-700">✅ {{ t('email_settings.queue.statusSent') }}</span>
              <strong>{{ queueStats.sent }}</strong>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-red-700">❌ {{ t('email_settings.queue.statusFailed') }}</span>
              <strong>{{ queueStats.failed }}</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
