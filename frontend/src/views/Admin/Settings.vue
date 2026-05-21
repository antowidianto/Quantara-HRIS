<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const toast = useToast()
const company = ref({})
const bpjs = ref({})

async function fetchData() {
  const res = await api.get('/settings')
  company.value = res.data.data.company || {}
  bpjs.value = res.data.data.bpjs_rates || {}
}
onMounted(fetchData)

async function saveCompany() {
  try {
    await api.put(`/companies/${company.value.id}`, company.value)
    toast.success('Profil perusahaan disimpan')
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function saveBpjs() {
  try {
    await api.put('/settings', { bpjs_rates: bpjs.value })
    toast.success('Konfigurasi BPJS disimpan')
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">Pengaturan</h1>

    <div class="card mb-6">
      <div class="card-header"><span class="font-semibold">Profil Perusahaan</span></div>
      <form @submit.prevent="saveCompany" class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="label">Nama</label><input v-model="company.name" class="input" /></div>
        <div><label class="label">Nama Legal</label><input v-model="company.legal_name" class="input" /></div>
        <div><label class="label">NPWP</label><input v-model="company.npwp" class="input" /></div>
        <div><label class="label">Email</label><input v-model="company.email" type="email" class="input" /></div>
        <div><label class="label">Telepon</label><input v-model="company.phone" class="input" /></div>
        <div><label class="label">Kota</label><input v-model="company.city" class="input" /></div>
        <div class="md:col-span-2"><label class="label">Alamat</label><textarea v-model="company.address" rows="2" class="input"></textarea></div>
        <div class="md:col-span-2 flex justify-end"><button class="btn-primary" type="submit">Simpan</button></div>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><span class="font-semibold">Konfigurasi BPJS</span></div>
      <form @submit.prevent="saveBpjs" class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="label">BPJS Kesehatan - Karyawan (rate)</label><input v-model.number="bpjs.kes_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">BPJS Kesehatan - Perusahaan (rate)</label><input v-model.number="bpjs.kes_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">Plafon BPJS Kesehatan</label><input v-model.number="bpjs.kes_max_salary" type="number" class="input" /></div>
        <div><label class="label">JHT - Karyawan</label><input v-model.number="bpjs.jht_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">JHT - Perusahaan</label><input v-model.number="bpjs.jht_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">JP - Karyawan</label><input v-model.number="bpjs.jp_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">JP - Perusahaan</label><input v-model.number="bpjs.jp_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">Plafon JP</label><input v-model.number="bpjs.jp_max_salary" type="number" class="input" /></div>
        <div><label class="label">JKK - Perusahaan</label><input v-model.number="bpjs.jkk_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">JKM - Perusahaan</label><input v-model.number="bpjs.jkm_company_rate" type="number" step="0.0001" class="input" /></div>
        <div class="md:col-span-2 flex justify-end"><button class="btn-primary" type="submit">Simpan</button></div>
      </form>
    </div>
  </div>
</template>