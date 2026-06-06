<script setup>
/**
 * Master Kategori Online Test
 *
 * 14 kategori built-in di-seed otomatis lewat migration #065
 * (TPA, Pauli, Kraepelin, DISC, MBTI, Wartegg, Holland, IST, English, dst).
 * Built-in tidak boleh dihapus, hanya HR custom yang bisa dimodifikasi/hapus.
 */
import { ref, onMounted, computed } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, MagnifyingGlassIcon, PencilIcon, TrashIcon, LockClosedIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const toast = useToast()
const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const loading = ref(false)

const params = ref({ page: 1, per_page: 50, search: '', group_label: '', scope: 'all' })

const showModal = ref(false)
const editing = ref(false)
const form = ref({})

const groupOptions = ['KOGNITIF', 'KEPRIBADIAN', 'KEMAMPUAN', 'BAHASA', 'TEKNIS', 'LAINNYA']

const scoringOptions = computed(() => [
  { value: 'SCORE',           label: t('master.online_test_categories.sc_score') },
  { value: 'NO_RIGHT_ANSWER', label: t('master.online_test_categories.sc_no_right') },
  { value: 'PROFILE',         label: t('master.online_test_categories.sc_profile') },
])

const grouped = computed(() => {
  const out = {}
  for (const r of items.value) {
    const g = r.group_label || 'LAINNYA'
    if (! out[g]) out[g] = []
    out[g].push(r)
  }
  return out
})

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/online-test-categories', { params: params.value })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.online_test_categories.load_failed'))
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

function openCreate() {
  form.value = {
    code: '',
    name: '',
    description: '',
    group_label: 'KOGNITIF',
    scoring_type: 'SCORE',
    is_active: 1,
  }
  editing.value = false
  showModal.value = true
}

function openEdit(item) {
  form.value = { ...item }
  editing.value = true
  showModal.value = true
}

async function save() {
  try {
    if (editing.value) {
      await api.put(`/online-test-categories/${form.value.id}`, form.value)
      toast.success(t('master.online_test_categories.saved_update'))
    } else {
      await api.post('/online-test-categories', form.value)
      toast.success(t('master.online_test_categories.saved_create'))
    }
    showModal.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.online_test_categories.save_failed'))
  }
}

async function remove(item) {
  if (item.is_built_in) {
    toast.warning(t('master.online_test_categories.builtin_no_delete'))
    return
  }
  const ok = await confirmDialog({
    title: t('master.online_test_categories.delete_title'),
    message: t('master.online_test_categories.delete_msg', { name: item.name }),
    okText: t('master.online_test_categories.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/online-test-categories/${item.id}`)
    toast.success(t('master.online_test_categories.deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.online_test_categories.delete_failed'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('master.online_test_categories.title') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ t('master.online_test_categories.subtitle') }}
        </p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('master.online_test_categories.add') }}
      </button>
    </div>

    <div class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <div class="relative w-64">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.search" :placeholder="t('master.online_test_categories.search_ph')" class="input pl-9"
            @input="fetchData" />
        </div>
        <select v-model="params.group_label" class="input w-44" @change="fetchData">
          <option value="">{{ t('master.online_test_categories.all_groups') }}</option>
          <option v-for="g in groupOptions" :key="g" :value="g">{{ g }}</option>
        </select>
        <select v-model="params.scope" class="input w-44" @change="fetchData">
          <option value="all">{{ t('master.online_test_categories.scope_all') }}</option>
          <option value="builtin">{{ t('master.online_test_categories.scope_builtin') }}</option>
          <option value="custom">{{ t('master.online_test_categories.scope_custom') }}</option>
        </select>
      </div>

      <div class="card-body">
        <div v-if="loading" class="text-center py-10 text-gray-400">{{ t('master.online_test_categories.loading') }}</div>
        <div v-else-if="items.length === 0" class="text-center py-10 text-gray-400">
          {{ t('master.online_test_categories.no_data') }}
        </div>
        <div v-else class="space-y-6">
          <div v-for="(rows, group) in grouped" :key="group">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ group }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              <div v-for="r in rows" :key="r.id"
                class="border rounded-lg p-3 hover:border-primary-300 hover:shadow-sm transition">
                <div class="flex items-start justify-between gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="text-xs font-mono px-1.5 py-0.5 bg-gray-100 rounded">{{ r.code }}</span>
                      <LockClosedIcon v-if="r.is_built_in" class="w-3.5 h-3.5 text-gray-400" :title="t('master.online_test_categories.builtin')" />
                    </div>
                    <div class="font-medium text-gray-800 mt-1">{{ r.name }}</div>
                    <div class="text-xs text-gray-500 mt-1 line-clamp-2">{{ r.description || '—' }}</div>
                    <div class="text-xs text-gray-400 mt-1">
                      {{ t('master.online_test_categories.scoring') }} <span class="font-medium">{{ r.scoring_type }}</span>
                      <span v-if="! r.is_active" class="ml-2 text-red-500">{{ t('master.online_test_categories.inactive') }}</span>
                    </div>
                  </div>
                  <div class="flex flex-col gap-1">
                    <button v-if="r.is_editable !== false"
                      class="text-gray-400 hover:text-primary-600" @click="openEdit(r)"
                      :title="t('master.online_test_categories.edit')">
                      <PencilIcon class="w-4 h-4" />
                    </button>
                    <button v-if="! r.is_built_in"
                      class="text-gray-400 hover:text-red-600" @click="remove(r)"
                      :title="t('master.online_test_categories.delete')">
                      <TrashIcon class="w-4 h-4" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">
            {{ editing ? t('master.online_test_categories.modal_title_edit') : t('master.online_test_categories.modal_title_add') }}
          </h2>
          <button class="text-gray-500" @click="showModal = false">&times;</button>
        </div>
        <form @submit.prevent="save" class="px-6 py-4 space-y-4">
          <div>
            <label class="label">{{ t('master.online_test_categories.f_code') }} <span class="text-red-500">*</span></label>
            <input v-model="form.code" required maxlength="32" class="input"
              :disabled="editing && form.is_built_in"
              :placeholder="t('master.online_test_categories.f_code_ph')" />
            <p class="text-xs text-gray-400 mt-1">{{ t('master.online_test_categories.f_code_hint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('master.online_test_categories.f_name') }} <span class="text-red-500">*</span></label>
            <input v-model="form.name" required maxlength="200" class="input" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('master.online_test_categories.f_group') }}</label>
              <select v-model="form.group_label" class="input">
                <option v-for="g in groupOptions" :key="g" :value="g">{{ g }}</option>
              </select>
            </div>
            <div>
              <label class="label">{{ t('master.online_test_categories.f_scoring') }}</label>
              <select v-model="form.scoring_type" class="input">
                <option v-for="o in scoringOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="label">{{ t('master.online_test_categories.f_description') }}</label>
            <textarea v-model="form.description" class="input" rows="3"></textarea>
          </div>
          <div>
            <label class="inline-flex items-center gap-2">
              <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
              <span class="text-sm">{{ t('master.online_test_categories.f_active') }}</span>
            </label>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showModal = false">{{ t('master.online_test_categories.f_cancel') }}</button>
            <button type="submit" class="btn-primary">{{ t('master.online_test_categories.f_save') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>