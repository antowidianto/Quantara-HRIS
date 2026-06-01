<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { promptDialog } from '@/composables/useConfirm'
import { PlusIcon } from '@heroicons/vue/24/outline'

/**
 * Bonus Run List (Sprint 19+)
 *
 * Pelaksanaan skema bonus. Setiap run punya workflow:
 *   DRAFT → CALCULATED → APPROVED → PAID
 *
 * `payroll_period_id` opsional — kalau di-set, bonus akan auto-merge
 * ke payslip period tersebut saat di-calculate (Sprint 17+).
 */
const toast = useToast()
const items = ref([])
const schemes = ref([])
const periods = ref([])
const loading = ref(false)
const showForm = ref(false)
const filterStatus = ref('')
const filterYear = ref('')

const blankForm = () => ({
  bonus_scheme_id: null,
  payroll_period_id: null,
  name: '',
  reference_date: new Date().toISOString().slice(0, 10),
  pay_date: new Date().toISOString().slice(0, 10),
  notes: '',
})
const form = ref(blankForm())
const errors = ref({})

async function fetchData() {
  loading.value = true
  try {
    const params = { per_page: 50 }
    if (filterStatus.value) params.status = filterStatus.value
    if (filterYear.value) params.year = filterYear.value
    const res = await api.get('/bonus-runs', { params })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat run')
  } finally {
    loading.value = false
  }
}

async function loadDropdowns() {
  try {
    const [schemesRes, periodsRes] = await Promise.all([
      api.get('/bonus-schemes', { params: { per_page: 100, is_active: 1 } }),
      api.get('/payroll-periods', { params: { per_page: 50, status: 'DRAFT' } }),
    ])
    schemes.value = schemesRes.data.data || []
    periods.value = periodsRes.data.data || []
  } catch (e) {
    // non-fatal
  }
}

onMounted(() => {
  fetchData()
  loadDropdowns()
})

function openCreate() {
  form.value = blankForm()
  errors.value = {}
  showForm.value = true
}

async function save() {
  errors.value = {}
  if (! form.value.bonus_scheme_id) errors.value.bonus_scheme_id = 'Wajib pilih skema'
  if (! form.value.name) errors.value.name = 'Wajib diisi'
  if (Object.keys(errors.value).length > 0) return
  try {
    const payload = { ...form.value }
    if (! payload.payroll_period_id) delete payload.payroll_period_id
    const res = await api.post('/bonus-runs', payload)
    toast.success('Bonus run dibuat (status DRAFT)')
    showForm.value = false
    // Auto-redirect ke detail untuk lanjut calculate
    if (res.data?.data?.id) {
      window.location.href = `/payroll/bonus/runs/${res.data.data.id}`
    } else {
      fetchData()
    }
  } catch (e) {
    if (e.response?.data?.errors) errors.value = e.response.data.errors
    toast.error(e.response?.data?.message || 'Gagal membuat run')
  }
}

const STATUS_BADGE = {
  DRAFT:      'badge-gray',
  CALCULATED: 'badge-blue',
  APPROVED:   'badge-yellow',
  PAID:       'badge-green',
  CANCELLED:  'badge-red',
}

async function cancel(row) {
  // Backend mewajibkan `reason` minimal 5 karakter sebagai audit trail
  // (lihat BonusController::cancelRun). Pakai promptDialog supaya user
  // langsung input alasan di modal yang sama dengan konfirmasi —
  // sebelumnya hanya konfirmasi tanpa reason → server reject 422
  // "Alasan minimal 5 karakter" walaupun status DB sudah berubah karena
  // request ke-2 dari user yang re-try (atau di scenario lain bingung).
  const reason = await promptDialog({
    title: 'Batalkan bonus run?',
    message: `Run "${row.name}" akan di-set CANCELLED dan recipient list akan jadi history saja. Tulis alasan pembatalan untuk audit trail.`,
    okText: 'Batalkan',
    variant: 'danger',
    inputLabel: 'Alasan pembatalan',
    inputType: 'textarea',
    inputPlaceholder: 'mis. salah perhitungan, run dibuat ganda, dst.',
    inputRequired: true,
  })
  if (reason === null) return
  const trimmed = (reason || '').trim()
  if (trimmed.length < 5) {
    toast.error('Alasan minimal 5 karakter')
    return
  }
  try {
    await api.post(`/bonus-runs/${row.id}/cancel`, { reason: trimmed })
    toast.success('Run dibatalkan')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal cancel')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">Bonus Runs</h1>
        <p class="text-sm text-gray-500">Pelaksanaan skema bonus. DRAFT → CALCULATED → APPROVED → PAID.</p>
      </div>
      <button @click="openCreate" class="btn-primary">
        <PlusIcon class="w-4 h-4" /> Run Baru
      </button>
    </div>

    <!-- Filter bar -->
    <div class="card mb-4 p-3">
      <div class="flex flex-wrap gap-3 items-end">
        <div>
          <label class="label text-xs">Status</label>
          <select v-model="filterStatus" @change="fetchData" class="input text-sm py-1.5">
            <option value="">Semua</option>
            <option value="DRAFT">DRAFT</option>
            <option value="CALCULATED">CALCULATED</option>
            <option value="APPROVED">APPROVED</option>
            <option value="PAID">PAID</option>
            <option value="CANCELLED">CANCELLED</option>
          </select>
        </div>
        <div>
          <label class="label text-xs">Tahun</label>
          <input v-model="filterYear" @change="fetchData" type="number" class="input text-sm py-1.5 w-24" placeholder="2027" />
        </div>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Scheme</th>
              <th>Period</th>
              <th>Pay Date</th>
              <th>Recipients</th>
              <th>Total</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Belum ada run</td></tr>
            <tr v-for="r in items" :key="r.id">
              <td>
                <RouterLink :to="`/payroll/bonus/runs/${r.id}`" class="text-primary-600 hover:underline font-medium">
                  {{ r.name }}
                </RouterLink>
                <div class="text-xs text-gray-500">ref: {{ fmtDate(r.reference_date) }}</div>
              </td>
              <td>
                <div class="text-sm">{{ r.scheme_name || '#' + r.bonus_scheme_id }}</div>
                <div v-if="r.scheme_kind" class="text-xs text-gray-500">{{ r.scheme_kind }}</div>
              </td>
              <td>
                <span v-if="r.payroll_period_id" class="text-xs">
                  Period #{{ r.payroll_period_id }}
                  <div class="text-gray-400">auto-merge</div>
                </span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td>{{ fmtDate(r.pay_date) }}</td>
              <td class="text-right tabular-nums">{{ r.recipient_count || 0 }}</td>
              <td class="text-right tabular-nums">{{ fmtCurrency(r.total_amount || 0) }}</td>
              <td>
                <span :class="STATUS_BADGE[r.status] || 'badge-gray'">{{ r.status }}</span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <RouterLink :to="`/payroll/bonus/runs/${r.id}`" class="btn-secondary">
                    Detail
                  </RouterLink>
                  <button v-if="['DRAFT', 'CALCULATED', 'APPROVED'].includes(r.status)"
                          class="btn-danger" @click="cancel(r)">
                    Batal
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Form Modal -->
    <div v-if="showForm" class="fixed inset-0 z-50 bg-black/40 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="showForm = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-xl my-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-lg font-semibold">Buat Bonus Run</h2>
          <button @click="showForm = false" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="px-6 py-4 space-y-4">
          <div>
            <label class="label">Bonus Scheme</label>
            <select v-model.number="form.bonus_scheme_id" class="input">
              <option :value="null">— Pilih skema —</option>
              <option v-for="s in schemes" :key="s.id" :value="s.id">
                {{ s.code }} — {{ s.name }} ({{ s.kind }})
              </option>
            </select>
            <p v-if="errors.bonus_scheme_id" class="text-xs text-red-600 mt-1">{{ errors.bonus_scheme_id }}</p>
          </div>
          <div>
            <label class="label">Run Name</label>
            <input v-model="form.name" class="input"
                   placeholder="Annual 2027 Wave 1 / KPI Q1 2027 / dst" />
            <p v-if="errors.name" class="text-xs text-red-600 mt-1">{{ errors.name }}</p>
          </div>
          <div>
            <label class="label">Payroll Period (opsional — auto-merge ke payslip)</label>
            <select v-model.number="form.payroll_period_id" class="input">
              <option :value="null">— Tidak terhubung ke periode payroll —</option>
              <option v-for="p in periods" :key="p.id" :value="p.id">
                {{ p.code }} — {{ p.name }} ({{ p.status }})
              </option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              Pilih period DRAFT untuk auto-merge bonus ke payslip karyawan saat period di-calculate.
              Sprint 17+ feature.
            </p>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Reference Date (untuk hitung tenure)</label>
              <input v-model="form.reference_date" type="date" class="input" />
            </div>
            <div>
              <label class="label">Pay Date</label>
              <input v-model="form.pay_date" type="date" class="input" />
            </div>
          </div>
          <div>
            <label class="label">Notes (opsional)</label>
            <textarea v-model="form.notes" class="input" rows="2"
                      placeholder="Catatan internal HR untuk run ini"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end gap-2">
          <button @click="showForm = false" class="btn-secondary">Batal</button>
          <button @click="save" class="btn-primary">Buat Run</button>
        </div>
      </div>
    </div>
  </div>
</template>