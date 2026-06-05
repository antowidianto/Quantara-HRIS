<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import { useI18n } from '@/locales'

const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()

const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const branches = ref([])
const loading = ref(false)
const reviewTarget = ref(null)
const reviewMode = ref('') // 'approve' | 'reject'
const reviewNote = ref('')
const reviewing = ref(false)

const filters = ref({
  status: 'PENDING',
  branch_id: '',
  start_date: '',
  end_date: '',
  employee_id: '',
})

const canCrossBranch = computed(() =>
  auth.isSuperAdmin || auth.permissions.includes('attendance.view_all_branches')
)

async function loadBranches() {
  if (!canCrossBranch.value) return
  try {
    const res = await api.get('/branches', { params: { per_page: 100 } })
    branches.value = res.data.data || []
  } catch (_) { /* abaikan */ }
}

async function fetchData(page = 1) {
  loading.value = true
  try {
    const params = { page, per_page: 20, ...filters.value }
    if (!params.branch_id) delete params.branch_id
    if (!params.employee_id) delete params.employee_id
    const res = await api.get('/attendance/change-requests', { params })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.approvals.load_failed'))
  } finally {
    loading.value = false
  }
}

function openReview(row, mode) {
  reviewTarget.value = row
  reviewMode.value = mode
  reviewNote.value = ''
}

async function submitReview() {
  if (reviewMode.value === 'reject' && !reviewNote.value.trim()) {
    toast.warning(t('attendance.approvals.reject_reason_required'))
    return
  }
  reviewing.value = true
  try {
    const url = `/attendance/change-requests/${reviewTarget.value.id}/${reviewMode.value === 'approve' ? 'approve' : 'reject'}`
    await api.post(url, { review_note: reviewNote.value })
    toast.success(reviewMode.value === 'approve' ? t('attendance.approvals.approved_ok') : t('attendance.approvals.rejected_ok'))
    reviewTarget.value = null
    fetchData(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.approvals.process_failed'))
  } finally {
    reviewing.value = false
  }
}

const statusBadge = {
  PENDING:   'bg-amber-100 text-amber-800',
  APPROVED:  'bg-green-100 text-green-800',
  REJECTED:  'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}

const typeLabel = computed(() => ({
  CLOCK_IN: t('attendance.approvals.type_clock_in'),
  CLOCK_OUT: t('attendance.approvals.type_clock_out'),
  BOTH: t('attendance.approvals.type_both'),
  STATUS: t('attendance.approvals.type_status'),
  MISSED: t('attendance.approvals.type_missed'),
}))

onMounted(async () => {
  await loadBranches()
  fetchData()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('attendance.approvals.title') }}</h1>
        <p class="text-sm text-gray-500">
          <span v-if="canCrossBranch">{{ t('attendance.approvals.mode_cross') }}</span>
          <span v-else>{{ t('attendance.approvals.mode_branch', { branch: auth.branch?.name || '—' }) }}</span>
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card-header gap-3 flex-wrap">
        <select v-model="filters.status" class="input w-44">
          <option value="">{{ t('attendance.approvals.all_status') }}</option>
          <option value="PENDING">{{ t('common.pending') }}</option>
          <option value="APPROVED">{{ t('common.approved') }}</option>
          <option value="REJECTED">{{ t('common.rejected') }}</option>
          <option value="CANCELLED">{{ t('common.cancelled') }}</option>
        </select>
        <div v-if="canCrossBranch" class="w-56">
          <SearchableSelect
            v-model="filters.branch_id"
            :items="branches"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','city']"
            :placeholder="t('attendance.approvals.all_branches')"
          />
        </div>
        <input v-model="filters.start_date" type="date" class="input w-40" />
        <input v-model="filters.end_date" type="date" class="input w-40" />
        <div class="w-56">
          <EmployeeSearchSelect
            v-model="filters.employee_id"
            :placeholder="t('attendance.approvals.all_employees')"
            :only-active="false"
          />
        </div>
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">{{ t('common.filter') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('attendance.approvals.col_number') }}</th>
              <th>{{ t('attendance.approvals.col_employee') }}</th>
              <th>{{ t('attendance.approvals.col_branch') }}</th>
              <th>{{ t('attendance.approvals.col_work_date') }}</th>
              <th>{{ t('attendance.approvals.col_type') }}</th>
              <th>{{ t('attendance.approvals.col_current') }}</th>
              <th>{{ t('attendance.approvals.col_requested') }}</th>
              <th>{{ t('attendance.approvals.col_reason') }}</th>
              <th>{{ t('attendance.approvals.col_status') }}</th>
              <th>{{ t('attendance.approvals.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="10" class="text-center py-8 text-gray-400">{{ t('attendance.approvals.no_requests') }}</td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td class="font-mono text-xs">{{ row.request_number }}</td>
              <td>
                <div class="font-medium">{{ row.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ row.employee_number }}</div>
              </td>
              <td class="text-xs">{{ row.branch_name || '—' }}</td>
              <td>{{ fmtDate(row.work_date) }}</td>
              <td class="text-xs">{{ typeLabel[row.change_type] || row.change_type }}</td>
              <td class="text-xs">
                <div v-if="row.current_clock_in">{{ t('attendance.approvals.in_prefix', { time: row.current_clock_in.slice(11, 16) }) }}</div>
                <div v-if="row.current_clock_out">{{ t('attendance.approvals.out_prefix', { time: row.current_clock_out.slice(11, 16) }) }}</div>
                <div v-if="row.current_status">{{ t('attendance.approvals.status_prefix', { status: row.current_status }) }}</div>
                <div v-if="!row.current_clock_in && !row.current_clock_out && !row.current_status" class="text-gray-400">—</div>
              </td>
              <td class="text-xs">
                <div v-if="row.requested_clock_in">{{ t('attendance.approvals.in_prefix', { time: row.requested_clock_in.slice(11, 16) }) }}</div>
                <div v-if="row.requested_clock_out">{{ t('attendance.approvals.out_prefix', { time: row.requested_clock_out.slice(11, 16) }) }}</div>
                <div v-if="row.requested_status">{{ t('attendance.approvals.status_prefix', { status: row.requested_status }) }}</div>
              </td>
              <td class="max-w-xs">
                <div class="text-sm truncate" :title="row.reason">{{ row.reason }}</div>
                <div v-if="row.review_note" class="text-xs text-gray-500 italic mt-1">{{ row.review_note }}</div>
              </td>
              <td>
                <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusBadge[row.status]]">
                  {{ row.status }}
                </span>
                <div v-if="row.reviewer_name" class="text-xs text-gray-500 mt-1">
                  {{ t('attendance.approvals.by_reviewer', { name: row.reviewer_name }) }}
                </div>
                <div v-if="row.reviewed_at" class="text-xs text-gray-400">{{ fmtDateTime(row.reviewed_at) }}</div>
              </td>
              <td>
                <div v-if="row.status === 'PENDING'" class="flex flex-col gap-1">
                  <button class="text-green-700 text-sm hover:underline" @click="openReview(row, 'approve')">
                    {{ t('attendance.approvals.approve') }}
                  </button>
                  <button class="text-red-700 text-sm hover:underline" @click="openReview(row, 'reject')">
                    {{ t('attendance.approvals.reject') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">{{ t('attendance.approvals.total', { n: meta.total }) }}</span>
        <div class="flex gap-1">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">{{ t('attendance.approvals.page_of', { page: meta.page, pages: meta.pages }) }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>

    <!-- Review modal -->
    <div v-if="reviewTarget" class="fixed inset-0 z-40 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold">
            {{ reviewMode === 'approve' ? t('attendance.approvals.modal_approve') : t('attendance.approvals.modal_reject') }}
          </h2>
        </div>
        <div class="p-5 space-y-3 text-sm">
          <div class="bg-gray-50 rounded p-3">
            <div class="font-medium">{{ reviewTarget.employee_name }}</div>
            <div class="text-xs text-gray-600">{{ fmtDate(reviewTarget.work_date) }} · {{ typeLabel[reviewTarget.change_type] }}</div>
            <div class="text-xs text-gray-600 mt-1">"{{ reviewTarget.reason }}"</div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">
              {{ reviewMode === 'reject' ? t('attendance.approvals.note_required') : t('attendance.approvals.note_optional') }}
            </label>
            <textarea v-model="reviewNote" rows="3" class="input w-full"
              :placeholder="reviewMode === 'approve' ? t('attendance.approvals.note_approve_ph') : t('attendance.approvals.note_reject_ph')"></textarea>
          </div>
        </div>
        <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="reviewTarget = null" :disabled="reviewing">{{ t('common.cancel') }}</button>
          <button :class="reviewMode === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="reviewing" @click="submitReview">
            {{ reviewing ? t('attendance.approvals.processing') : (reviewMode === 'approve' ? t('attendance.approvals.approve_apply') : t('attendance.approvals.reject')) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>