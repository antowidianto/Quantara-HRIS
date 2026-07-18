<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ t('training_ui.certificates.title') }}</h1>
        <p class="text-sm text-gray-600">{{ t('training_ui.certificates.subtitle') }}</p>
      </div>
      <div class="flex gap-2">
        <button @click="loadExpiring"
          class="px-3 py-2 rounded bg-amber-100 hover:bg-amber-200 text-amber-700 text-sm">
          {{ t('training_ui.certificates.expiring', { count: expiringCount }) }}
        </button>
        <button v-if="canManage" @click="openCreate"
          class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          {{ t('training_ui.certificates.upload') }}
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg p-3 border border-gray-200 flex flex-wrap gap-2 items-center">
      <label class="text-sm flex items-center gap-2 text-gray-700">
        <input type="checkbox" v-model="filters.mine" @change="fetch" />
        {{ t('training_ui.certificates.mine') }}
      </label>
      <select v-model="filters.status" @change="fetch"
        class="px-3 py-2 rounded border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
        <option value="">{{ t('training_ui.certificates.all_statuses') }}</option>
        <option value="ACTIVE">{{ t('training_ui.certificates.status_active') }}</option>
        <option value="EXPIRED">{{ t('training_ui.certificates.status_expired') }}</option>
        <option value="REVOKED">{{ t('training_ui.certificates.status_revoked') }}</option>
      </select>
    </div>

    <!-- List -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.number_title') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.employee') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.issuer') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.issued') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.valid_until') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.certificates.status') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in items" :key="c.id" class="hover:bg-gray-50"
            :class="{ 'bg-amber-50/60': isExpiringSoon(c) }">
            <td class="px-3 py-2">
              <div class="font-mono text-xs text-gray-500">{{ c.certificate_number }}</div>
              <div class="font-medium text-gray-900">{{ c.title }}</div>
              <div class="text-xs text-gray-500">{{ categoryLabel(c.category) }}</div>
            </td>
            <td class="px-3 py-2">
              <div class="text-gray-900">{{ c.employee_name }}</div>
              <div class="text-xs text-gray-500">{{ c.employee_number }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ c.issuer || '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ formatDate(c.issued_date) }}</td>
            <td class="px-3 py-2 text-xs text-gray-700">
              <span v-if="c.expires_date">
                {{ formatDate(c.expires_date) }}
                <div v-if="isExpiringSoon(c)" class="text-amber-700 font-medium text-xs">
                  {{ t('training_ui.certificates.days_left', { count: daysLeft(c) }) }}
                </div>
              </span>
              <span v-else class="text-gray-400">{{ t('training_ui.certificates.lifetime') }}</span>
            </td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
            </td>
          </tr>
          <tr v-if="!items.length && !loading">
            <td colspan="6" class="px-3 py-12 text-center text-gray-500">{{ t('training_ui.certificates.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Upload -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">{{ t('training_ui.certificates.modal_title') }}</h2>
          <button v-bind="{ 'aria-label': t('training_ui.certificates.close') }" @click="showModal = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="save" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.employee_required') }}</label>
            <EmployeeSearchSelect
              v-model="form.employee_id"
              v-bind="{ placeholder: t('training_ui.certificates.employee_placeholder') }"
              :only-active="true"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.name_required') }}</label>
            <input v-model="form.title" required maxlength="200"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              v-bind="{ placeholder: t('training_ui.certificates.name_placeholder') }" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.category') }}</label>
              <select v-model="form.category"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="PROFESSIONAL">{{ t('training_ui.certificates.category_professional') }}</option>
                <option value="COMPLIANCE">{{ t('training_ui.certificates.category_compliance') }}</option>
                <option value="LICENSE">{{ t('training_ui.certificates.category_license') }}</option>
                <option value="TRAINING">{{ t('training_ui.certificates.category_training') }}</option>
                <option value="OTHER">{{ t('training_ui.certificates.category_other') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.certificate_number') }}</label>
              <input v-model="form.certificate_number"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                v-bind="{ placeholder: t('training_ui.certificates.number_placeholder') }" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.issuer_label') }}</label>
            <input v-model="form.issuer"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              v-bind="{ placeholder: t('training_ui.certificates.issuer_placeholder') }" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.issued_required') }}</label>
              <AppDatePicker v-model="form.issued_date"  required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.expires') }}</label>
              <AppDatePicker v-model="form.expires_date"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                v-bind="{ placeholder: t('training_ui.certificates.expires_placeholder') }" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.file') }}</label>
            <FileUploader
              v-model="form.file_path"
              context="training"
              accept=".pdf,.jpg,.jpeg,.png"
              :max-size-mb="8"
              :hint="t('training_ui.certificates.file_hint')"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.certificates.notes') }}</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="showModal = false" class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">{{ t('training_ui.certificates.cancel') }}</button>
            <button type="submit" :disabled="saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ saving ? t('training_ui.certificates.saving') : t('training_ui.certificates.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import FileUploader from '@/components/FileUploader.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const auth = useAuthStore()
const { t, locale } = useI18n()
const canManage = computed(() => auth.can('certificate.manage'))

const items = ref([])
const loading = ref(false)
const filters = ref({ mine: false, status: '' })
const expiringCount = ref(0)

const showModal = ref(false)
const saving = ref(false)
const form = ref(blankForm())

function blankForm() {
  return {
    employee_id: '', title: '', certificate_number: '',
    category: 'PROFESSIONAL', issuer: '',
    issued_date: '', expires_date: '',
    file_path: '', notes: '',
  }
}

async function fetch() {
  loading.value = true
  try {
    const params = { per_page: 100 }
    if (filters.value.mine) params.mine = 1
    if (filters.value.status) params.status = filters.value.status
    const r = await api.get('/certificates', { params })
    items.value = r.data?.data || []
  } finally { loading.value = false }
}

async function loadExpiring() {
  try {
    const r = await api.get('/certificates/expiring', { params: { days: 30 } })
    items.value = r.data?.data || []
    expiringCount.value = items.value.length
  } catch (e) {}
}

async function fetchExpiringCount() {
  try {
    const r = await api.get('/certificates/expiring', { params: { days: 30 } })
    expiringCount.value = (r.data?.data || []).length
  } catch (e) { expiringCount.value = 0 }
}

function openCreate() {
  form.value = blankForm()
  form.value.issued_date = new Date().toISOString().slice(0, 10)
  showModal.value = true
}

async function save() {
  saving.value = true
  try {
    await api.post('/certificates', form.value)
    showModal.value = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.certificates.save_failed'))
  } finally { saving.value = false }
}

function isExpiringSoon(c) {
  if (! c.expires_date || c.status !== 'ACTIVE') return false
  const days = (new Date(c.expires_date) - new Date()) / 86400000
  return days >= 0 && days <= 30
}

function daysLeft(c) {
  if (! c.expires_date) return null
  return Math.max(0, Math.floor((new Date(c.expires_date) - new Date()) / 86400000))
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString(locale.value === 'id' ? 'id-ID' : 'en-US', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusLabel(s) {
  return t(`training_ui.certificates.status_${String(s).toLowerCase()}`)
}

function categoryLabel(category) {
  return t(`training_ui.certificates.category_${String(category).toLowerCase()}`)
}

function statusClass(s) {
  return {
    ACTIVE: 'bg-emerald-100 text-emerald-700',
    EXPIRED: 'bg-red-100 text-red-700',
    REVOKED: 'bg-gray-100 text-gray-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

onMounted(() => {
  fetch()
  fetchExpiringCount()
})
</script>
