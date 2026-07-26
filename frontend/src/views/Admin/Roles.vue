<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import {
  PlusIcon, PencilIcon, EyeIcon, ShieldCheckIcon, LockClosedIcon, TrashIcon,
  ClockIcon, CheckCircleIcon, XCircleIcon, ArrowPathIcon,
} from '@heroicons/vue/24/outline'

/**
 * Roles & Permissions admin page.
 *
 * Otorisasi (matches backend RoleController):
 *   - Semua user terautentikasi boleh lihat daftar (read-only).
 *   - HANYA super admin boleh create / edit / delete role.
 *
 * UI guard di sini hanya untuk pengalaman pengguna; backend juga
 * melakukan check yang sama (lihat ensureSuperAdmin di RoleController),
 * jadi ngakalin frontend pun tetap kena 403 di server.
 */

const toast = useToast()
const { t } = useI18n()
const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.isSuperAdmin)
const permissionsReadOnly = computed(() =>
  viewMode.value || editing.value?.slug === 'super_admin'
)

const roles = ref([])
const grouped = ref({})
const loading = ref(false)
const showForm = ref(false)
const viewMode = ref(false)        // true = read-only view (untuk admin biasa)
const editing = ref(null)
const form = ref({ slug: '', name: '', description: '', permissions: [] })
const privilegedPending = ref(null)
const saving = ref(false)
const approvalRequests = ref([])
const approvalsLoading = ref(false)
const detailRequest = ref(null)

async function fetchApprovals() {
  approvalsLoading.value = true
  try {
    const res = await api.get('/roles/approval-requests')
    approvalRequests.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat daftar persetujuan RBAC')
  } finally {
    approvalsLoading.value = false
  }
}

async function fetchData() {
  loading.value = true
  try {
    const [r, p] = await Promise.all([
      api.get('/roles'),
      api.get('/permissions'),
      fetchApprovals(),
    ])
    roles.value = r.data.data
    grouped.value = p.data.data.grouped
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.failed_load'))
  } finally {
    loading.value = false
  }
}
onMounted(fetchData)

async function open(role, modeView = false) {
  try {
    const res = await api.get(`/roles/${role.id}`)
    editing.value = role
    // Read-only kalau:
    //   - Caller minta view explicit (tombol mata), ATAU
    //   - User bukan super admin.
    // Super admin BOLEH edit semua role termasuk system role
    // (hr_admin, manager, finance, employee). Backend sudah dilonggarkan
    // sesuai perubahan terbaru di RoleController::update.
    viewMode.value = modeView || ! isSuperAdmin.value
    form.value = {
      slug: res.data.data.slug,
      name: res.data.data.name,
      description: res.data.data.description || '',
      permissions: res.data.data.permissions.map(p => p.id),
    }
    privilegedPending.value = null
    showForm.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.failed_load_detail'))
  }
}

function openCreate() {
  if (! isSuperAdmin.value) {
    toast.warning(t('roles.only_superadmin_add'))
    return
  }
  editing.value = null
  viewMode.value = false
  form.value = { slug: '', name: '', description: '', permissions: [] }
  privilegedPending.value = null
  showForm.value = true
}

async function save() {
  if (! isSuperAdmin.value) {
    toast.error(t('roles.only_superadmin_save'))
    return
  }
  saving.value = true
  try {
    const res = editing.value
      ? await api.put(`/roles/${editing.value.id}`, form.value)
      : await api.post('/roles', form.value)

    if (res.status === 202 && res.data?.data?.approval_state === 'PENDING') {
      privilegedPending.value = {
        requestId: res.data.data.request_id,
        requestNumber: res.data.data.request_number,
      }
      toast.warning('Perubahan belum diterapkan. Menunggu persetujuan checker.')
      await fetchApprovals()
      return
    }

    privilegedPending.value = null
    toast.success(t('roles.saved'))
    showForm.value = false
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally {
    saving.value = false
  }
}

async function remove(role) {
  if (! isSuperAdmin.value) return
  if (role.is_system) {
    toast.warning(t('roles.system_role_no_delete'))
    return
  }
  const ok = await confirmDialog({
    title: t('roles.delete_title'),
    message: t('roles.delete_message', { name: role.name }),
    okText: t('roles.delete_btn'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/roles/${role.id}`)
    toast.success(t('roles.deleted_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.delete_failed'))
  }
}

const totalSelected = computed(() => form.value.permissions.length)
const totalAvailable = computed(() => Object.values(grouped.value).reduce((sum, arr) => sum + arr.length, 0))

function toggleGroup(perms) {
  if (permissionsReadOnly.value) return
  const ids = perms.map(p => p.id)
  const allSelected = ids.every(id => form.value.permissions.includes(id))
  if (allSelected) {
    form.value.permissions = form.value.permissions.filter(id => ! ids.includes(id))
  } else {
    form.value.permissions = [...new Set([...form.value.permissions, ...ids])]
  }
}

function groupSelectedCount(perms) {
  return perms.filter(p => form.value.permissions.includes(p.id)).length
}

function fmtDate(value) {
  if (! value) return '-'
  return new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

function approvalStatus(item) {
  if (item.status === 'USED') return { label: 'Diterapkan', class: 'bg-blue-50 text-blue-700', icon: CheckCircleIcon }
  if (item.status === 'APPROVED') return { label: 'Siap diterapkan', class: 'bg-green-50 text-green-700', icon: CheckCircleIcon }
  if (item.status === 'REJECTED') return { label: 'Ditolak', class: 'bg-red-50 text-red-700', icon: XCircleIcon }
  return { label: 'Menunggu persetujuan', class: 'bg-amber-50 text-amber-700', icon: ClockIcon }
}

function roleTarget(path) {
  const match = String(path || '').match(/\/roles\/(\d+)$/)
  if (! match) return path?.endsWith('/roles') ? 'Role baru' : 'Role & Permission'
  const role = roles.value.find(item => Number(item.id) === Number(match[1]))
  return role ? `${role.name} (${role.slug})` : `Role #${match[1]}`
}

function changed(before, after) {
  return String(before ?? '') !== String(after ?? '')
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShieldCheckIcon class="w-7 h-7 text-primary-600" />
          {{ t('roles.title') }}
        </h1>
      </div>
      <button v-if="isSuperAdmin" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('roles.add_role') }}
      </button>
    </div>

    <!-- Banner read-only untuk non-super-admin -->
    <div v-if="!isSuperAdmin"
      class="mb-4 rounded-md p-3 flex items-start gap-2 bg-amber-50 border border-amber-200 text-amber-900 text-sm">
      <LockClosedIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
      <div>
        <div class="font-medium">{{ t('role.read_only_banner') }}</div>
        <div class="text-xs">
          {{ t('role.read_only_note') }}
        </div>
      </div>
    </div>

    <!-- Monitoring maker-checker perubahan RBAC -->
    <section class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
        <div>
          <h2 class="flex items-center gap-2 font-semibold text-gray-900">
            <ClockIcon class="h-5 w-5 text-primary-600" />
            Persetujuan Perubahan Role
          </h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Super Admin menerapkan perubahan RBAC langsung. Daftar ini menyimpan riwayat request
            maker-checker dari pengguna non-Super Admin.
          </p>
        </div>
        <button type="button" class="btn-secondary inline-flex items-center gap-2 text-xs"
          :disabled="approvalsLoading" @click="fetchApprovals">
          <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': approvalsLoading }" />
          Muat Ulang
        </button>
      </div>

      <div v-if="approvalsLoading && approvalRequests.length === 0"
        class="px-5 py-8 text-center text-sm text-gray-400">
        Memuat persetujuan...
      </div>
      <div v-else-if="approvalRequests.length === 0"
        class="px-5 py-8 text-center text-sm text-gray-400">
        Belum ada permintaan persetujuan perubahan role.
      </div>
      <div v-else
        class="max-h-[420px] divide-y divide-gray-100 overflow-y-auto overscroll-contain"
        aria-label="Riwayat persetujuan perubahan role">
        <article v-for="request in approvalRequests" :key="request.id"
          class="grid gap-4 px-5 py-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] lg:items-center">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-mono text-xs font-semibold text-primary-700">
                {{ request.request_number }}
              </span>
              <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                :class="approvalStatus(request).class">
                <component :is="approvalStatus(request).icon" class="h-3.5 w-3.5" />
                {{ approvalStatus(request).label }}
              </span>
            </div>
            <div class="mt-1 text-sm font-semibold text-gray-900">
              {{ request.http_method }} · {{ roleTarget(request.request_path) }}
            </div>
            <div class="mt-1 text-xs text-gray-500">
              Diajukan oleh {{ request.submitted_by_name || request.submitted_by_email || '-' }}
              · {{ fmtDate(request.submitted_at) }}
            </div>
          </div>

          <div class="min-w-0 text-xs">
            <template v-if="request.status === 'PENDING_APPROVAL'">
              <div class="font-semibold text-gray-700">
                Tahap {{ request.current_step_order || '-' }} ·
                {{ request.current_step_name || 'Menunggu checker' }}
              </div>
              <div class="mt-1 text-gray-500">
                Approver:
                <span v-if="request.current_approvers?.length">
                  {{ request.current_approvers.map(user => user.name).join(', ') }}
                </span>
                <span v-else class="font-medium text-red-600">belum ter-resolve</span>
              </div>
            </template>
            <template v-else-if="request.status === 'APPROVED'">
              <div class="font-semibold text-green-700">
                Disetujui oleh {{ request.approved_by_name || '-' }}
              </div>
              <div class="mt-1 text-gray-500">
                Berlaku sampai {{ fmtDate(request.expires_at) }}; maker harus menerapkan ulang.
              </div>
            </template>
            <template v-else-if="request.status === 'USED'">
              <div class="font-semibold text-blue-700">Perubahan telah diterapkan</div>
              <div class="mt-1 text-gray-500">{{ fmtDate(request.used_at) }}</div>
            </template>
            <template v-else>
              <div class="font-semibold text-red-700">
                Ditolak oleh {{ request.rejected_by_name || '-' }}
              </div>
              <div class="mt-1 text-gray-500">{{ request.rejection_reason || '-' }}</div>
            </template>
          </div>

          <div class="flex flex-wrap gap-2 lg:justify-end">
            <button type="button" class="btn-secondary justify-center whitespace-nowrap text-xs"
              @click="detailRequest = request">
              Detail Perubahan
            </button>
            <RouterLink v-if="request.approval_request_id"
              :to="{ path: '/approvals/inbox', query: { request_id: request.approval_request_id } }"
              class="btn-secondary justify-center whitespace-nowrap text-xs">
              Lihat Approval
            </RouterLink>
          </div>
        </article>
      </div>
    </section>

    <div class="card">
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('roles.slug') }}</th>
            <th>{{ t('roles.name') }}</th>
            <th>{{ t('users.role') }}</th>
            <th class="text-right">{{ t('roles.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading">
            <td colspan="4" class="text-center py-8 text-gray-400">{{ t('roles.loading') }}</td>
          </tr>
          <tr v-else-if="roles.length === 0">
            <td colspan="4" class="text-center py-8 text-gray-400">{{ t('common.no_data') }}</td>
          </tr>
          <tr v-for="r in roles" :key="r.id" class="hover:bg-gray-50">
            <td class="font-mono text-xs">{{ r.slug }}</td>
            <td class="font-medium">{{ r.name }}</td>
            <td>
              <span class="px-2 py-0.5 rounded text-xs"
                :class="r.is_system ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700'">
                {{ r.is_system ? t('role.system_role') : t('role.custom_role') }}
              </span>
            </td>
            <td class="text-right">
              <div class="inline-flex gap-2">
                <!-- Lihat tersedia untuk semua user terautentikasi -->
                <button class="text-gray-600 hover:text-primary-600" :title="t('roles.view')"
                  @click="open(r, true)">
                  <EyeIcon class="w-4 h-4" />
                </button>
                <!-- Edit untuk super admin. Global role selain super_admin
                     otomatis dibuatkan salinan khusus tenant saat diterapkan. -->
                <button v-if="isSuperAdmin"
                  class="text-primary-600 hover:text-primary-800"
                  :title="t('roles.edit')"
                  @click="open(r)">
                  <PencilIcon class="w-4 h-4" />
                </button>
                <!-- Delete: super admin saja, dan TIDAK untuk role sistem -->
                <button v-if="isSuperAdmin && ! r.is_system"
                  class="text-red-600 hover:text-red-800" :title="t('roles.delete')"
                  @click="remove(r)">
                  <TrashIcon class="w-4 h-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal detail snapshot perubahan RBAC -->
    <Teleport to="body">
      <div v-if="detailRequest"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
        @click.self="detailRequest = null">
        <div role="dialog" aria-modal="true" aria-labelledby="rbac-detail-title"
          class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
          <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
            <div>
              <h2 id="rbac-detail-title" class="text-lg font-bold text-gray-900">
                Detail Perubahan Role
              </h2>
              <p class="mt-1 font-mono text-xs text-primary-700">{{ detailRequest.request_number }}</p>
            </div>
            <button type="button" class="text-2xl leading-none text-gray-400 hover:text-gray-700"
              aria-label="Tutup" @click="detailRequest = null">&times;</button>
          </div>

          <div class="overflow-y-auto p-6">
            <div class="mb-5 grid gap-3 rounded-xl bg-gray-50 p-4 text-sm sm:grid-cols-2">
              <div><span class="text-gray-500">Aksi:</span>
                <strong class="ml-2">{{ detailRequest.http_method }} {{ detailRequest.request_path }}</strong>
              </div>
              <div><span class="text-gray-500">Target:</span>
                <strong class="ml-2">{{ roleTarget(detailRequest.request_path) }}</strong>
              </div>
              <div><span class="text-gray-500">Pengaju:</span>
                <strong class="ml-2">{{ detailRequest.submitted_by_name || '-' }}</strong>
              </div>
              <div><span class="text-gray-500">Diajukan:</span>
                <strong class="ml-2">{{ fmtDate(detailRequest.submitted_at) }}</strong>
              </div>
            </div>

            <div v-if="! detailRequest.change_detail?.available"
              class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
              Detail payload tidak tersedia untuk request lama. Snapshot perubahan mulai direkam untuk request baru.
            </div>

            <template v-else>
              <div class="mb-6">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Perubahan Informasi Role</h3>
                <div class="overflow-hidden rounded-xl border border-gray-200">
                  <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                      <tr><th class="px-4 py-3">Field</th><th class="px-4 py-3">Sebelum</th><th class="px-4 py-3">Usulan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr>
                        <td class="px-4 py-3 font-medium">Nama</td>
                        <td class="px-4 py-3 text-gray-600">{{ detailRequest.change_detail.role_name_before || '-' }}</td>
                        <td class="px-4 py-3" :class="{ 'font-semibold text-blue-700': changed(detailRequest.change_detail.role_name_before, detailRequest.change_detail.role_name_after) }">
                          {{ detailRequest.change_detail.role_name_after || '-' }}
                        </td>
                      </tr>
                      <tr>
                        <td class="px-4 py-3 font-medium">Deskripsi</td>
                        <td class="px-4 py-3 whitespace-pre-wrap text-gray-600">{{ detailRequest.change_detail.description_before || '-' }}</td>
                        <td class="px-4 py-3 whitespace-pre-wrap" :class="{ 'font-semibold text-blue-700': changed(detailRequest.change_detail.description_before, detailRequest.change_detail.description_after) }">
                          {{ detailRequest.change_detail.description_after || '-' }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-green-200 bg-green-50/50 p-4">
                  <h3 class="flex items-center gap-2 text-sm font-semibold text-green-800">
                    <PlusIcon class="h-4 w-4" />
                    Permission Dibuka ({{ detailRequest.change_detail.permissions_added.length }})
                  </h3>
                  <div v-if="detailRequest.change_detail.permissions_added.length" class="mt-3 space-y-2">
                    <div v-for="permission in detailRequest.change_detail.permissions_added" :key="permission.id"
                      class="rounded-lg border border-green-100 bg-white px-3 py-2">
                      <div class="font-mono text-xs font-semibold text-green-700">{{ permission.slug }}</div>
                      <div class="mt-0.5 text-xs text-gray-600">{{ permission.name }}</div>
                    </div>
                  </div>
                  <p v-else class="mt-3 text-xs text-gray-500">Tidak ada permission baru.</p>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50/50 p-4">
                  <h3 class="flex items-center gap-2 text-sm font-semibold text-red-800">
                    <TrashIcon class="h-4 w-4" />
                    Permission Dicabut ({{ detailRequest.change_detail.permissions_removed.length }})
                  </h3>
                  <div v-if="detailRequest.change_detail.permissions_removed.length" class="mt-3 space-y-2">
                    <div v-for="permission in detailRequest.change_detail.permissions_removed" :key="permission.id"
                      class="rounded-lg border border-red-100 bg-white px-3 py-2">
                      <div class="font-mono text-xs font-semibold text-red-700">{{ permission.slug }}</div>
                      <div class="mt-0.5 text-xs text-gray-600">{{ permission.name }}</div>
                    </div>
                  </div>
                  <p v-else class="mt-3 text-xs text-gray-500">Tidak ada permission yang dicabut.</p>
                </div>
              </div>

              <details class="mt-5 rounded-xl border border-gray-200 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700">
                  Permission Tetap ({{ detailRequest.change_detail.permissions_kept.length }})
                </summary>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                  <div v-for="permission in detailRequest.change_detail.permissions_kept" :key="permission.id"
                    class="rounded-lg bg-gray-50 px-3 py-2">
                    <div class="font-mono text-xs text-gray-700">{{ permission.slug }}</div>
                    <div class="text-xs text-gray-500">{{ permission.name }}</div>
                  </div>
                </div>
              </details>
            </template>
          </div>

          <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-6 py-4">
            <button type="button" class="btn-secondary" @click="detailRequest = null">Tutup</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal: edit / create / view -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showForm = false">
      <form @submit.prevent="save" class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b font-semibold flex items-center justify-between">
          <span class="flex items-center gap-2">
            <ShieldCheckIcon class="w-5 h-5 text-primary-600" />
            <span v-if="viewMode">{{ t('role.detail') }}</span>
            <span v-else>{{ editing ? t('roles.edit_title') : t('roles.create_title') }}</span>
            <span v-if="viewMode"
              class="ml-2 inline-flex items-center gap-1 text-xs font-normal text-amber-700 bg-amber-100 px-2 py-0.5 rounded">
              <LockClosedIcon class="w-3 h-3" /> Read-only
            </span>
          </span>
          <button type="button" class="text-gray-500" @click="showForm = false">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <div v-if="privilegedPending"
            class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="font-semibold">Perubahan belum diterapkan</div>
            <p class="mt-1 text-xs leading-5">
              Request <span class="font-mono">{{ privilegedPending.requestNumber }}</span>
              menunggu persetujuan checker. Setelah disetujui, klik
              <strong>Terapkan Perubahan</strong> dengan data yang sama.
              Pembuat request tidak dapat menyetujui request-nya sendiri.
            </p>
            <RouterLink to="/approvals/inbox"
              class="mt-2 inline-flex text-xs font-semibold text-primary-700 hover:underline">
              Buka Approval Inbox
            </RouterLink>
          </div>

          <!-- Banner peringatan saat super admin edit role sistem -->
          <div v-if="!viewMode && editing && editing.is_system"
            class="rounded-md p-3 bg-amber-50 border border-amber-200 text-amber-900 text-sm flex items-start gap-2">
            <LockClosedIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
            <div>
              <div class="font-medium">Anda sedang mengedit role sistem</div>
              <div class="text-xs">
                Role <span class="font-mono">{{ editing.slug }}</span> adalah role bawaan.
                Slug tidak bisa diubah. Role global selain <span class="font-mono">super_admin</span>
                otomatis disalin menjadi role khusus tenant agar perubahan tidak berdampak ke perusahaan lain.
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">{{ t('roles.slug') }}</label>
              <input v-model="form.slug" class="input" required
                :disabled="!!editing || viewMode" />
              <p v-if="editing" class="text-xs text-gray-500 mt-1">
                Slug tidak dapat diubah setelah dibuat.
              </p>
            </div>
            <div>
              <label class="label">{{ t('roles.name') }}</label>
              <input v-model="form.name" class="input" required :disabled="viewMode" />
            </div>
          </div>
          <div>
            <label class="label">{{ t('roles.description') }}</label>
            <textarea v-model="form.description" rows="2" class="input" :disabled="viewMode"></textarea>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="label mb-0">{{ t('roles.permissions') }}</label>
              <span class="text-xs text-gray-500">
                {{ t('roles.permissions_selected', { selected: totalSelected, total: totalAvailable }) }}
              </span>
            </div>

            <div v-for="(perms, group) in grouped" :key="group" class="mb-3 border border-gray-100 rounded p-3">
              <div class="flex items-center justify-between mb-2">
                <div class="text-xs font-semibold text-gray-700 uppercase">{{ group }}</div>
                <div class="flex items-center gap-2 text-xs">
                  <span class="text-gray-500">
                    {{ groupSelectedCount(perms) }} / {{ perms.length }}
                  </span>
                  <button v-if="!permissionsReadOnly" type="button" class="text-primary-600 hover:underline"
                    @click="toggleGroup(perms)">
                    {{ t('role.toggle_all') }}
                  </button>
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                <label v-for="p in perms" :key="p.id"
                  class="flex items-center gap-2 text-sm py-0.5"
                  :class="permissionsReadOnly ? 'cursor-default' : 'cursor-pointer hover:bg-gray-50 rounded px-1'">
                  <input type="checkbox" :value="p.id" v-model="form.permissions"
                    :disabled="permissionsReadOnly" class="w-4 h-4" />
                  <span class="font-mono text-xs text-gray-500">{{ p.slug }}</span>
                  <span class="text-gray-700">— {{ p.name }}</span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-4 border-t flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">
            {{ viewMode ? t('common.close') : t('common.cancel') }}
          </button>
          <button v-if="!viewMode" type="submit" class="btn-primary" :disabled="saving">
            {{ saving
              ? 'Memproses...'
              : privilegedPending
                ? 'Terapkan Perubahan'
                : t('common.save') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
