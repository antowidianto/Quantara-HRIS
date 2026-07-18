<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { InformationCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import FileUploader from '@/components/FileUploader.vue'
import { useI18n } from '@/locales'

/**
 * Form pengajuan cuti / izin (full page).
 *
 * Sumber tipe cuti: GET /leave-types/for-me — backend memfilter berdasarkan
 * gender, status menikah, masa kerja, dan saldo, plus mengembalikan window
 * tanggal yang dibolehkan per kategori (mengikuti payroll yang aktif).
 */

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()

const types = ref([])
const policy = ref(null)
const loadingTypes = ref(false)
const saving = ref(false)

const form = ref({
  leave_type_id: '',
  start_date: '',
  end_date: '',
  reason: '',
  is_half_day: false,
  half_day_part: 'MORNING',
  attachment: '',
  contact_during_leave: '',
})

function fmtDateID(d) {
  if (! d) return ''
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

function clampDate(d, min, max) {
  if (! d) return d
  if (min && d < min) return min
  if (max && d > max) return max
  return d
}

const categoryLabel = computed(() => ({
  ANNUAL:      t('leave.quick.cat_annual'),
  SICK:        t('leave.quick.cat_sick'),
  MATERNITY:   t('leave.quick.cat_maternity'),
  PATERNITY:   t('leave.quick.cat_paternity'),
  MENSTRUAL:   t('leave.quick.cat_menstrual'),
  MARRIAGE:    t('leave.quick.cat_marriage'),
  BEREAVEMENT: t('leave.quick.cat_bereavement'),
  HAJJ:        t('leave.quick.cat_hajj'),
  UNPAID:      t('leave.quick.cat_unpaid'),
  SPECIAL:     t('leave.quick.cat_special'),
}))

const eligibleTypes   = computed(() => types.value.filter(t => t.eligible))
const ineligibleTypes = computed(() => types.value.filter(t => ! t.eligible))

const selectedType = computed(() =>
  types.value.find(t => t.id === Number(form.value.leave_type_id)) || null
)

// Konflik tim: tarik /leaves/team-calendar untuk window pilihan saya, lalu
// hitung berapa rekan satu cabang/dept yang juga cuti pada hari yang sama.
// Tujuannya bukan blokir, tapi memberi sinyal awal supaya user
// mempertimbangkan ulang (mis. "3 rekan se-tim juga cuti tgl segini").
const teamConflicts = ref([])
const conflictsLoading = ref(false)
let conflictTimer = null

async function fetchConflicts() {
  if (! form.value.start_date || ! form.value.end_date) {
    teamConflicts.value = []
    return
  }
  conflictsLoading.value = true
  try {
    const res = await api.get('/leaves/team-calendar', {
      params: { start_date: form.value.start_date, end_date: form.value.end_date },
    })
    // Backend (LeaveController::teamCalendar) return { leaves: [...], count, ... }
    // dengan tiap row punya employee_id + leave_name + start/end_date.
    const data = res.data?.data || {}
    const leaves = Array.isArray(data.leaves) ? data.leaves : []
    const myEmpId = Number(auth?.user?.employee_id || auth?.profile?.employee_id || 0)
    teamConflicts.value = leaves.filter(l => Number(l.employee_id) !== myEmpId)
  } catch (e) {
    // Jangan ganggu user dengan toast — silent fail.
    teamConflicts.value = []
  } finally {
    conflictsLoading.value = false
  }
}

function debounceFetchConflicts() {
  if (conflictTimer) clearTimeout(conflictTimer)
  conflictTimer = setTimeout(fetchConflicts, 350)
}

onMounted(async () => {
  loadingTypes.value = true
  try {
    const res = await api.get('/leave-types/for-me')
    if (Array.isArray(res.data.data)) {
      types.value = res.data.data
    } else {
      types.value = res.data.data?.types || []
      policy.value = res.data.data?.policy || null
    }
    if (eligibleTypes.value.length) {
      const t = eligibleTypes.value[0]
      form.value.leave_type_id = t.id
      form.value.start_date = t.min_start_date || new Date().toISOString().slice(0, 10)
      form.value.end_date   = form.value.start_date
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('leave.form.load_failed'))
  } finally {
    loadingTypes.value = false
  }
})

// Saat user pindah tipe cuti, clamp ulang tanggal ke window-nya.
watch(() => form.value.leave_type_id, () => {
  const t = selectedType.value
  if (! t) return
  form.value.start_date = clampDate(form.value.start_date || t.min_start_date, t.min_start_date, t.max_start_date)
  if (form.value.end_date < form.value.start_date) form.value.end_date = form.value.start_date
  form.value.end_date = clampDate(form.value.end_date, t.min_start_date, t.max_start_date)
})

watch(() => form.value.start_date, (newVal) => {
  if (form.value.end_date < newVal) form.value.end_date = newVal
  debounceFetchConflicts()
})

watch(() => form.value.end_date, () => debounceFetchConflicts())

async function submit() {
  if (! form.value.leave_type_id) {
    toast.warning(t('leave.form.pick_type'))
    return
  }
  if (selectedType.value && ! selectedType.value.eligible) {
    toast.error(t('leave.form.not_eligible') + selectedType.value.reasons.join(', '))
    return
  }
  // Pre-validation tanggal di client supaya feedback instan.
  if (selectedType.value) {
    if (form.value.start_date < selectedType.value.min_start_date) {
      toast.error(t('leave.form.start_before', { date: fmtDateID(selectedType.value.min_start_date) }))
      return
    }
    if (form.value.start_date > selectedType.value.max_start_date) {
      toast.error(t('leave.form.start_after', { date: fmtDateID(selectedType.value.max_start_date) }))
      return
    }
  }
  if (form.value.reason.trim().length < 5) {
    toast.warning(t('leave.form.reason_min'))
    return
  }
  if (selectedType.value?.requires_doc && ! form.value.attachment.trim()) {
    toast.warning(t('leave.form.doc_required', { name: selectedType.value.name }))
    return
  }

  saving.value = true
  try {
    const payload = {
      leave_type_id: Number(form.value.leave_type_id),
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      reason: form.value.reason.trim(),
      is_half_day: form.value.is_half_day ? 1 : 0,
    }
    if (form.value.is_half_day) payload.half_day_part = form.value.half_day_part
    if (form.value.attachment.trim()) payload.attachment = form.value.attachment.trim()
    if (form.value.contact_during_leave.trim()) {
      payload.contact_during_leave = form.value.contact_during_leave.trim()
    }
    await api.post('/leaves', payload)
    toast.success(t('leave.form.submitted'))
    router.push('/leaves')
  } catch (e) {
    const msg = e.response?.data?.message || t('leave.form.submit_failed')
    const errors = e.response?.data?.errors
    toast.error(errors ? `${msg}: ${Object.values(errors).flat().join(', ')}` : msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-1">{{ t('leave.form.title') }}</h1>
    <p class="text-sm text-gray-500 mb-4">
      {{ t('leave.form.subtitle') }}
    </p>

    <div v-if="loadingTypes" class="card p-6 text-center text-gray-500">
      {{ t('leave.form.loading') }}
    </div>

    <div v-else-if="eligibleTypes.length === 0" class="card p-6">
      <div class="flex items-start gap-3 text-amber-800">
        <ExclamationTriangleIcon class="w-6 h-6 flex-shrink-0 mt-0.5" />
        <div>
          <div class="font-semibold">{{ t('leave.form.no_types_title') }}</div>
          <div class="text-sm text-gray-700 mt-1">
            {{ t('leave.form.no_types_note') }}
          </div>
        </div>
      </div>
      <ul v-if="ineligibleTypes.length" class="mt-4 text-xs text-gray-600 space-y-1">
        <li v-for="lt in ineligibleTypes" :key="lt.id">
          🔒 <strong>{{ lt.name }}</strong> — {{ lt.reasons.join(', ') }}
        </li>
      </ul>
    </div>

    <form v-else @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <!-- Banner periode payroll aktif -->
        <div v-if="policy?.current_period"
          class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-900">
          <div class="font-medium">{{ t('leave.form.active_period', { name: policy.current_period.name }) }}</div>
          <div>
            {{ t('leave.form.period_range', { from: fmtDateID(policy.current_period.start_date), to: fmtDateID(policy.current_period.end_date), pay: fmtDateID(policy.current_period.pay_date) }) }}
          </div>
          <div class="text-[11px] text-blue-700 mt-1">{{ policy.help_text }}</div>
        </div>
        <div v-else-if="policy"
          class="rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
          {{ t('leave.form.no_active_period') }}
        </div>

        <div>
          <label class="label">{{ t('leave.form.type_label') }}</label>
          <select v-model="form.leave_type_id" class="input" required>
            <optgroup :label="t('leave.form.group_available')">
              <option v-for="lt in eligibleTypes" :key="lt.id" :value="lt.id">
                {{ lt.name }} ({{ categoryLabel[lt.category] || lt.category }})
                <template v-if="lt.gender_restriction === 'F'"> ♀</template>
                <template v-else-if="lt.gender_restriction === 'M'"> ♂</template>
                <template v-if="lt.is_system_mandatory"> · wajib terbayar</template>
                <template v-if="lt.balance">{{ t('leave.form.balance_remaining', { remaining: lt.balance.remaining, allocated: lt.balance.allocated }) }}</template>
              </option>
            </optgroup>
            <optgroup v-if="ineligibleTypes.length" :label="t('leave.form.group_unavailable')">
              <option v-for="lt in ineligibleTypes" :key="lt.id" disabled :value="lt.id">
                {{ lt.name }} — {{ lt.reasons[0] }}
              </option>
            </optgroup>
          </select>

          <div v-if="selectedType" class="mt-3 rounded-md bg-blue-50/60 border border-blue-100 p-3 text-xs space-y-1.5">
            <div v-if="selectedType.description" class="text-gray-700 italic">
              {{ selectedType.description }}
            </div>
            <div class="flex flex-wrap gap-1.5">
              <span v-if="selectedType.balance"
                class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                {{ t('leave.form.balance_badge', { remaining: selectedType.balance.remaining, allocated: selectedType.balance.allocated }) }}
              </span>
              <span v-if="selectedType.is_paid"
                class="px-2 py-0.5 rounded-full bg-green-100 text-green-800">{{ t('leave.form.paid') }}</span>
              <span v-else class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ t('leave.form.unpaid') }}</span>
              <!-- H1.11 — Penanda cuti wajib & gender restriction -->
              <span v-if="selectedType.is_system_mandatory"
                class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800"
                title="Cuti wajib UU 13/2003 / UU KIA — tidak memotong saldo cuti tahunan">
                Cuti Wajib · Tidak potong saldo
              </span>
              <span v-if="selectedType.gender_restriction === 'F'"
                class="px-2 py-0.5 rounded-full bg-pink-100 text-pink-800"
                title="Hanya untuk karyawan perempuan">
                ♀ Khusus Perempuan
              </span>
              <span v-else-if="selectedType.gender_restriction === 'M'"
                class="px-2 py-0.5 rounded-full bg-sky-100 text-sky-800"
                title="Hanya untuk karyawan laki-laki">
                ♂ Khusus Laki-laki
              </span>
              <span v-if="selectedType.default_days"
                class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                {{ t('leave.form.default_days', { n: selectedType.default_days }) }}
              </span>
              <span v-if="selectedType.min_tenure_months"
                class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                {{ t('leave.form.min_tenure_badge', { n: selectedType.min_tenure_months }) }}
              </span>
            </div>
            <div v-if="selectedType.requires_doc" class="flex items-center gap-1 text-amber-700">
              <InformationCircleIcon class="w-3.5 h-3.5" />
              {{ t('leave.form.requires_doc') }}
            </div>
          </div>
        </div>

        <div v-if="selectedType" class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">{{ t('leave.form.start_date') }}</label>
            <AppDatePicker v-model="form.start_date"  class="input" required
              :min="selectedType.min_start_date" :max="selectedType.max_start_date" />
          </div>
          <div>
            <label class="label">{{ t('leave.form.end_date') }}</label>
            <AppDatePicker v-model="form.end_date"  class="input" required
              :min="form.start_date" :max="selectedType.max_start_date" />
          </div>
          <div class="col-span-2 -mt-1 text-xs text-gray-500">
            {{ t('leave.form.window_note') }}
            <span class="font-medium">{{ fmtDateID(selectedType.min_start_date) }}</span> –
            <span class="font-medium">{{ fmtDateID(selectedType.max_start_date) }}</span>.
            <span v-if="selectedType.category === 'SICK'" class="text-amber-700">
              {{ t('leave.form.sick_note') }}
            </span>
          </div>

          <!-- Notifikasi bentrok cuti tim -->
          <div v-if="teamConflicts.length" class="col-span-2 rounded-md bg-amber-50 border border-amber-200 p-3">
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
              <div class="text-xs text-amber-900">
                <div class="font-semibold mb-1">
                  {{ t('leave.form.conflicts_title', { n: teamConflicts.length }) }}
                </div>
                <ul class="space-y-0.5 max-h-24 overflow-y-auto">
                  <li v-for="c in teamConflicts.slice(0, 8)" :key="c.id">
                    • <span class="font-medium">{{ c.employee_name }}</span>
                    <span v-if="c.leave_name" class="text-amber-700">— {{ c.leave_name }}</span>
                    <span v-if="c.start_date" class="text-amber-700">
                      ({{ fmtDateID(c.start_date) }}<span v-if="c.end_date && c.end_date !== c.start_date">–{{ fmtDateID(c.end_date) }}</span>)
                    </span>
                    <span v-if="c.status === 'PENDING'" class="text-gray-500 italic">{{ t('leave.form.conflict_pending') }}</span>
                  </li>
                  <li v-if="teamConflicts.length > 8" class="text-amber-700 italic">
                    {{ t('leave.form.conflict_more', { n: teamConflicts.length - 8 }) }}
                  </li>
                </ul>
                <div class="mt-1.5 text-amber-700 italic">
                  {{ t('leave.form.conflict_note') }}
                </div>
              </div>
            </div>
          </div>
          <div v-else-if="conflictsLoading" class="col-span-2 text-xs text-gray-400 italic">
            {{ t('leave.form.checking_team') }}
          </div>
        </div>

        <label class="flex items-center gap-2">
          <input type="checkbox" v-model="form.is_half_day" />
          <span class="text-sm">{{ t('leave.form.half_day') }}</span>
        </label>

        <div v-if="form.is_half_day">
          <label class="label">{{ t('leave.form.part') }}</label>
          <select v-model="form.half_day_part" class="input">
            <option value="MORNING">{{ t('leave.form.morning') }}</option>
            <option value="AFTERNOON">{{ t('leave.form.afternoon') }}</option>
          </select>
        </div>

        <div>
          <label class="label">{{ t('leave.form.reason') }}</label>
          <textarea v-model="form.reason" rows="3" class="input" required
            :placeholder="t('leave.form.reason_ph')"></textarea>
        </div>

        <div>
          <label class="label">
            {{ t('leave.form.attachment') }}
            <span v-if="selectedType?.requires_doc" class="text-red-500">*</span>
            <span v-else class="text-gray-400">{{ t('leave.form.optional') }}</span>
          </label>
          <FileUploader
            v-model="form.attachment"
            context="leave"
            :hint="t('leave.form.attachment_hint')"
          />
        </div>

        <div>
          <label class="label">{{ t('leave.form.contact') }}</label>
          <input v-model="form.contact_during_leave" type="text" class="input"
            :placeholder="t('leave.form.contact_ph')" />
        </div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? t('leave.form.submitting') : t('leave.form.submit') }}
        </button>
      </div>
    </form>
  </div>
</template>