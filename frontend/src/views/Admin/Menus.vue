<script setup>
/**
 * Admin Menu Manager — CRUD untuk tabel `menus` (lihat migration #076).
 *
 * Fitur:
 *   - List tree (semua menu, termasuk yang permission-nya tidak dimiliki).
 *   - Create / edit / soft-delete menu.
 *   - Reorder via tombol naik/turun (sederhana, no drag-drop dependency).
 *   - Toggle aktif/non-aktif.
 *
 * Untuk reorder pakai endpoint POST /menus/reorder yang batch update
 * sort_order + parent_id. UI tidak pakai library drag-and-drop khusus
 * supaya tidak menambah dependency; tombol panah cukup untuk kebutuhan
 * admin yang jarang dilakukan.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useMenuStore } from '@/stores/menu'
import MenuRow from '@/components/menu/MenuRow.vue'
import { availableIconNames, resolveIcon } from '@/components/menu/iconMap'
import {
  PlusIcon, ArrowPathIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from '@/locales'

const { t } = useI18n()
const menuStore = useMenuStore()

const tree = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const showForm = ref(false)
const formMode = ref('create') // 'create' | 'edit'
const form = ref(emptyForm())

const iconNames = availableIconNames()

function emptyForm() {
  return {
    id: null,
    parent_id: null,
    type: 'ITEM',
    code: '',
    label: '',
    translation_key: '',
    icon: '',
    route_path: '',
    permission: '',
    super_admin_only: false,
    sort_order: 0,
    is_active: true,
  }
}

// Flat parent picker (PARENT + SECTION saja)
const parentOptions = computed(() => {
  const out = [{ id: null, label: t('menus.topLevel') }]
  const walk = (nodes, depth) => {
    for (const n of nodes || []) {
      if (n.type === 'SECTION' || n.type === 'PARENT') {
        // Tidak boleh pilih dirinya sendiri saat edit
        if (form.value.id && Number(form.value.id) === Number(n.id)) continue
        out.push({
          id: n.id,
          label: '— '.repeat(depth) + `[${n.type}] ${n.label}`,
        })
      }
      if (n.children?.length) walk(n.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return out
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/menus')
    tree.value = res.data?.data?.tree || []
  } catch (e) {
    error.value = e?.response?.data?.message || e?.message || t('menus.loadFailed')
  } finally {
    loading.value = false
  }
}

function openCreate(parent = null) {
  formMode.value = 'create'
  form.value = emptyForm()
  if (parent) form.value.parent_id = parent.id
  showForm.value = true
}

function openEdit(node) {
  formMode.value = 'edit'
  form.value = {
    id: node.id,
    parent_id: node.parent_id ?? null,
    type: node.type,
    code: node.code,
    label: node.label,
    translation_key: node.translation_key || '',
    icon: node.icon || '',
    route_path: node.route_path || '',
    permission: node.permission || '',
    super_admin_only: !! node.super_admin_only,
    sort_order: Number(node.sort_order ?? 0),
    is_active: node.is_active !== 0,
  }
  showForm.value = true
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const payload = {
      ...form.value,
      // Backend nerima null untuk kosong supaya kolom optional tidak
      // jadi string kosong. Trimming juga supaya tidak ada whitespace.
      code: (form.value.code || '').trim(),
      label: (form.value.label || '').trim(),
      translation_key: (form.value.translation_key || '').trim() || null,
      icon: (form.value.icon || '').trim() || null,
      route_path: (form.value.route_path || '').trim() || null,
      permission: (form.value.permission || '').trim() || null,
    }
    if (formMode.value === 'create') {
      await api.post('/menus', payload)
    } else {
      await api.put(`/menus/${form.value.id}`, payload)
    }
    showForm.value = false
    await load()
    // Refresh sidebar user supaya perubahan tampak tanpa reload halaman.
    await menuStore.refresh()
  } catch (e) {
    error.value = e?.response?.data?.message || t('menus.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(node) {
  if (! confirm(t('menus.confirmDelete', { label: node.label }))) return
  try {
    await api.delete(`/menus/${node.id}`)
    await load()
    await menuStore.refresh()
  } catch (e) {
    alert(e?.response?.data?.message || t('menus.deleteFailed'))
  }
}

async function toggleActive(node) {
  try {
    await api.put(`/menus/${node.id}`, { is_active: ! (Number(node.is_active) === 1) })
    await load()
    await menuStore.refresh()
  } catch (e) {
    alert(e?.response?.data?.message || t('menus.statusChangeFailed'))
  }
}

/**
 * Pindahkan node ke atas/bawah di antara siblings yang sama.
 * Strategi: tukar sort_order dengan tetangga, lalu post batch reorder.
 * `siblings` di-pass dari MenuRow supaya kita tahu konteks parent-nya.
 */
async function move(node, direction, siblings) {
  const sib = siblings || []
  const idx = sib.findIndex(s => s.id === node.id)
  if (idx < 0) return
  const targetIdx = direction === 'up' ? idx - 1 : idx + 1
  if (targetIdx < 0 || targetIdx >= sib.length) return

  const a = sib[idx], b = sib[targetIdx]
  try {
    await api.post('/menus/reorder', {
      items: [
        { id: a.id, parent_id: a.parent_id ?? null, sort_order: Number(b.sort_order ?? 0) },
        { id: b.id, parent_id: b.parent_id ?? null, sort_order: Number(a.sort_order ?? 0) },
      ],
    })
    await load()
    await menuStore.refresh()
  } catch (e) {
    alert(e?.response?.data?.message || t('menus.reorderFailed'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-gray-800">{{ t('menus.title') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">
          {{ t('menus.subtitle') }}
        </p>
      </div>
      <div class="flex gap-2">
        <button
          @click="load"
          class="inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-md border border-gray-300 bg-white hover:bg-gray-50"
        >
          <ArrowPathIcon class="w-4 h-4" />
          <span>{{ t('common.refresh') }}</span>
        </button>
        <button
          @click="openCreate(null)"
          class="inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-md bg-primary-600 text-white hover:bg-primary-700"
        >
          <PlusIcon class="w-4 h-4" />
          <span>{{ t('menus.addMenu') }}</span>
        </button>
      </div>
    </div>

    <div v-if="error"
      class="border border-red-200 bg-red-50 text-red-700 text-sm rounded-md px-4 py-2">
      {{ error }}
    </div>

    <!-- Tree -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-6 text-center text-sm text-gray-500">
        {{ t('common.loading') }}
      </div>
      <div v-else-if="tree.length === 0" class="p-6 text-center text-sm text-gray-500">
        {{ t('menus.noMenus') }}
      </div>
      <ul v-else class="divide-y divide-gray-100">
        <MenuRow
          v-for="(n, i) in tree"
          :key="n.id"
          :node="n"
          :siblings="tree"
          :index="i"
          :depth="0"
          @edit="openEdit"
          @remove="remove"
          @toggle="toggleActive"
          @add-child="openCreate"
          @move="move"
        />
      </ul>
    </div>

    <!-- Form modal -->
    <div v-if="showForm"
      class="fixed inset-0 z-40 bg-black/40 flex items-start justify-center overflow-y-auto p-4"
      @click.self="showForm = false">
      <div class="bg-white w-full max-w-2xl rounded-lg shadow-xl my-8">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-800">
            {{ formMode === 'create' ? t('menus.addMenu') : t('menus.editMenu') }}
          </h2>
          <button @click="showForm = false"
            class="text-gray-400 hover:text-gray-600">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <form @submit.prevent="submit" class="px-5 py-4 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.type') }}</span>
              <select v-model="form.type"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="SECTION">SECTION (header grup)</option>
                <option value="PARENT">PARENT (dropdown)</option>
                <option value="ITEM">ITEM (link biasa)</option>
              </select>
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.parent') }}</span>
              <select v-model="form.parent_id"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500">
                <option v-for="p in parentOptions" :key="p.id ?? 'root'"
                  :value="p.id">{{ p.label }}</option>
              </select>
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.code') }} <span class="text-red-500">*</span></span>
              <input v-model="form.code" required maxlength="100"
                placeholder="mis. hr.employees"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.label') }} <span class="text-red-500">*</span></span>
              <input v-model="form.label" required maxlength="150"
                placeholder="mis. Karyawan"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.translationKey') }}</span>
              <input v-model="form.translation_key" maxlength="150"
                placeholder="mis. nav.items.employees"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.icon') }}</span>
              <select v-model="form.icon"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">— {{ t('menus.form.noIcon') }} —</option>
                <option v-for="n in iconNames" :key="n" :value="n">{{ n }}</option>
              </select>
              <span v-if="form.icon" class="mt-1 inline-flex items-center gap-1 text-xs text-gray-500">
                Preview:
                <component :is="resolveIcon(form.icon)" class="w-4 h-4" />
              </span>
            </label>

            <label class="block text-sm col-span-2">
              <span class="text-gray-700">{{ t('menus.form.routePath') }}</span>
              <input v-model="form.route_path" maxlength="255"
                placeholder="mis. /employees"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
              <span class="text-xs text-gray-400 mt-0.5 block">
                {{ t('menus.form.routePathHelp') }}
              </span>
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.permission') }}</span>
              <input v-model="form.permission" maxlength="100"
                placeholder="mis. employee.view"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
              <span class="text-xs text-gray-400 mt-0.5 block">
                {{ t('menus.form.permissionHelp') }}
              </span>
            </label>

            <label class="block text-sm">
              <span class="text-gray-700">{{ t('menus.form.sortOrder') }}</span>
              <input v-model.number="form.sort_order" type="number"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500" />
            </label>

            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" class="rounded text-primary-600" />
              <span class="text-gray-700">{{ t('common.active') }}</span>
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.super_admin_only" type="checkbox" class="rounded text-primary-600" />
              <span class="text-gray-700">{{ t('menus.form.superAdminOnly') }}</span>
            </label>
          </div>

          <div v-if="error" class="text-sm text-red-600">{{ error }}</div>

          <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" @click="showForm = false"
              class="px-3 py-2 text-sm rounded-md border border-gray-300 bg-white hover:bg-gray-50">
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving"
              class="px-3 py-2 text-sm rounded-md bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-60">
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
