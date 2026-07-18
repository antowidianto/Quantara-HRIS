<template>
  <div class="p-6 space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">License & Certification Tracker</h1>
      <p class="text-sm text-slate-500 dark:text-slate-400">
        Track sertifikat & lisensi karyawan dengan masa berlaku — STR/SIP, K3 Umum,
        BOSIET, SIM B-Umum. Auto-notif H-30/H-7/H-1 sebelum expire.
      </p>
    </header>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-4 gap-3">
      <StatCard label="Expired" :value="stats.expired" color="red" />
      <StatCard label="≤ 7 hari" :value="stats.expiring_in_7" color="orange" />
      <StatCard label="≤ 30 hari" :value="stats.expiring_in_30" color="amber" />
      <StatCard label="≤ 90 hari" :value="stats.expiring_in_90" color="blue" />
    </div>

    <!-- Filter + Actions -->
    <div class="flex justify-between items-center">
      <div class="flex gap-2 items-end">
        <div>
          <label class="text-xs text-slate-500">Status</label>
          <select v-model="statusFilter" class="border rounded px-2 py-1 text-sm" @change="load">
            <option value="">Semua</option>
            <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500">Expiring dalam</label>
          <select v-model="expiringFilter" class="border rounded px-2 py-1 text-sm" @change="load">
            <option value="">—</option>
            <option value="7">7 hari</option>
            <option value="30">30 hari</option>
            <option value="90">90 hari</option>
          </select>
        </div>
      </div>
      <div class="space-x-2">
        <button class="px-3 py-1 text-sm bg-slate-100 rounded" @click="scanNow">
          Run Scan
        </button>
        <button class="px-3 py-1 text-sm bg-blue-600 text-white rounded" @click="openCreate">
          + Tambah License
        </button>
      </div>
    </div>

    <!-- Licenses table -->
    <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2">Employee</th>
            <th class="px-4 py-2">Type</th>
            <th class="px-4 py-2">Number</th>
            <th class="px-4 py-2">Issued</th>
            <th class="px-4 py-2">Expires</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in licenses" :key="l.id" class="border-t dark:border-slate-700">
            <td class="px-4 py-2">#{{ l.employee_id }}</td>
            <td class="px-4 py-2">
              <span class="text-xs font-mono">{{ l.license_code }}</span>
              <div class="text-xs text-slate-500">{{ l.license_name }}</div>
            </td>
            <td class="px-4 py-2 font-mono text-xs">{{ l.license_number }}</td>
            <td class="px-4 py-2 text-xs">{{ l.issued_on }}</td>
            <td class="px-4 py-2 text-xs">{{ l.expires_on || 'Lifetime' }}</td>
            <td class="px-4 py-2">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusClass(l.status)">
                {{ l.status }}
              </span>
            </td>
            <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
              <button v-if="!['RENEWED','REVOKED'].includes(l.status)"
                class="text-blue-600 text-sm" @click="renew(l)">Renew</button>
              <button v-if="!['RENEWED','REVOKED'].includes(l.status)"
                class="text-red-600 text-sm" @click="revoke(l.id)">Revoke</button>
            </td>
          </tr>
          <tr v-if="licenses.length === 0">
            <td colspan="7" class="px-4 py-6 text-center text-slate-400">
              Belum ada license terdaftar. Tambah license type dulu via menu master, lalu
              record license per karyawan.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-96">
        <h3 class="font-medium mb-4">Tambah License Karyawan</h3>
        <form @submit.prevent="submitCreate" class="space-y-3">
          <div>
            <label class="block text-sm">Employee ID</label>
            <input v-model.number="form.employee_id" type="number" class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">License Type</label>
            <select v-model.number="form.license_type_id" class="w-full border rounded px-2 py-1" required>
              <option :value="null" disabled>-- pilih --</option>
              <option v-for="t in types" :key="t.id" :value="t.id">
                {{ t.code }} — {{ t.name }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm">License Number</label>
            <input v-model="form.license_number" class="w-full border rounded px-2 py-1" required />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-sm">Issued On</label>
              <AppDatePicker v-model="form.issued_on"  class="w-full border rounded px-2 py-1" required />
            </div>
            <div>
              <label class="block text-sm">Expires On</label>
              <AppDatePicker v-model="form.expires_on"  class="w-full border rounded px-2 py-1" />
              <p class="text-xs text-slate-500 mt-1">Kosong = lifetime</p>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-3 py-1 text-sm" @click="showCreate = false">Batal</button>
            <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, h } from 'vue'
import api from '@/api/client'

const StatCard = (props) => h('div', {
  class: 'bg-white dark:bg-slate-800 p-4 rounded shadow',
}, [
  h('div', { class: `text-2xl font-semibold text-${props.color}-600` }, String(props.value)),
  h('div', { class: 'text-xs text-slate-500' }, props.label),
])

const statuses = ['ACTIVE', 'EXPIRING_SOON', 'EXPIRED', 'RENEWED', 'REVOKED']

const stats     = ref(null)
const licenses  = ref([])
const types     = ref([])
const statusFilter = ref('')
const expiringFilter = ref('')
const showCreate = ref(false)
const today = new Date().toISOString().slice(0, 10)
const form = ref({
  employee_id: null,
  license_type_id: null,
  license_number: '',
  issued_on: today,
  expires_on: '',
})

async function load() {
  const params = {}
  if (statusFilter.value)   params.status = statusFilter.value
  if (expiringFilter.value) params.expiring_in = expiringFilter.value
  const [s, l, t] = await Promise.all([
    api.get('/licenses/expiring-dashboard'),
    api.get('/employee-licenses', { params }),
    api.get('/license-types'),
  ])
  stats.value    = s.data?.data
  licenses.value = l.data?.data ?? []
  types.value    = t.data?.data ?? []
}

function openCreate() {
  form.value = {
    employee_id: null,
    license_type_id: types.value[0]?.id ?? null,
    license_number: '',
    issued_on: today,
    expires_on: '',
  }
  showCreate.value = true
}

async function submitCreate() {
  try {
    const payload = { ...form.value }
    if (!payload.expires_on) delete payload.expires_on
    await api.post('/employee-licenses', payload)
    showCreate.value = false
    await load()
  } catch (err) {
    alert('Gagal: ' + (err.response?.data?.message || err.message))
  }
}

async function renew(license) {
  const newExp = prompt('Tanggal expires baru (YYYY-MM-DD)?', '')
  if (!newExp) return
  try {
    await api.post(`/employee-licenses/${license.id}/renew`, {
      license_number: license.license_number,
      issued_on: today,
      expires_on: newExp,
    })
    await load()
  } catch (err) {
    alert('Renew failed: ' + (err.response?.data?.message || err.message))
  }
}

async function revoke(id) {
  const reason = prompt('Alasan revoke?')
  if (!reason) return
  try {
    await api.post(`/employee-licenses/${id}/revoke`, { reason })
    await load()
  } catch (err) {
    alert('Revoke failed: ' + (err.response?.data?.message || err.message))
  }
}

async function scanNow() {
  try {
    const res = await api.post('/licenses/scan-now')
    const d = res.data?.data
    alert(`Scan: ${d.to_expire} expired, ${d.to_warn} warned, ${d.applied} updated`)
    await load()
  } catch (err) {
    alert('Scan failed: ' + (err.response?.data?.message || err.message))
  }
}

function statusClass(s) {
  return {
    ACTIVE:        'bg-green-100 text-green-700',
    EXPIRING_SOON: 'bg-amber-100 text-amber-700',
    EXPIRED:       'bg-red-100 text-red-700',
    RENEWED:       'bg-blue-100 text-blue-700',
    REVOKED:       'bg-slate-200 text-slate-700',
  }[s] || 'bg-slate-100'
}

onMounted(load)
</script>