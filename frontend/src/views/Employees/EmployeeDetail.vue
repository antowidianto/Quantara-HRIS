<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { EyeIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const employee = ref(null)
const balances = ref([])
const balanceYear = ref(new Date().getFullYear())
const contracts = ref([])
const generatedContracts = ref([])
const leaves = ref([])
const leavesLoading = ref(false)

const STATUS_BADGE = {
  PENDING: 'badge-yellow',
  APPROVED: 'badge-green',
  REJECTED: 'badge-red',
  CANCELLED: 'badge-gray',
}

async function fetchAll(id) {
  const [emp, bal, ctr, gen, lv] = await Promise.allSettled([
    api.get(`/employees/${id}`),
    api.get(`/leave-balances/${id}`),
    api.get(`/employees/${id}/contracts`),
    api.get('/generated-contracts', { params: { employee_id: id } }),
    api.get('/leaves', { params: { employee_id: id, per_page: 50 } }),
  ])
  if (emp.status === 'fulfilled') employee.value = emp.value.data.data
  if (bal.status === 'fulfilled') {
    // LeaveController::balance returns { employee_id, year, balances: [...], today }.
    // Sebelumnya kita salah baca `data.data` langsung sebagai array — itu sebabnya
    // `balances.length` selalu undefined dan list di UI tidak pernah render.
    const payload = bal.value.data?.data || {}
    balances.value = Array.isArray(payload.balances) ? payload.balances : []
    if (payload.year) balanceYear.value = payload.year
  }
  if (ctr.status === 'fulfilled') contracts.value = ctr.value.data.data || []
  if (gen.status === 'fulfilled') generatedContracts.value = gen.value.data.data || []
  if (lv.status === 'fulfilled') leaves.value = lv.value.data.data || []
  leavesLoading.value = false
}

onMounted(async () => {
  leavesLoading.value = true
  await fetchAll(route.params.id)
})

function openGeneratedContract(c) {
  api.get(`/generated-contracts/${c.id}/html`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data)
    window.open(url, '_blank')
  }).catch(e => {
    console.error('Gagal membuka kontrak:', e)
  })
}

const totalAllocated = computed(() =>
  balances.value.reduce((sum, b) => sum + Number(b.allocated || 0), 0)
)
const totalRemaining = computed(() =>
  balances.value.reduce((sum, b) => sum + Number(b.remaining || 0), 0)
)
</script>

<template>
  <div v-if="employee">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ employee.full_name }}</h1>
        <div class="text-gray-500 text-sm">{{ employee.employee_number }} · {{ employee.email || '-' }}</div>
      </div>
      <RouterLink :to="`/employees/${employee.id}/edit`" class="btn-primary">Edit</RouterLink>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="card lg:col-span-2">
        <div class="card-header"><span class="font-semibold">Detail Karyawan</span></div>
        <div class="card-body grid grid-cols-2 gap-4 text-sm">
          <div><div class="text-gray-500">Status</div><div>{{ employee.employment_status }} · {{ employee.is_active ? 'Aktif' : 'Non-aktif' }}</div></div>
          <div><div class="text-gray-500">Bergabung</div><div>{{ fmtDate(employee.join_date) }}</div></div>
          <div><div class="text-gray-500">Telepon</div><div>{{ employee.phone || '-' }}</div></div>
          <div><div class="text-gray-500">Jenis Kelamin</div><div>{{ employee.gender === 'F' ? 'Perempuan' : (employee.gender === 'M' ? 'Laki-laki' : '-') }}</div></div>
          <div><div class="text-gray-500">PTKP</div><div>{{ employee.ptkp_status }}</div></div>
          <div><div class="text-gray-500">Gaji Pokok</div><div>{{ fmtCurrency(employee.basic_salary) }}</div></div>
          <div><div class="text-gray-500">NPWP</div><div>{{ employee.npwp || '-' }}</div></div>
          <div><div class="text-gray-500">Bank</div><div>{{ employee.bank_name || '-' }} {{ employee.bank_account ? '· ' + employee.bank_account : '' }}</div></div>
          <div><div class="text-gray-500">BPJS Kes</div><div>{{ employee.bpjs_kesehatan_no || '-' }}</div></div>
          <div class="col-span-2"><div class="text-gray-500">Alamat</div><div>{{ employee.address || '-' }}</div></div>
          <div v-if="employee.contract_end_date"><div class="text-gray-500">Akhir Kontrak</div><div>{{ fmtDate(employee.contract_end_date) }}</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-semibold">Saldo Cuti {{ balanceYear }}</span>
          <span v-if="balances.length" class="text-xs text-gray-500">
            Sisa <strong class="text-primary-700">{{ totalRemaining }}</strong>/{{ totalAllocated }} hari
          </span>
        </div>
        <div class="card-body">
          <div v-if="balances.length === 0" class="text-sm text-gray-400">
            Belum ada saldo cuti yang berlaku untuk karyawan ini.
          </div>
          <ul v-else class="text-sm divide-y divide-gray-100">
            <li v-for="b in balances" :key="b.leave_type_id" class="py-2">
              <div class="flex justify-between items-baseline">
                <span class="font-medium">{{ b.name }}</span>
                <span class="font-semibold tabular-nums">
                  {{ b.remaining }}<span class="text-gray-400">/{{ b.allocated }}</span>
                </span>
              </div>
              <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                <span class="font-mono">{{ b.code }}</span>
                <span>·</span>
                <span>Terpakai {{ b.used }}</span>
                <span v-if="b.is_prorated && b.prorate_factor < 1">
                  · Prorate {{ b.months_worked }}/12 bln
                </span>
                <span v-if="b.carried_over > 0">
                  · Carry-over {{ b.carried_over }} hari
                </span>
                <span v-if="b.is_expired" class="text-red-600 font-medium">
                  · Kadaluwarsa
                </span>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header flex items-center justify-between">
        <span class="font-semibold">Riwayat Pengajuan Cuti</span>
        <span class="text-xs text-gray-500">{{ leaves.length }} entri</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No. Pengajuan</th>
              <th>Tipe</th>
              <th>Mulai</th>
              <th>Selesai</th>
              <th class="text-right">Hari</th>
              <th>Status</th>
              <th>Alasan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="leavesLoading">
              <td colspan="7" class="text-center py-4 text-gray-400">Memuat...</td>
            </tr>
            <tr v-else-if="leaves.length === 0">
              <td colspan="7" class="text-center py-4 text-gray-400">Belum ada pengajuan cuti</td>
            </tr>
            <tr v-for="l in leaves" :key="l.id">
              <td class="font-mono text-xs">{{ l.request_number }}</td>
              <td class="text-sm">{{ l.leave_name || l.leave_code || '-' }}</td>
              <td class="text-sm">{{ fmtDate(l.start_date) }}</td>
              <td class="text-sm">{{ fmtDate(l.end_date) }}</td>
              <td class="text-right tabular-nums">{{ l.days }}</td>
              <td>
                <span :class="STATUS_BADGE[l.status] || 'badge-gray'">{{ l.status }}</span>
              </td>
              <td class="text-sm text-gray-600 max-w-md truncate" :title="l.reason">
                {{ l.reason || '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header"><span class="font-semibold">Riwayat Kontrak</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>No</th><th>Tipe</th><th>Mulai</th><th>Berakhir</th><th>Gaji</th></tr></thead>
          <tbody>
            <tr v-if="contracts.length === 0"><td colspan="5" class="text-center py-4 text-gray-400">Belum ada kontrak</td></tr>
            <tr v-for="c in contracts" :key="c.id">
              <td>{{ c.contract_number }}</td>
              <td><span class="badge-blue">{{ c.contract_type }}</span></td>
              <td>{{ fmtDate(c.start_date) }}</td>
              <td>{{ fmtDate(c.end_date) }}</td>
              <td>{{ fmtCurrency(c.basic_salary) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header"><span class="font-semibold">Dokumen Kontrak Generated</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>No Kontrak</th><th>Subject</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
          <tbody>
            <tr v-if="generatedContracts.length === 0"><td colspan="5" class="text-center py-4 text-gray-400">Belum ada kontrak digenerate</td></tr>
            <tr v-for="g in generatedContracts" :key="g.id">
              <td class="font-mono text-sm">{{ g.contract_number }}</td>
              <td class="text-sm">{{ g.subject || '—' }}</td>
              <td><span class="badge-blue">{{ g.status }}</span></td>
              <td class="text-sm">{{ fmtDate(g.created_at) }}</td>
              <td class="text-right">
                <button class="btn-secondary text-xs" @click="openGeneratedContract(g)">
                  <EyeIcon class="w-3.5 h-3.5" /> Buka
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">Memuat...</div>
</template>