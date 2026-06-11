<script setup>
/**
 * S3.5 SaaS — Super Admin Console: List Tenants.
 *
 * Internal Quantara console untuk monitor + manage semua tenant.
 * Akses hanya untuk super_admin (Quantara internal).
 *
 * Features:
 *   - Filter by status, plan_code, search
 *   - Inline impersonate, suspend, unsuspend action
 *   - Drill-down ke detail tenant
 */
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  EyeIcon,
  UserCircleIcon,
  PauseCircleIcon,
  PlayCircleIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const tenants = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const loading = ref(true)
const acting = ref(null)

const filters = reactive({
  status: '',
  plan_code: '',
  search: '',
})

const isSuperAdmin = computed(() => auth.user?.role === 'super_admin' || auth.user?.is_super_admin)

async function loadTenants(page = 1) {
  loading.value = true
  try {
    const res = await api.get('/admin/tenants', {
      params: {
        page,
        per_page: 20,
        ...filters,
      },
    })
    tenants.value = res.data?.data || []
    meta.value = res.data?.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tenant')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (! isSuperAdmin.value) {
    toast.error('Hanya super admin Quantara yang bisa akses halaman ini')
    router.push('/')
    return
  }
  loadTenants()
})

function statusBadge(status) {
  const map = {
    TRIAL:     'bg-blue-100 text-blue-800',
    ACTIVE:    'bg-emerald-100 text-emerald-800',
    PAST_DUE:  'bg-amber-100 text-amber-800',
    SUSPENDED: 'bg-rose-100 text-rose-800',
    CANCELLED: 'bg-gray-200 text-gray-700',
  }
  return map[status] || 'bg-gray-100'
}

function formatRupiah(v) {
  if (! v) return '-'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function impersonate(tenant) {
  const reason = window.prompt(
    `Impersonate ${tenant.name}?\n\nMasukkan alasan (audit log mandatory, min 10 karakter):`
  )
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  const ok = await confirmDialog({
    title: 'Konfirmasi Impersonate',
    message: `Anda akan login sebagai admin ${tenant.name} selama 15 menit. Action ini tercatat di audit log dengan severity HIGH.`,
    okText: 'Ya, Impersonate',
    variant: 'warning',
  })
  if (! ok) return

  acting.value = tenant.id
  try {
    const res = await api.post(`/admin/tenants/${tenant.id}/impersonate`, { reason: reason.trim() })
    const token = res.data?.data?.token
    const expiresIn = res.data?.data?.expires_in || 900
    if (token) {
      // Store impersonation token (replace current token, save original to revert)
      localStorage.setItem('impersonation_origin_token', auth.token || '')
      localStorage.setItem('impersonation_started_at', String(Date.now()))
      localStorage.setItem('impersonation_expires_in', String(expiresIn))
      auth.setToken(token)
      toast.success(`Login sebagai ${tenant.name}. Token expired dalam ${Math.floor(expiresIn / 60)} menit.`)
      router.push('/')
      // Reload supaya fetch user baru dengan token impersonate
      setTimeout(() => window.location.reload(), 500)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal impersonate')
  } finally {
    acting.value = null
  }
}

async function suspend(tenant) {
  const reason = window.prompt(
    `Suspend ${tenant.name}?\n\nAlasan (mandatory, min 10 karakter):`
  )
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  const ok = await confirmDialog({
    title: '4-Eyes Confirmation',
    message: `Suspend tenant ${tenant.name} akan memblok semua POST/PUT/DELETE. User tetap bisa login lihat data tapi tidak bisa membuat transaksi.`,
    okText: 'Ya, Suspend',
    variant: 'danger',
  })
  if (! ok) return

  acting.value = tenant.id
  try {
    await api.post(`/admin/tenants/${tenant.id}/suspend`, {
      reason: reason.trim(),
      confirm: true,
    })
    toast.success(`${tenant.name} disuspend`)
    await loadTenants(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal suspend')
  } finally {
    acting.value = null
  }
}

async function unsuspend(tenant) {
  const ok = await confirmDialog({
    title: 'Aktifkan Kembali Tenant?',
    message: `Tenant ${tenant.name} akan kembali ke status ACTIVE. Pastikan tagihan sudah dibayar.`,
    okText: 'Ya, Unsuspend',
    variant: 'primary',
  })
  if (! ok) return

  acting.value = tenant.id
  try {
    await api.post(`/admin/tenants/${tenant.id}/unsuspend`)
    toast.success(`${tenant.name} aktif kembali`)
    await loadTenants(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal unsuspend')
  } finally {
    acting.value = null
  }
}
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold dark:text-white">Tenant Management</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Console internal Quantara — manage semua tenant + support actions
        </p>
      </div>
      <router-link
        to="/admin/saas-metrics"
        class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500"
      >
        Dashboard SaaS Metrics
      </router-link>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
          <div class="relative">
            <MagnifyingGlassIcon class="absolute left-2 top-2.5 h-4 w-4 text-gray-400" />
            <input
              v-model="filters.search"
              type="text"
              class="w-full pl-8 pr-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="Nama / kode tenant…"
              @keyup.enter="loadTenants(1)"
            />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select
            v-model="filters.status"
            class="w-full px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            @change="loadTenants(1)"
          >
            <option value="">Semua</option>
            <option value="TRIAL">TRIAL</option>
            <option value="ACTIVE">ACTIVE</option>
            <option value="PAST_DUE">PAST DUE</option>
            <option value="SUSPENDED">SUSPENDED</option>
            <option value="CANCELLED">CANCELLED</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Plan</label>
          <select
            v-model="filters.plan_code"
            class="w-full px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            @change="loadTenants(1)"
          >
            <option value="">Semua</option>
            <option value="FREE_TRIAL">FREE_TRIAL</option>
            <option value="STARTER">STARTER</option>
            <option value="PRO">PRO</option>
            <option value="ENTERPRISE">ENTERPRISE</option>
          </select>
        </div>
        <div class="flex items-end">
          <button
            type="button"
            class="w-full px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500"
            @click="loadTenants(1)"
          >
            Apply Filter
          </button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
      <div v-if="loading" class="text-gray-500 py-12 text-center">Memuat…</div>

      <div v-else-if="!tenants.length" class="text-gray-500 py-12 text-center">
        Tidak ada tenant yang match filter
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-900">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tenant</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Period End</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">PO #</th>
            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr v-for="t in tenants" :key="t.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
            <td class="px-3 py-3">
              <div class="text-sm font-medium dark:text-white">{{ t.name }}</div>
              <div class="text-xs text-gray-500">{{ t.code }} · ID #{{ t.id }}</div>
            </td>
            <td class="px-3 py-3">
              <span class="text-xs font-medium dark:text-gray-200">{{ t.plan_code || '-' }}</span>
              <div v-if="t.custom_price" class="text-xs text-gray-500 mt-0.5">{{ formatRupiah(t.custom_price) }}/bln</div>
            </td>
            <td class="px-3 py-3">
              <span
                v-if="t.sub_status"
                class="px-2 py-0.5 text-xs font-semibold rounded"
                :class="statusBadge(t.sub_status)"
              >
                {{ t.sub_status }}
              </span>
              <span v-else class="text-xs text-gray-400">No subscription</span>
            </td>
            <td class="px-3 py-3 text-xs text-gray-700 dark:text-gray-300">
              <div>{{ formatDate(t.current_period_end) }}</div>
              <div v-if="t.trial_ends_at" class="text-blue-600">Trial: {{ formatDate(t.trial_ends_at) }}</div>
            </td>
            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-400">{{ t.po_number || '-' }}</td>
            <td class="px-3 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <router-link
                  :to="`/admin/tenants/${t.id}`"
                  class="p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"
                  title="Detail"
                >
                  <EyeIcon class="h-4 w-4" />
                </router-link>
                <button
                  type="button"
                  class="p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"
                  title="Impersonate"
                  :disabled="acting === t.id"
                  @click="impersonate(t)"
                >
                  <UserCircleIcon class="h-4 w-4" />
                </button>
                <button
                  v-if="t.sub_status === 'SUSPENDED'"
                  type="button"
                  class="p-1.5 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded"
                  title="Unsuspend"
                  :disabled="acting === t.id"
                  @click="unsuspend(t)"
                >
                  <PlayCircleIcon class="h-4 w-4" />
                </button>
                <button
                  v-else-if="t.sub_status === 'ACTIVE' || t.sub_status === 'PAST_DUE'"
                  type="button"
                  class="p-1.5 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/30 rounded"
                  title="Suspend"
                  :disabled="acting === t.id"
                  @click="suspend(t)"
                >
                  <PauseCircleIcon class="h-4 w-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-200 dark:border-gray-700">
        <span class="text-sm text-gray-600 dark:text-gray-400">
          Page {{ meta.page }} dari {{ meta.pages }} ({{ meta.total }} tenant)
        </span>
        <div class="flex gap-1">
          <button
            type="button"
            class="px-3 py-1 text-sm border rounded disabled:opacity-50 dark:border-gray-600 dark:text-gray-200"
            :disabled="meta.page <= 1"
            @click="loadTenants(meta.page - 1)"
          >
            Sebelumnya
          </button>
          <button
            type="button"
            class="px-3 py-1 text-sm border rounded disabled:opacity-50 dark:border-gray-600 dark:text-gray-200"
            :disabled="meta.page >= meta.pages"
            @click="loadTenants(meta.page + 1)"
          >
            Berikutnya
          </button>
        </div>
      </div>
    </div>
  </div>
</template>