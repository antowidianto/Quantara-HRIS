<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  ClockIcon, ArrowPathIcon, PlusIcon, ArrowLeftIcon,
  ExclamationTriangleIcon, CheckCircleIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 8 / H1.6 — Overtime Pre-Approval form.
 *
 * Karyawan / supervisor mengajukan PLAN lembur sebelum tanggal kerja.
 * Setelah disetujui dan kerja dilakukan, gunakan endpoint /realize untuk
 * convert PLAN → REALIZED dengan actual_hours.
 *
 * Endpoints:
 *   POST /overtimes/plan                — submit plan (status PLANNED)
 *   POST /overtimes/:id/realize          — realize plan dengan actual hours
 *   GET  /overtimes?status=PLANNED       — list rencana
 *
 * Variance handling: jika actual_hours selisih > 50% dari planned_hours,
 * variance_note wajib diisi.
 */

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const planForm = reactive({
  date: '',
  planned_hours: 1,
  reason: '',
})

const submitting = ref(false)
const myPlanned = ref([])
const realizingId = ref(null)
const realizeForm = reactive({
  actual_hours: 0,
  variance_note: '',
})

async function loadMyPlanned() {
  try {
    const res = await api.get('/overtimes', {
      params: { status: 'PLANNED,APPROVED', mine: 1 },
    })
    myPlanned.value = res.data.data || []
  } catch (_) {
    myPlanned.value = []
  }
}

async function submitPlan() {
  if (! planForm.date) {
    toast.warning('Tanggal lembur wajib')
    return
  }
  if (! planForm.planned_hours || planForm.planned_hours <= 0) {
    toast.warning('Jam lembur harus > 0')
    return
  }
  if (! planForm.reason?.trim() || planForm.reason.length < 10) {
    toast.warning('Alasan minimal 10 karakter (untuk justifikasi approval)')
    return
  }
  submitting.value = true
  try {
    await api.post('/overtimes/plan', planForm)
    toast.success('Rencana lembur terkirim — menunggu approval atasan')
    Object.assign(planForm, { date: '', planned_hours: 1, reason: '' })
    loadMyPlanned()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit')
  } finally {
    submitting.value = false
  }
}

async function realizePlan() {
  if (! realizingId.value) return
  if (! realizeForm.actual_hours || realizeForm.actual_hours <= 0) {
    toast.warning('Actual hours harus > 0')
    return
  }
  // Cek variance > 50%
  const plan = myPlanned.value.find(p => p.id === realizingId.value)
  const planned = Number(plan?.planned_hours || 0)
  const actual = Number(realizeForm.actual_hours)
  const variance = planned > 0 ? Math.abs(actual - planned) / planned : 0
  if (variance > 0.5 && ! realizeForm.variance_note?.trim()) {
    toast.warning('Selisih > 50% — wajib isi catatan variance')
    return
  }
  try {
    await api.post(`/overtimes/${realizingId.value}/realize`, {
      actual_hours: actual,
      variance_note: realizeForm.variance_note,
    })
    toast.success('Lembur direalisasikan — siap diproses payroll')
    realizingId.value = null
    realizeForm.actual_hours = 0
    realizeForm.variance_note = ''
    loadMyPlanned()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal realize')
  }
}

function statusBadge(s) {
  const map = {
    PLANNED:   'bg-blue-100 text-blue-700',
    PENDING:   'bg-amber-100 text-amber-700',
    APPROVED:  'bg-emerald-100 text-emerald-700',
    REJECTED:  'bg-red-100 text-red-700',
    REALIZED:  'bg-purple-100 text-purple-700',
    PAID:      'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-gray-100 text-gray-500',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

const today = new Date().toISOString().slice(0, 10)
const maxDate = (() => {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  return d.toISOString().slice(0, 10)
})()

const realizeVariance = computed(() => {
  if (! realizingId.value) return 0
  const plan = myPlanned.value.find(p => p.id === realizingId.value)
  const planned = Number(plan?.planned_hours || 0)
  const actual = Number(realizeForm.actual_hours || 0)
  if (planned === 0) return 0
  return Math.abs(actual - planned) / planned
})

onMounted(loadMyPlanned)
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 inline-flex items-center gap-1 mb-3"
      @click="router.push('/overtimes')">
      <ArrowLeftIcon class="w-4 h-4" /> Kembali ke list lembur
    </button>

    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClockIcon class="w-6 h-6" /> Rencana Lembur (Pre-Approval)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          PP 35/2021 mensyaratkan lembur direncanakan dulu, baru dikerjakan.
          Submit rencana → atasan approve → kerja → realize dengan actual hours.
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Form submit plan -->
      <div class="card">
        <div class="card-header"><span class="font-medium">Submit Rencana Baru</span></div>
        <div class="card-body space-y-3">
          <div>
            <label class="label">Tanggal Lembur <span class="text-red-500">*</span></label>
            <input type="date" v-model="planForm.date" class="input" :min="today" :max="maxDate" />
            <p class="text-xs text-gray-500 mt-1">Maks 30 hari ke depan</p>
          </div>
          <div>
            <label class="label">Estimasi Jam <span class="text-red-500">*</span></label>
            <input type="number" min="0.5" max="12" step="0.5"
              v-model.number="planForm.planned_hours" class="input" />
            <p class="text-xs text-gray-500 mt-1">PP 35/2021 batas maksimal lembur 4 jam/hari, 18 jam/minggu</p>
          </div>
          <div>
            <label class="label">Alasan / Project <span class="text-red-500">*</span></label>
            <textarea v-model="planForm.reason" class="input" rows="3"
              placeholder="contoh: Closing project Q3, deadline klien hari Senin"></textarea>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-800 flex items-start gap-2">
            <InformationCircleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
            <div>
              Rencana lembur akan diteruskan ke atasan untuk approval.
              Setelah disetujui dan lembur dilakukan, klik "Realize" di tabel di samping
              untuk konfirmasi actual hours sebelum diteruskan ke payroll.
            </div>
          </div>
          <button class="btn-primary w-full" @click="submitPlan" :disabled="submitting">
            <PlusIcon class="w-4 h-4" />
            {{ submitting ? 'Mengirim…' : 'Submit Rencana' }}
          </button>
        </div>
      </div>

      <!-- List planned/approved -->
      <div class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">Rencana Saya</span>
          <button class="btn-icon" @click="loadMyPlanned" title="Refresh">
            <ArrowPathIcon class="w-4 h-4" />
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th class="text-right">Planned</th>
                <th class="text-right">Actual</th>
                <th>Status</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="myPlanned.length === 0">
                <td colspan="5" class="text-center py-8 text-gray-400">
                  Belum ada rencana lembur
                </td>
              </tr>
              <tr v-for="r in myPlanned" :key="r.id">
                <td>{{ r.date }}</td>
                <td class="text-right">{{ r.planned_hours }} jam</td>
                <td class="text-right">
                  <span v-if="r.actual_hours">{{ r.actual_hours }} jam</span>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td>
                  <span class="inline-block px-2 py-0.5 rounded text-xs" :class="statusBadge(r.status)">
                    {{ r.status }}
                  </span>
                </td>
                <td class="text-right">
                  <button v-if="r.status === 'APPROVED'"
                    class="btn-secondary text-xs"
                    @click="realizingId = r.id; realizeForm.actual_hours = r.planned_hours; realizeForm.variance_note = ''">
                    Realize
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal: Realize -->
    <div v-if="realizingId" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="realizingId = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Realisasi Lembur</h3>
        </div>
        <div class="p-5 space-y-3">
          <div class="text-sm text-gray-700">
            Konfirmasi jam aktual lembur yang sudah dikerjakan.
            Variance > 50% dari planned wajib diberi catatan.
          </div>
          <div>
            <label class="label">Actual Hours <span class="text-red-500">*</span></label>
            <input type="number" step="0.5" min="0.5" max="24"
              v-model.number="realizeForm.actual_hours" class="input" />
          </div>
          <div v-if="realizeVariance > 0.5"
            class="bg-amber-50 border border-amber-200 rounded p-2 text-xs text-amber-800 flex items-start gap-1.5">
            <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
            <div>
              Selisih {{ (realizeVariance * 100).toFixed(0) }}% dari rencana.
              Wajib isi catatan variance di bawah.
            </div>
          </div>
          <div>
            <label class="label">
              Catatan Variance
              <span v-if="realizeVariance > 0.5" class="text-red-500">*</span>
              <span v-else class="text-gray-400">(opsional)</span>
            </label>
            <textarea v-model="realizeForm.variance_note" class="input" rows="3"
              placeholder="Jelaskan kenapa actual berbeda jauh dari planned"></textarea>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="realizingId = null">Batal</button>
          <button class="btn-primary" @click="realizePlan">
            <CheckCircleIcon class="w-4 h-4" /> Konfirmasi Realisasi
          </button>
        </div>
      </div>
    </div>
  </div>
</template>