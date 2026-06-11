<template>
  <div class="p-6 space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">ERP Journal Integration</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          Posting jurnal payroll otomatis ke Accurate / Zahir / Odoo / SAP — atau export CSV generic.
        </p>
      </div>
      <button
        class="px-4 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-700"
        @click="showCreateExport = true"
      >
        + Export Periode
      </button>
    </header>

    <!-- Tabs -->
    <div class="border-b border-slate-200 dark:border-slate-700 flex gap-4">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        class="py-2 px-1 text-sm border-b-2"
        :class="activeTab === tab.id
          ? 'border-blue-600 text-blue-600 dark:text-blue-400'
          : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
        @click="activeTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Connections Tab -->
    <section v-if="activeTab === 'connections'" class="space-y-4">
      <div class="flex justify-between items-center">
        <h2 class="font-medium text-slate-700 dark:text-slate-200">Koneksi ERP</h2>
        <button class="text-sm text-blue-600" @click="showAddConnection = true">+ Tambah</button>
      </div>
      <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600 dark:text-slate-300">
            <tr>
              <th class="px-4 py-2">Provider</th>
              <th class="px-4 py-2">Base URL</th>
              <th class="px-4 py-2">Status</th>
              <th class="px-4 py-2">Last Test</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in connections" :key="c.id" class="border-t dark:border-slate-700">
              <td class="px-4 py-2 font-medium">{{ c.provider }}</td>
              <td class="px-4 py-2 text-slate-500">{{ c.base_url || '—' }}</td>
              <td class="px-4 py-2">
                <span :class="c.is_active ? 'text-green-600' : 'text-slate-400'">
                  {{ c.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-2 text-slate-500">
                {{ c.last_test_status || 'Belum dites' }}
                <span v-if="c.last_test_at" class="text-xs">({{ c.last_test_at }})</span>
              </td>
              <td class="px-4 py-2 text-right">
                <button class="text-blue-600 text-sm" @click="testConnection(c.id)">Test</button>
              </td>
            </tr>
            <tr v-if="connections.length === 0">
              <td colspan="5" class="px-4 py-6 text-center text-slate-400">
                Belum ada koneksi. Tambah dulu provider ERP untuk mulai posting jurnal.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Mappings Tab -->
    <section v-if="activeTab === 'mappings'" class="space-y-4">
      <div class="flex justify-between items-center">
        <h2 class="font-medium text-slate-700 dark:text-slate-200">COA Mapping</h2>
        <button class="text-sm text-blue-600" @click="showAddMapping = true">+ Tambah Mapping</button>
      </div>
      <p class="text-xs text-slate-500">
        Mapping per cost_center × account_type → kode akun (COA) debit/kredit di ERP. Mapping
        dengan cost_center_id NULL berlaku sebagai fallback (catch-all).
      </p>
      <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600 dark:text-slate-300">
            <tr>
              <th class="px-4 py-2">Cost Center</th>
              <th class="px-4 py-2">Account Type</th>
              <th class="px-4 py-2">Debit COA</th>
              <th class="px-4 py-2">Credit COA</th>
              <th class="px-4 py-2">Active</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in mappings" :key="m.id" class="border-t dark:border-slate-700">
              <td class="px-4 py-2">{{ m.cost_center_id ?? '— (catch-all)' }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ m.account_type }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ m.debit_coa }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ m.credit_coa }}</td>
              <td class="px-4 py-2">
                <span :class="m.is_active ? 'text-green-600' : 'text-slate-400'">
                  {{ m.is_active ? 'Yes' : 'No' }}
                </span>
              </td>
            </tr>
            <tr v-if="mappings.length === 0">
              <td colspan="5" class="px-4 py-6 text-center text-slate-400">
                Belum ada mapping. Tambah minimal 1 mapping per account_type sebelum export.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Exports Tab -->
    <section v-if="activeTab === 'exports'" class="space-y-4">
      <h2 class="font-medium text-slate-700 dark:text-slate-200">Riwayat Export Jurnal</h2>
      <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600 dark:text-slate-300">
            <tr>
              <th class="px-4 py-2">ID</th>
              <th class="px-4 py-2">Source</th>
              <th class="px-4 py-2">Period</th>
              <th class="px-4 py-2">Format</th>
              <th class="px-4 py-2 text-right">Total Debit</th>
              <th class="px-4 py-2 text-right">Lines</th>
              <th class="px-4 py-2">Status</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in exports" :key="e.id" class="border-t dark:border-slate-700">
              <td class="px-4 py-2 font-mono">#{{ e.id }}</td>
              <td class="px-4 py-2 text-xs">{{ e.source_type }} #{{ e.source_id }}</td>
              <td class="px-4 py-2">{{ e.period_label || '—' }}</td>
              <td class="px-4 py-2 text-xs">{{ e.format }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ formatRupiah(e.total_debit) }}</td>
              <td class="px-4 py-2 text-right">{{ e.line_count }}</td>
              <td class="px-4 py-2">
                <span class="text-xs px-2 py-0.5 rounded" :class="statusClass(e.status)">
                  {{ e.status }}
                </span>
              </td>
              <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                <a class="text-blue-600 text-sm" :href="csvUrl(e.id)" target="_blank">CSV</a>
                <button
                  v-if="e.status === 'DRAFT'"
                  class="text-green-600 text-sm"
                  @click="postExport(e.id)"
                >
                  Post
                </button>
              </td>
            </tr>
            <tr v-if="exports.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-400">
                Belum ada export. Klik "Export Periode" untuk membuat jurnal pertama.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Modal: Create Export -->
    <div v-if="showCreateExport" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-96">
        <h3 class="font-medium mb-4">Export Jurnal Periode Payroll</h3>
        <form @submit.prevent="submitExport" class="space-y-3">
          <div>
            <label class="block text-sm">Payroll Period ID</label>
            <input v-model.number="exportForm.source_id" type="number" class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">Format</label>
            <select v-model="exportForm.format" class="w-full border rounded px-2 py-1">
              <option value="GENERIC_CSV">Generic CSV</option>
              <option value="ACCURATE_API">Accurate API</option>
              <option value="ZAHIR_CSV">Zahir CSV</option>
              <option value="ODOO_API">Odoo API</option>
              <option value="SAP_IDOC">SAP iDoc</option>
            </select>
          </div>
          <div>
            <label class="block text-sm">Period Label (opsional)</label>
            <input v-model="exportForm.period_label" type="text" placeholder="2026-09" class="w-full border rounded px-2 py-1" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-3 py-1 text-sm text-slate-600" @click="showCreateExport = false">Batal</button>
            <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded">Buat Export</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'

const tabs = [
  { id: 'connections', label: 'Connections' },
  { id: 'mappings',    label: 'COA Mappings' },
  { id: 'exports',     label: 'Journal Exports' },
]
const activeTab = ref('exports')

const connections = ref([])
const mappings    = ref([])
const exports     = ref([])

const showAddConnection = ref(false)
const showAddMapping    = ref(false)
const showCreateExport  = ref(false)

const exportForm = ref({
  source_type: 'PAYROLL_PERIOD',
  source_id: null,
  format: 'GENERIC_CSV',
  period_label: '',
})

async function loadAll() {
  try {
    const [c, m, e] = await Promise.all([
      api.get('/erp/connections'),
      api.get('/erp/mappings'),
      api.get('/erp/journal-exports'),
    ])
    connections.value = c.data?.data ?? []
    mappings.value    = m.data?.data ?? []
    exports.value     = e.data?.data ?? []
  } catch (err) {
    console.error('Load ERP data failed', err)
  }
}

async function testConnection(id) {
  try {
    const res = await api.post(`/erp/connections/${id}/test`)
    alert(`Test ${res.data.data.status}: ${res.data.data.message}`)
    await loadAll()
  } catch (err) {
    alert('Test failed: ' + (err.response?.data?.message || err.message))
  }
}

async function submitExport() {
  try {
    const res = await api.post('/erp/journal-exports', {
      source_type: exportForm.value.source_type,
      source_id: exportForm.value.source_id,
      format: exportForm.value.format,
      period_label: exportForm.value.period_label || null,
    })
    showCreateExport.value = false
    if (res.data.data.warnings?.length) {
      alert('Export dibuat dengan warning:\n' + res.data.data.warnings.join('\n'))
    }
    await loadAll()
  } catch (err) {
    alert('Export failed: ' + (err.response?.data?.message || err.message))
  }
}

async function postExport(id) {
  if (! confirm('Mark export sebagai POSTED ke ERP? Audit log akan dibuat.')) return
  try {
    await api.post(`/erp/journal-exports/${id}/post`, { note: 'Posted via UI' })
    await loadAll()
  } catch (err) {
    alert('Post failed: ' + (err.response?.data?.message || err.message))
  }
}

function csvUrl(id) {
  const base = api.defaults?.baseURL || ''
  return `${base}/erp/journal-exports/${id}/csv`
}

function formatRupiah(n) {
  if (n == null) return '0'
  return new Intl.NumberFormat('id-ID').format(Math.round(n))
}

function statusClass(s) {
  return {
    DRAFT:    'bg-slate-100 text-slate-700',
    POSTED:   'bg-green-100 text-green-700',
    FAILED:   'bg-red-100 text-red-700',
    REVERSED: 'bg-amber-100 text-amber-700',
  }[s] || 'bg-slate-100 text-slate-700'
}

onMounted(loadAll)
</script>