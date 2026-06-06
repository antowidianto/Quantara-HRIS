<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const router = useRouter()
const toast = useToast()
const { t } = useI18n()
const saving = ref(false)
const form = ref({
  work_date: new Date().toISOString().slice(0, 10),
  start_time: '',
  end_time: '',
  reason: '',
  compensation_type: 'CASH',
})

const hours = computed(() => {
  if (! form.value.start_time || ! form.value.end_time) return 0
  const [sh, sm] = form.value.start_time.split(':').map(Number)
  const [eh, em] = form.value.end_time.split(':').map(Number)
  const startMin = sh * 60 + sm
  const endMin = eh * 60 + em
  if (endMin <= startMin) return 0
  return Math.round(((endMin - startMin) / 60) * 100) / 100
})

const leaveDaysIfConverted = computed(() => {
  // Default ratio 1.0, hari kerja 8 jam. Backend bisa override per company.
  return Math.round((hours.value / 8) * 100) / 100
})

async function submit() {
  saving.value = true
  try {
    // Backend expects datetimes
    const start = `${form.value.work_date} ${form.value.start_time}:00`
    const end   = `${form.value.work_date} ${form.value.end_time}:00`
    await api.post('/overtimes', { ...form.value, start_time: start, end_time: end })
    toast.success(t('overtime.submit_success'))
    router.push('/overtimes')
  } catch (e) {
    toast.error(e.response?.data?.message || t('overtime.submit_failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-4">{{ t('overtime.form_title') }}</h1>
    <form @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <div>
          <label class="label">{{ t('overtime.date_label') }}</label>
          <input v-model="form.work_date" type="date" class="input" required />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">{{ t('overtime.start_time_label') }}</label>
            <input v-model="form.start_time" type="time" class="input" required />
          </div>
          <div>
            <label class="label">{{ t('overtime.end_time_label') }}</label>
            <input v-model="form.end_time" type="time" class="input" required />
          </div>
        </div>
        <div v-if="hours > 0" class="text-sm text-gray-600">
          {{ t('overtime.hours_total', { n: hours }) }}
        </div>
        <div>
          <label class="label">{{ t('overtime.reason_label') }}</label>
          <textarea v-model="form.reason" rows="3" class="input" required></textarea>
        </div>

        <!-- Compensation type toggle -->
        <div>
          <label class="label">{{ t('overtime.compensation_label') }}</label>
          <div class="grid grid-cols-2 gap-3">
            <label
              :class="['border rounded-lg p-4 cursor-pointer transition',
                form.compensation_type === 'CASH'
                  ? 'border-primary-600 bg-primary-50 ring-2 ring-primary-200'
                  : 'border-gray-200 hover:border-gray-300']">
              <input type="radio" v-model="form.compensation_type" value="CASH" class="hidden" />
              <div class="font-semibold">{{ t('overtime.comp_cash_title') }}</div>
              <div class="text-xs text-gray-600 mt-1">
                {{ t('overtime.comp_cash_desc') }}
              </div>
            </label>
            <label
              :class="['border rounded-lg p-4 cursor-pointer transition',
                form.compensation_type === 'LEAVE'
                  ? 'border-primary-600 bg-primary-50 ring-2 ring-primary-200'
                  : 'border-gray-200 hover:border-gray-300']">
              <input type="radio" v-model="form.compensation_type" value="LEAVE" class="hidden" />
              <div class="font-semibold">{{ t('overtime.comp_leave_title') }}</div>
              <div class="text-xs text-gray-600 mt-1">
                {{ t('overtime.comp_leave_desc', { n: leaveDaysIfConverted }) }}
              </div>
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-2">
            {{ t('overtime.form_hint') }}
          </p>
        </div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">{{ t('overtime.cancel_btn') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? t('overtime.submitting') : t('overtime.submit_btn') }}</button>
      </div>
    </form>
  </div>
</template>
