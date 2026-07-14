<script setup>
/**
 * S1.3 SaaS — Self-Service Tenant Signup (publik, no auth).
 *
 * Form pendaftaran tenant baru. Setelah submit:
 *   1. Backend kirim email verifikasi
 *   2. User klik link di email → halaman /verify-email
 *   3. Backend auto-provision tenant (company + user + subscription TRIAL)
 *      dan return JWT untuk auto-login
 *   4. Redirect ke /onboarding wizard
 *
 * Form ini juga capture UTM params untuk attribution analytics.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { createIdempotencyKey } from '@/api/idempotency'
import { useToast } from 'vue-toastification'
import { CheckCircleIcon, EnvelopeIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const route = useRoute()

// State
const submitting = ref(false)
const submitted = ref(false)
const submittedEmail = ref('')

// Form state
const form = reactive({
  email: '',
  company_name: '',
  admin_name: '',
  phone: '',
  password: '',
  password_confirm: '',
  employees_count_estimate: 10,
  industry: '',
  signup_source: 'organic',
  referrer_code: '',
  utm_campaign: '',
  utm_medium: '',
  utm_source: '',
  agreed_terms: false,
})

const errors = ref({})

const passwordStrength = computed(() => {
  const p = form.password
  if (! p) return { label: '', color: '', score: 0 }
  let score = 0
  if (p.length >= 8) score++
  if (p.length >= 12) score++
  if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++
  if (/[0-9]/.test(p)) score++
  if (/[^A-Za-z0-9]/.test(p)) score++
  if (score <= 2) return { label: 'Lemah', color: 'bg-rose-500', score: score * 20 }
  if (score <= 3) return { label: 'Sedang', color: 'bg-amber-500', score: score * 20 }
  return { label: 'Kuat', color: 'bg-emerald-500', score: 100 }
})

const passwordsMatch = computed(() =>
  form.password && form.password_confirm && form.password === form.password_confirm,
)

const canSubmit = computed(() =>
  form.email
    && form.company_name
    && form.password.length >= 8
    && passwordsMatch.value
    && form.agreed_terms
    && !submitting.value,
)

onMounted(() => {
  // Capture UTM params dari URL
  const q = route.query || {}
  if (q.utm_source) form.utm_source = String(q.utm_source).slice(0, 64)
  if (q.utm_medium) form.utm_medium = String(q.utm_medium).slice(0, 64)
  if (q.utm_campaign) form.utm_campaign = String(q.utm_campaign).slice(0, 100)
  if (q.referrer) form.referrer_code = String(q.referrer).slice(0, 64)
  if (q.source) form.signup_source = String(q.source).slice(0, 64)
})

async function handleSubmit() {
  errors.value = {}
  if (! canSubmit.value) {
    if (! passwordsMatch.value) {
      errors.value.password_confirm = 'Password tidak cocok'
    }
    return
  }

  submitting.value = true
  try {
    // Pakai axios langsung (tidak via @/api/client) supaya tidak attach JWT
    // dan tidak stuck di interceptor 401 (signup harus tetap 100% public).
    const baseUrl = import.meta.env.VITE_API_URL || '/api/v1'
    await axios.post(`${baseUrl}/auth/signup`, {
      email: form.email.trim().toLowerCase(),
      company_name: form.company_name.trim(),
      admin_name: form.admin_name.trim() || null,
      phone: form.phone.trim() || null,
      password: form.password,
      password_confirm: form.password_confirm,
      employees_count_estimate: Number(form.employees_count_estimate) || null,
      industry: form.industry.trim() || null,
      signup_source: form.signup_source,
      referrer_code: form.referrer_code || null,
      utm_campaign: form.utm_campaign || null,
      utm_medium: form.utm_medium || null,
      utm_source: form.utm_source || null,
      agreed_terms: true,
    }, { headers: { 'Idempotency-Key': createIdempotencyKey() } })
    submittedEmail.value = form.email
    submitted.value = true
  } catch (e) {
    const code = e.response?.data?.code
    const msg = e.response?.data?.message || 'Signup gagal'
    if (code === 'EMAIL_EXISTS') {
      errors.value.email = msg
    } else if (code === 'RATE_LIMITED') {
      toast.error(msg)
    } else if (e.response?.data?.errors) {
      errors.value = e.response.data.errors
    } else {
      toast.error(msg)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800 flex items-center justify-center p-4">
    <div class="w-full max-w-2xl">
      <div class="bg-white dark:bg-gray-800 shadow-xl rounded-lg p-8">
        <!-- Header -->
        <div class="text-center mb-6">
          <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Quantara HRIS</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            HR & Payroll otomatis untuk perusahaan Indonesia
          </p>
        </div>

        <!-- Submitted state -->
        <div v-if="submitted" class="text-center py-8">
          <div class="mx-auto w-16 h-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mb-4">
            <EnvelopeIcon class="h-8 w-8 text-emerald-600" />
          </div>
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Cek Email Anda</h2>
          <p class="text-gray-600 dark:text-gray-300">
            Kami sudah kirim link verifikasi ke
          </p>
          <p class="text-lg font-semibold text-blue-600 dark:text-blue-400 mt-1">
            {{ submittedEmail }}
          </p>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
            Klik link di email untuk aktivasi tenant Anda. Link berlaku 24 jam.
          </p>
          <p class="text-xs text-gray-400 mt-6">
            Tidak terima email? Cek folder spam, atau
            <button class="text-blue-600 hover:underline" @click="submitted = false">signup ulang</button>.
          </p>
        </div>

        <!-- Form -->
        <form v-else class="space-y-4" @submit.prevent="handleSubmit">
          <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">
            Coba gratis 14 hari — tanpa kartu kredit
          </h2>

          <!-- Company name -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
              Nama Perusahaan *
            </label>
            <input
              v-model="form.company_name"
              type="text"
              required
              maxlength="191"
              placeholder="PT Contoh Indonesia"
              class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="errors.company_name" class="text-xs text-rose-600 mt-1">{{ errors.company_name }}</p>
          </div>

          <!-- Email -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
              Email Admin *
            </label>
            <input
              v-model="form.email"
              type="email"
              required
              maxlength="191"
              placeholder="admin@contoh.co.id"
              class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="errors.email" class="text-xs text-rose-600 mt-1">{{ errors.email }}</p>
          </div>

          <!-- Admin name + Phone -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Nama Anda
              </label>
              <input
                v-model="form.admin_name"
                type="text"
                maxlength="100"
                placeholder="Budi Hartono"
                class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                No. Telepon
              </label>
              <input
                v-model="form.phone"
                type="tel"
                maxlength="32"
                placeholder="0812-xxxx-xxxx"
                class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              />
            </div>
          </div>

          <!-- Industry + Employee count -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Industri
              </label>
              <select
                v-model="form.industry"
                class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              >
                <option value="">Pilih industri…</option>
                <option value="manufacturing">Manufaktur</option>
                <option value="services">Jasa</option>
                <option value="retail">Retail / F&B</option>
                <option value="finance">Keuangan / Fintech</option>
                <option value="technology">Teknologi / IT</option>
                <option value="healthcare">Kesehatan</option>
                <option value="education">Pendidikan</option>
                <option value="construction">Konstruksi</option>
                <option value="logistics">Logistik / Transportasi</option>
                <option value="agriculture">Pertanian / Perkebunan</option>
                <option value="other">Lainnya</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                Jumlah Karyawan (estimasi)
              </label>
              <select
                v-model="form.employees_count_estimate"
                class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              >
                <option :value="10">1-10</option>
                <option :value="25">11-25</option>
                <option :value="50">26-50</option>
                <option :value="100">51-100</option>
                <option :value="250">101-250</option>
                <option :value="500">251-500</option>
                <option :value="1000">500+</option>
              </select>
            </div>
          </div>

          <!-- Password -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
              Password *
            </label>
            <input
              v-model="form.password"
              type="password"
              required
              minlength="8"
              maxlength="200"
              placeholder="Minimal 8 karakter"
              class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
            <div v-if="form.password" class="mt-2">
              <div class="flex items-center gap-2 text-xs">
                <div class="flex-1 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                  <div
                    :class="passwordStrength.color"
                    class="h-full transition-all"
                    :style="{ width: passwordStrength.score + '%' }"
                  />
                </div>
                <span class="text-gray-600 dark:text-gray-400 w-12 text-right">{{ passwordStrength.label }}</span>
              </div>
              <p class="text-xs text-gray-500 mt-1">
                Tip: gunakan kombinasi huruf besar, kecil, angka, simbol untuk password kuat.
              </p>
            </div>
          </div>

          <!-- Password confirm -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
              Konfirmasi Password *
            </label>
            <input
              v-model="form.password_confirm"
              type="password"
              required
              minlength="8"
              maxlength="200"
              placeholder="Ulangi password"
              class="w-full px-3 py-2 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              :class="form.password_confirm && !passwordsMatch ? 'border-rose-500' : ''"
            />
            <p v-if="form.password_confirm && !passwordsMatch" class="text-xs text-rose-600 mt-1">
              Password tidak cocok
            </p>
            <p v-else-if="passwordsMatch" class="text-xs text-emerald-600 mt-1 inline-flex items-center gap-1">
              <CheckCircleIcon class="h-3 w-3" /> Password cocok
            </p>
          </div>

          <!-- Terms -->
          <label class="flex items-start gap-2 mt-4 cursor-pointer">
            <input
              v-model="form.agreed_terms"
              type="checkbox"
              required
              class="mt-1 rounded border-gray-300"
            />
            <span class="text-xs text-gray-600 dark:text-gray-300">
              Saya setuju dengan
              <a href="/terms" target="_blank" class="text-blue-600 hover:underline">Syarat & Ketentuan</a>
              dan
              <a href="/privacy" target="_blank" class="text-blue-600 hover:underline">Kebijakan Privasi</a>
              Quantara, termasuk pemrosesan data sesuai UU PDP RI.
            </span>
          </label>

          <!-- Submit -->
          <button
            type="submit"
            :disabled="!canSubmit"
            class="w-full mt-4 px-4 py-3 text-sm font-semibold bg-blue-600 text-white rounded hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
          >
            {{ submitting ? 'Memproses…' : 'Mulai Trial 14 Hari Gratis' }}
          </button>

          <!-- Footer -->
          <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-4">
            Sudah punya akun?
            <router-link to="/login" class="text-blue-600 hover:underline font-medium">Login</router-link>
          </p>
        </form>
      </div>

      <!-- Trust indicators -->
      <div class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <p>✓ Tanpa kartu kredit · ✓ 14 hari gratis · ✓ Cancel anytime</p>
        <p>PMK 168/2023 · UU PDP · BPJS · JKN compliant by default</p>
      </div>
    </div>
  </div>
</template>
