<script setup>
import { ref, onMounted, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate } from '@/composables/useResource'
import { confirm as confirmDialog, promptDialog } from '@/composables/useConfirm'
import { PlusIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useI18n } from '@/locales'

const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const { t } = useI18n()
const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const filterStatus = ref(route.query.status || '')
const showMine = ref(route.query.scope === 'mine' || ! auth.can('leave.approve'))

async function fetchData() {
  const res = await api.get('/leaves', {
    params: { status: filterStatus.value || undefined, mine: showMine.value ? 1 : undefined, page: meta.value.page },
  })
  items.value = res.data.data
  meta.value = res.data.meta
}

onMounted(fetchData)

watch(
  () => [route.query.scope, route.query.status],
  () => {
    showMine.value = route.query.scope === 'mine' || ! auth.can('leave.approve')
    filterStatus.value = route.query.status || ''
    meta.value.page = 1
    fetchData()
  },
)

async function approve(id) {
  const ok = await confirmDialog({
    title: t('leave.list.approve_title'),
    message: t('leave.list.approve_message'),
    okText: t('leave.list.approve_ok'),
    variant: 'success',
  })
  if (! ok) return
  try {
    await api.post(`/leaves/${id}/approve`)
    toast.success(t('leave.list.approved'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('leave.list.approve_failed'))
  }
}

async function reject(id) {
  const reason = await promptDialog({
    title: t('leave.list.reject_title'),
    message: t('leave.list.reject_message'),
    inputLabel: t('leave.list.reject_label'),
    inputType: 'textarea',
    inputRequired: true,
    okText: t('leave.list.reject_ok'),
    variant: 'danger',
  })
  if (reason === null) return
  try {
    await api.post(`/leaves/${id}/reject`, { reason })
    toast.success(t('leave.list.rejected'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('leave.list.reject_failed'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ showMine ? t('leave.list.title_mine') : t('leave.list.title_manage') }}</h1>
      <RouterLink to="/leaves/new" class="btn-primary"><PlusIcon class="w-4 h-4" /> {{ t('leave.list.apply') }}</RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3">
        <div class="flex gap-2 items-center">
          <select v-model="filterStatus" @change="fetchData" class="input w-40">
            <option value="">{{ t('leave.list.all_status') }}</option>
            <option value="PENDING">{{ t('leave.list.st_pending') }}</option>
            <option value="APPROVED">{{ t('leave.list.st_approved') }}</option>
            <option value="REJECTED">{{ t('leave.list.st_rejected') }}</option>
            <option value="CANCELLED">{{ t('leave.list.st_cancelled') }}</option>
          </select>
          <label v-if="auth.can('leave.approve')" class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="showMine" @change="fetchData" /> {{ t('leave.list.only_mine') }}
          </label>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('leave.list.col_number') }}</th>
              <th v-if="!showMine">{{ t('leave.list.col_employee') }}</th>
              <th>{{ t('leave.list.col_type') }}</th>
              <th>{{ t('leave.list.col_period') }}</th>
              <th>{{ t('leave.list.col_days') }}</th>
              <th>{{ t('leave.list.col_status') }}</th>
              <th class="text-right">{{ t('leave.list.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('leave.list.no_requests') }}</td></tr>
            <tr v-for="l in items" :key="l.id">
              <td class="font-mono text-xs">{{ l.request_number }}</td>
              <td v-if="!showMine">{{ l.employee_name }}</td>
              <td>{{ l.leave_name }}</td>
              <td>{{ fmtDate(l.start_date) }} - {{ fmtDate(l.end_date) }}</td>
              <td>{{ l.days }}</td>
              <td>
                <span :class="{
                  'badge-yellow': l.status === 'PENDING',
                  'badge-green': l.status === 'APPROVED',
                  'badge-red': l.status === 'REJECTED',
                  'badge-gray': l.status === 'CANCELLED',
                }">{{ l.status }}</span>
              </td>
              <td class="text-right">
                <div v-if="l.status === 'PENDING' && auth.can('leave.approve')" class="inline-flex gap-2">
                  <button class="text-green-600 hover:bg-green-50 rounded p-1" @click="approve(l.id)" :title="t('leave.list.tip_approve')"><CheckIcon class="w-4 h-4" /></button>
                  <button class="text-red-600 hover:bg-red-50 rounded p-1" @click="reject(l.id)" :title="t('leave.list.tip_reject')"><XMarkIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
