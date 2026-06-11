<script setup>
/**
 * S3.1 SaaS — White-Label Branding Settings (ENTERPRISE plan).
 *
 * Tenant super_company_admin bisa custom:
 *   - Logo, favicon, login bg
 *   - Primary + secondary color (color picker)
 *   - App name override
 *   - Support email
 *   - Email footer HTML
 *   - Custom domain dengan DNS TXT verification
 *
 * Feature gate: kalau plan bukan ENTERPRISE, tampilkan UpgradeBanner +
 * disable form (read-only mode).
 */
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useFeature } from '@/composables/useFeature'
import UpgradeBanner from '@/components/UpgradeBanner.vue'
import {
  CheckCircleIcon,
  XCircleIcon,
  ArrowPathIcon,
  EyeIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { has } = useFeature()
const branding = ref(null)
const loading = ref(true)
const saving = ref(false)
const verifying = ref(false)

const form = reactive({
  app_name: '',
  primary_color: '#1E40AF',
  secondary_color: '#3B82F6',
  logo_path: '',
  favicon_path: '',
  login_bg_path: '',
  email_footer_html: '',
  support_email: '',
  custom_domain: '',
})

const canEdit = computed(() => has('white_label.enabled'))
const hasCustomDomain = computed(() => !! branding.value?.custom_domain)
const isDomainVerified = computed(() => !! branding.value?.has_custom_domain)

async function loadBranding() {
  loading.value = true
  try {
    const res = await api.get('/companies/me/branding')
    branding.value = res.data?.data || null
    if (branding.value) {
      form.app_name = branding.value.app_name || ''
      form.primary_color = branding.value.primary_color || '#1E40AF'
      form.secondary_color = branding.value.secondary_color || '#3B82F6'
      form.logo_path = branding.value.logo_path || ''
      form.favicon_path = branding.value.favicon_path || ''
      form.login_bg_path = branding.value.login_bg_path || ''
      form.email_footer_html = branding.value.email_footer_html || ''
      form.support_email = branding.value.support_email || ''
      form.custom_domain = branding.value.custom_domain || ''
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat branding')
  } finally {
    loading.value = false
  }
}

onMounted(loadBranding)

async function save() {
  if (! canEdit.value) {
    toast.error('Fitur white-label hanya tersedia di plan ENTERPRISE')
    return
  }
  saving.value = true
  try {
    await api.put('/companies/me/branding', { ...form })
    toast.success('Branding berhasil disimpan')
    await loadBranding()
  } catch (e) {
    const errors = e.response?.data?.errors
    if (errors && typeof errors === 'object') {
      const msg = Object.entries(errors).map(([k, v]) => `${k}: ${v}`).join('; ')
      toast.error(msg)
    } else {
      toast.error(e.response?.data?.message || 'Gagal simpan branding')
    }
  } finally {
    saving.value = false
  }
}

async function verifyDomain() {
  verifying.value = true
  try {
    const res = await api.post('/companies/me/branding/verify-domain')
    toast.success('Custom domain berhasil diverifikasi!')
    await loadBranding()
  } catch (e) {
    const reason = e.response?.data?.data?.reason || e.response?.data?.message || 'Gagal verify'
    if (reason === 'token_mismatch' || reason.includes('token_mismatch')) {
      toast.error('TXT record tidak match. Pastikan sudah pasang dan tunggu DNS propagation (max 24 jam).')
    } else {
      toast.error(reason)
    }
  } finally {
    verifying.value = false
  }
}

const verificationToken = computed(() => {
  // Token tidak di-expose lewat API response untuk keamanan, tapi
  // backend akan auto-generate saat custom_domain diset. User bisa
  // dapatkan token dari endpoint detail kalau perlu.
  return 'Akan tampil setelah custom domain di-set + simpan'
})
</script>

<template>
  <div class="p-6 max-w-5xl mx-auto">
    <h1 class="text-2xl font-bold mb-2 dark:text-white">Branding & White-Label</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
      Custom logo, warna, dan domain untuk pengalaman white-label penuh
    </p>

    <!-- Feature gate banner -->
    <UpgradeBanner
      v-if="!canEdit && !loading"
      feature="white_label.enabled"
      title="White-Labeling membutuhkan plan ENTERPRISE"
      message="Anda tetap bisa lihat preview, tapi perubahan tidak bisa disimpan tanpa upgrade ke ENTERPRISE."
      plan-required="ENTERPRISE"
    />

    <div v-if="loading" class="text-gray-500 dark:text-gray-400 py-12 text-center">
      Memuat branding…
    </div>

    <form v-else class="space-y-6" @submit.prevent="save">
      <!-- Identity section -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-lg font-semibold mb-4 dark:text-white">Identitas</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">App Name</label>
            <input
              v-model="form.app_name"
              type="text"
              maxlength="64"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="Quantara HRIS"
              :disabled="!canEdit"
            />
            <p class="text-xs text-gray-500 mt-1">Tampil di header, email subject, dan tab title (max 64 char)</p>
          </div>

          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Support Email</label>
            <input
              v-model="form.support_email"
              type="email"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="support@perusahaan.com"
              :disabled="!canEdit"
            />
            <p class="text-xs text-gray-500 mt-1">Email kontak support yang ditampilkan di footer</p>
          </div>
        </div>
      </div>

      <!-- Color section -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-lg font-semibold mb-4 dark:text-white">Warna</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Primary Color</label>
            <div class="flex items-center gap-2">
              <input
                v-model="form.primary_color"
                type="color"
                class="h-10 w-16 border rounded cursor-pointer"
                :disabled="!canEdit"
              />
              <input
                v-model="form.primary_color"
                type="text"
                class="flex-1 px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white font-mono text-sm"
                placeholder="#1E40AF"
                :disabled="!canEdit"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Secondary Color</label>
            <div class="flex items-center gap-2">
              <input
                v-model="form.secondary_color"
                type="color"
                class="h-10 w-16 border rounded cursor-pointer"
                :disabled="!canEdit"
              />
              <input
                v-model="form.secondary_color"
                type="text"
                class="flex-1 px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white font-mono text-sm"
                placeholder="#3B82F6"
                :disabled="!canEdit"
              />
            </div>
          </div>
        </div>

        <!-- Live preview -->
        <div class="mt-4 p-4 rounded border-2 border-dashed border-gray-300 dark:border-gray-600">
          <div class="text-xs text-gray-500 mb-2">PREVIEW</div>
          <div class="flex items-center gap-3">
            <div
              class="px-4 py-2 rounded font-medium text-white text-sm"
              :style="{ backgroundColor: form.primary_color }"
            >
              {{ form.app_name || 'Quantara HRIS' }}
            </div>
            <div
              class="px-4 py-2 rounded font-medium text-white text-sm"
              :style="{ backgroundColor: form.secondary_color }"
            >
              Tombol Sekunder
            </div>
          </div>
        </div>
      </div>

      <!-- Asset section -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-lg font-semibold mb-4 dark:text-white">Asset (Logo, Favicon, Background)</h2>
        <p class="text-xs text-gray-500 mb-4">
          Upload file via /upload?context=branding terlebih dahulu, lalu paste URL di bawah.
          Recommended: logo 200×60px PNG, favicon 32×32px ICO, login bg 1920×1080px JPG.
        </p>

        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Logo Path</label>
            <input
              v-model="form.logo_path"
              type="text"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm font-mono"
              placeholder="/uploads/branding/logo.png"
              :disabled="!canEdit"
            />
          </div>
          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Favicon Path</label>
            <input
              v-model="form.favicon_path"
              type="text"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm font-mono"
              placeholder="/uploads/branding/favicon.ico"
              :disabled="!canEdit"
            />
          </div>
          <div>
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Login Background Path</label>
            <input
              v-model="form.login_bg_path"
              type="text"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm font-mono"
              placeholder="/uploads/branding/login-bg.jpg"
              :disabled="!canEdit"
            />
          </div>
        </div>
      </div>

      <!-- Email footer -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-lg font-semibold mb-4 dark:text-white">Footer Email</h2>
        <p class="text-xs text-gray-500 mb-2">HTML yang ditampilkan di footer setiap email outbound (notifikasi, payslip, dll)</p>
        <textarea
          v-model="form.email_footer_html"
          rows="4"
          class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm font-mono"
          placeholder="<p>PT Acme Corp · Jakarta · <a href='https://acme.com'>acme.com</a></p>"
          :disabled="!canEdit"
        ></textarea>
      </div>

      <!-- Custom domain -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-lg font-semibold mb-1 dark:text-white">Custom Domain</h2>
        <p class="text-xs text-gray-500 mb-4">
          Akses HRIS via domain custom Anda (mis. <code class="text-gray-700 dark:text-gray-300">hr.acme.com</code>)
        </p>

        <div class="flex items-end gap-2 flex-wrap">
          <div class="flex-1 min-w-[280px]">
            <label class="block text-sm font-medium dark:text-gray-200 mb-1">Domain</label>
            <input
              v-model="form.custom_domain"
              type="text"
              class="w-full px-3 py-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="hr.perusahaan.com"
              :disabled="!canEdit"
            />
          </div>
          <span
            v-if="hasCustomDomain && isDomainVerified"
            class="px-3 py-2 inline-flex items-center gap-1 text-sm font-medium text-emerald-700 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300 rounded"
          >
            <CheckCircleIcon class="h-4 w-4" />
            Verified
          </span>
          <span
            v-else-if="hasCustomDomain"
            class="px-3 py-2 inline-flex items-center gap-1 text-sm font-medium text-amber-700 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300 rounded"
          >
            <XCircleIcon class="h-4 w-4" />
            Belum Diverifikasi
          </span>
        </div>

        <!-- DNS instruction -->
        <div
          v-if="hasCustomDomain && !isDomainVerified"
          class="mt-4 rounded bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 p-4"
        >
          <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-2">
            Langkah Verifikasi DNS
          </h3>
          <ol class="text-xs text-blue-800 dark:text-blue-200 space-y-1 list-decimal list-inside">
            <li>Login ke DNS provider Anda (Cloudflare, Route53, dll).</li>
            <li>
              Tambah TXT record:
              <div class="my-1 ml-4 font-mono bg-blue-100 dark:bg-blue-900/60 px-2 py-1 rounded">
                Host: <strong>_quantara.{{ form.custom_domain }}</strong><br>
                Value: <em>(token akan tersedia setelah save — cek di detail subscription)</em>
              </div>
            </li>
            <li>Tunggu DNS propagation (5-15 menit, max 24 jam).</li>
            <li>Klik tombol "Verify Domain" di bawah.</li>
          </ol>
          <button
            type="button"
            class="mt-3 inline-flex items-center gap-1 px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-500"
            :disabled="verifying || !canEdit"
            @click="verifyDomain"
          >
            <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': verifying }" />
            {{ verifying ? 'Verifying…' : 'Verify Domain' }}
          </button>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex items-center justify-end gap-2">
        <router-link
          to="/billing/plan"
          class="px-4 py-2 text-sm font-medium border rounded dark:border-gray-600 dark:text-gray-200"
        >
          Kembali ke Billing
        </router-link>
        <button
          type="submit"
          class="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500 disabled:opacity-50"
          :disabled="saving || !canEdit"
        >
          {{ saving ? 'Menyimpan…' : 'Simpan Branding' }}
        </button>
      </div>
    </form>
  </div>
</template>