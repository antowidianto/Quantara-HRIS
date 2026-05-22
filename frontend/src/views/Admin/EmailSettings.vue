<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import {
  EnvelopeIcon, ServerIcon, ShieldCheckIcon, PaperAirplaneIcon,
  KeyIcon, CheckCircleIcon, XCircleIcon, ClockIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

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
    Object.assign(source, data.data?.source || {})
    queueStats.value = data.data?.queue_stats || { queued: 0, sent: 0, failed: 0 }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat email settings')
  } finally {
    loading.value = false
  }
}

async function save() {
  if (! canManage.value) { toast.error('Tidak punya permission'); return }
  if (! form.host) { toast.error('Host SMTP wajib diisi'); return }
  if (! form.from_address) { toast.error('From address wajib diisi'); return }

  saving.value = true
  try {
    const payload = { ...form }
    // Kalau password kosong dan sudah ada password tersimpan,
    // jangan kirim field password — backend akan pertahankan yang lama.
    if (! payload.password && hasPassword.value) {
      delete payload.password
    }
    await api.put('/email-settings', payload)
    toast.success('Setting email tersimpan')
    form.password = ''
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal simpan')
  } finally {
    saving.value = false
  }
}

async function clearPassword() {
  if (! confirm('Hapus password tersimpan? Email akan pakai password dari .env (kalau ada).')) return
  saving.value = true
  try {
    await api.put('/email-settings', { password: '' })
    toast.success('Password dihapus dari DB; fallback ke .env')
    form.password = ''
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally { saving.value = false }
}

async function sendTest() {
  if (! testTo.value || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testTo.value)) {
    toast.error('Masukkan alamat email tujuan yang valid')
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
    toast.success(data.message || 'Test email berhasil dikirim — cek inbox tujuan')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal kirim test email')
  } finally { testing.value = false }
}

function presetGmail() {
  form.host = 'smtp.gmail.com'
  form.port = 587
  form.encryption = 'tls'
  toast.info('Untuk Gmail, username = email lengkap, password = "App Password" (bukan password Google biasa). Aktifkan 2FA dulu untuk bisa generate App Password.')
}
function presetMailtrap() {
  form.host = 'sandbox.smtp.mailtrap.io'
  form.port = 587
  form.encryption = 'tls'
  toast.info('Mailtrap: ambil credential dari dashboard Mailtrap → Inboxes → SMTP Settings.')
}
function presetSendGrid() {
  form.host = 'smtp.sendgrid.net'
  form.port = 587
  form.encryption = 'tls'
  form.username = 'apikey'
  toast.info('SendGrid: username = literal "apikey", password = SG.xxx... API key.')
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <EnvelopeIcon class="w-6 h-6 text-primary-600" />
          Pengaturan Email (SMTP)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Konfigurasi server SMTP untuk kirim email otomatis: notifikasi approval,
          welcome credential karyawan baru, payslip, dst.
        </p>
      </div>
      <div class="flex items-center gap-3 text-xs">
        <div class="flex items-center gap-1.5">
          <ClockIcon class="w-4 h-4 text-amber-600" />
          <span class="text-gray-600">Antrian: <strong class="text-amber-700">{{ queueStats.queued }}</strong></span>
        </div>
        <div class="flex items-center gap-1.5">
          <CheckCircleIcon class="w-4 h-4 text-emerald-600" />
          <span class="text-gray-600">Terkirim: <strong class="text-emerald-700">{{ queueStats.sent }}</strong></span>
        </div>
        <div class="flex items-center gap-1.5">
          <XCircleIcon class="w-4 h-4 text-red-600" />
          <span class="text-gray-600">Gagal: <strong class="text-red-700">{{ queueStats.failed }}</strong></span>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else-if="! canManage" class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
      Anda tidak punya permission <code>settings.manage</code> untuk mengubah pengaturan email.
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Form SMTP -->
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-6">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <ServerIcon class="w-5 h-5" /> Server SMTP
          </h2>

          <!-- Quick presets -->
          <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-gray-500">Quick preset:</span>
            <button @click="presetGmail" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Gmail</button>
            <button @click="presetSendGrid" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">SendGrid</button>
            <button @click="presetMailtrap" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Mailtrap (testing)</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="md:col-span-2">
              <label class="label">Host SMTP *
                <span class="text-xs text-gray-400 ml-1">({{ source.host }})</span>
              </label>
              <input v-model="form.host" class="input" placeholder="smtp.gmail.com" />
            </div>
            <div>
              <label class="label">Port *
                <span class="text-xs text-gray-400 ml-1">({{ source.port }})</span>
              </label>
              <input v-model.number="form.port" type="number" class="input" placeholder="587" />
              <p class="text-xs text-gray-500 mt-1">587 (TLS) atau 465 (SSL)</p>
            </div>
            <div>
              <label class="label">Enkripsi
                <span class="text-xs text-gray-400 ml-1">({{ source.encryption }})</span>
              </label>
              <select v-model="form.encryption" class="input">
                <option value="tls">TLS (STARTTLS)</option>
                <option value="ssl">SSL (SMTPS)</option>
                <option value="none">Tidak ada (plain)</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="label">Username
                <span class="text-xs text-gray-400 ml-1">({{ source.username }})</span>
              </label>
              <input v-model="form.username" class="input" placeholder="noreply@perusahaan.id" autocomplete="off" />
            </div>
            <div class="md:col-span-2">
              <label class="label flex items-center justify-between">
                <span>Password
                  <span class="text-xs text-gray-400 ml-1">({{ source.password }})</span>
                </span>
                <button v-if="hasPassword" type="button" @click="clearPassword"
                  class="text-xs text-red-600 hover:underline font-normal">
                  Hapus password tersimpan
                </button>
              </label>
              <input v-model="form.password" type="password" class="input"
                :placeholder="hasPassword ? '•••••••• (kosongkan untuk pertahankan)' : 'Password SMTP / App Password'"
                autocomplete="new-password" />
              <p class="text-xs text-gray-500 mt-1 flex items-start gap-1">
                <KeyIcon class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                <span>Password disimpan ter-enkripsi (AES-256-GCM). Untuk Gmail, gunakan
                <a class="text-primary-600 hover:underline" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a>,
                bukan password Google biasa.</span>
              </p>
            </div>
          </div>
        </div>

        <div class="card p-6">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <PaperAirplaneIcon class="w-5 h-5" /> Identitas Pengirim
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="label">From Address *
                <span class="text-xs text-gray-400 ml-1">({{ source.from_address }})</span>
              </label>
              <input v-model="form.from_address" class="input" placeholder="noreply@perusahaan.id" />
            </div>
            <div>
              <label class="label">From Name
                <span class="text-xs text-gray-400 ml-1">({{ source.from_name }})</span>
              </label>
              <input v-model="form.from_name" class="input" placeholder="HRIS Perusahaan" />
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-3 flex items-start gap-1">
            <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5 text-amber-500" />
            <span>From address sebaiknya domain perusahaan dengan SPF/DKIM/DMARC sudah ter-konfigurasi
            supaya tidak masuk spam. Gmail/free SMTP bisa pakai alamat sendiri tapi rate-limit lebih ketat.</span>
          </p>
        </div>

        <div class="flex items-center justify-between gap-3">
          <button class="btn-secondary" @click="load" :disabled="saving">Reset</button>
          <button class="btn-primary" @click="save" :disabled="saving">
            <CheckCircleIcon class="w-4 h-4" />
            {{ saving ? 'Menyimpan…' : 'Simpan Pengaturan' }}
          </button>
        </div>
      </div>

      <!-- Test Email panel -->
      <div class="space-y-4">
        <div class="card p-6 sticky top-4">
          <h2 class="font-semibold mb-3 flex items-center gap-2">
            <ShieldCheckIcon class="w-5 h-5 text-emerald-600" /> Test Konfigurasi
          </h2>
          <p class="text-xs text-gray-600 mb-3">
            Kirim email test ke alamat Anda untuk verify SMTP berfungsi
            <strong>sebelum</strong> menyimpan permanent. Field yang Anda isi
            di kiri akan dipakai saat test (override DB).
          </p>
          <label class="label">Email tujuan</label>
          <input v-model="testTo" type="email" class="input" placeholder="email.anda@example.com" />
          <button class="btn-primary w-full mt-3" @click="sendTest" :disabled="testing">
            <PaperAirplaneIcon class="w-4 h-4" />
            {{ testing ? 'Mengirim…' : 'Kirim Test Email' }}
          </button>

          <div class="mt-5 pt-4 border-t border-gray-200">
            <h3 class="text-sm font-semibold mb-2">Tips troubleshooting</h3>
            <ul class="text-xs text-gray-600 space-y-1.5 list-disc pl-4">
              <li><strong>Connection timeout</strong> — port salah / firewall block. Coba 587 (TLS) atau 465 (SSL).</li>
              <li><strong>Could not authenticate</strong> — username/password salah. Untuk Gmail, pakai App Password.</li>
              <li><strong>Email tidak masuk</strong> — cek folder spam. Setup SPF/DKIM/DMARC untuk domain Anda.</li>
              <li><strong>Rate limit exceeded</strong> — Gmail batasi ~500 email/hari. Untuk volume besar pakai SendGrid/Mailgun/SES.</li>
            </ul>
          </div>
        </div>

        <div class="card p-6">
          <h3 class="text-sm font-semibold mb-2">Antrian Email</h3>
          <p class="text-xs text-gray-600 mb-3">
            Email dikirim oleh worker <code class="text-[11px]">php spark email:process</code>
            yang harus dijadwalkan via cron tiap 1-2 menit.
          </p>
          <div class="space-y-1.5 text-xs">
            <div class="flex items-center justify-between">
              <span class="text-amber-700">⏳ Menunggu</span>
              <strong>{{ queueStats.queued }}</strong>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-emerald-700">✅ Terkirim</span>
              <strong>{{ queueStats.sent }}</strong>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-red-700">❌ Gagal</span>
              <strong>{{ queueStats.failed }}</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>