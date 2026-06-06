<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtDate } from '@/composables/useResource'
import { useI18n } from '@/locales'
import {
  ArrowLeftIcon, PaperAirplaneIcon, LockClosedIcon,
  CheckCircleIcon, ArrowPathIcon, StarIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const tid = computed(() => Number(route.params.id))
const ticket = ref(null)
const loading = ref(false)
const replyBody = ref('')
const replyInternal = ref(false)
const showCloseModal = ref(false)
const closeForm = reactive({ rating: 5, note: '' })
const showAssignModal = ref(false)
const hrUsers = ref([])
const assignUserId = ref(null)

const STATUS_BADGE = computed(() => ({
  OPEN:        { label: t('tickets.open'),         cls: 'bg-emerald-100 text-emerald-700' },
  IN_PROGRESS: { label: t('tickets.in_progress'),   cls: 'bg-blue-100 text-blue-700' },
  ANSWERED:    { label: t('tickets.answered'),      cls: 'bg-purple-100 text-purple-700' },
  CLOSED:      { label: t('tickets.closed'),      cls: 'bg-gray-100 text-gray-500' },
  REOPENED:    { label: t('tickets.reopened'), cls: 'bg-amber-100 text-amber-700' },
}))
const PRIORITY_BADGE = computed(() => ({
  URGENT: { label: t('tickets.priority_urgent'), cls: 'bg-red-100 text-red-700' },
  HIGH:   { label: t('tickets.priority_high'), cls: 'bg-orange-100 text-orange-700' },
  MEDIUM: { label: t('tickets.priority_medium'), cls: 'bg-amber-100 text-amber-700' },
  LOW:    { label: t('tickets.priority_low'), cls: 'bg-emerald-100 text-emerald-700' },
}))

const canHandle = computed(() => auth.can('helpdesk.handle'))
const canInternal = computed(() => auth.can('helpdesk.internal'))
const isOwner = computed(() => ticket.value?.employee?.id === auth.user?.employee_id)
const isClosed = computed(() => ticket.value?.status === 'CLOSED')

async function fetchTicket() {
  loading.value = true
  try {
    const res = await api.get(`/helpdesk/tickets/${tid.value}`)
    ticket.value = res.data.data
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')); router.push('/helpdesk/tickets') }
  finally { loading.value = false }
}

onMounted(async () => {
  await fetchTicket()
  if (canHandle.value) {
    try {
      const res = await api.get('/users', { params: { role_id: 2, per_page: 100 } })
      hrUsers.value = res.data.data
    } catch (_) {}
  }
})

async function postReply() {
  if (replyBody.value.trim().length < 2) { toast.error(t('tickets.body_min_char')); return }
  try {
    await api.post(`/helpdesk/tickets/${tid.value}/comments`, {
      body: replyBody.value,
      is_internal: replyInternal.value ? 1 : 0,
    })
    replyBody.value = ''
    replyInternal.value = false
    toast.success(t('tickets.comment_sent'))
    fetchTicket()
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')) }
}

async function changeStatus(status) {
  try {
    await api.post(`/helpdesk/tickets/${tid.value}/status`, { status })
    toast.success(t('tickets.status_changed'))
    fetchTicket()
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')) }
}

async function doAssign() {
  if (! assignUserId.value) { toast.error(t('tickets.choose_hr_error')); return }
  try {
    await api.post(`/helpdesk/tickets/${tid.value}/assign`, { assignee_user_id: assignUserId.value })
    toast.success(t('tickets.ticket_assigned'))
    showAssignModal.value = false
    fetchTicket()
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')) }
}

async function doClose() {
  try {
    await api.post(`/helpdesk/tickets/${tid.value}/close`, {
      satisfaction_rating: closeForm.rating,
      satisfaction_note: closeForm.note,
    })
    toast.success(t('tickets.ticket_closed'))
    showCloseModal.value = false
    fetchTicket()
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')) }
}

async function doReopen() {
  try {
    await api.post(`/helpdesk/tickets/${tid.value}/reopen`)
    toast.success(t('tickets.ticket_reopened'))
    fetchTicket()
  } catch (e) { toast.error(e.response?.data?.message || t('tickets.fail')) }
}

function isOverdue() {
  if (! ticket.value?.sla_due_at || isClosed.value) return false
  return new Date(ticket.value.sla_due_at) < new Date()
}
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1 mb-3"
      @click="router.push('/helpdesk/tickets')">
      <ArrowLeftIcon class="w-4 h-4" /> {{ t('tickets.back_to_list') }}
    </button>

    <div v-if="loading" class="text-center py-12 text-gray-400">{{ t('tickets.loading') }}</div>
    <div v-else-if="ticket" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Main column -->
      <div class="lg:col-span-2 space-y-4">
        <!-- Header card -->
        <div class="card card-body">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">{{ ticket.subject }}</h1>
              <div class="text-xs text-gray-500 font-mono">{{ ticket.ticket_number }}</div>
            </div>
            <div class="flex flex-col items-end gap-1">
              <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE.value[ticket.status]?.cls">
                {{ STATUS_BADGE.value[ticket.status]?.label || ticket.status }}
              </span>
              <span class="px-2 py-0.5 rounded text-xs" :class="PRIORITY_BADGE.value[ticket.priority]?.cls">
                {{ PRIORITY_BADGE.value[ticket.priority]?.label }}
              </span>
              <span v-if="isOverdue()" class="text-xs text-red-600 font-semibold">⚠ {{ t('tickets.overdue_label') }}</span>
            </div>
          </div>

          <div class="mt-4 prose prose-sm max-w-none">
            <p class="whitespace-pre-wrap text-sm text-gray-700">{{ ticket.body }}</p>
          </div>

          <div class="mt-4 pt-3 border-t text-xs text-gray-500 grid grid-cols-2 md:grid-cols-4 gap-2">
            <div><div class="text-gray-400">{{ t('tickets.created_at') }}</div>{{ fmtDate(ticket.created_at) }}</div>
            <div><div class="text-gray-400">{{ t('tickets.sla_due') }}</div>{{ ticket.sla_due_at ? fmtDate(ticket.sla_due_at) : '-' }}</div>
            <div v-if="ticket.first_response_at"><div class="text-gray-400">{{ t('tickets.first_response') }}</div>{{ fmtDate(ticket.first_response_at) }}</div>
            <div v-if="ticket.closed_at"><div class="text-gray-400">{{ t('tickets.closed_at') }}</div>{{ fmtDate(ticket.closed_at) }}</div>
          </div>

          <div v-if="ticket.satisfaction_rating" class="mt-3 pt-3 border-t">
            <div class="text-xs text-gray-500 mb-1">{{ t('tickets.csat_rating') }}</div>
            <div class="flex items-center gap-1">
              <StarIcon v-for="n in ticket.satisfaction_rating" :key="n" class="w-4 h-4 fill-amber-400 text-amber-400" />
              <span class="text-sm ml-2 text-gray-700">{{ ticket.satisfaction_note || '-' }}</span>
            </div>
          </div>
        </div>

        <!-- Comments thread -->
        <div class="card">
          <div class="px-6 py-3 border-b">
            <h2 class="font-semibold text-sm">{{ t('tickets.discussion', { n: ticket.comments?.length || 0 }) }}</h2>
          </div>
          <div class="divide-y divide-gray-100">
            <div v-if="!ticket.comments?.length" class="px-6 py-12 text-center text-gray-400 text-sm">
              {{ t('tickets.no_replies') }}
            </div>
            <div v-for="c in ticket.comments" :key="c.id" class="px-6 py-3"
              :class="c.is_internal ? 'bg-amber-50/50 border-l-4 border-amber-400' : ''">
              <div class="flex items-center justify-between text-sm mb-1">
                <div>
                  <span class="font-medium">{{ c.author_name || 'User' }}</span>
                  <span class="text-xs text-gray-500 ml-2 px-1.5 py-0.5 rounded"
                    :class="c.author_role === 'EMPLOYEE' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700'">
                    {{ c.author_role }}
                  </span>
                  <span v-if="c.is_internal" class="ml-2 text-xs text-amber-700 inline-flex items-center gap-1">
                    <LockClosedIcon class="w-3 h-3" /> INTERNAL
                  </span>
                </div>
                <div class="text-xs text-gray-400">{{ fmtDate(c.created_at) }}</div>
              </div>
              <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ c.body }}</p>
            </div>
          </div>
        </div>

        <!-- Reply form -->
        <div v-if="!isClosed || isOwner" class="card card-body">
          <h3 class="font-semibold text-sm mb-2">{{ t('tickets.reply') }}</h3>
          <textarea v-model="replyBody" rows="4" class="input"
            :placeholder="t('tickets.reply_placeholder')"></textarea>
          <div class="flex items-center justify-between mt-2">
            <label v-if="canInternal" class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" v-model="replyInternal" />
              <LockClosedIcon class="w-4 h-4 text-amber-600" />
              <span>{{ t('tickets.internal_note') }}</span>
            </label>
            <span v-else></span>
            <button class="btn-primary" @click="postReply">
              <PaperAirplaneIcon class="w-4 h-4" /> {{ t('tickets.send') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-3">
        <div class="card card-body">
          <h3 class="font-semibold text-sm mb-2">{{ t('tickets.reporter') }}</h3>
          <div class="text-sm">
            <div class="font-medium">{{ ticket.employee?.full_name }}</div>
            <div class="text-xs text-gray-500 font-mono">{{ ticket.employee?.employee_number }}</div>
            <div class="text-xs text-gray-500 mt-2">{{ ticket.employee?.department_name || '-' }}</div>
            <div class="text-xs text-gray-500">{{ ticket.employee?.branch_name || '-' }}</div>
            <div v-if="ticket.employee?.email" class="text-xs text-gray-500 mt-1">{{ ticket.employee.email }}</div>
            <div v-if="ticket.employee?.phone" class="text-xs text-gray-500">{{ ticket.employee.phone }}</div>
          </div>
        </div>

        <div class="card card-body">
          <h3 class="font-semibold text-sm mb-2">{{ t('tickets.details') }}</h3>
          <div class="space-y-1 text-sm">
            <div><span class="text-xs text-gray-500">{{ t('tickets.category') }}:</span> {{ ticket.category }}</div>
            <div><span class="text-xs text-gray-500">{{ t('tickets.priority') }}:</span> {{ ticket.priority }}</div>
            <div v-if="ticket.assignee_name">
              <span class="text-xs text-gray-500">{{ t('tickets.assigned_to') }}:</span> {{ ticket.assignee_name }}
            </div>
          </div>
        </div>

        <!-- Action panel -->
        <div v-if="!isClosed" class="card card-body space-y-2">
          <h3 class="font-semibold text-sm">{{ t('tickets.action') }}</h3>
          <button v-if="canHandle && !ticket.assignee_user_id"
            class="btn-secondary w-full text-sm" @click="showAssignModal = true">
            📌 {{ t('tickets.assign_hr') }}
          </button>
          <button v-if="canHandle && ticket.status !== 'IN_PROGRESS'"
            class="btn-secondary w-full text-sm" @click="changeStatus('IN_PROGRESS')">
            🔧 {{ t('tickets.mark_in_progress') }}
          </button>
          <button v-if="canHandle || isOwner"
            class="bg-emerald-600 hover:bg-emerald-700 text-white w-full text-sm rounded-md py-2 flex items-center justify-center gap-1"
            @click="showCloseModal = true">
            <CheckCircleIcon class="w-4 h-4" /> {{ t('tickets.close_ticket') }}
          </button>
        </div>

        <div v-else class="card card-body space-y-2">
          <button v-if="canHandle || isOwner"
            class="btn-secondary w-full text-sm" @click="doReopen">
            <ArrowPathIcon class="w-4 h-4 inline mr-1" /> {{ t('tickets.reopen_ticket') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Close modal -->
    <div v-if="showCloseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ t('tickets.close_ticket') }}</h2>
          <button class="text-gray-500" @click="showCloseModal = false">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <label class="label">{{ t('tickets.csat_label') }}</label>
            <div class="flex items-center gap-1">
              <button v-for="n in 5" :key="n" type="button"
                @click="closeForm.rating = n">
                <StarIcon class="w-7 h-7"
                  :class="n <= closeForm.rating ? 'fill-amber-400 text-amber-400' : 'text-gray-300'" />
              </button>
              <span class="ml-2 text-sm text-gray-600">{{ closeForm.rating }}/5</span>
            </div>
          </div>
          <div>
            <label class="label">{{ t('tickets.note_optional') }}</label>
            <textarea v-model="closeForm.note" rows="3" class="input"
              :placeholder="t('tickets.csat_placeholder')"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCloseModal = false">{{ t('tickets.cancel') }}</button>
          <button class="btn-primary" @click="doClose">{{ t('tickets.close_ticket') }}</button>
        </div>
      </div>
    </div>

    <!-- Assign modal -->
    <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ t('tickets.assign_title') }}</h2>
          <button class="text-gray-500" @click="showAssignModal = false">&times;</button>
        </div>
        <div class="p-6">
          <label class="label">{{ t('tickets.choose_hr') }}</label>
          <select v-model="assignUserId" class="input">
            <option :value="null">{{ t('tickets.choose_placeholder') }}</option>
            <option v-for="u in hrUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAssignModal = false">{{ t('tickets.cancel') }}</button>
          <button class="btn-primary" @click="doAssign">{{ t('tickets.assign_title') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
