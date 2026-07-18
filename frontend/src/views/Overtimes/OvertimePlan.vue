 <script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'
import {
  ClockIcon, ArrowPathIcon, PlusIcon, ArrowLeftIcon, EyeIcon, XMarkIcon,
  ExclamationTriangleIcon, CheckCircleIcon, InformationCircleIcon, XCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 8 / H1.6 — Overtime Pre-Approval form.
 *
 * Karyawan / supervisor mengajukan PLAN lembur sebelum tanggal kerja.
 * Setelah disetujui dan kerja dilakukan, gunakan endpoint /realize untuk
 * convert PLAN → REALIZED dengan actual_hours.
 *
 * Endpoints:
 *   POST /overtimes/plan                — submit plan (status PLANNED)
 *   POST /overtimes/:id/realize          — realize plan dengan actual hours
 *   GET  /overtimes?status=PLANNED       — list rencana
 *
 * Variance handling: jika actual_hours selisih > 50% dari planned_hours,
 * variance_note wajib diisi.
 */

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const planForm = reactive({
  work_date: '',
  start_time: '',
  end_time: '',
  reason: '',
})

const submitting = ref(false)
const myPlanned = ref([])
const detail = ref(null)
const detailLoadingId = ref(null)
const realizingId = ref(null)
const realizeForm = reactive({
  actual_hours: 0,
  variance_note: '',
})

async function loadMyPlanned() {
  try {
    const res = await api.get('/overtimes', {
      params: { status: 'PLANNED,PENDING,APPROVED', mine: 1 },
    })
    myPlanned.value = res.data.data || []
  } catch (_) {
    myPlanned.value = []
  }
}

const plannedHours = computed(() => {
  if (!planForm.start_time || !planForm.end_time) return 0

  const [startHour, startMinute] = planForm.start_time.split(':').map(Number)
  const [endHour, endMinute] = planForm.end_time.split(':').map(Number)
  const minutes = (endHour * 60 + endMinute) - (startHour * 60 + startMinute)

  return minutes > 0 ? minutes / 60 : 0
})

async function submitPlan() {
  if (!planForm.work_date) {
    toast.warning(t('overtime.date_required'))
    return
  }
  if (plannedHours.value <= 0) {
    toast.warning(t('overtime.hours_positive'))
    return
  }
  if (! planForm.reason?.trim() || planForm.reason.length < 10) {
    toast.warning(t('overtime.reason_min_char'))
    return
  }
  submitting.value = true
  try {
    await api.post('/overtimes/plan', {
      work_date: planForm.work_date,
      start_time: planForm.start_time,
      end_time: planForm.end_time,
      reason: planForm.reason.trim(),
    })
    toast.success(t('overtime.submit_plan_success'))
    Object.assign(planForm, { work_date: '', start_time: '', end_time: '', reason: '' })
    loadMyPlanned()
  } catch (e) {
    toast.error(e.response?.data?.message || t('overtime.submit_plan_failed'))
  } finally {
    submitting.value = false
  }
}

async function openDetail(row) {
  detailLoadingId.value = row.id
  try {
    const res = await api.get(`/overtimes/${row.id}`)
    detail.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat detail pengajuan')
  } finally {
    detailLoadingId.value = null
  }
}

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(date)
}

function formatTime(value) {
  if (!value) return '—'
  const match = String(value).match(/(\d{2}:\d{2})/)
  return match?.[1] || value
}

function decisionBadge(decision) {
  const map = {
    APPROVED: 'bg-emerald-100 text-emerald-700',
    REJECTED: 'bg-red-100 text-red-700',
    SKIPPED: 'bg-gray-100 text-gray-600',
    DELEGATED: 'bg-blue-100 text-blue-700',
    PENDING: 'bg-amber-100 text-amber-700',
    WAITING_APPROVAL: 'bg-amber-100 text-amber-700',
  }
  return map[decision] || 'bg-gray-100 text-gray-600'
}

async function realizePlan() {
  if (! realizingId.value) return
  if (! realizeForm.actual_hours || realizeForm.actual_hours <= 0) {
    toast.warning(t('overtime.realize_hours_positive'))
    return
  }
  // Cek variance > 50%
  const plan = myPlanned.value.find(p => p.id === realizingId.value)
  const planned = Number(plan?.planned_hours || 0)
  const actual = Number(realizeForm.actual_hours)
  const variance = planned > 0 ? Math.abs(actual - planned) / planned : 0
  if (variance > 0.5 && ! realizeForm.variance_note?.trim()) {
    toast.warning(t('overtime.realize_variance_required'))
    return
  }
  try {
    await api.post(`/overtimes/${realizingId.value}/realize`, {
      actual_hours: actual,
      variance_note: realizeForm.variance_note,
    })
    toast.success(t('overtime.realize_success'))
    realizingId.value = null
    realizeForm.actual_hours = 0
    realizeForm.variance_note = ''
    loadMyPlanned()
  } catch (e) {
    toast.error(e.response?.data?.message || t('overtime.realize_failed'))
  }
}

function statusBadge(s) {
  const map = {
    PLANNED:   'bg-blue-100 text-blue-700',
    PENDING:   'bg-amber-100 text-amber-700',
    APPROVED:  'bg-emerald-100 text-emerald-700',
    REJECTED:  'bg-red-100 text-red-700',
    REALIZED:  'bg-purple-100 text-purple-700',
    PAID:      'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-gray-100 text-gray-500',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

const today = new Date().toISOString().slice(0, 10)
const maxDate = (() => {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  return d.toISOString().slice(0, 10)
})()

const realizeVariance = computed(() => {
  if (! realizingId.value) return 0
  const plan = myPlanned.value.find(p => p.id === realizingId.value)
  const planned = Number(plan?.planned_hours || 0)
  const actual = Number(realizeForm.actual_hours || 0)
  if (planned === 0) return 0
  return Math.abs(actual - planned) / planned
})

onMounted(loadMyPlanned)
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 inline-flex items-center gap-1 mb-3"
      @click="router.push('/overtimes?scope=mine')">
      <ArrowLeftIcon class="w-4 h-4" /> {{ t('overtime.back_to_list') }}
    </button>

    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClockIcon class="w-6 h-6" /> {{ t('overtime.plan_title') }}
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ t('overtime.plan_subtitle') }}
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Form submit plan -->
      <div class="card">
        <div class="card-header"><span class="font-medium">{{ t('overtime.new_plan_title') }}</span></div>
        <div class="card-body space-y-3">
          <div>
            <label class="label">{{ t('overtime.new_plan_date') }}</label>
            <AppDatePicker  v-model="planForm.work_date" class="input" :min="today" :max="maxDate" />
            <p class="text-xs text-gray-500 mt-1">{{ t('overtime.new_plan_date_hint') }}</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('overtime.start_time_label') }}</label>
              <input type="time" v-model="planForm.start_time" class="input" required />
            </div>
            <div>
              <label class="label">{{ t('overtime.end_time_label') }}</label>
              <input type="time" v-model="planForm.end_time" class="input" required />
            </div>
          </div>
          <div>
            <label class="label">{{ t('overtime.new_plan_hours') }}</label>
            <div class="input bg-gray-50 text-gray-700">
              {{ t('overtime.hours_suffix', { n: plannedHours.toFixed(2) }) }}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ t('overtime.new_plan_hours_hint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('overtime.new_plan_reason') }}</label>
            <textarea v-model="planForm.reason" class="input" rows="3"
              :placeholder="t('overtime.new_plan_reason_placeholder')"></textarea>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-800 flex items-start gap-2">
            <InformationCircleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
            <div>
              {{ t('overtime.new_plan_info') }}
            </div>
          </div>
          <button class="btn-primary w-full" @click="submitPlan" :disabled="submitting">
            <PlusIcon class="w-4 h-4" />
            {{ submitting ? t('overtime.new_plan_submitting') : t('overtime.new_plan_submit') }}
          </button>
        </div>
      </div>

      <!-- List planned/approved -->
      <div class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">{{ t('overtime.my_plans_title') }}</span>
          <button class="btn-icon" @click="loadMyPlanned" :title="t('overtime.refresh_tooltip')">
            <ArrowPathIcon class="w-4 h-4" />
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('overtime.col_date') }}</th>
                <th class="text-right">{{ t('overtime.col_planned') }}</th>
                <th class="text-right">{{ t('overtime.col_actual') }}</th>
                <th>{{ t('overtime.col_status') }}</th>
                <th class="text-right">{{ t('overtime.col_actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="myPlanned.length === 0">
                <td colspan="5" class="text-center py-8 text-gray-400">
                  {{ t('overtime.my_plans_no_data') }}
                </td>
              </tr>
              <tr v-for="r in myPlanned" :key="r.id">
                <td>{{ r.work_date }}</td>
                <td class="text-right">{{ t('overtime.hours_suffix', { n: r.planned_hours ?? r.hours }) }}</td>
                <td class="text-right">
                  <span v-if="r.actual_hours">{{ t('overtime.hours_suffix', { n: r.actual_hours }) }}</span>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td>
                  <span class="inline-block px-2 py-0.5 rounded text-xs" :class="statusBadge(r.status)">
                    {{ r.status }}
                  </span>
                </td>
                <td class="text-right">
                  <div class="inline-flex items-center justify-end gap-1">
                    <button class="btn-secondary text-xs" :disabled="detailLoadingId === r.id"
                      @click="openDetail(r)">
                      <ArrowPathIcon v-if="detailLoadingId === r.id" class="w-4 h-4 animate-spin" />
                      <EyeIcon v-else class="w-4 h-4" />
                      Detail
                    </button>
                    <button v-if="r.status === 'APPROVED'"
                      class="btn-secondary text-xs"
                      @click="realizingId = r.id; realizeForm.actual_hours = r.planned_hours ?? r.hours; realizeForm.variance_note = ''">
                      {{ t('overtime.realize_btn') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal: Detail pengajuan dan alur approval -->
    <div v-if="detail" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="detail = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="sticky top-0 bg-white px-5 py-4 border-b flex items-start justify-between z-10">
          <div>
            <h2 class="font-semibold text-lg">Detail Pengajuan Lembur</h2>
            <p class="text-xs text-gray-500 font-mono">{{ detail.request_number }}</p>
          </div>
          <button class="text-gray-500 hover:text-gray-800" @click="detail = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-5">
          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-sm">Informasi Pengajuan</h3>
              <span class="px-2 py-0.5 rounded text-xs" :class="statusBadge(detail.status)">
                {{ detail.status }}
              </span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm bg-gray-50 rounded-lg p-4">
              <div>
                <dt class="text-xs text-gray-500">Karyawan</dt>
                <dd class="font-medium">{{ detail.employee_name || '—' }}</dd>
                <dd class="text-xs text-gray-500">{{ detail.employee_number || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Tanggal Lembur</dt>
                <dd class="font-medium">{{ detail.work_date }}</dd>
                <dd class="text-xs text-gray-500">{{ detail.day_type }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Waktu</dt>
                <dd>{{ formatTime(detail.start_time) }} – {{ formatTime(detail.end_time) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Durasi Rencana</dt>
                <dd>{{ Number(detail.planned_hours ?? detail.hours ?? 0).toFixed(2) }} jam</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Jenis Kompensasi</dt>
                <dd>{{ detail.compensation_type || 'CASH' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Diajukan</dt>
                <dd>{{ formatDateTime(detail.planned_at || detail.created_at) }}</dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-xs text-gray-500">Alasan</dt>
                <dd class="whitespace-pre-line">{{ detail.reason || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-sm">Daftar Approval</h3>
              <span v-if="detail.approval" class="px-2 py-0.5 rounded text-xs"
                :class="decisionBadge(detail.approval.status)">
                {{ detail.approval.status }}
              </span>
            </div>

            <div v-if="!detail.approval" class="border border-dashed rounded-lg p-4 text-sm text-gray-500">
              Workflow approval belum tersedia untuk pengajuan ini.
            </div>

            <div v-else class="space-y-3">
              <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 text-sm">
                <div><span class="text-gray-500">Approval chain:</span> {{ detail.approval.chain_name || 'Default' }}</div>
                <div class="text-xs text-gray-500 mt-1">
                  Request #{{ detail.approval.id }} · dibuat {{ formatDateTime(detail.approval.created_at) }}
                </div>
              </div>

              <div v-if="!detail.approval.timeline?.length"
                class="border border-dashed rounded-lg p-4 text-sm text-gray-500">
                Belum ada tahapan approval yang dikonfigurasi.
              </div>

              <ol v-else class="relative border-l-2 border-gray-200 ml-3 space-y-4">
                <li v-for="step in detail.approval.timeline" :key="step.step_id ?? `fallback-${step.step_order}`"
                  class="pl-5 relative" :class="{ 'opacity-60': step.status === 'SKIPPED' }">
                  <span class="absolute -left-[7px] top-1 w-3 h-3 rounded-full border-2 border-white"
                    :class="step.status === 'APPROVED'
                      ? 'bg-emerald-500'
                      : step.status === 'REJECTED'
                        ? 'bg-red-500'
                        : step.status === 'SKIPPED'
                          ? 'bg-gray-400'
                          : 'bg-amber-400'">
                  </span>
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="font-medium text-sm">
                        {{ step.step_name || `Tahap ${step.step_order}` }}
                        <span v-if="step.is_current" class="ml-1 text-xs text-amber-700">(tahap aktif)</span>
                      </div>
                      <div class="text-xs text-gray-500">
                        {{ step.approver_user_name
                          || step.expected_approver_names?.join(', ')
                          || step.approver_type
                          || 'PIC belum ditentukan' }}
                      </div>
                      <div v-if="step.condition_field && step.condition_matched"
                        class="text-xs text-blue-600 mt-0.5">
                        Rule: {{ step.condition_field }} {{ step.condition_op }} {{ step.condition_value }}
                      </div>
                    </div>
                    <span class="px-2 py-0.5 rounded text-xs whitespace-nowrap"
                      :class="decisionBadge(step.status)">
                      {{ step.status === 'WAITING_APPROVAL' ? 'WAITING APPROVAL' : step.status }}
                    </span>
                  </div>
                  <div v-if="step.decided_at" class="text-xs text-gray-500 mt-1">
                    {{ formatDateTime(step.decided_at) }}
                  </div>
                  <div v-if="step.note" class="mt-1 text-xs bg-gray-50 rounded p-2 whitespace-pre-line">
                    {{ step.note }}
                  </div>
                </li>
              </ol>
            </div>
          </section>
        </div>

        <div class="sticky bottom-0 bg-white px-5 py-3 border-t flex justify-end">
          <button class="btn-secondary" @click="detail = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Modal: Realize -->
    <div v-if="realizingId" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="realizingId = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">{{ t('overtime.modal_title') }}</h3>
        </div>
        <div class="p-5 space-y-3">
          <div class="text-sm text-gray-700">
            {{ t('overtime.modal_desc') }}
          </div>
          <div>
            <label class="label">{{ t('overtime.modal_hours_label') }}</label>
            <input type="number" step="0.5" min="0.5" max="24"
              v-model.number="realizeForm.actual_hours" class="input" />
          </div>
          <div v-if="realizeVariance > 0.5"
            class="bg-amber-50 border border-amber-200 rounded p-2 text-xs text-amber-800 flex items-start gap-1.5">
            <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
            <div>
              {{ t('overtime.modal_variance_warn', { n: (realizeVariance * 100).toFixed(0) }) }}
            </div>
          </div>
          <div>
            <label class="label">
              {{ t('overtime.modal_note_label') }}
              <span v-if="realizeVariance > 0.5" class="text-red-500">{{ t('overtime.required') }}</span>
              <span v-else class="text-gray-400">{{ t('overtime.optional') }}</span>
            </label>
            <textarea v-model="realizeForm.variance_note" class="input" rows="3"
              :placeholder="t('overtime.modal_note_placeholder')"></textarea>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="realizingId = null">{{ t('overtime.cancel_btn') }}</button>
          <button class="btn-primary" @click="realizePlan">
            <CheckCircleIcon class="w-4 h-4" /> {{ t('overtime.modal_confirm_btn') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
