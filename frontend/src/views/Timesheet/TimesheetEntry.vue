<template>
  <div class="p-6 space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">Timesheet</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          Catat jam kerja per project untuk billing klien & tracking utilization.
        </p>
      </div>
      <button class="px-4 py-2 rounded bg-blue-600 text-white text-sm" @click="openCreate">
        + Entry Baru
      </button>
    </header>

    <div class="flex gap-2 items-end">
      <div>
        <label class="text-xs text-slate-500">From</label>
        <input v-model="from" type="date" class="border rounded px-2 py-1 text-sm" @change="load" />
      </div>
      <div>
        <label class="text-xs text-slate-500">To</label>
        <input v-model="to" type="date" class="border rounded px-2 py-1 text-sm" @change="load" />
      </div>
      <button class="px-3 py-1 text-sm bg-slate-100 rounded" @click="loadUtilization">
        Lihat Utilization
      </button>
    </div>

    <!-- Utilization summary -->
    <div v-if="utilization" class="grid grid-cols-3 gap-3">
      <div class="bg-white dark:bg-slate-800 p-4 rounded shadow">
        <div class="text-2xl font-semibold">{{ utilization.total_hours }}</div>
        <div class="text-xs text-slate-500">Total Hours</div>
      </div>
      <div class="bg-white dark:bg-slate-800 p-4 rounded shadow">
        <div class="text-2xl font-semibold text-green-600">{{ utilization.billable_hours }}</div>
        <div class="text-xs text-slate-500">Billable Hours</div>
      </div>
      <div class="bg-white dark:bg-slate-800 p-4 rounded shadow">
        <div class="text-2xl font-semibold" :class="utilColor(utilization.utilization_pct)">
          {{ utilization.utilization_pct }}%
        </div>
        <div class="text-xs text-slate-500">Utilization Rate</div>
      </div>
    </div>

    <!-- Entries -->
    <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2">Date</th>
            <th class="px-4 py-2">Project</th>
            <th class="px-4 py-2 text-right">Hours</th>
            <th class="px-4 py-2">Task</th>
            <th class="px-4 py-2">Billable</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id" class="border-t dark:border-slate-700">
            <td class="px-4 py-2">{{ e.work_date }}</td>
            <td class="px-4 py-2">#{{ e.project_id }}</td>
            <td class="px-4 py-2 text-right font-mono">{{ e.hours }}</td>
            <td class="px-4 py-2 text-xs">{{ e.task_description }}</td>
            <td class="px-4 py-2">
              <span :class="e.is_billable ? 'text-green-600' : 'text-slate-400'">
                {{ e.is_billable ? '✓' : '—' }}
              </span>
            </td>
            <td class="px-4 py-2">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusClass(e.status)">
                {{ e.status }}
              </span>
            </td>
            <td class="px-4 py-2 text-right">
              <button v-if="['DRAFT', 'REJECTED'].includes(e.status)"
                class="text-blue-600 text-sm" @click="submit(e.id)">
                Submit
              </button>
            </td>
          </tr>
          <tr v-if="entries.length === 0">
            <td colspan="7" class="px-4 py-6 text-center text-slate-400">
              Belum ada entry untuk periode ini.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-96">
        <h3 class="font-medium mb-4">Entry Timesheet</h3>
        <form @submit.prevent="submitCreate" class="space-y-3">
          <div>
            <label class="block text-sm">Project</label>
            <select v-model.number="form.project_id" class="w-full border rounded px-2 py-1" required>
              <option :value="null" disabled>-- pilih project --</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">
                {{ p.code }} — {{ p.name }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm">Tanggal</label>
            <input v-model="form.work_date" type="date" class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">Jam (kelipatan 0.25)</label>
            <input v-model.number="form.hours" type="number" step="0.25" min="0.25" max="24"
              class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">Deskripsi Task</label>
            <textarea v-model="form.task_description" rows="2"
              class="w-full border rounded px-2 py-1" required></textarea>
          </div>
          <div>
            <label class="flex items-center text-sm">
              <input type="checkbox" v-model="form.is_billable" class="mr-2" />
              Billable ke klien
            </label>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-3 py-1 text-sm" @click="showCreate = false">Batal</button>
            <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded">Simpan Draft</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'

const today = new Date().toISOString().slice(0, 10)
const monthStart = new Date(); monthStart.setDate(1)
const from = ref(monthStart.toISOString().slice(0, 10))
const to   = ref(today)

const entries  = ref([])
const projects = ref([])
const utilization = ref(null)
const showCreate = ref(false)
const form = ref({ project_id: null, work_date: today, hours: 8, task_description: '', is_billable: true })

async function load() {
  const res = await api.get('/timesheet/my', { params: { from: from.value, to: to.value } })
  entries.value = res.data?.data ?? []
}

async function loadProjects() {
  const res = await api.get('/projects', { params: { status: 'ACTIVE' } })
  projects.value = res.data?.data ?? []
}

async function loadUtilization() {
  const res = await api.get('/timesheet/utilization', { params: { from: from.value, to: to.value } })
  utilization.value = res.data?.data
}

function openCreate() {
  form.value = {
    project_id: projects.value[0]?.id ?? null,
    work_date: today,
    hours: 8,
    task_description: '',
    is_billable: true,
  }
  showCreate.value = true
}

async function submitCreate() {
  try {
    await api.post('/timesheet', form.value)
    showCreate.value = false
    await load()
  } catch (err) {
    alert('Gagal: ' + (err.response?.data?.message || err.message))
  }
}

async function submit(id) {
  if (! confirm('Submit untuk approval?')) return
  try {
    await api.post(`/timesheet/${id}/submit`)
    await load()
  } catch (err) {
    alert('Gagal submit: ' + (err.response?.data?.message || err.message))
  }
}

function statusClass(s) {
  return {
    DRAFT:     'bg-slate-100 text-slate-700',
    SUBMITTED: 'bg-blue-100 text-blue-700',
    APPROVED:  'bg-green-100 text-green-700',
    REJECTED:  'bg-red-100 text-red-700',
  }[s] || 'bg-slate-100'
}

function utilColor(pct) {
  if (pct >= 75) return 'text-green-600'
  if (pct >= 50) return 'text-amber-600'
  return 'text-red-600'
}

onMounted(async () => {
  await Promise.all([load(), loadProjects()])
})
</script>