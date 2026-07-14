<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, TrashIcon, PencilIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const { t, locale } = useI18n()
const policies = ref([])
const loading = ref(true)
const saving = ref(false)
const showForm = ref(false)
const formData = ref({ id: null, name: '', description: '', limit_amount: 0, reset_type: 'YEARLY' })

async function fetchPolicies() {
  loading.value = true
  try {
    const res = await api.get('/benefit-policies')
    policies.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('benefit_policies.load_failed'))
  } finally {
    loading.value = false
  }
}

onMounted(fetchPolicies)

function openCreate() {
  formData.value = { id: null, name: '', description: '', limit_amount: 0, reset_type: 'YEARLY' }
  showForm.value = true
}

function openEdit(d) {
  formData.value = { ...d }
  showForm.value = true
}

async function save() {
  if (saving.value) return
  if (! formData.value.name?.trim()) {
    toast.warning(t('benefit_policies.name_required'))
    return
  }
  if (Number(formData.value.limit_amount) < 0) {
    toast.warning(t('benefit_policies.limit_invalid'))
    return
  }
  saving.value = true
  try {
    if (formData.value.id) {
      await api.put(`/benefit-policies/${formData.value.id}`, formData.value)
      toast.success(t('benefit_policies.updated'))
    } else {
      await api.post('/benefit-policies', formData.value)
      toast.success(t('benefit_policies.created'))
    }
    showForm.value = false
    fetchPolicies()
  } catch (e) {
    toast.error(e.response?.data?.message || t('benefit_policies.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove(id) {
  const ok = await confirmDialog({
    title: t('benefit_policies.delete_title'),
    message: t('benefit_policies.delete_message'),
    okText: t('benefit_policies.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/benefit-policies/${id}`)
    toast.success(t('benefit_policies.deleted'))
    fetchPolicies()
  } catch (e) {
    toast.error(e.response?.data?.message || t('benefit_policies.delete_failed'))
  }
}

function formatCurrency(value) {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0,
  }).format(Number(value) || 0)
}

function resetTypeLabel(type) {
  return type === 'MONTHLY' ? t('benefit_policies.reset_monthly') : t('benefit_policies.reset_yearly')
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">{{ t('benefit_policies.title') }}</h1>
        <p class="text-gray-500 text-sm">{{ t('benefit_policies.subtitle') }}</p>
      </div>
      <button type="button" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-5 h-5 mr-1" /> {{ t('benefit_policies.add') }}
      </button>
    </div>

    <div class="card overflow-hidden">
      <table class="table w-full">
        <thead>
          <tr>
            <th>{{ t('benefit_policies.col_category') }}</th>
            <th>{{ t('benefit_policies.col_description') }}</th>
            <th>{{ t('benefit_policies.col_limit') }}</th>
            <th>{{ t('benefit_policies.col_reset') }}</th>
            <th class="w-24">{{ t('benefit_policies.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading"><td colspan="5" class="text-center py-6">{{ t('benefit_policies.loading') }}</td></tr>
          <tr v-else-if="policies.length === 0"><td colspan="5" class="text-center py-6 text-gray-400">{{ t('benefit_policies.empty') }}</td></tr>
          <tr v-for="d in policies" :key="d.id" class="hover:bg-gray-50 transition-colors">
            <td class="font-semibold">{{ d.name }}</td>
            <td class="text-sm text-gray-500">{{ d.description || t('benefit_policies.empty_value') }}</td>
            <td class="font-bold text-emerald-600">{{ formatCurrency(d.limit_amount) }}</td>
            <td>
              <span class="bg-blue-100 text-blue-800 px-2 py-1 text-xs rounded-full font-medium">
                {{ resetTypeLabel(d.reset_type) }}
              </span>
            </td>
            <td>
              <div class="flex gap-2">
                <button type="button" class="text-gray-400 hover:text-primary-600" @click="openEdit(d)" v-bind="{ title: t('benefit_policies.edit'), 'aria-label': t('benefit_policies.edit') }"><PencilIcon class="w-4 h-4" /></button>
                <button type="button" class="text-gray-400 hover:text-rose-600" @click="remove(d.id)" v-bind="{ title: t('benefit_policies.delete'), 'aria-label': t('benefit_policies.delete') }"><TrashIcon class="w-4 h-4" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Form -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md" role="dialog" aria-modal="true" v-bind="{ 'aria-label': formData.id ? t('benefit_policies.modal_edit') : t('benefit_policies.modal_create') }">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
          <h3 class="font-bold text-lg">{{ formData.id ? t('benefit_policies.modal_edit') : t('benefit_policies.modal_create') }}</h3>
          <button type="button" @click="showForm = false" class="text-gray-400 hover:text-gray-600" v-bind="{ title: t('benefit_policies.close'), 'aria-label': t('benefit_policies.close') }">&times;</button>
        </div>
        <form @submit.prevent="save">
          <div class="p-6 space-y-4">
            <div>
              <label class="label">{{ t('benefit_policies.category') }}</label>
              <input type="text" v-model="formData.name" class="input" v-bind="{ placeholder: t('benefit_policies.category_ph') }" required />
            </div>
            <div>
              <label class="label">{{ t('benefit_policies.limit') }}</label>
              <div class="relative">
                <span class="absolute left-3 top-2.5 text-gray-500">Rp</span>
                <input type="number" min="0" v-model.number="formData.limit_amount" class="input pl-10" required />
              </div>
            </div>
            <div>
              <label class="label">{{ t('benefit_policies.reset_type') }}</label>
              <select v-model="formData.reset_type" class="input">
                <option value="YEARLY">{{ t('benefit_policies.reset_yearly') }}</option>
                <option value="MONTHLY">{{ t('benefit_policies.reset_monthly') }}</option>
              </select>
            </div>
            <div>
              <label class="label">{{ t('benefit_policies.description') }}</label>
              <textarea v-model="formData.description" class="input" rows="2"></textarea>
            </div>
          </div>
          <div class="px-6 py-4 bg-gray-50 flex justify-end gap-2 rounded-b-xl">
            <button type="button" class="btn-secondary" :disabled="saving" @click="showForm = false">{{ t('benefit_policies.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? t('benefit_policies.saving') : t('benefit_policies.save') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
