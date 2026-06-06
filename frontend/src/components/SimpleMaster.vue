<script setup>
import { ref, onMounted, watch, reactive } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, MagnifyingGlassIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import LocationPicker from '@/components/LocationPicker.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const props = defineProps({
  endpoint: { type: String, required: true },
  title: { type: String, required: true },
  columns: { type: Array, required: true }, // [{ key, label, formatter? }]
  fields: { type: Array, required: true },  // [{ key, label, type, options?, required? }]
  defaults: { type: Object, default: () => ({}) },
  // Migration #067 — kalau di-set, render tombol "Import Excel" di toolbar.
  // Slug-nya harus sama dengan ImportRegistry di backend.
  importResource: { type: String, default: '' },
})

const toast = useToast()
const auth = useAuthStore()
const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1, per_page: 20 })
const loading = ref(false)
const params = reactive({ page: 1, per_page: 20, search: '' })

const showModal = ref(false)
const editing = ref(false)
const form = ref({ ...props.defaults })

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get(props.endpoint, { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

onMounted(() => fetchData())

let timer = null
watch(() => params.search, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { params.page = 1; fetchData() }, 300)
})

function openCreate() {
  form.value = { ...props.defaults }
  editing.value = false
  showModal.value = true
}

function openEdit(item) {
  form.value = { ...props.defaults, ...item }
  editing.value = true
  showModal.value = true
}

async function save() {
  try {
    if (editing.value) {
      await api.put(`${props.endpoint}/${form.value.id}`, form.value)
      toast.success(t('common.saved'))
    } else {
      await api.post(props.endpoint, form.value)
      toast.success(t('common.saved'))
    }
    showModal.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  }
}

async function remove(id) {
  const ok = await confirmDialog({
    title: t('common.delete'),
    message: t('common.confirm_delete'),
    okText: t('common.delete'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`${props.endpoint}/${id}`)
    toast.success(t('common.deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  }
}

function go(page) { params.page = page; fetchData() }
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-2xl font-bold">{{ title }}</h1>
      <div class="flex items-center gap-2 flex-wrap">
        <ExcelImporter v-if="importResource && auth.can(importResource + '.bulk_import')"
          :resource="importResource"
          :button-label="t('common.import') + ' Excel'"
          @committed="fetchData()"
        />
        <button class="btn-primary" @click="openCreate"><PlusIcon class="w-4 h-4" /> {{ t('common.add') }}</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="relative w-full md:w-64">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.search" :placeholder="t('common.search') + '...'" class="input pl-9" />
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th v-for="c in columns" :key="c.key">{{ c.label }}</th>
              <th class="text-right">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td :colspan="columns.length + 1" class="text-center py-8 text-gray-400">{{ t('common.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td :colspan="columns.length + 1" class="text-center py-8 text-gray-400">{{ t('common.no_data') }}</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td v-for="c in columns" :key="c.key">
                <slot :name="`cell-${c.key}`" :item="item">{{ c.formatter ? c.formatter(item[c.key], item) : (item[c.key] ?? '-') }}</slot>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openEdit(item)"><PencilIcon class="w-4 h-4" /></button>
                  <button class="text-gray-500 hover:text-red-600" @click="remove(item.id)"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">{{ t('common.total') }}: {{ meta.total }}</div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-500">{{ t('common.per_page') }}:</label>
          <select v-model.number="params.per_page" class="input py-1 w-20 text-sm" @change="go(1)">
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('common.previous') }}</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? t('common.edit') : t('common.add') }} {{ title }}</h2>
          <button class="text-gray-500" @click="showModal = false">&times;</button>
        </div>
        <form @submit.prevent="save" class="px-6 py-4 space-y-4">
          <div v-for="f in fields" :key="f.key">
            <label v-if="f.type !== 'location'" class="label">{{ f.label }}{{ f.required ? ' *' : '' }}</label>
            <LocationPicker v-if="f.type === 'location'"
              v-model:lat="form[f.latKey || 'lat']"
              v-model:lng="form[f.lngKey || 'lng']"
              v-model:radius="form[f.radiusKey || 'geofence_radius_m']"
              :show-radius="f.showRadius !== false" />
            <select v-else-if="f.type === 'select'" v-model="form[f.key]" class="input" :required="f.required">
              <option value="">{{ t('common.select') }}</option>
              <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <textarea v-else-if="f.type === 'textarea'" v-model="form[f.key]" class="input" rows="3"></textarea>
            <input v-else-if="f.type === 'checkbox'" v-model="form[f.key]" type="checkbox" class="w-4 h-4" />
            <input v-else v-model="form[f.key]" :type="f.type || 'text'" class="input" :required="f.required" :step="f.step" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showModal = false">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn-primary">{{ t('common.save') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>