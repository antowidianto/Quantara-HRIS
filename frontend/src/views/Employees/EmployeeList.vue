<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useResource, fmtDate } from '@/composables/useResource'
import { useAuthStore } from '@/stores/auth'
import { PlusIcon, MagnifyingGlassIcon, PencilIcon, EyeIcon, TrashIcon } from '@heroicons/vue/24/outline'
import EmployeeModal from './EmployeeModal.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { items, meta, params, loading, fetch, remove } = useResource('/employees')

// Modal state
const modalOpen = ref(false)
const modalEmployeeId = ref(null)  // null = create new
const modalMode = ref('view')      // 'view' | 'edit'

function openCreate() {
  modalEmployeeId.value = null
  modalMode.value = 'edit'
  modalOpen.value = true
}
function openView(id) {
  modalEmployeeId.value = id
  modalMode.value = 'view'
  modalOpen.value = true
}
function openEdit(id) {
  modalEmployeeId.value = id
  modalMode.value = 'edit'
  modalOpen.value = true
}
function closeModal() {
  modalOpen.value = false
  modalEmployeeId.value = null
}
function onSaved() {
  fetch()
}

// Mendukung URL deep-link lama: /employees/:id, /employees/:id/edit, /employees/new
function syncFromRoute() {
  const path = route.path
  if (path.endsWith('/employees/new')) {
    openCreate()
  } else {
    const m = path.match(/\/employees\/(\d+)(\/edit)?$/)
    if (m) {
      modalEmployeeId.value = Number(m[1])
      modalMode.value = m[2] ? 'edit' : 'view'
      modalOpen.value = true
    }
  }
}

onMounted(() => {
  fetch()
  syncFromRoute()
})

let timer = null
watch(() => params.search, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { params.page = 1; fetch() }, 300)
})

// Sync route changes (mis. user klik back)
watch(() => route.path, syncFromRoute)

function go(page) { params.page = page; fetch() }
function removeEmployee(emp) {
  remove(emp.id, {
    title: 'Nonaktifkan karyawan?',
    message: `${emp.full_name} (${emp.employee_number}) akan ditandai non-aktif.`,
    okText: 'Nonaktifkan',
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">Karyawan</h1>
        <p class="text-gray-500 text-sm">Kelola data karyawan perusahaan</p>
      </div>
      <button v-if="auth.can('employee.create')" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Karyawan
      </button>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="relative w-full md:w-80">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.search" placeholder="Cari nama, NIP, atau email..." class="input pl-9" />
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>NIP</th>
              <th>Nama</th>
              <th>Cabang</th>
              <th>Departemen</th>
              <th>Jabatan</th>
              <th>Status</th>
              <th>Bergabung</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Tidak ada data</td></tr>
            <tr v-for="e in items" :key="e.id" class="hover:bg-gray-50 cursor-pointer" @click="openView(e.id)">
              <td class="font-mono">{{ e.employee_number }}</td>
              <td>
                <div class="font-medium">{{ e.full_name }}</div>
                <div class="text-xs text-gray-500">{{ e.email }}</div>
              </td>
              <td class="text-sm">{{ e.branch_name || '-' }}</td>
              <td class="text-sm">{{ e.department_name || '-' }}</td>
              <td class="text-sm">{{ e.position_name || '-' }}</td>
              <td>
                <span :class="e.is_active ? 'badge-green' : 'badge-gray'">
                  {{ e.is_active ? 'Aktif' : 'Non-aktif' }}
                </span>
                <span class="badge-blue ml-1">{{ e.employment_status }}</span>
              </td>
              <td>{{ fmtDate(e.join_date) }}</td>
              <td class="text-right" @click.stop>
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openView(e.id)" title="Detail">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="auth.can('employee.update')" class="text-gray-500 hover:text-primary-600" @click="openEdit(e.id)" title="Edit">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="auth.can('employee.delete')" @click="removeEmployee(e)" class="text-gray-500 hover:text-red-600" title="Nonaktifkan">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">Total: {{ meta.total }} karyawan</div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">Sebelumnya</button>
          <span class="px-3 py-2 text-sm">Halaman {{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <EmployeeModal
      v-if="modalOpen"
      :employee-id="modalEmployeeId"
      :mode="modalMode"
      @close="closeModal"
      @saved="onSaved"
    />
  </div>
</template>