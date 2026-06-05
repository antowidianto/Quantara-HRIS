<script setup>
import { ref, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import FileUploader from '@/components/FileUploader.vue'
import { useI18n } from '@/locales'

const props = defineProps({
  day: { type: Object, required: true },
})
const emit = defineEmits(['close', 'submitted'])
const toast = useToast()
const { t } = useI18n()

const submitting = ref(false)

const form = ref({
  change_type: 'BOTH',
  // Default jam: dari shift kalau ada, sekitar 09:00–17:00.
  requested_clock_in_time:  props.day.shift?.start_time?.slice(0, 5) || '09:00',
  requested_clock_out_time: props.day.shift?.end_time?.slice(0, 5)   || '17:00',
  requested_status: '',
  reason: '',
  attachment_path: '',
})

const typeOptions = computed(() => [
  { value: 'CLOCK_IN',  label: t('attendance.change_modal.opt_clock_in') },
  { value: 'CLOCK_OUT', label: t('attendance.change_modal.opt_clock_out') },
  { value: 'BOTH',      label: t('attendance.change_modal.opt_both') },
  { value: 'STATUS',    label: t('attendance.change_modal.opt_status') },
  { value: 'MISSED',    label: t('attendance.change_modal.opt_missed') },
])

const showInTime  = computed(() => ['CLOCK_IN', 'BOTH', 'MISSED'].includes(form.value.change_type))
const showOutTime = computed(() => ['CLOCK_OUT', 'BOTH', 'MISSED'].includes(form.value.change_type))
const showStatus  = computed(() => form.value.change_type === 'STATUS')

function buildDt(time) {
  if (!time) return null
  // Normalize to HH:MM:SS
  const t = time.length === 5 ? `${time}:00` : time
  return `${props.day.date} ${t}`
}

async function submit() {
  if (form.value.reason.trim().length < 10) {
    toast.warning(t('attendance.change_modal.reason_min'))
    return
  }
  submitting.value = true
  try {
    const payload = {
      work_date: props.day.date,
      change_type: form.value.change_type,
      reason: form.value.reason.trim(),
    }
    if (showInTime.value)  payload.requested_clock_in  = buildDt(form.value.requested_clock_in_time)
    if (showOutTime.value) payload.requested_clock_out = buildDt(form.value.requested_clock_out_time)
    if (showStatus.value)  payload.requested_status   = form.value.requested_status
    if (form.value.attachment_path) payload.attachment_path = form.value.attachment_path

    await api.post('/attendance/change-requests', payload)
    emit('submitted')
  } catch (e) {
    const msg = e.response?.data?.message || t('attendance.change_modal.send_failed')
    const errors = e.response?.data?.errors
    if (errors) {
      toast.error(msg + ': ' + Object.values(errors).flat().join(', '))
    } else {
      toast.error(msg)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
      <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold">{{ t('attendance.change_modal.title') }}</h2>
        <button class="text-gray-400 hover:text-gray-600" @click="emit('close')">✕</button>
      </div>

      <div class="p-5 space-y-4">
        <div class="bg-gray-50 rounded p-3 text-sm">
          <div class="font-medium">{{ day.day_name }}, {{ day.date }}</div>
          <div class="text-gray-600 text-xs mt-1">{{ t('attendance.change_modal.current_status') }} <span class="font-medium">{{ day.status_label }}</span></div>
          <div v-if="day.attendance" class="text-xs text-gray-600 mt-1">
            {{ t('attendance.change_modal.current_times', { in: day.attendance.clock_in_at?.slice(11, 16) || '-', out: day.attendance.clock_out_at?.slice(11, 16) || '-' }) }}
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.change_type') }}</label>
          <select v-model="form.change_type" class="input w-full">
            <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>

        <div v-if="showInTime">
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.requested_in') }}</label>
          <input type="time" v-model="form.requested_clock_in_time" class="input w-full" />
        </div>

        <div v-if="showOutTime">
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.requested_out') }}</label>
          <input type="time" v-model="form.requested_clock_out_time" class="input w-full" />
        </div>

        <div v-if="showStatus">
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.new_status') }}</label>
          <select v-model="form.requested_status" class="input w-full">
            <option value="">{{ t('attendance.change_modal.choose') }}</option>
            <option value="PRESENT">{{ t('attendance.change_modal.st_present') }}</option>
            <option value="LATE">{{ t('attendance.change_modal.st_late') }}</option>
            <option value="SICK">{{ t('attendance.change_modal.st_sick') }}</option>
            <option value="LEAVE">{{ t('attendance.change_modal.st_leave') }}</option>
            <option value="HALF_DAY">{{ t('attendance.change_modal.st_half_day') }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.reason') }} <span class="text-red-500">*</span></label>
          <textarea v-model="form.reason" rows="4" class="input w-full"
            :placeholder="t('attendance.change_modal.reason_ph')"></textarea>
          <div class="text-xs text-gray-500 mt-1">{{ t('attendance.change_modal.reason_hint') }}</div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('attendance.change_modal.attachment') }}</label>
          <FileUploader
            v-model="form.attachment_path"
            context="change_request"
            :hint="t('attendance.change_modal.attachment_hint')"
          />
        </div>
      </div>

      <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
        <button class="btn-secondary" @click="emit('close')" :disabled="submitting">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="submitting" @click="submit">
          {{ submitting ? t('attendance.change_modal.submitting') : t('attendance.change_modal.submit') }}
        </button>
      </div>
    </div>
  </div>
</template>