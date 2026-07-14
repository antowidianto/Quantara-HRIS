<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  DocumentTextIcon, EyeIcon, TrashIcon, PaperAirplaneIcon,
  CheckBadgeIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

/**
 * Halaman daftar kontrak yang sudah digenerate.
 *
 * Menampilkan kontrak yang dihasilkan dari template dengan snapshot HTML —
 * dari sini HR bisa preview, ubah status (DRAFT → SENT → SIGNED), atau hapus.
 */

const { t, locale } = useI18n()
const toast = useToast()

const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const loading = ref(false)
const params = reactive({ page: 1, per_page: 20, status: '', employee_id: '' })

const statusBadge = computed(() => ({
  DRAFT:   { class: 'bg-gray-100 text-gray-700',   label: t('employees.contracts.st_draft') },
  SENT:    { class: 'bg-blue-50 text-blue-700',    label: t('employees.contracts.st_sent') },
  SIGNED:  { class: 'bg-green-50 text-green-700',  label: t('employees.contracts.st_signed') },
  REVOKED: { class: 'bg-red-50 text-red-700',      label: t('employees.contracts.st_revoked') },
}))

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/generated-contracts', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('employees.contracts.load_failed'))
  } finally {
    loading.value = false
  }
}

// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
onMounted(() => {
  fetchData()
})

watch(() => [params.status, params.employee_id], () => {
  params.page = 1
  fetchData()
})

function go(page) { params.page = page; fetchData() }

async function openHtml(item) {
  // Buka HTML kontrak di tab baru. Endpoint butuh JWT, jadi kita ambil
  // dulu via api lalu buka object URL.
  const previewWindow = window.open('', '_blank')
  if (!previewWindow) {
    toast.warning(t('employees.contracts.popup_blocked'))
    return
  }
  previewWindow.opener = null
  try {
    const res = await api.get(`/generated-contracts/${item.id}/html`, { responseType: 'text' })
    const blob = new Blob([res.data], { type: 'text/html' })
    const url = URL.createObjectURL(blob)
    if (previewWindow.closed) {
      URL.revokeObjectURL(url)
      return
    }
    previewWindow.location.href = url
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    previewWindow.close()
    toast.error(e.response?.data?.message || t('employees.contracts.open_failed'))
  }
}

async function changeStatus(item, newStatus) {
  const actionMap = {
    SENT: t('employees.contracts.action_sent'),
    SIGNED: t('employees.contracts.action_signed'),
    REVOKED: t('employees.contracts.action_revoked'),
  }
  const ok = await confirmDialog({
    title: t('employees.contracts.change_status_title'),
    message: t('employees.contracts.change_status_msg', {
      action: actionMap[newStatus] || t('employees.contracts.action_default'),
      number: item.contract_number,
    }),
    okText: t('employees.contracts.ok_yes'),
    variant: newStatus === 'REVOKED' ? 'danger' : 'primary',
  })
  if (! ok) return
  try {
    await api.put(`/generated-contracts/${item.id}/status`, { status: newStatus })
    toast.success(t('employees.contracts.status_updated'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('employees.contracts.status_failed'))
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: t('employees.contracts.delete_title'),
    message: t('employees.contracts.delete_msg', { number: item.contract_number }),
    okText: t('employees.contracts.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/generated-contracts/${item.id}`)
    toast.success(t('employees.contracts.deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('employees.contracts.delete_failed'))
  }
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString(locale.value === 'en' ? 'en-US' : 'id-ID', {
    dateStyle: 'short', timeStyle: 'short',
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <DocumentTextIcon class="w-6 h-6" /> {{ t('employees.contracts.title') }}
      </h1>
    </div>

    <div class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <select v-model="params.status" class="input w-full md:w-48">
          <option value="">{{ t('employees.contracts.all_status') }}</option>
          <option value="DRAFT">{{ t('employees.contracts.st_draft') }}</option>
          <option value="SENT">{{ t('employees.contracts.st_sent') }}</option>
          <option value="SIGNED">{{ t('employees.contracts.st_signed') }}</option>
          <option value="REVOKED">{{ t('employees.contracts.st_revoked') }}</option>
        </select>
        <div class="w-full md:w-72">
          <EmployeeSearchSelect
            v-model="params.employee_id"
            v-bind="{ placeholder: t('employees.contracts.all_employees') }"
            :only-active="false"
          />
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('employees.contracts.col_number') }}</th>
              <th>{{ t('employees.contracts.col_employee') }}</th>
              <th>{{ t('employees.contracts.col_subject') }}</th>
              <th>{{ t('employees.contracts.col_template') }}</th>
              <th>{{ t('employees.contracts.col_status') }}</th>
              <th>{{ t('employees.contracts.col_created') }}</th>
              <th>{{ t('employees.contracts.col_signed') }}</th>
              <th class="text-right w-44">{{ t('employees.contracts.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">{{ t('employees.contracts.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">{{ t('employees.contracts.no_contracts') }}</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.contract_number }}</td>
              <td>
                <div class="font-medium">{{ item.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ item.employee_number }}</div>
              </td>
              <td class="max-w-[320px] truncate" :title="item.subject">{{ item.subject }}</td>
              <td>
                <div class="text-sm">{{ item.template_name }}</div>
                <div class="text-xs text-gray-500">{{ item.template_type }}</div>
              </td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs', statusBadge[item.status]?.class]">
                  {{ statusBadge[item.status]?.label || item.status }}
                </span>
              </td>
              <td class="text-xs text-gray-500">{{ fmtDate(item.created_at) }}</td>
              <td class="text-xs text-gray-500">{{ fmtDate(item.signed_at) }}</td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button type="button" class="text-gray-500 hover:text-primary-600" @click="openHtml(item)"
                    v-bind="{ title: t('employees.contracts.view_print'), 'aria-label': t('employees.contracts.view_print') }">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="item.status === 'DRAFT'" type="button" class="text-blue-600 hover:text-blue-800"
                    @click="changeStatus(item, 'SENT')"
                    v-bind="{ title: t('employees.contracts.mark_sent'), 'aria-label': t('employees.contracts.mark_sent') }">
                    <PaperAirplaneIcon class="w-4 h-4" />
                  </button>
                  <button v-if="item.status === 'SENT'" type="button" class="text-green-600 hover:text-green-800"
                    @click="changeStatus(item, 'SIGNED')"
                    v-bind="{ title: t('employees.contracts.mark_signed'), 'aria-label': t('employees.contracts.mark_signed') }">
                    <CheckBadgeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="['DRAFT','SENT','SIGNED'].includes(item.status)" type="button"
                    class="text-amber-600 hover:text-amber-800"
                    @click="changeStatus(item, 'REVOKED')"
                    v-bind="{ title: t('employees.contracts.revoke'), 'aria-label': t('employees.contracts.revoke') }">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                  <button type="button" class="text-gray-500 hover:text-red-600" @click="remove(item)"
                    v-bind="{ title: t('employees.contracts.delete'), 'aria-label': t('employees.contracts.delete') }">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">{{ t('employees.contracts.total', { n: meta.total }) }}</div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('employees.contracts.previous') }}</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">{{ t('employees.contracts.next') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
