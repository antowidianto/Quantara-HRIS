<script setup>
/**
 * S3.4 SaaS — Onboarding Wizard untuk tenant baru.
 *
 * Tampilkan checklist 8 langkah aktivasi tenant. Tiap step bisa
 * di-mark complete via tombol, atau auto-detect dari backend
 * (e.g. setelah upload logo, backend auto mark `logo_uploaded`).
 *
 * Tujuan: tenant tidak ditinggal kebingungan setelah signup.
 * Target conversion ≥ 25% dari trial → paid.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  CheckCircleIcon,
  ArrowRightIcon,
  PhotoIcon,
  ClockIcon,
  UserPlusIcon,
  CalculatorIcon,
  DocumentTextIcon,
  HandRaisedIcon,
  PuzzlePieceIcon,
  BookOpenIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const progress = ref(null)
const loading  = ref(true)
const acting   = ref(null)

// Mapping step_key → metadata FE (icon, label, deeplink)
const STEP_META = {
  logo_uploaded: {
    icon: PhotoIcon,
    title: 'Upload Logo Perusahaan',
    description: 'Brand HRIS dengan logo Anda untuk tampilan email dan portal yang profesional.',
    cta:   'Upload Logo',
    link:  '/settings/branding',
  },
  timezone_set: {
    icon: ClockIcon,
    title: 'Atur Timezone & Currency',
    description: 'Pastikan jam absen dan format Rupiah sesuai dengan operasi Anda.',
    cta:   'Buka Settings',
    link:  '/admin/settings',
  },
  first_employee_invited: {
    icon: UserPlusIcon,
    title: 'Undang Karyawan Pertama',
    description: 'Tambahkan minimal 1 karyawan untuk mulai mencoba modul payroll, absensi, cuti.',
    cta:   'Tambah Karyawan',
    link:  '/employees',
  },
  payroll_period_created: {
    icon: CalculatorIcon,
    title: 'Buat Periode Payroll',
    description: 'Setup periode bulanan untuk hitung gaji + PPh21 + BPJS otomatis.',
    cta:   'Buka Payroll',
    link:  '/payroll/periods',
  },
  first_payslip_generated: {
    icon: DocumentTextIcon,
    title: 'Generate Payslip Pertama',
    description: 'Validasi perhitungan gaji + ekspor slip ke PDF / kirim email otomatis.',
    cta:   'Lihat Payslip',
    link:  '/payroll/periods',
  },
  first_leave_approved: {
    icon: HandRaisedIcon,
    title: 'Approve Cuti Pertama',
    description: 'Test workflow cuti end-to-end (request → approve → balance update).',
    cta:   'Buka Approvals',
    link:  '/approvals/leaves',
  },
  integrations_explored: {
    icon: PuzzlePieceIcon,
    title: 'Eksplorasi Integrasi',
    description: 'Connect WhatsApp, payment gateway, ADMS device, atau export accounting.',
    cta:   'Buka Integrasi',
    link:  '/admin/whatsapp-settings',
  },
  docs_read: {
    icon: BookOpenIcon,
    title: 'Baca Dokumentasi & Tutorial',
    description: 'Pelajari best practice payroll Indonesia (PMK 168, UU PDP, BPJS).',
    cta:   'Buka Docs',
    link:  '/help',
  },
}

const orderedSteps = computed(() => {
  if (! progress.value?.steps) return []
  return progress.value.steps.map(s => ({
    ...s,
    meta: STEP_META[s.step_key] || {
      title: s.step_key,
      description: '',
      cta: 'Buka',
      link: '/',
    },
  }))
})

const completionPercent = computed(() => progress.value?.completion_percent || 0)
const allDone = computed(() => completionPercent.value >= 100)

async function loadProgress() {
  loading.value = true
  try {
    const res = await api.get('/onboarding/progress')
    progress.value = res.data?.data || null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat progress onboarding')
  } finally {
    loading.value = false
  }
}

async function markComplete(stepKey) {
  acting.value = stepKey
  try {
    await api.post(`/onboarding/${encodeURIComponent(stepKey)}/complete`, {})
    toast.success('Step selesai')
    await loadProgress()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mark complete')
  } finally {
    acting.value = null
  }
}

function goToStep(step) {
  if (step.meta.link) {
    router.push(step.meta.link)
  }
}

onMounted(loadProgress)
</script>

<template>
  <div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-bold dark:text-white">Selamat Datang di Quantara HRIS</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
        Selesaikan {{ progress?.total_steps || 8 }} langkah ini untuk aktivasi tenant Anda.
        Estimasi waktu: 15-30 menit.
      </p>
    </div>

    <div v-if="loading" class="text-gray-500 py-12 text-center">Memuat progress…</div>

    <div v-else>
      <!-- Progress bar -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5 mb-6">
        <div class="flex items-center justify-between mb-2">
          <div>
            <div class="text-sm font-medium dark:text-gray-200">
              Progress: {{ progress?.completed || 0 }} / {{ progress?.total_steps || 8 }} step
            </div>
            <div class="text-xs text-gray-500 mt-0.5">
              {{ completionPercent }}% selesai
            </div>
          </div>
          <div v-if="allDone" class="px-3 py-1 text-sm font-semibold bg-emerald-100 text-emerald-800 rounded-full">
            🎉 Tenant Aktif
          </div>
        </div>
        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
          <div
            class="h-full bg-gradient-to-r from-blue-500 to-emerald-500 transition-all"
            :style="{ width: completionPercent + '%' }"
          ></div>
        </div>
      </div>

      <!-- Step list -->
      <div class="space-y-3">
        <div
          v-for="(step, idx) in orderedSteps"
          :key="step.step_key"
          class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 flex items-start gap-4 transition-all"
          :class="step.completed ? 'opacity-60' : 'hover:shadow-md'"
        >
          <!-- Step number / check -->
          <div
            class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center font-semibold"
            :class="step.completed
              ? 'bg-emerald-100 text-emerald-700'
              : 'bg-blue-100 text-blue-700'"
          >
            <CheckCircleIcon v-if="step.completed" class="h-6 w-6" />
            <span v-else>{{ idx + 1 }}</span>
          </div>

          <!-- Icon -->
          <component
            :is="step.meta.icon"
            v-if="step.meta.icon"
            class="h-6 w-6 text-gray-400 dark:text-gray-500 mt-2"
          />

          <!-- Body -->
          <div class="flex-1 min-w-0">
            <div
              class="font-semibold dark:text-white"
              :class="step.completed ? 'line-through' : ''"
            >
              {{ step.meta.title }}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
              {{ step.meta.description }}
            </div>
            <div v-if="step.completed_at" class="text-xs text-emerald-600 mt-1">
              ✓ Selesai pada {{ new Date(step.completed_at).toLocaleString('id-ID') }}
            </div>
          </div>

          <!-- Actions -->
          <div v-if="!step.completed" class="flex items-center gap-2 shrink-0">
            <button
              type="button"
              class="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500 inline-flex items-center gap-1"
              @click="goToStep(step)"
            >
              {{ step.meta.cta }}
              <ArrowRightIcon class="h-4 w-4" />
            </button>
            <button
              type="button"
              class="px-2 py-1.5 text-xs text-gray-500 hover:text-gray-700 disabled:opacity-50"
              :disabled="acting === step.step_key"
              @click="markComplete(step.step_key)"
              title="Tandai selesai manual"
            >
              Skip
            </button>
          </div>
        </div>
      </div>

      <!-- Done state -->
      <div v-if="allDone" class="mt-6 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg p-5 text-center">
        <h3 class="text-lg font-bold text-emerald-800 dark:text-emerald-200">🎉 Selamat, tenant aktif!</h3>
        <p class="text-sm text-emerald-700 dark:text-emerald-300 mt-1">
          Anda sudah selesai setup. Coba upgrade ke plan berbayar sebelum trial habis untuk lock-in harga early adopter.
        </p>
        <router-link
          to="/billing/plan"
          class="mt-3 inline-flex items-center gap-1 px-4 py-2 text-sm font-medium bg-emerald-600 text-white rounded hover:bg-emerald-500"
        >
          Lihat Plan Berbayar <ArrowRightIcon class="h-4 w-4" />
        </router-link>
      </div>
    </div>
  </div>
</template>