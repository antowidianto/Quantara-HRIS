<script setup>
import { ref, watch, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show: Boolean,
})

const emit = defineEmits(['close', 'created'])

const toast = useToast()
const saving = ref(false)
const now = new Date()

const MONTH_NAMES_ID = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

function pad(n) { return String(n).padStart(2, '0') }
function ymd(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
function buildName(year, month, type) {
  const monthIdx = Math.min(12, Math.max(1, Number(month) || 1)) - 1
  const monthName = MONTH_NAMES_ID[monthIdx]
  if (type === 'THR') return `THR ${year}`
  if (type === 'BONUS') return `Bonus ${monthName} ${year}`
  return `${monthName} ${year}`
}

const initialYear = now.getFullYear()
const initialMonth = now.getMonth() + 1
const form = ref({
  name: buildName(initialYear, initialMonth, 'MONTHLY'),
  type: 'MONTHLY',
  period_year: initialYear,
  period_month: initialMonth,
  start_date: ymd(new Date(initialYear, initialMonth - 1, 1)),
  end_date: ymd(new Date(initialYear, initialMonth, 0)),
  pay_date: ymd(new Date(initialYear, initialMonth, 5)),
  cutoff_attendance_date: null,
})

const nameTouched = ref(false)
function onNameInput() { nameTouched.value = true }

// Validasi keamanan: pastikan tanggal konsisten
const dateValidation = computed(() => {
  const start = new Date(form.value.start_date)
  const end = new Date(form.value.end_date)
  const pay = new Date(form.value.pay_date)
  
  if (end < start) {
    return 'Tanggal akhir harus setelah tanggal mulai'
  }
  if (pay < start) {
    return 'Tanggal bayar tidak boleh sebelum tanggal mulai periode'
  }
  
  // Validasi cutoff jika diisi
  if (form.value.cutoff_attendance_date) {
    const cutoff = new Date(form.value.cutoff_attendance_date)
    if (cutoff < start || cutoff > end) {
      return 'Cutoff absensi harus dalam rentang periode'
    }
  }
  
  return null
})

// Validasi tahun dan bulan
const periodValidation = computed(() => {
  const year = Number(form.value.period_year)
  const month = Number(form.value.period_month)
  
  if (year < 2020 || year > 2100) {
    return 'Tahun harus antara 2020-2100'
  }
  if (month < 1 || month > 12) {
    return 'Bulan harus antara 1-12'
  }
  
  return null
})

const isValid = computed(() => {
  return !dateValidation.value && !periodValidation.value && form.value.name.trim().length > 0
})

// Auto-sync name + dates
watch(
  () => [form.value.period_year, form.value.period_month, form.value.type],
  ([year, month, type]) => {
    const y = Number(year)
    const m = Number(month)
    if (!y || !m) return
    if (!nameTouched.value) {
      form.value.name = buildName(y, m, type)
    }
    form.value.start_date = ymd(new Date(y, m - 1, 1))
    form.value.end_date = ymd(new Date(y, m, 0))
    form.value.pay_date = ymd(new Date(y, m, 5))
  },
)

// Reset form saat modal dibuka
watch(() => props.show, (newVal) => {
  if (newVal) {
    resetForm()
  }
})

function resetForm() {
  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth() + 1
  
  form.value = {
    name: buildName(year, month, 'MONTHLY'),
    type: 'MONTHLY',
    period_year: year,
    period_month: month,
    start_date: ymd(new Date(year, month - 1, 1)),
    end_date: ymd(new Date(year, month, 0)),
    pay_date: ymd(new Date(year, month, 5)),
    cutoff_attendance_date: null,
  }
  nameTouched.value = false
}

function close() {
  emit('close')
}

async function submit() {
  if (!isValid.value) {
    toast.error('Mohon periksa kembali input Anda')
    return
  }
  
  saving.value = true
  try {
    // Sanitasi input sebelum kirim
    const payload = {
      name: form.value.name.trim(),
      type: form.value.type,
      period_year: Number(form.value.period_year),
      period_month: Number(form.value.period_month),
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      pay_date: form.value.pay_date,
    }
    
    // Hanya kirim cutoff jika diisi
    if (form.value.cutoff_attendance_date) {
      payload.cutoff_attendance_date = form.value.cutoff_attendance_date
    }
    
    const res = await api.post('/payroll-periods', payload)
    toast.success('Periode payroll berhasil dibuat')
    emit('created', res.data.data)
    close()
  } catch (e) {
    const resp = e.response?.data
    if (resp?.code === 'PERIOD_DUPLICATE') {
      toast.error(resp.message || 'Periode untuk bulan ini sudah ada')
    } else if (resp?.code === 'VALIDATION_ERROR') {
      toast.error(resp.message || 'Data tidak valid')
    } else if (resp?.code === 'PERMISSION_DENIED') {
      toast.error('Anda tidak memiliki izin untuk membuat periode payroll')
    } else {
      toast.error(resp?.message || 'Gagal membuat periode')
    }
  } finally {
    saving.value = false
  }
}

// Prevent modal close on backdrop click saat sedang saving
function handleBackdropClick() {
  if (!saving.value) {
    close()
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto" @click.self="handleBackdropClick">
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-black/50 transition-opacity" @click="handleBackdropClick"></div>
          
          <!-- Modal -->
          <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
              <h2 class="text-xl font-bold text-gray-900 dark:text-white">Buat Periode Payroll</h2>
              <button 
                type="button" 
                @click="close" 
                :disabled="saving"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
              >
                <XMarkIcon class="w-6 h-6" />
              </button>
            </div>

            <!-- Body -->
            <form @submit.prevent="submit">
              <div class="p-6 space-y-4">
                <!-- Validation Errors -->
                <div v-if="dateValidation || periodValidation" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                  <p class="text-sm text-red-600 dark:text-red-400">
                    {{ dateValidation || periodValidation }}
                  </p>
                </div>

                <!-- Nama Periode -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Nama Periode <span class="text-red-500">*</span>
                  </label>
                  <input 
                    v-model="form.name" 
                    type="text"
                    class="input w-full" 
                    required 
                    maxlength="100"
                    @input="onNameInput"
                    :disabled="saving"
                  />
                </div>

                <!-- Tipe & Tahun -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Tipe <span class="text-red-500">*</span>
                    </label>
                    <select v-model="form.type" class="input w-full" :disabled="saving">
                      <option value="MONTHLY">Bulanan</option>
                      <option value="THR">THR</option>
                      <option value="BONUS">Bonus</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Tahun <span class="text-red-500">*</span>
                    </label>
                    <input 
                      v-model.number="form.period_year" 
                      type="number" 
                      min="2020"
                      max="2100"
                      class="input w-full" 
                      required 
                      :disabled="saving"
                    />
                  </div>
                </div>

                <!-- Bulan & Cutoff -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Bulan <span class="text-red-500">*</span>
                    </label>
                    <input 
                      v-model.number="form.period_month" 
                      type="number" 
                      min="1" 
                      max="12" 
                      class="input w-full" 
                      required 
                      :disabled="saving"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Cutoff Absensi
                    </label>
                    <AppDatePicker
                      v-model="form.cutoff_attendance_date" 

                      class="input w-full"
                      :disabled="saving"
                    />
                  </div>
                </div>

                <!-- Tanggal Mulai & Akhir -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Tanggal Mulai <span class="text-red-500">*</span>
                    </label>
                    <AppDatePicker
                      v-model="form.start_date" 

                      class="input w-full" 
                      required 
                      :disabled="saving"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Tanggal Akhir <span class="text-red-500">*</span>
                    </label>
                    <AppDatePicker
                      v-model="form.end_date" 

                      class="input w-full" 
                      required 
                      :disabled="saving"
                    />
                  </div>
                </div>

                <!-- Tanggal Bayar -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Tanggal Bayar <span class="text-red-500">*</span>
                  </label>
                  <AppDatePicker
                    v-model="form.pay_date" 

                    class="input w-full" 
                    required 
                    :disabled="saving"
                  />
                </div>
              </div>

              <!-- Footer -->
              <div class="flex items-center justify-end gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                <button 
                  type="button" 
                  class="btn-secondary" 
                  @click="close"
                  :disabled="saving"
                >
                  Batal
                </button>
                <button 
                  type="submit" 
                  class="btn-primary" 
                  :disabled="saving || !isValid"
                >
                  {{ saving ? 'Menyimpan...' : 'Buat Periode' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active .relative,
.modal-leave-active .relative {
  transition: transform 0.3s ease;
}

.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
}
</style>
