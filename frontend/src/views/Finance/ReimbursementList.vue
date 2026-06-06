<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const toast = useToast()
const { t } = useI18n()
const items = ref([])
const showForm = ref(false)
const form = ref({ category: 'TRANSPORT', expense_date: '', amount: 0, description: '' })

async function fetchData() {
  const res = await api.get('/reimbursements', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function submit() {
  try {
    await api.post('/reimbursements', form.value)
    toast.success(t('reimbursement.submit_success'))
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || t('reimbursement.submit_failed')) }
}
</script>
<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('reimbursement.title') }}</h1>
      <button class="btn-primary" @click="showForm = true">+ {{ t('reimbursement.apply') }}</button>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>{{ t('reimbursement.col_number') }}</th><th>{{ t('reimbursement.col_date') }}</th><th>{{ t('reimbursement.col_category') }}</th><th>{{ t('reimbursement.col_amount') }}</th><th>{{ t('reimbursement.col_description') }}</th><th>{{ t('reimbursement.col_status') }}</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="6" class="text-center py-8 text-gray-400">{{ t('reimbursement.no_data') }}</td></tr>
          <tr v-for="r in items" :key="r.id">
            <td class="font-mono text-xs">{{ r.request_number }}</td>
            <td>{{ fmtDate(r.expense_date) }}</td>
            <td>{{ r.category }}</td>
            <td>{{ fmtCurrency(r.amount) }}</td>
            <td>{{ r.description }}</td>
            <td><span :class="{
              'badge-yellow': r.status === 'PENDING',
              'badge-green': r.status === 'APPROVED' || r.status === 'PAID',
              'badge-red': r.status === 'REJECTED',
            }">{{ r.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40" @click.self="showForm = false">
      <form @submit.prevent="submit" class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold">{{ t('reimbursement.apply_title') }}</h2>
        <div><label class="label">{{ t('reimbursement.category') }}</label>
          <select v-model="form.category" class="input">
            <option>TRANSPORT</option><option>MEDICAL</option><option>MEAL</option><option>OFFICE</option><option>OTHER</option>
          </select>
        </div>
        <div><label class="label">{{ t('reimbursement.expense_date') }}</label><input v-model="form.expense_date" type="date" class="input" required /></div>
        <div><label class="label">{{ t('reimbursement.amount_label') }}</label><input v-model.number="form.amount" type="number" min="1" class="input" required /></div>
        <div><label class="label">{{ t('reimbursement.description') }}</label><textarea v-model="form.description" rows="3" class="input" required></textarea></div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">{{ t('common.cancel') }}</button>
          <button type="submit" class="btn-primary">{{ t('common.submit') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>