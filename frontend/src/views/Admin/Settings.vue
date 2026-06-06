<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const toast = useToast()
const { t } = useI18n()
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
    toast.success(t('settings.company_saved'))
  } catch (e) { toast.error(e.response?.data?.message || t('settings.failed')) }
}

async function saveBpjs() {
  try {
    await api.put('/settings', { bpjs_rates: bpjs.value })
    toast.success(t('settings.bpjs_saved'))
  } catch (e) { toast.error(e.response?.data?.message || t('settings.failed')) }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">{{ t('settings.title') }}</h1>

    <div class="card mb-6">
      <div class="card-header"><span class="font-semibold">{{ t('settings.company_profile') }}</span></div>
      <form @submit.prevent="saveCompany" class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="label">{{ t('settings.name') }}</label><input v-model="company.name" class="input" /></div>
        <div><label class="label">{{ t('settings.legal_name') }}</label><input v-model="company.legal_name" class="input" /></div>
        <div><label class="label">{{ t('settings.npwp') }}</label><input v-model="company.npwp" class="input" /></div>
        <div><label class="label">{{ t('settings.email') }}</label><input v-model="company.email" type="email" class="input" /></div>
        <div><label class="label">{{ t('settings.phone') }}</label><input v-model="company.phone" class="input" /></div>
        <div><label class="label">{{ t('settings.city') }}</label><input v-model="company.city" class="input" /></div>
        <div class="md:col-span-2"><label class="label">{{ t('settings.address') }}</label><textarea v-model="company.address" rows="2" class="input"></textarea></div>
        <div class="md:col-span-2 flex justify-end"><button class="btn-primary" type="submit">{{ t('settings.save') }}</button></div>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><span class="font-semibold">{{ t('settings.bpjs_config') }}</span></div>
      <form @submit.prevent="saveBpjs" class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="label">{{ t('settings.bpjs_kes_employee') }}</label><input v-model.number="bpjs.kes_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_kes_company') }}</label><input v-model.number="bpjs.kes_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_kes_max_salary') }}</label><input v-model.number="bpjs.kes_max_salary" type="number" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jht_employee') }}</label><input v-model.number="bpjs.jht_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jht_company') }}</label><input v-model.number="bpjs.jht_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jp_employee') }}</label><input v-model.number="bpjs.jp_employee_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jp_company') }}</label><input v-model.number="bpjs.jp_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jp_max_salary') }}</label><input v-model.number="bpjs.jp_max_salary" type="number" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jkk_company') }}</label><input v-model.number="bpjs.jkk_company_rate" type="number" step="0.0001" class="input" /></div>
        <div><label class="label">{{ t('settings.bpjs_jkm_company') }}</label><input v-model.number="bpjs.jkm_company_rate" type="number" step="0.0001" class="input" /></div>
        <div class="md:col-span-2 flex justify-end"><button class="btn-primary" type="submit">{{ t('settings.save') }}</button></div>
      </form>
    </div>
  </div>
</template>
