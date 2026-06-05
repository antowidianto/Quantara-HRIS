<script setup>
/**
 * Attendance/Anomalies.vue — HR review attendance anomaly + device management.
 *
 * Endpoint:
 *   GET    /attendance-anomaly?min_score=&reviewed=&from_date=&to_date=&page=&per_page=
 *   GET    /attendance-anomaly/stats?from_date=&to_date=
 *   POST   /attendance-anomaly/:id/review { note }
 *   GET    /attendance-devices?user_id=
 *   POST   /attendance-devices/:id/trust
 *   POST   /attendance-devices/:id/block { reason }
 *
 * Layout: tab "Anomali" (list + detail review modal) dan "Device" (list + trust/block).
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useConfirm, promptDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import {
  ShieldExclamationIcon, ExclamationTriangleIcon, CheckCircleIcon,
  DevicePhoneMobileIcon, NoSymbolIcon, HandThumbUpIcon, FlagIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { confirm } = useConfirm()
const { t } = useI18n()

const tab = ref('anomalies') // 'anomalies' | 'devices'

// ─── Anomalies ──────────────────────────────────────────────────────
const anomalies = ref([])
const aLoading = ref(false)
const aPage = ref(1)
const aTotal = ref(0)
const aFilters = ref({
  min_score: 30,
  reviewed: '',     // '' | 0 | 1
  from_date: '',
  to_date: '',
})

const stats = ref(null)

// Modal review
const reviewing = ref(null)
const reviewNote = ref('')
const reviewSubmitting = ref(false)

// ─── Devices ────────────────────────────────────────────────────────
const devices = ref([])
const dLoading = ref(false)

async function fetchAnomalies() {
  aLoading.value = true
  try {
    const res = await api.get('/attendance-anomaly', {
      params: {
        page: aPage.value,
        per_page: 20,
        min_score: aFilters.value.min_score || 0,
        reviewed: aFilters.value.reviewed === '' ? undefined : aFilters.value.reviewed,
        from_date: aFilters.value.from_date || undefined,
        to_date: aFilters.value.to_date || undefined,
      },
    })
    const data = res.data?.data || {}
    anomalies.value = data.items || data.rows || data || []
    aTotal.value = data.total || anomalies.value.length || 0
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.anomaly.load_failed'))
  } finally {
    aLoading.value = false
  }
}

async function fetchStats() {
  try {
    const res = await api.get('/attendance-anomaly/stats', {
      params: {
        from_date: aFilters.value.from_date || undefined,
        to_date: aFilters.value.to_date || undefined,
      },
    })
    stats.value = res.data?.data || null
  } catch (e) {
    // Silent — stats card optional
  }
}

async function fetchDevices() {
  dLoading.value = true
  try {
    const res = await api.get('/attendance-devices')
    devices.value = res.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.anomaly.load_devices_failed'))
  } finally {
    dLoading.value = false
  }
}

function openReview(row) {
  reviewing.value = row
  reviewNote.value = ''
}
async function submitReview() {
  if (! reviewing.value) return
  if (reviewNote.value.trim().length < 5) {
    toast.warning(t('attendance.anomaly.review_min'))
    return
  }
  reviewSubmitting.value = true
  try {
    await api.post(`/attendance-anomaly/${reviewing.value.id}/review`, {
      note: reviewNote.value.trim(),
    })
    toast.success(t('attendance.anomaly.reviewed_ok'))
    reviewing.value = null
    reviewNote.value = ''
    fetchAnomalies()
    fetchStats()
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.anomaly.review_failed'))
  } finally {
    reviewSubmitting.value = false
  }
}

async function trustDevice(d) {
  const ok = await confirm({
    title: t('attendance.anomaly.trust_title'),
    message: t('attendance.anomaly.trust_message', { name: d.device_name || d.device_fingerprint?.substring(0, 16) }),
    confirmText: t('attendance.anomaly.trust_confirm'),
    type: 'info',
  })
  if (! ok) return
  try {
    await api.post(`/attendance-devices/${d.id}/trust`)
    toast.success(t('attendance.anomaly.trusted_ok'))
    fetchDevices()
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.anomaly.trust_failed'))
  }
}

async function blockDevice(d) {
  const reason = await promptDialog({
    title: t('attendance.anomaly.block_title', { name: d.device_name || 'unknown' }),
    message: t('attendance.anomaly.block_message'),
    inputLabel: t('attendance.anomaly.block_reason_label'),
    inputType: 'textarea',
    inputRequired: true,
    okText: t('attendance.anomaly.block_ok'),
    variant: 'danger',
  })
  if (! reason || reason.trim().length < 5) {
    if (reason !== null) toast.warning(t('attendance.anomaly.block_min'))
    return
  }
  try {
    await api.post(`/attendance-devices/${d.id}/block`, { reason: reason.trim() })
    toast.success(t('attendance.anomaly.blocked_ok'))
    fetchDevices()
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.anomaly.block_failed'))
  }
}

function applyFilters() {
  aPage.value = 1
  fetchAnomalies()
  fetchStats()
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

function fmtDateOnly(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

const flagDescriptions = computed(() => ({
  MOCK_GPS:        { label: t('attendance.anomaly.flag_mock_gps'),       color: 'bg-red-100 text-red-700' },
  LOW_ACCURACY:    { label: t('attendance.anomaly.flag_low_accuracy'),   color: 'bg-amber-100 text-amber-700' },
  NEW_DEVICE:      { label: t('attendance.anomaly.flag_new_device'),     color: 'bg-blue-100 text-blue-700' },
  BLOCKED_DEVICE:  { label: t('attendance.anomaly.flag_blocked_device'), color: 'bg-red-100 text-red-700' },
  IP_MISMATCH:     { label: t('attendance.anomaly.flag_ip_mismatch'),    color: 'bg-amber-100 text-amber-700' },
  GEOFENCE_FAR:    { label: t('attendance.anomaly.flag_geofence_far'),   color: 'bg-amber-100 text-amber-700' },
  NO_SELFIE:       { label: t('attendance.anomaly.flag_no_selfie'),      color: 'bg-gray-100 text-gray-700' },
}))

function scoreColor(score) {
  const s = Number(score) || 0
  if (s >= 70) return 'bg-red-100 text-red-700 border-red-300'
  if (s >= 40) return 'bg-amber-100 text-amber-700 border-amber-300'
  return 'bg-yellow-50 text-yellow-700 border-yellow-200'
}

onMounted(() => {
  fetchAnomalies()
  fetchStats()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShieldExclamationIcon class="w-6 h-6 text-red-600" />
          {{ t('attendance.anomaly.title') }}
        </h1>
        <p class="text-sm text-gray-500">
          {{ t('attendance.anomaly.subtitle') }}
        </p>
      </div>
      <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
        <button
          class="px-3 py-1.5 text-sm rounded-md transition"
          :class="tab === 'anomalies' ? 'bg-white text-primary-700 shadow' : 'text-gray-600'"
          @click="tab = 'anomalies'"
        >
          <FlagIcon class="w-4 h-4 inline -mt-0.5" /> {{ t('attendance.anomaly.tab_anomalies') }}
        </button>
        <button
          class="px-3 py-1.5 text-sm rounded-md transition"
          :class="tab === 'devices' ? 'bg-white text-primary-700 shadow' : 'text-gray-600'"
          @click="tab = 'devices'; fetchDevices()"
        >
          <DevicePhoneMobileIcon class="w-4 h-4 inline -mt-0.5" /> {{ t('attendance.anomaly.tab_devices') }}
        </button>
      </div>
    </div>

    <!-- ANOMALI TAB ───────────────────────────────────────────────── -->
    <div v-if="tab === 'anomalies'">
      <!-- Stats -->
      <div v-if="stats" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card p-3">
          <div class="text-xs text-gray-500">{{ t('attendance.anomaly.stat_total') }}</div>
          <div class="text-xl font-bold">{{ stats.total ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">{{ t('attendance.anomaly.stat_reviewed') }}</div>
          <div class="text-xl font-bold text-emerald-600">{{ stats.reviewed ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">{{ t('attendance.anomaly.stat_unreviewed') }}</div>
          <div class="text-xl font-bold text-amber-600">{{ stats.unreviewed ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">{{ t('attendance.anomaly.stat_max_score') }}</div>
          <div class="text-xl font-bold text-red-600">{{ stats.max_score ?? 0 }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-body grid grid-cols-1 md:grid-cols-5 gap-3">
          <div>
            <label class="label text-xs">{{ t('attendance.anomaly.filter_min_score') }}</label>
            <input v-model.number="aFilters.min_score" type="number" min="0" max="100" class="input" />
          </div>
          <div>
            <label class="label text-xs">{{ t('attendance.anomaly.filter_review_status') }}</label>
            <select v-model="aFilters.reviewed" class="input">
              <option value="">{{ t('attendance.anomaly.filter_all') }}</option>
              <option :value="0">{{ t('attendance.anomaly.filter_unreviewed') }}</option>
              <option :value="1">{{ t('attendance.anomaly.filter_reviewed') }}</option>
            </select>
          </div>
          <div>
            <label class="label text-xs">{{ t('attendance.anomaly.filter_from') }}</label>
            <input v-model="aFilters.from_date" type="date" class="input" />
          </div>
          <div>
            <label class="label text-xs">{{ t('attendance.anomaly.filter_to') }}</label>
            <input v-model="aFilters.to_date" type="date" class="input" />
          </div>
          <div class="flex items-end">
            <button class="btn-primary w-full" @click="applyFilters">{{ t('common.filter') }}</button>
          </div>
        </div>
      </div>

      <!-- List -->
      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_employee') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_date') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_clock') }}</th>
              <th class="px-3 py-2 text-center">{{ t('attendance.anomaly.col_score') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_flags') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_status') }}</th>
              <th class="px-3 py-2 text-right">{{ t('attendance.anomaly.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="aLoading">
              <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ t('attendance.anomaly.loading') }}</td>
            </tr>
            <tr v-else-if="!anomalies.length">
              <td colspan="7" class="px-4 py-12 text-center text-gray-400">
                <CheckCircleIcon class="w-10 h-10 mx-auto mb-2 text-emerald-300" />
                {{ t('attendance.anomaly.no_anomalies') }}
              </td>
            </tr>
            <tr v-for="a in anomalies" :key="a.id" class="hover:bg-gray-50">
              <td class="px-3 py-2">
                <div class="font-medium">{{ a.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ a.employee_number }} · {{ a.department_name || '-' }}</div>
              </td>
              <td class="px-3 py-2">{{ fmtDateOnly(a.attendance_date) }}</td>
              <td class="px-3 py-2 text-xs">
                <div v-if="a.clock_in_time">{{ t('attendance.anomaly.in_prefix', { time: a.clock_in_time }) }}</div>
                <div v-if="a.clock_out_time">{{ t('attendance.anomaly.out_prefix', { time: a.clock_out_time }) }}</div>
              </td>
              <td class="px-3 py-2 text-center">
                <span :class="['inline-block px-2 py-0.5 rounded border text-xs font-bold', scoreColor(a.anomaly_score)]">
                  {{ a.anomaly_score }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-1">
                  <span v-for="f in a.anomaly_flags || []" :key="f"
                    class="text-[10px] px-1.5 py-0.5 rounded font-mono"
                    :class="(flagDescriptions[f] || {}).color || 'bg-gray-100 text-gray-700'"
                    :title="(flagDescriptions[f] || {}).label || f">
                    {{ f }}
                  </span>
                </div>
              </td>
              <td class="px-3 py-2 text-xs">
                <span v-if="a.anomaly_reviewed_at" class="text-emerald-700 flex items-center gap-1">
                  <CheckCircleIcon class="w-3.5 h-3.5" /> {{ fmtDate(a.anomaly_reviewed_at) }}
                </span>
                <span v-else class="text-amber-700 flex items-center gap-1">
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" /> {{ t('attendance.anomaly.reviewed_label') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <button v-if="!a.anomaly_reviewed_at" class="btn-secondary text-xs" @click="openReview(a)">
                  {{ t('attendance.anomaly.review') }}
                </button>
                <button v-else class="btn-secondary text-xs" @click="openReview(a)" :title="t('attendance.anomaly.view_add_note')">
                  {{ t('attendance.anomaly.view') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- DEVICE TAB ───────────────────────────────────────────────── -->
    <div v-else>
      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_user') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_device') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_seen') }}</th>
              <th class="px-3 py-2 text-center">{{ t('attendance.anomaly.col_usage') }}</th>
              <th class="px-3 py-2 text-left">{{ t('attendance.anomaly.col_status') }}</th>
              <th class="px-3 py-2 text-right">{{ t('attendance.anomaly.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="dLoading">
              <td colspan="6" class="px-4 py-6 text-center text-gray-400">{{ t('attendance.anomaly.loading') }}</td>
            </tr>
            <tr v-else-if="!devices.length">
              <td colspan="6" class="px-4 py-12 text-center text-gray-400">{{ t('attendance.anomaly.no_devices') }}</td>
            </tr>
            <tr v-for="d in devices" :key="d.id" class="hover:bg-gray-50"
              :class="{ 'bg-red-50/40': d.is_blocked }">
              <td class="px-3 py-2">
                <div class="font-medium">{{ d.user_name || d.employee_name || '-' }}</div>
                <div class="text-xs text-gray-500">{{ d.employee_number || '-' }}</div>
              </td>
              <td class="px-3 py-2">
                <div class="text-sm">{{ d.device_name || t('attendance.anomaly.unknown_device') }}</div>
                <code class="text-[10px] text-gray-500 break-all">
                  {{ (d.device_fingerprint || '').substring(0, 32) }}…
                </code>
              </td>
              <td class="px-3 py-2 text-xs">
                <div>{{ t('attendance.anomaly.first_seen', { time: fmtDate(d.first_seen_at) }) }}</div>
                <div>{{ t('attendance.anomaly.last_seen', { time: fmtDate(d.last_seen_at) }) }}</div>
              </td>
              <td class="px-3 py-2 text-center">{{ t('attendance.anomaly.usage_count', { n: d.usage_count || 0 }) }}</td>
              <td class="px-3 py-2">
                <span v-if="d.is_blocked" class="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700">{{ t('attendance.anomaly.blocked') }}</span>
                <span v-else-if="d.is_trusted" class="text-xs px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700">{{ t('attendance.anomaly.trusted') }}</span>
                <span v-else class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">{{ t('attendance.anomaly.normal') }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button v-if="!d.is_trusted && !d.is_blocked"
                  class="btn-secondary text-xs text-emerald-600 mr-1" @click="trustDevice(d)">
                  <HandThumbUpIcon class="w-3.5 h-3.5" /> {{ t('attendance.anomaly.trust') }}
                </button>
                <button v-if="!d.is_blocked"
                  class="btn-secondary text-xs text-red-600" @click="blockDevice(d)">
                  <NoSymbolIcon class="w-3.5 h-3.5" /> {{ t('attendance.anomaly.block') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- REVIEW MODAL ─────────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="reviewing" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-lg shadow-xl">
          <div class="px-5 py-3 border-b">
            <div class="text-lg font-semibold">{{ t('attendance.anomaly.review_modal_title') }}</div>
            <div class="text-xs text-gray-500">
              {{ t('attendance.anomaly.review_modal_sub', { name: reviewing.employee_name, date: fmtDateOnly(reviewing.attendance_date), score: reviewing.anomaly_score }) }}
            </div>
          </div>
          <div class="p-5 space-y-3">
            <div class="bg-gray-50 rounded p-3 text-xs">
              <div class="font-semibold mb-1">{{ t('attendance.anomaly.flags_detected') }}</div>
              <div class="flex flex-wrap gap-1">
                <span v-for="f in reviewing.anomaly_flags || []" :key="f"
                  class="text-[10px] px-1.5 py-0.5 rounded"
                  :class="(flagDescriptions[f] || {}).color || 'bg-gray-100 text-gray-700'">
                  {{ (flagDescriptions[f] || {}).label || f }}
                </span>
              </div>
              <div v-if="reviewing.gps_accuracy" class="mt-2 text-gray-600">
                {{ t('attendance.anomaly.gps_accuracy', { n: reviewing.gps_accuracy }) }}
              </div>
              <div v-if="reviewing.ip_address" class="text-gray-600">
                {{ t('attendance.anomaly.ip_label', { ip: reviewing.ip_address }) }}
              </div>
            </div>
            <div v-if="reviewing.anomaly_reviewed_at" class="text-xs text-emerald-700 italic">
              {{ t('attendance.anomaly.already_reviewed', { time: fmtDate(reviewing.anomaly_reviewed_at) }) }}
              <span v-if="reviewing.anomaly_review_note">
                — "{{ reviewing.anomaly_review_note }}"
              </span>
            </div>
            <div v-if="!reviewing.anomaly_reviewed_at">
              <label class="label">{{ t('attendance.anomaly.review_note') }}</label>
              <textarea v-model="reviewNote" rows="4" class="input"
                :placeholder="t('attendance.anomaly.review_note_ph')"></textarea>
            </div>
          </div>
          <div class="px-5 py-3 border-t flex justify-end gap-2">
            <button class="btn-secondary" @click="reviewing = null">{{ t('attendance.anomaly.close') }}</button>
            <button v-if="!reviewing.anomaly_reviewed_at"
              class="btn-primary" :disabled="reviewSubmitting" @click="submitReview">
              {{ reviewSubmitting ? t('attendance.anomaly.saving') : t('attendance.anomaly.mark_reviewed') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>