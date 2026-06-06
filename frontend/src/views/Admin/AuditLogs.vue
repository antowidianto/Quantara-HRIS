<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import api from '@/api/client'
import { fmtDateTime } from '@/composables/useResource'
import { useI18n } from '@/locales'

const { t } = useI18n()

const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const stats = ref(null)
const loading = ref(false)
const selected = ref(null)
const related = ref([])

const filters = reactive({
  start_date: '',
  end_date: '',
  module: '',
  severity: '',
  entity: '',
  action: '',
  user_id: '',
  request_id: '',
  search: '',
  success: '',
})

const MODULE_OPTIONS = computed(() => [
  ['', t('audit_logs.filters.allModules')],
  ['AUTH', t('audit_logs.modules.auth')],
  ['EMPLOYEE', t('audit_logs.modules.employee')],
  ['ATTENDANCE', t('audit_logs.modules.attendance')],
  ['LEAVE', t('audit_logs.modules.leave')],
  ['OVERTIME', t('audit_logs.modules.overtime')],
  ['PAYROLL', t('audit_logs.modules.payroll')],
  ['LOAN', t('audit_logs.modules.loan')],
  ['REIMBURSEMENT', t('audit_logs.modules.reimbursement')],
  ['MASTER', t('audit_logs.modules.master')],
  ['SETTING', t('audit_logs.modules.setting')],
  ['ADMIN', t('audit_logs.modules.admin')],
  ['HTTP', t('audit_logs.modules.http')],
  ['AUDIT', t('audit_logs.modules.audit')],
])

const SEVERITY_OPTIONS = computed(() => [
  ['', t('audit_logs.filters.allLevels')],
  ['DEBUG', t('audit_logs.severities.debug')],
  ['INFO', t('audit_logs.severities.info')],
  ['NOTICE', t('audit_logs.severities.notice')],
  ['WARNING', t('audit_logs.severities.warning')],
  ['CRITICAL', t('audit_logs.severities.critical')],
])

const severityClass = (s) => ({
  DEBUG:    'bg-gray-100 text-gray-700',
  INFO:     'bg-blue-100 text-blue-700',
  NOTICE:   'bg-indigo-100 text-indigo-700',
  WARNING:  'bg-amber-100 text-amber-800',
  CRITICAL: 'bg-red-100 text-red-700',
}[s] || 'bg-gray-100 text-gray-700')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

async function fetchData() {
  loading.value = true
  try {
    const params = { page: page.value, per_page: perPage.value }
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) params[k] = v })
    const res = await api.get('/audit-logs', { params })
    items.value = res.data.data
    total.value = res.data.meta?.total ?? items.value.length
  } finally {
    loading.value = false
  }
}

async function fetchStats() {
  const params = {}
  if (filters.start_date) params.start_date = filters.start_date
  if (filters.end_date) params.end_date = filters.end_date
  const res = await api.get('/audit-logs/stats', { params })
  stats.value = res.data.data
}

function applyFilter() { page.value = 1; fetchData(); fetchStats() }

function resetFilter() {
  Object.keys(filters).forEach(k => filters[k] = '')
  applyFilter()
}

async function openDetail(log) {
  const res = await api.get(`/audit-logs/${log.id}`)
  selected.value = res.data.data.log
  related.value = res.data.data.related || []
}

function closeDetail() { selected.value = null; related.value = [] }

async function exportCsv() {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) params.set(k, v) })
  const res = await api.get(`/audit-logs/export?${params.toString()}`, { responseType: 'blob' })
  const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `audit-logs-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

function fmtValue(v) {
  if (v === null || v === undefined) return '∅'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

function changeCount(log) {
  if (!log.changes) return 0
  return Object.keys(log.changes).length
}

onMounted(() => { fetchData(); fetchStats() })
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-950">{{ t('audit_logs.title') }}</h1>
        <p class="text-sm text-gray-500">Track and inspect system activity & changes</p>
      </div>
      <button class="btn-secondary flex items-center gap-2" @click="exportCsv">
        {{ t('audit_logs.exportCsv') }}
      </button>
    </div>

    <!-- Quick Stats -->
    <div v-if="stats" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="card p-4">
        <div class="text-xs text-gray-500">{{ t('audit_logs.stats.period') }}</div>
        <div class="text-sm font-medium">{{ stats.period }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-gray-500">{{ t('audit_logs.stats.topModule') }}</div>
        <div class="text-sm font-medium">{{ stats.top_module || '-' }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-gray-500">{{ t('audit_logs.stats.failures') }}</div>
        <div class="text-sm font-medium text-red-600">{{ stats.failures }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-gray-500">{{ t('audit_logs.stats.topUser') }}</div>
        <div class="text-sm font-medium truncate">
          {{ stats.top_users[0]?.email || '-' }}
          <span class="text-gray-400">({{ stats.top_users[0]?.total || 0 }})</span>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
      <div class="card-header gap-2 flex-wrap">
        <input v-model="filters.start_date" type="date" class="input w-44" :placeholder="t('audit_logs.filters.startDate')" />
        <input v-model="filters.end_date" type="date" class="input w-44" :placeholder="t('audit_logs.filters.endDate')" />
        <select v-model="filters.module" class="input w-44">
          <option v-for="[v, l] in MODULE_OPTIONS" :key="v" :value="v">{{ l }}</option>
        </select>
        <select v-model="filters.severity" class="input w-36">
          <option v-for="[v, l] in SEVERITY_OPTIONS" :key="v" :value="v">{{ l }}</option>
        </select>
        <select v-model="filters.success" class="input w-32">
          <option value="">{{ t('audit_logs.filters.allStatuses') }}</option>
          <option value="1">{{ t('audit_logs.filters.success') }}</option>
          <option value="0">{{ t('audit_logs.filters.failure') }}</option>
        </select>
        <input v-model="filters.entity" :placeholder="t('audit_logs.filters.entityPlaceholder')" class="input w-48" />
        <input v-model="filters.search" :placeholder="t('audit_logs.filters.searchPlaceholder')" class="input w-64" />
        <button class="btn-primary" @click="applyFilter">{{ t('audit_logs.filters.filter') }}</button>
        <button class="btn-secondary" @click="resetFilter">{{ t('audit_logs.filters.reset') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('audit_logs.table.time') }}</th>
              <th>{{ t('audit_logs.table.user') }}</th>
              <th>{{ t('audit_logs.table.module') }}</th>
              <th>{{ t('audit_logs.table.descriptionAction') }}</th>
              <th>{{ t('audit_logs.table.entity') }}</th>
              <th>{{ t('audit_logs.table.severity') }}</th>
              <th>{{ t('audit_logs.table.status') }}</th>
              <th>{{ t('audit_logs.table.ip') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading">
              <td colspan="9" class="text-center py-8 text-gray-400">{{ t('common.loading') }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="9" class="text-center py-8 text-gray-400">{{ t('common.no_data') }}</td>
            </tr>
            <tr v-for="l in items" :key="l.id" class="hover:bg-gray-50">
              <td class="text-xs whitespace-nowrap">{{ fmtDateTime(l.created_at) }}</td>
              <td class="text-xs">
                <div class="font-medium">{{ l.user_name || '-' }}</div>
                <div class="text-gray-500">{{ l.user_email || '-' }}</div>
              </td>
              <td>
                <span class="text-xs font-mono px-2 py-0.5 rounded bg-gray-100">{{ l.module || '-' }}</span>
              </td>
              <td class="max-w-md">
                <div class="text-sm">{{ l.description || '-' }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ l.action }}</div>
                <div v-if="changeCount(l)" class="text-xs text-blue-600 mt-0.5">
                  {{ t('audit_logs.table.fieldsChanged', { count: changeCount(l) }) }}
                </div>
              </td>
              <td class="text-xs">
                <div>{{ l.entity || '-' }}</div>
                <div v-if="l.entity_id" class="text-gray-500">#{{ l.entity_id }}</div>
              </td>
              <td>
                <span class="text-xs font-medium px-2 py-0.5 rounded" :class="severityClass(l.severity)">
                  {{ l.severity || 'INFO' }}
                </span>
              </td>
              <td>
                <span v-if="l.success == 1" class="text-xs text-green-700">✓ OK</span>
                <span v-else class="text-xs text-red-700">✗ {{ l.error_code || 'GAGAL' }}</span>
              </td>
              <td class="text-xs font-mono text-gray-500">{{ l.ip_address }}</td>
              <td>
                <button class="text-xs text-blue-600 hover:underline" @click="openDetail(l)">{{ t('common.detail') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between p-3 border-t text-sm">
        <div class="text-gray-500">{{ t('common.total') }}: {{ total }} log</div>
        <div class="flex items-center gap-2">
          <button class="btn-secondary" :disabled="page <= 1" @click="page--; fetchData()">‹ {{ t('common.previous') }}</button>
          <span>{{ t('common.page_of', { page: page, pages: totalPages }) }}</span>
          <button class="btn-secondary" :disabled="page >= totalPages" @click="page++; fetchData()">{{ t('common.next') }} ›</button>
        </div>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="selected" class="fixed inset-0 bg-black/50 z-50 flex items-start justify-center p-4 overflow-y-auto" @click.self="closeDetail">
      <div class="bg-white rounded-lg max-w-4xl w-full my-8 shadow-xl">
        <div class="flex items-center justify-between p-4 border-b">
          <div>
            <h2 class="text-lg font-bold">{{ t('audit_logs.modal.detailTitle', { id: selected.id }) }}</h2>
            <div class="text-xs text-gray-500 font-mono">{{ selected.request_id }}</div>
          </div>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="closeDetail">×</button>
        </div>

        <div class="p-4 space-y-4">
          <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.time') }}</div>
              <div>{{ fmtDateTime(selected.created_at) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.user') }}</div>
              <div>{{ selected.user_name || '-' }} <span class="text-gray-400">({{ selected.user_email || '-' }})</span></div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.severity') }}</div>
              <span class="text-xs font-medium px-2 py-0.5 rounded" :class="severityClass(selected.severity)">
                {{ selected.severity }}
              </span>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.module') }}</div>
              <div>{{ selected.module }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.action') }}</div>
              <div class="font-mono text-xs">{{ selected.action }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.entity') }}</div>
              <div>{{ selected.entity || '-' }} {{ selected.entity_id ? `#${selected.entity_id}` : '' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.http') }}</div>
              <div class="font-mono text-xs">{{ selected.http_method }} {{ selected.http_path }} → {{ selected.status_code || '-' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.ipSource') }}</div>
              <div class="font-mono text-xs">{{ selected.ip_address }} ({{ selected.source }})</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('audit_logs.modal.duration') }}</div>
              <div>{{ selected.duration_ms ? selected.duration_ms + ' ms' : '-' }}</div>
            </div>
          </div>

          <div v-if="selected.description">
            <div class="text-xs text-gray-500 mb-1">{{ t('audit_logs.modal.description') }}</div>
            <div class="bg-gray-50 p-3 rounded text-sm">{{ selected.description }}</div>
          </div>

          <!-- Diff Changes -->
          <div v-if="selected.changes && Object.keys(selected.changes).length">
            <div class="text-xs text-gray-500 mb-1">{{ t('audit_logs.modal.fieldsChanged', { count: Object.keys(selected.changes).length }) }}</div>
            <div class="border rounded overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="text-left p-2 w-1/3">{{ t('audit_logs.modal.fieldName') }}</th>
                    <th class="text-left p-2 w-1/3">{{ t('audit_logs.modal.before') }}</th>
                    <th class="text-left p-2 w-1/3">{{ t('audit_logs.modal.after') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(diff, key) in selected.changes" :key="key" class="border-t">
                    <td class="p-2 font-mono text-xs">{{ key }}</td>
                    <td class="p-2 bg-red-50 text-red-900">
                      <span class="text-xs">{{ fmtValue(diff.from) }}</span>
                    </td>
                    <td class="p-2 bg-green-50 text-green-900">
                      <span class="text-xs">{{ fmtValue(diff.to) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Raw before/after if no diff -->
          <details v-if="selected.before_data || selected.after_data" class="text-xs">
            <summary class="cursor-pointer text-gray-600">{{ t('audit_logs.modal.fullData') }}</summary>
            <div class="grid grid-cols-2 gap-2 mt-2">
              <div>
                <div class="text-gray-500 mb-1">Before</div>
                <pre class="bg-gray-50 p-2 rounded overflow-auto max-h-64">{{ JSON.stringify(selected.before_data, null, 2) }}</pre>
              </div>
              <div>
                <div class="text-gray-500 mb-1">After</div>
                <pre class="bg-gray-50 p-2 rounded overflow-auto max-h-64">{{ JSON.stringify(selected.after_data, null, 2) }}</pre>
              </div>
            </div>
          </details>

          <details v-if="selected.metadata" class="text-xs">
            <summary class="cursor-pointer text-gray-600">{{ t('audit_logs.modal.metadata') }}</summary>
            <pre class="bg-gray-50 p-2 rounded mt-2 overflow-auto max-h-48">{{ JSON.stringify(selected.metadata, null, 2) }}</pre>
          </details>

          <!-- Related logs in same request_id -->
          <div v-if="related.length">
            <div class="text-xs text-gray-500 mb-1">{{ t('audit_logs.modal.relatedLogs', { count: related.length }) }}</div>
            <div class="border rounded divide-y text-sm">
              <div v-for="r in related" :key="r.id" class="p-2 flex items-center gap-2">
                <span class="text-xs px-2 py-0.5 rounded" :class="severityClass(r.severity)">{{ r.severity }}</span>
                <span class="font-mono text-xs">{{ r.action }}</span>
                <span class="text-gray-600 flex-1 truncate">{{ r.description }}</span>
                <span class="text-xs text-gray-400">{{ fmtDateTime(r.created_at) }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="p-4 border-t flex justify-end">
          <button class="btn-secondary" @click="closeDetail">{{ t('audit_logs.modal.close') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
