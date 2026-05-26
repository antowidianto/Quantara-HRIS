<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ClockIcon, ExclamationTriangleIcon, CheckCircleIcon,
  XCircleIcon, ArrowsRightLeftIcon, UserIcon,
} from '@heroicons/vue/24/outline'

/**
 * ProbationDashboard — HR menu untuk monitoring & decision
 * masa percobaan PKWTT (Sprint 2 / HIRE-1).
 *
 * Tab:
 *   - Akan habis: probation_end_date dalam 14 hari ke depan
 *   - Sudah lewat: probation_end_date sudah lewat tapi belum diputuskan
 *
 * Aksi: PASS (jadi tetap), FAIL (resign), EXTEND (max 1x extension).
 *
 * Backend gate sesuai UU 13/2003 Pasal 60: probation max 3 bulan,
 * lewat tanpa keputusan otomatis jadi karyawan tetap.
 */

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const tab = ref('expiring')        // 'expiring' | 'expired'
const expiring = ref([])
const expired = ref([])
const loading = ref(false)

// Decision modal
const showDecisionModal = ref(null) // employee row
const decisionForm = reactive({
  decision: 'PASS',     // PASS | FAIL | EXTEND
  note: '',
  extend_to: '',        // tanggal baru kalau EXTEND
})

const canManage = computed(
  () => auth.isSuperAdmin || auth.can('employee.update'),
)

async function fetchAll() {
  loading.value = true
  try {
    const [a, b] = await Promise.all([
      api.get('/probation/expiring'),
      api.get('/probation/expired'),
    ])
    expiring.value = a.data.data || []
    expired.value = b.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data probation')
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)

function openDecisionModal(emp, defaultDecision = 'PASS') {
  showDecisionModal.value = emp
  Object.assign(decisionForm, {
    decision: defaultDecision,
    note: '',
    extend_to: '',
  })
}

async function submitDecision() {
  if (! showDecisionModal.value) return
  const emp = showDecisionModal.value

  if (decisionForm.note.trim().length < 10) {
    toast.error('Catatan keputusan minimum 10 karakter (untuk audit trail)')
    return
  }
  if (decisionForm.decision === 'EXTEND' && ! decisionForm.extend_to) {
    toast.error('Tanggal extension wajib diisi')
    return
  }

  // Konfirmasi tambahan untuk FAIL — ini akan trigger resign_date set
  if (decisionForm.decision === 'FAIL') {
    const ok = await confirmDialog({
      title: 'Konfirmasi probation FAIL',
      message: `${emp.full_name} akan ditandai probation FAIL.\n\n`
        + 'Sistem otomatis akan:\n'
        + '• Set resign_date = hari ini\n'
        + '• Trigger offboarding tasks\n'
        + '• Auto-create MPP DRAFT untuk pengganti\n\n'
        + 'Lanjutkan?',
      okText: 'Ya, FAIL',
      variant: 'danger',
    })
    if (! ok) return
  }

  try {
    const payload = {
      decision: decisionForm.decision,
      note: decisionForm.note.trim(),
    }
    if (decisionForm.decision === 'EXTEND') {
      payload.extend_to = decisionForm.extend_to
    }
    await api.post(`/employees/${emp.id}/probation/decide`, payload)
    toast.success(`Keputusan probation ${decisionForm.decision} untuk ${emp.full_name} tercatat`)
    showDecisionModal.value = null
    fetchAll()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit keputusan')
  }
}

function daysUntilLabel(emp) {
  if (! emp.probation_end_date) return '-'
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const end = new Date(emp.probation_end_date)
  end.setHours(0, 0, 0, 0)
  const days = Math.round((end.getTime() - today.getTime()) / 86400000)
  if (days < 0) return `${Math.abs(days)} hari lewat`
  if (days === 0) return 'Hari ini'
  return `${days} hari lagi`
}

function urgencyClass(emp) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const end = new Date(emp.probation_end_date || today)
  end.setHours(0, 0, 0, 0)
  const days = Math.round((end.getTime() - today.getTime()) / 86400000)
  if (days < 0) return 'bg-rose-50 border-rose-300 text-rose-900'
  if (days <= 7) return 'bg-amber-50 border-amber-300 text-amber-900'
  return 'bg-blue-50 border-blue-200 text-blue-900'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClockIcon class="w-6 h-6 text-amber-600" />
          Probation Tracker
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Monitoring masa percobaan karyawan PKWTT — UU 13/2003 Pasal 60 (max 3 bulan).
          Lewat tanpa keputusan otomatis jadi tetap.
        </p>
      </div>
      <button class="btn-secondary text-xs" @click="fetchAll">Refresh</button>
    </div>

    <!-- Quick stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Total Akan Habis</div>
        <div class="text-2xl font-bold text-amber-700">{{ expiring.length }}</div>
        <div class="text-xs text-gray-500 mt-0.5">Probation berakhir dalam 14 hari ke depan</div>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Sudah Lewat & Belum Diputuskan</div>
        <div class="text-2xl font-bold text-rose-700">{{ expired.length }}</div>
        <div class="text-xs text-gray-500 mt-0.5">
          ⚠️ Auto-PASS sesuai UU kalau tidak diputuskan segera
        </div>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Total Aktif</div>
        <div class="text-2xl font-bold text-blue-700">{{ expiring.length + expired.length }}</div>
        <div class="text-xs text-gray-500 mt-0.5">Karyawan dalam masa probation</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-3 border-b border-gray-200">
      <button
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
          tab === 'expiring' ? 'border-amber-500 text-amber-700' : 'border-transparent text-gray-500 hover:text-gray-700',
        ]"
        @click="tab = 'expiring'">
        Akan Habis ({{ expiring.length }})
      </button>
      <button
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
          tab === 'expired' ? 'border-rose-500 text-rose-700' : 'border-transparent text-gray-500 hover:text-gray-700',
        ]"
        @click="tab = 'expired'">
        Sudah Lewat ({{ expired.length }})
      </button>
    </div>

    <!-- List -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else>
      <div v-if="tab === 'expiring' && expiring.length === 0" class="text-center py-12 text-gray-400 italic">
        Tidak ada karyawan dengan probation berakhir dalam 14 hari ke depan.
      </div>
      <div v-if="tab === 'expired' && expired.length === 0" class="text-center py-12 text-gray-400 italic">
        Tidak ada probation overdue. Bagus.
      </div>

      <div v-if="tab === 'expiring' && expiring.length > 0" class="space-y-2">
        <div v-for="emp in expiring" :key="emp.id"
          :class="['rounded-lg border p-4 flex items-start gap-3 flex-wrap', urgencyClass(emp)]">
          <UserIcon class="w-8 h-8 flex-shrink-0 opacity-50" />
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <strong class="text-base">{{ emp.full_name }}</strong>
              <span class="text-xs font-mono text-gray-600">{{ emp.employee_number }}</span>
            </div>
            <div class="text-sm mt-0.5">
              {{ emp.position_name || '-' }} · {{ emp.department_name || '-' }}
            </div>
            <div class="text-sm mt-1">
              <ClockIcon class="w-4 h-4 inline mr-1" />
              Probation berakhir
              <strong>{{ fmtDate(emp.probation_end_date) }}</strong>
              <span class="ml-2">({{ daysUntilLabel(emp) }})</span>
            </div>
            <div class="text-xs text-gray-600 mt-1">
              Bergabung: {{ fmtDate(emp.join_date) }}
              <span v-if="emp.supervisor_name" class="ml-2">· Supervisor: {{ emp.supervisor_name }}</span>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 sm:flex-nowrap" v-if="canManage">
            <button class="text-xs px-3 py-1.5 rounded bg-emerald-600 text-white hover:bg-emerald-700 flex items-center gap-1"
              @click="openDecisionModal(emp, 'PASS')">
              <CheckCircleIcon class="w-3.5 h-3.5" /> PASS
            </button>
            <button class="text-xs px-3 py-1.5 rounded bg-amber-600 text-white hover:bg-amber-700 flex items-center gap-1"
              @click="openDecisionModal(emp, 'EXTEND')">
              <ArrowsRightLeftIcon class="w-3.5 h-3.5" /> Extend
            </button>
            <button class="text-xs px-3 py-1.5 rounded bg-rose-600 text-white hover:bg-rose-700 flex items-center gap-1"
              @click="openDecisionModal(emp, 'FAIL')">
              <XCircleIcon class="w-3.5 h-3.5" /> FAIL
            </button>
          </div>
        </div>
      </div>

      <div v-if="tab === 'expired' && expired.length > 0" class="space-y-2">
        <div class="bg-rose-50 border border-rose-200 rounded p-3 text-sm text-rose-900 mb-3 flex items-start gap-2">
          <ExclamationTriangleIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
          <div>
            <strong>Probation di bawah ini sudah lewat tanpa keputusan tertulis.</strong>
            Sesuai UU 13/2003 Pasal 60, secara hukum karyawan-karyawan ini
            <em>otomatis menjadi karyawan tetap</em>. Cron harian akan auto-set
            probation_status = PASSED untuk yang lewat > 1 hari, namun
            sebaiknya HR membuat keputusan eksplisit untuk audit trail yang jelas.
          </div>
        </div>
        <div v-for="emp in expired" :key="emp.id"
          :class="['rounded-lg border p-4 flex items-start gap-3 flex-wrap', urgencyClass(emp)]">
          <UserIcon class="w-8 h-8 flex-shrink-0 opacity-50" />
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <strong class="text-base">{{ emp.full_name }}</strong>
              <span class="text-xs font-mono text-gray-600">{{ emp.employee_number }}</span>
              <span class="text-[10px] px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 font-semibold">
                OVERDUE
              </span>
            </div>
            <div class="text-sm mt-0.5">
              {{ emp.position_name || '-' }} · {{ emp.department_name || '-' }}
            </div>
            <div class="text-sm mt-1">
              <ClockIcon class="w-4 h-4 inline mr-1" />
              Probation berakhir <strong>{{ fmtDate(emp.probation_end_date) }}</strong>
              <span class="ml-2">({{ daysUntilLabel(emp) }})</span>
            </div>
            <div class="text-xs text-gray-600 mt-1">
              Bergabung: {{ fmtDate(emp.join_date) }}
              <span v-if="emp.supervisor_name" class="ml-2">· Supervisor: {{ emp.supervisor_name }}</span>
            </div>
          </div>
          <div class="flex flex-wrap gap-2" v-if="canManage">
            <button class="text-xs px-3 py-1.5 rounded bg-emerald-600 text-white hover:bg-emerald-700 flex items-center gap-1"
              @click="openDecisionModal(emp, 'PASS')">
              <CheckCircleIcon class="w-3.5 h-3.5" /> Konfirmasi PASS
            </button>
            <button class="text-xs px-3 py-1.5 rounded bg-rose-600 text-white hover:bg-rose-700 flex items-center gap-1"
              @click="openDecisionModal(emp, 'FAIL')">
              <XCircleIcon class="w-3.5 h-3.5" /> FAIL (telat)
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Decision modal -->
    <div v-if="showDecisionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">Keputusan Probation</h2>
            <p class="text-xs text-gray-500">
              {{ showDecisionModal.full_name }} ({{ showDecisionModal.employee_number }})
            </p>
          </div>
          <button class="text-gray-500" @click="showDecisionModal = null">&times;</button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Keputusan *</label>
            <div class="grid grid-cols-3 gap-2">
              <button type="button"
                :class="[
                  'px-3 py-3 rounded border text-sm flex flex-col items-center gap-1',
                  decisionForm.decision === 'PASS'
                    ? 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-2 ring-emerald-200'
                    : 'border-gray-300 hover:bg-gray-50',
                ]"
                @click="decisionForm.decision = 'PASS'">
                <CheckCircleIcon class="w-5 h-5" />
                <span class="font-semibold">PASS</span>
                <span class="text-[11px] text-gray-500">Lulus, jadi tetap</span>
              </button>
              <button type="button"
                :class="[
                  'px-3 py-3 rounded border text-sm flex flex-col items-center gap-1',
                  decisionForm.decision === 'EXTEND'
                    ? 'border-amber-500 bg-amber-50 text-amber-800 ring-2 ring-amber-200'
                    : 'border-gray-300 hover:bg-gray-50',
                ]"
                @click="decisionForm.decision = 'EXTEND'">
                <ArrowsRightLeftIcon class="w-5 h-5" />
                <span class="font-semibold">EXTEND</span>
                <span class="text-[11px] text-gray-500">Perpanjang</span>
              </button>
              <button type="button"
                :class="[
                  'px-3 py-3 rounded border text-sm flex flex-col items-center gap-1',
                  decisionForm.decision === 'FAIL'
                    ? 'border-rose-500 bg-rose-50 text-rose-800 ring-2 ring-rose-200'
                    : 'border-gray-300 hover:bg-gray-50',
                ]"
                @click="decisionForm.decision = 'FAIL'">
                <XCircleIcon class="w-5 h-5" />
                <span class="font-semibold">FAIL</span>
                <span class="text-[11px] text-gray-500">Tidak lulus</span>
              </button>
            </div>
          </div>

          <div v-if="decisionForm.decision === 'EXTEND'">
            <label class="label">Perpanjang sampai *</label>
            <input type="date" v-model="decisionForm.extend_to" class="input"
              :min="showDecisionModal.probation_end_date" />
            <p class="text-xs text-gray-500 mt-1">
              Total probation (asli + extension) tetap max 3 bulan dari join_date
              sesuai UU 13/2003 Pasal 60. Backend akan validasi.
            </p>
          </div>

          <div v-if="decisionForm.decision === 'FAIL'"
            class="bg-rose-50 border border-rose-200 rounded p-3 text-xs text-rose-800">
            <strong>Konsekuensi FAIL:</strong>
            <ul class="list-disc list-inside mt-1 space-y-0.5">
              <li>Karyawan resign_date di-set hari ini</li>
              <li>Offboarding tasks otomatis di-trigger</li>
              <li>MPP DRAFT pengganti otomatis dibuat</li>
              <li>Hak final settlement: gaji prorata + UPH (sisa cuti, dst)</li>
            </ul>
          </div>

          <div>
            <label class="label">Catatan keputusan *</label>
            <textarea v-model="decisionForm.note" class="input" rows="3"
              placeholder="Mis. Performa konsisten di atas ekspektasi, tim & supervisor positif. Direkomendasikan jadi tetap."></textarea>
            <p class="text-xs text-gray-500 mt-1">
              Min 10 karakter. Ini masuk audit log permanen — pastikan rinci untuk
              kalau di kemudian hari ada dispute.
            </p>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showDecisionModal = null">Batal</button>
          <button :class="[
            'btn-primary',
            decisionForm.decision === 'FAIL' ? '!bg-rose-600 hover:!bg-rose-700' : '',
            decisionForm.decision === 'EXTEND' ? '!bg-amber-600 hover:!bg-amber-700' : '',
          ]"
            @click="submitDecision">
            Submit {{ decisionForm.decision }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>