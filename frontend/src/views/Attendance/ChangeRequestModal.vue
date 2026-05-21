<script setup>
import { ref, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const props = defineProps({
  day: { type: Object, required: true },
})
const emit = defineEmits(['close', 'submitted'])
const toast = useToast()

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

const typeOptions = [
  { value: 'CLOCK_IN',  label: 'Perbaiki Clock-In' },
  { value: 'CLOCK_OUT', label: 'Perbaiki Clock-Out' },
  { value: 'BOTH',      label: 'Perbaiki Clock-In & Clock-Out' },
  { value: 'STATUS',    label: 'Ubah Status (mis. Sakit)' },
  { value: 'MISSED',    label: 'Lupa Absen' },
]

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
    toast.warning('Alasan minimal 10 karakter')
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
    const msg = e.response?.data?.message || 'Gagal mengirim pengajuan'
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
        <h2 class="text-lg font-semibold">Ajukan Perubahan Absensi</h2>
        <button class="text-gray-400 hover:text-gray-600" @click="emit('close')">✕</button>
      </div>

      <div class="p-5 space-y-4">
        <div class="bg-gray-50 rounded p-3 text-sm">
          <div class="font-medium">{{ day.day_name }}, {{ day.date }}</div>
          <div class="text-gray-600 text-xs mt-1">Status saat ini: <span class="font-medium">{{ day.status_label }}</span></div>
          <div v-if="day.attendance" class="text-xs text-gray-600 mt-1">
            Saat ini: {{ day.attendance.clock_in_at?.slice(11, 16) || '-' }} – {{ day.attendance.clock_out_at?.slice(11, 16) || '-' }}
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Tipe perubahan</label>
          <select v-model="form.change_type" class="input w-full">
            <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>

        <div v-if="showInTime">
          <label class="block text-sm font-medium mb-1">Clock-In yang diajukan</label>
          <input type="time" v-model="form.requested_clock_in_time" class="input w-full" />
        </div>

        <div v-if="showOutTime">
          <label class="block text-sm font-medium mb-1">Clock-Out yang diajukan</label>
          <input type="time" v-model="form.requested_clock_out_time" class="input w-full" />
        </div>

        <div v-if="showStatus">
          <label class="block text-sm font-medium mb-1">Status baru</label>
          <select v-model="form.requested_status" class="input w-full">
            <option value="">— pilih —</option>
            <option value="PRESENT">Hadir</option>
            <option value="LATE">Terlambat</option>
            <option value="SICK">Sakit</option>
            <option value="LEAVE">Cuti</option>
            <option value="HALF_DAY">Setengah Hari</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Alasan <span class="text-red-500">*</span></label>
          <textarea v-model="form.reason" rows="4" class="input w-full"
            placeholder="Jelaskan alasan perubahan (mis. lupa clock-out karena meeting di lokasi klien)..."></textarea>
          <div class="text-xs text-gray-500 mt-1">Minimal 10 karakter. Akan ditinjau oleh HR/Manager.</div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Lampiran (opsional)</label>
          <input v-model="form.attachment_path" type="text" class="input w-full"
            placeholder="URL/path file pendukung (mis. surat dokter)" />
        </div>
      </div>

      <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
        <button class="btn-secondary" @click="emit('close')" :disabled="submitting">Batal</button>
        <button class="btn-primary" :disabled="submitting" @click="submit">
          {{ submitting ? 'Mengirim...' : 'Kirim Pengajuan' }}
        </button>
      </div>
    </div>
  </div>
</template>