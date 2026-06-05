<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import { computed } from 'vue'

const toast = useToast()
const { t } = useI18n()
const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const loading = ref(false)
const filters = ref({ status: '', start_date: '', end_date: '' })

async function fetchData(page = 1) {
  loading.value = true
  try {
    const res = await api.get('/attendance/change-requests', {
      params: { mine: 1, page, per_page: 20, ...filters.value },
    })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.change_list.load_failed'))
  } finally {
    loading.value = false
  }
}

async function cancelRequest(row) {
  const ok = await confirmDialog({
    title: t('attendance.change_list.cancel_title'),
    message: t('attendance.change_list.cancel_message', { number: row.request_number }),
    okText: t('attendance.change_list.cancel_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/attendance/change-requests/${row.id}/cancel`)
    toast.success(t('attendance.change_list.cancelled'))
    fetchData(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.change_list.cancel_failed'))
  }
}

const statusBadge = {
  PENDING:   'bg-amber-100 text-amber-800',
  APPROVED:  'bg-green-100 text-green-800',
  REJECTED:  'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}

const typeLabel = computed(() => ({
  CLOCK_IN: t('attendance.change_list.type_clock_in'),
  CLOCK_OUT: t('attendance.change_list.type_clock_out'),
  BOTH: t('attendance.change_list.type_both'),
  STATUS: t('attendance.change_list.type_status'),
  MISSED: t('attendance.change_list.type_missed'),
}))

onMounted(() => fetchData())
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('attendance.change_list.title') }}</h1>
        <p class="text-sm text-gray-500">{{ t('attendance.change_list.subtitle') }}</p>
      </div>
      <RouterLink to="/attendance/my" class="btn-secondary">{{ t('attendance.change_list.back_to_calendar') }}</RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3 flex-wrap">
        <select v-model="filters.status" class="input w-44">
          <option value="">{{ t('attendance.change_list.all_status') }}</option>
          <option value="PENDING">{{ t('common.pending') }}</option>
          <option value="APPROVED">{{ t('common.approved') }}</option>
          <option value="REJECTED">{{ t('common.rejected') }}</option>
          <option value="CANCELLED">{{ t('common.cancelled') }}</option>
        </select>
        <input v-model="filters.start_date" type="date" class="input w-40" />
        <input v-model="filters.end_date" type="date" class="input w-40" />
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">{{ t('common.filter') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('attendance.change_list.col_number') }}</th>
              <th>{{ t('attendance.change_list.col_work_date') }}</th>
              <th>{{ t('attendance.change_list.col_type') }}</th>
              <th>{{ t('attendance.change_list.col_request') }}</th>
              <th>{{ t('attendance.change_list.col_reason') }}</th>
              <th>{{ t('attendance.change_list.col_status') }}</th>
              <th>{{ t('attendance.change_list.col_reviewer') }}</th>
              <th>{{ t('attendance.change_list.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="8" class="text-center py-8 text-gray-400">{{ t('attendance.change_list.no_requests') }}</td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td class="font-mono text-xs">{{ row.request_number }}</td>
              <td>{{ fmtDate(row.work_date) }}</td>
              <td>{{ typeLabel[row.change_type] || row.change_type }}</td>
              <td class="text-xs">
                <div v-if="row.requested_clock_in">{{ t('attendance.change_list.req_in', { time: row.requested_clock_in.slice(11, 16) }) }}</div>
                <div v-if="row.requested_clock_out">{{ t('attendance.change_list.req_out', { time: row.requested_clock_out.slice(11, 16) }) }}</div>
                <div v-if="row.requested_status">{{ t('attendance.change_list.req_status', { status: row.requested_status }) }}</div>
              </td>
              <td class="max-w-xs truncate" :title="row.reason">{{ row.reason }}</td>
              <td>
                <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusBadge[row.status]]">
                  {{ row.status }}
                </span>
                <div v-if="row.review_note" class="text-xs text-gray-500 mt-1">{{ row.review_note }}</div>
              </td>
              <td class="text-xs">
                <div v-if="row.reviewer_name">{{ row.reviewer_name }}</div>
                <div v-if="row.reviewed_at" class="text-gray-500">{{ fmtDateTime(row.reviewed_at) }}</div>
              </td>
              <td>
                <button v-if="row.status === 'PENDING'" class="text-red-600 text-sm hover:underline"
                  @click="cancelRequest(row)">{{ t('attendance.change_list.cancel') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">{{ t('attendance.change_list.total', { n: meta.total }) }}</span>
        <div class="flex gap-1">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">{{ t('attendance.change_list.page_of', { page: meta.page, pages: meta.pages }) }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>