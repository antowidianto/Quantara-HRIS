<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <router-link to="/training" class="text-primary-600 hover:underline text-sm">{{ t('training_ui.detail.back') }}</router-link>
    </div>

    <div v-if="training" class="bg-white rounded-lg p-5 border border-gray-200">
      <div class="flex items-start justify-between mb-3">
        <div>
          <div class="font-mono text-xs text-gray-500">{{ training.code }}</div>
          <h1 class="text-2xl font-bold text-gray-900">{{ training.title }}</h1>
          <div v-if="training.description" class="text-sm text-gray-600 mt-1">{{ training.description }}</div>
        </div>
        <span class="px-3 py-1 rounded text-xs font-medium" :class="statusClass(training.status)">{{ trainingStatusLabel(training.status) }}</span>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-sm">
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.category') }}</div>
          <div class="font-medium text-gray-900">{{ categoryLabel(training.category) }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.date') }}</div>
          <div class="font-medium text-gray-900">{{ formatDate(training.start_date) }} – {{ formatDate(training.end_date) }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.duration') }}</div>
          <div class="font-medium text-gray-900">{{ t('training_ui.detail.hours', { count: training.duration_hours }) }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.location') }}</div>
          <div class="font-medium text-gray-900">{{ training.location || '-' }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.provider') }}</div>
          <div class="font-medium text-gray-900">{{ training.provider || '-' }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.cost_participant') }}</div>
          <div class="font-medium text-gray-900">{{ formatCurrency(training.cost_per_participant) }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.capacity') }}</div>
          <div class="font-medium text-gray-900">
            {{ training.registrations?.filter(r => !['CANCELLED','REJECTED'].includes(r.status)).length || 0 }}
            <span v-if="training.max_participants > 0"> / {{ training.max_participants }}</span>
          </div>
        </div>
        <div>
          <div class="text-xs text-gray-500">{{ t('training_ui.detail.certificate') }}</div>
          <div class="font-medium">
            <span v-if="+training.issues_certificate === 1" class="text-emerald-600">{{ t('training_ui.detail.yes') }}
              <span v-if="training.certificate_validity_months">{{ t('training_ui.detail.months', { count: training.certificate_validity_months }) }}</span>
            </span>
            <span v-else class="text-gray-400">{{ t('training_ui.detail.no') }}</span>
          </div>
        </div>
      </div>

      <div class="flex gap-2 mt-4 pt-4 border-t border-gray-200">
        <button v-if="canRegister && training.status === 'PUBLISHED'" @click="registerSelf"
          class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm">
          {{ t('training_ui.detail.register_self') }}
        </button>
        <button v-if="canManage && training.status === 'PUBLISHED'" @click="openAddParticipant"
          class="px-4 py-2 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm">
          {{ t('training_ui.detail.register_employee') }}
        </button>
      </div>
    </div>

    <!-- Participants -->
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
        <h2 class="font-bold text-gray-900">{{ t('training_ui.detail.participants', { count: training?.registrations?.length || 0 }) }}</h2>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.detail.employee') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.detail.department') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.detail.status') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.detail.score') }}</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ t('training_ui.detail.cost') }}</th>
            <th class="px-3 py-2 text-right font-medium text-gray-700">{{ t('training_ui.detail.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in training?.registrations || []" :key="r.id" class="hover:bg-gray-50">
            <td class="px-3 py-2">
              <div class="font-medium text-gray-900">{{ r.employee_name }}</div>
              <div class="text-xs text-gray-500">{{ r.employee_number }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ r.department_name || '-' }}</td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="regStatusClass(r.status)">{{ regStatusLabel(r.status) }}</span>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">
              <span v-if="r.final_score !== null">
                {{ r.final_score }}
                <span v-if="r.is_passed === 1" class="text-emerald-600 ml-1">✓</span>
                <span v-else-if="r.is_passed === 0" class="text-red-600 ml-1">✗</span>
              </span>
              <span v-else class="text-gray-400">-</span>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ formatCurrency(r.cost) }}</td>
            <td class="px-3 py-2 text-right space-x-1 whitespace-nowrap">
              <button v-if="canManage && r.status === 'REGISTERED'" @click="approve(r)"
                class="px-2 py-1 rounded bg-emerald-100 hover:bg-emerald-200 text-emerald-700 text-xs">
                {{ t('training_ui.detail.approve') }}
              </button>
              <button v-if="canManage && r.status === 'REGISTERED'" @click="reject(r)"
                class="px-2 py-1 rounded bg-red-100 hover:bg-red-200 text-red-700 text-xs">
                {{ t('training_ui.detail.reject') }}
              </button>
              <button v-if="canManage && ['APPROVED','ATTENDING'].includes(r.status)" @click="openComplete(r)"
                class="px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs">
                {{ t('training_ui.detail.complete') }}
              </button>
            </td>
          </tr>
          <tr v-if="!training?.registrations?.length">
            <td colspan="6" class="px-3 py-8 text-center text-gray-500">{{ t('training_ui.detail.no_participants') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Complete -->
    <div v-if="completeModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="completeModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">{{ t('training_ui.detail.complete_title') }}</h2>
          <button v-bind="{ 'aria-label': t('training_ui.detail.close') }" @click="completeModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="submitComplete" class="p-5 space-y-3">
          <div class="text-sm text-gray-700">{{ t('training_ui.detail.participant') }} <span class="font-medium">{{ completeModal.reg?.employee_name }}</span></div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.detail.final_score') }}</label>
            <input v-model.number="completeModal.score" type="number" min="0" max="100" step="0.5"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              v-bind="{ placeholder: t('training_ui.detail.passing_score', { score: training?.passing_score || '-' }) }" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.detail.attendance') }}</label>
            <input v-model.number="completeModal.attendance" type="number" min="0" max="100"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div v-if="+training?.issues_certificate === 1" class="bg-blue-50 rounded p-2 text-xs text-blue-700 border border-blue-200">
            {{ t('training_ui.detail.certificate_notice') }}
            <span v-if="training.passing_score">({{ training.passing_score }})</span>.
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="completeModal.show = false" class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">{{ t('training_ui.detail.cancel') }}</button>
            <button type="submit" :disabled="completeModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ completeModal.saving ? t('training_ui.detail.saving') : t('training_ui.detail.complete_action') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal Add Participant -->
    <div v-if="addModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="addModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">{{ t('training_ui.detail.add_title') }}</h2>
          <button v-bind="{ 'aria-label': t('training_ui.detail.close') }" @click="addModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="submitAdd" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('training_ui.detail.employee_required') }}</label>
            <EmployeeSearchSelect
              v-model="addModal.employeeId"
              v-bind="{ placeholder: t('training_ui.detail.employee_placeholder') }"
              :only-active="true"
            />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="addModal.show = false" class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">{{ t('training_ui.detail.cancel') }}</button>
            <button type="submit" :disabled="addModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">{{ t('training_ui.detail.register_action') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const route = useRoute()
const { t, locale } = useI18n()
const auth = useAuthStore()
const trainingId = computed(() => Number(route.params.id))
const canManage = computed(() => auth.can('training.manage'))
const canRegister = computed(() => auth.can('training.register'))

const training = ref(null)
const completeModal = ref({ show: false, reg: null, score: null, attendance: 100, saving: false })
const addModal = ref({ show: false, employeeId: '', saving: false })

async function fetch() {
  const r = await api.get(`/trainings/${trainingId.value}`)
  training.value = r.data?.data
}

async function registerSelf() {
  if (! confirm(t('training_ui.detail.register_confirm'))) return
  try {
    await api.post(`/trainings/${trainingId.value}/register`, {})
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.detail.register_failed'))
  }
}

function openAddParticipant() {
  addModal.value = { show: true, employeeId: '', saving: false }
}

async function submitAdd() {
  if (! addModal.value.employeeId) return
  addModal.value.saving = true
  try {
    await api.post(`/trainings/${trainingId.value}/register`, { employee_id: addModal.value.employeeId })
    addModal.value.show = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.detail.add_failed'))
  } finally { addModal.value.saving = false }
}

async function approve(r) {
  if (! confirm(t('training_ui.detail.approve_confirm', { name: r.employee_name }))) return
  try {
    await api.post(`/training-registrations/${r.id}/approve`)
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.detail.approve_failed'))
  }
}

async function reject(r) {
  const reason = prompt(t('training_ui.detail.reject_reason'))
  if (! reason || reason.length < 5) return
  try {
    await api.post(`/training-registrations/${r.id}/reject`, { reason })
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.detail.reject_failed'))
  }
}

function openComplete(r) {
  completeModal.value = { show: true, reg: r, score: null, attendance: 100, saving: false }
}

async function submitComplete() {
  completeModal.value.saving = true
  try {
    await api.post(`/training-registrations/${completeModal.value.reg.id}/complete`, {
      final_score: completeModal.value.score,
      attendance_percent: completeModal.value.attendance,
    })
    completeModal.value.show = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || t('training_ui.detail.complete_failed'))
  } finally { completeModal.value.saving = false }
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString(locale.value === 'id' ? 'id-ID' : 'en-US', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatCurrency(n) {
  return new Intl.NumberFormat(locale.value === 'id' ? 'id-ID' : 'en-US', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(n || 0))
}

function regStatusLabel(status) {
  return t(`training_ui.detail.status_${String(status).toLowerCase()}`)
}

function trainingStatusLabel(status) {
  return t(`training_ui.list.status_${String(status).toLowerCase()}`)
}

function categoryLabel(category) {
  return t(`training_ui.list.category_${String(category).toLowerCase()}`)
}

function statusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    PUBLISHED: 'bg-blue-100 text-blue-700',
    ONGOING: 'bg-amber-100 text-amber-700',
    COMPLETED: 'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

function regStatusClass(s) {
  return {
    REGISTERED: 'bg-gray-100 text-gray-700',
    APPROVED: 'bg-blue-100 text-blue-700',
    ATTENDING: 'bg-purple-100 text-purple-700',
    COMPLETED: 'bg-emerald-100 text-emerald-700',
    CERTIFIED: 'bg-emerald-200 text-emerald-800',
    REJECTED: 'bg-red-100 text-red-700',
    CANCELLED: 'bg-gray-100 text-gray-500',
    NO_SHOW: 'bg-amber-100 text-amber-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

onMounted(() => {
  fetch()
})
</script>
