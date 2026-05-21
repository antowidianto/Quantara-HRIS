<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const router = useRouter()
const toast = useToast()
const saving = ref(false)
const now = new Date()
const form = ref({
  name: now.toLocaleString('id-ID', { month: 'long', year: 'numeric' }),
  type: 'MONTHLY',
  period_year: now.getFullYear(),
  period_month: now.getMonth() + 1,
  start_date: new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10),
  end_date: new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10),
  pay_date: new Date(now.getFullYear(), now.getMonth() + 1, 5).toISOString().slice(0, 10),
})

async function submit() {
  saving.value = true
  try {
    const res = await api.post('/payroll-periods', form.value)
    toast.success('Periode dibuat')
    router.push(`/payroll/periods/${res.data.data.id}`)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { saving.value = false }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-4">Buat Periode Payroll</h1>
    <form @submit.prevent="submit" class="card">
      <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2"><label class="label">Nama Periode</label><input v-model="form.name" class="input" required /></div>
        <div><label class="label">Tipe</label>
          <select v-model="form.type" class="input">
            <option value="MONTHLY">Bulanan</option><option value="THR">THR</option><option value="BONUS">Bonus</option>
          </select>
        </div>
        <div><label class="label">Tahun</label><input v-model.number="form.period_year" type="number" class="input" required /></div>
        <div><label class="label">Bulan</label><input v-model.number="form.period_month" type="number" min="1" max="12" class="input" required /></div>
        <div><label class="label">Cutoff Absensi</label><input v-model="form.cutoff_attendance_date" type="date" class="input" /></div>
        <div><label class="label">Tanggal Mulai</label><input v-model="form.start_date" type="date" class="input" required /></div>
        <div><label class="label">Tanggal Akhir</label><input v-model="form.end_date" type="date" class="input" required /></div>
        <div class="md:col-span-2"><label class="label">Tanggal Bayar</label><input v-model="form.pay_date" type="date" class="input" required /></div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">Batal</button>
        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Buat Periode' }}</button>
      </div>
    </form>
  </div>
</template>