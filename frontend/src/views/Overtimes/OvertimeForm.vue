<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const router = useRouter()
const toast = useToast()
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
    toast.success('Pengajuan lembur dikirim')
    router.push('/overtimes')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-4">Ajukan Lembur</h1>
    <form @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <div>
          <label class="label">Tanggal Lembur *</label>
          <input v-model="form.work_date" type="date" class="input" required />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Jam Mulai *</label>
            <input v-model="form.start_time" type="time" class="input" required />
          </div>
          <div>
            <label class="label">Jam Selesai *</label>
            <input v-model="form.end_time" type="time" class="input" required />
          </div>
        </div>
        <div v-if="hours > 0" class="text-sm text-gray-600">
          Total jam: <strong>{{ hours }} jam</strong>
        </div>
        <div>
          <label class="label">Alasan / Pekerjaan *</label>
          <textarea v-model="form.reason" rows="3" class="input" required></textarea>
        </div>

        <!-- Compensation type toggle -->
        <div>
          <label class="label">Bentuk kompensasi *</label>
          <div class="grid grid-cols-2 gap-3">
            <label
              :class="['border rounded-lg p-4 cursor-pointer transition',
                form.compensation_type === 'CASH'
                  ? 'border-primary-600 bg-primary-50 ring-2 ring-primary-200'
                  : 'border-gray-200 hover:border-gray-300']">
              <input type="radio" v-model="form.compensation_type" value="CASH" class="hidden" />
              <div class="font-semibold">💵 Dibayar Uang</div>
              <div class="text-xs text-gray-600 mt-1">
                Lembur dihitung sesuai PP 35/2021. Tarif/jam = (gaji + tunjangan tetap) / 173.
              </div>
            </label>
            <label
              :class="['border rounded-lg p-4 cursor-pointer transition',
                form.compensation_type === 'LEAVE'
                  ? 'border-primary-600 bg-primary-50 ring-2 ring-primary-200'
                  : 'border-gray-200 hover:border-gray-300']">
              <input type="radio" v-model="form.compensation_type" value="LEAVE" class="hidden" />
              <div class="font-semibold">🌴 Cuti Pengganti</div>
              <div class="text-xs text-gray-600 mt-1">
                Jam lembur ditambahkan ke quota Cuti Pengganti Lembur.
                Estimasi: <strong>{{ leaveDaysIfConverted }} hari</strong> (rasio 1:1).
              </div>
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-2">
            Pilihan ini final saat di-submit. Setelah disetujui atasan, sistem otomatis
            menambah jam lembur ke saldo Cuti Pengganti (kalau memilih cuti) atau
            menghitung uang lembur (kalau memilih uang).
          </p>
        </div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">Batal</button>
        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Mengirim...' : 'Kirim' }}</button>
      </div>
    </form>
  </div>
</template>