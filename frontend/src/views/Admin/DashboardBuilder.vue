<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import DynamicDashboard from '@/components/dashboard/DynamicDashboard.vue'
import {
  ArrowDownIcon,
  ArrowPathIcon,
  ArrowUpIcon,
  Bars3Icon,
  CheckCircleIcon,
  EyeIcon,
  PlusIcon,
  PresentationChartLineIcon,
  RocketLaunchIcon,
  TrashIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()
const catalog = ref([])
const roles = ref([])
const templates = ref([])
const selectedId = ref(null)
const loading = ref(true)
const saving = ref(false)
const previewing = ref(false)
const previewDashboard = ref(null)
const draggedIndex = ref(null)

const emptyForm = () => ({
  name: '',
  description: '',
  target_role_slug: '',
  company_id: auth.activeCompanyId || null,
  widgets: [],
})
const form = ref(emptyForm())

const roleOptions = computed(() => {
  const seen = new Set()
  return roles.value.filter((role) => {
    if (!role.slug || role.slug === 'super_admin' || seen.has(role.slug)) return false
    seen.add(role.slug)
    return true
  })
})
const groupedCatalog = computed(() =>
  catalog.value.reduce((groups, item) => {
    const group = item.group || 'Lainnya'
    if (!groups[group]) groups[group] = []
    groups[group].push(item)
    return groups
  }, {})
)
const selectedTemplate = computed(() =>
  templates.value.find((item) => item.id === selectedId.value)
)
const isValid = computed(
  () => form.value.name.trim() && form.value.target_role_slug && form.value.widgets.length
)

function normalizeCompanyRows(payload) {
  if (Array.isArray(payload)) return payload
  return payload?.items || payload?.data || []
}

async function load() {
  loading.value = true
  try {
    const [catalogResponse, templatesResponse, rolesResponse] = await Promise.all([
      api.get('/admin/dashboard-builder/catalog'),
      api.get('/admin/dashboard-builder/templates'),
      api.get('/roles'),
    ])
    catalog.value = catalogResponse.data.data || []
    templates.value = templatesResponse.data.data || []
    roles.value = normalizeCompanyRows(rolesResponse.data.data)
    if (templates.value.length) selectTemplate(templates.value[0])
    else newTemplate()
  } catch (error) {
    toast.error(error.response?.data?.message || 'Gagal memuat Dashboard Builder')
  } finally {
    loading.value = false
  }
}

function newTemplate() {
  selectedId.value = null
  form.value = emptyForm()
}

function selectTemplate(template) {
  selectedId.value = template.id
  form.value = {
    name: template.name || '',
    description: template.description || '',
    target_role_slug: template.target_role_slug || '',
    company_id: template.company_id || null,
    widgets: (template.widgets || []).map((widget) => ({ ...widget })),
  }
}

function addWidget(metric) {
  if (form.value.widgets.length >= 24) {
    toast.warning('Maksimal 24 widget dalam satu dashboard')
    return
  }
  form.value.widgets.push({
    id: `widget_${Date.now()}_${form.value.widgets.length}`,
    metric_key: metric.key,
    title: '',
    width: metric.default_width,
    color: metric.color,
  })
}

function metricFor(key) {
  return catalog.value.find((item) => item.key === key) || {}
}

function moveWidget(index, direction) {
  const target = index + direction
  if (target < 0 || target >= form.value.widgets.length) return
  const [widget] = form.value.widgets.splice(index, 1)
  form.value.widgets.splice(target, 0, widget)
}

function dropWidget(targetIndex) {
  if (draggedIndex.value === null || draggedIndex.value === targetIndex) return
  const [widget] = form.value.widgets.splice(draggedIndex.value, 1)
  form.value.widgets.splice(targetIndex, 0, widget)
  draggedIndex.value = null
}

function payload() {
  return {
    ...form.value,
    company_id: form.value.company_id ? Number(form.value.company_id) : null,
  }
}

async function save(showToast = true) {
  if (!isValid.value) {
    toast.warning('Isi nama, pilih role, dan tambahkan minimal satu widget')
    return null
  }
  saving.value = true
  try {
    const createDraftCopy = selectedTemplate.value?.status === 'PUBLISHED'
    const response =
      selectedId.value && !createDraftCopy
        ? await api.put(`/admin/dashboard-builder/templates/${selectedId.value}`, payload())
        : await api.post('/admin/dashboard-builder/templates', payload())
    const saved = response.data.data
    selectedId.value = saved.id
    const current = templates.value.findIndex((item) => item.id === saved.id)
    if (current >= 0) templates.value.splice(current, 1, saved)
    else templates.value.unshift(saved)
    selectTemplate(saved)
    if (showToast) {
      toast.success(
        createDraftCopy
          ? 'Draft salinan dibuat; dashboard aktif tetap aman sampai versi baru dipublish'
          : 'Draft dashboard berhasil disimpan'
      )
    }
    return saved
  } catch (error) {
    toast.error(error.response?.data?.message || 'Gagal menyimpan dashboard')
    return null
  } finally {
    saving.value = false
  }
}

async function preview() {
  if (!isValid.value) {
    toast.warning('Lengkapi dashboard sebelum melihat preview')
    return
  }
  previewing.value = true
  try {
    const response = await api.post('/admin/dashboard-builder/preview', payload())
    previewDashboard.value = response.data.data
  } catch (error) {
    toast.error(error.response?.data?.message || 'Preview dashboard gagal dibuat')
  } finally {
    previewing.value = false
  }
}

async function publish() {
  const ok = await confirmDialog({
    title: 'Publish dashboard?',
    message:
      'Dashboard ini akan langsung menjadi dashboard aktif untuk seluruh pengguna dengan role dan tenant yang dipilih.',
    okText: 'Simpan & Publish',
  })
  if (!ok) return
  const saved = await save(false)
  if (!saved) return
  saving.value = true
  try {
    const response = await api.post(`/admin/dashboard-builder/templates/${saved.id}/publish`)
    const published = response.data.data
    templates.value = templates.value.map((item) => {
      const sameScope =
        item.target_role_slug === published.target_role_slug &&
        Number(item.company_id || 0) === Number(published.company_id || 0)
      if (sameScope && item.id !== published.id && item.status === 'PUBLISHED') {
        return { ...item, status: 'ARCHIVED' }
      }
      return item.id === published.id ? published : item
    })
    selectTemplate(published)
    toast.success('Dashboard sudah aktif untuk role yang dipilih')
  } catch (error) {
    toast.error(error.response?.data?.message || 'Gagal publish dashboard')
  } finally {
    saving.value = false
  }
}

async function removeTemplate() {
  if (!selectedId.value) return
  const ok = await confirmDialog({
    title: 'Hapus template dashboard?',
    message: `Template "${form.value.name}" akan dihapus dari Dashboard Builder.`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api.delete(`/admin/dashboard-builder/templates/${selectedId.value}`)
    templates.value = templates.value.filter((item) => item.id !== selectedId.value)
    if (templates.value.length) selectTemplate(templates.value[0])
    else newTemplate()
    toast.success('Template dashboard dihapus')
  } catch (error) {
    toast.error(error.response?.data?.message || 'Gagal menghapus template')
  }
}

function companyName(id) {
  if (!id) return 'Global (semua tenant)'
  return (
    auth.availableCompanies.find((company) => Number(company.id) === Number(id))?.name ||
    `Tenant #${id}`
  )
}

const TYPE_LABELS = {
  stat: 'Kartu',
  progress: 'Progress',
  bar: 'Bar',
  donut: 'Donut',
  pie: 'Pie',
  line: 'Line',
  area: 'Area',
  links: 'Link',
}
const CHART_TYPES = ['bar', 'donut', 'pie', 'line', 'area']

function typeLabel(type) {
  return TYPE_LABELS[type] || 'Kartu'
}

function typeBadgeClass(type) {
  if (CHART_TYPES.includes(type)) {
    return 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300'
  }
  return 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
}

onMounted(load)
</script>

<template>
  <section class="space-y-5 text-gray-900 dark:text-gray-100">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <PresentationChartLineIcon class="h-7 w-7 text-primary-600 dark:text-primary-400" />
          <h1 class="text-2xl font-bold">Dashboard Builder</h1>
        </div>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
          Susun dashboard monitoring berbasis role tanpa kode. Data tetap mengikuti tenant dan
          permission pengguna.
        </p>
      </div>
      <button class="btn btn-primary shrink-0" type="button" @click="newTemplate">
        <PlusIcon class="h-4 w-4" /> Dashboard Baru
      </button>
    </header>

    <div
      v-if="loading"
      class="card card-body flex min-h-64 items-center justify-center dark:border-gray-700 dark:bg-gray-900"
    >
      <ArrowPathIcon class="h-7 w-7 animate-spin text-primary-500" />
    </div>

    <div v-else class="grid min-h-[680px] gap-5 xl:grid-cols-[260px_minmax(0,1fr)_330px]">
      <aside class="card overflow-hidden dark:border-gray-700 dark:bg-gray-900">
        <div class="card-header border-gray-200 dark:border-gray-700">
          <h2 class="font-semibold">Template</h2>
          <p class="text-xs text-gray-400">{{ templates.length }} dashboard tersimpan</p>
        </div>
        <div class="max-h-[620px] space-y-2 overflow-y-auto p-3">
          <button
            v-for="template in templates"
            :key="template.id"
            type="button"
            class="w-full rounded-lg border p-3 text-left transition"
            :class="
              selectedId === template.id
                ? 'border-primary-300 bg-primary-50 dark:border-primary-700 dark:bg-primary-950/40'
                : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:border-gray-600 dark:hover:bg-gray-800'
            "
            @click="selectTemplate(template)"
          >
            <div class="flex items-start justify-between gap-2">
              <span class="truncate text-sm font-semibold">{{ template.name }}</span>
              <span
                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                :class="
                  template.status === 'PUBLISHED'
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                    : template.status === 'ARCHIVED'
                      ? 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                      : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                "
                >{{ template.status }}</span
              >
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {{ template.target_role_slug }}
            </p>
            <p class="mt-1 truncate text-[11px] text-gray-400">
              {{ companyName(template.company_id) }}
            </p>
          </button>
          <div v-if="!templates.length" class="px-3 py-10 text-center text-sm text-gray-400">
            Belum ada template.
          </div>
        </div>
      </aside>

      <main class="min-w-0 space-y-5">
        <div class="card dark:border-gray-700 dark:bg-gray-900">
          <div
            class="card-header flex flex-wrap items-center justify-between gap-3 border-gray-200 dark:border-gray-700"
          >
            <div>
              <h2 class="font-semibold">Konfigurasi Dashboard</h2>
              <p class="text-xs text-gray-400">Versi {{ selectedTemplate?.version || 1 }}</p>
            </div>
            <button
              v-if="selectedId && selectedTemplate?.status !== 'PUBLISHED'"
              type="button"
              class="btn btn-outline text-red-600 dark:border-gray-600 dark:text-red-400"
              @click="removeTemplate"
            >
              <TrashIcon class="h-4 w-4" /> Hapus
            </button>
          </div>
          <div class="card-body grid gap-4 md:grid-cols-2">
            <label class="block">
              <span class="mb-1 block text-sm font-medium">Nama dashboard</span>
              <input
                v-model="form.name"
                class="form-input w-full dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                maxlength="150"
                placeholder="Contoh: Monitoring Payroll"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium">Role penerima</span>
              <select
                v-model="form.target_role_slug"
                class="form-select w-full dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option value="" disabled>Pilih role</option>
                <option v-for="role in roleOptions" :key="role.slug" :value="role.slug">
                  {{ role.name }} ({{ role.slug }})
                </option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium">Cakupan tenant</span>
              <select
                v-model="form.company_id"
                class="form-select w-full dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option :value="null">Global — semua tenant tanpa override</option>
                <option
                  v-for="company in auth.availableCompanies"
                  :key="company.id"
                  :value="company.id"
                >
                  {{ company.name }}
                </option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium">Deskripsi</span>
              <input
                v-model="form.description"
                class="form-input w-full dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                maxlength="1000"
                placeholder="Tujuan dashboard ini"
              />
            </label>
          </div>
        </div>

        <div class="card dark:border-gray-700 dark:bg-gray-900">
          <div
            class="card-header flex items-center justify-between border-gray-200 dark:border-gray-700"
          >
            <div>
              <h2 class="font-semibold">Susunan Widget</h2>
              <p class="text-xs text-gray-400">
                Tarik widget untuk mengurutkan. Maksimal 24 komponen.
              </p>
            </div>
            <span class="text-xs font-medium text-gray-500">{{ form.widgets.length }}/24</span>
          </div>
          <div class="max-h-[650px] space-y-3 overflow-y-auto p-4">
            <article
              v-for="(widget, index) in form.widgets"
              :key="widget.id"
              draggable="true"
              class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
              @dragstart="draggedIndex = index"
              @dragover.prevent
              @drop="dropWidget(index)"
            >
              <div class="flex items-start gap-3">
                <Bars3Icon class="mt-2 h-5 w-5 shrink-0 cursor-grab text-gray-400" />
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <h3 class="text-sm font-semibold">
                        {{ metricFor(widget.metric_key).label }}
                      </h3>
                      <p class="text-xs text-gray-400">
                        {{ metricFor(widget.metric_key).description }}
                      </p>
                    </div>
                    <div class="flex items-center gap-1">
                      <button
                        type="button"
                        class="rounded p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700"
                        :disabled="index === 0"
                        title="Naikkan"
                        @click="moveWidget(index, -1)"
                      >
                        <ArrowUpIcon class="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        class="rounded p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700"
                        :disabled="index === form.widgets.length - 1"
                        title="Turunkan"
                        @click="moveWidget(index, 1)"
                      >
                        <ArrowDownIcon class="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40"
                        title="Hapus widget"
                        @click="form.widgets.splice(index, 1)"
                      >
                        <TrashIcon class="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                  <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label class="sm:col-span-1">
                      <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
                        >Judul khusus</span
                      >
                      <input
                        v-model="widget.title"
                        class="form-input w-full text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="Gunakan judul default"
                        maxlength="100"
                      />
                    </label>
                    <label>
                      <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Lebar</span>
                      <select
                        v-model="widget.width"
                        class="form-select w-full text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option value="quarter">25%</option>
                        <option value="half">50%</option>
                        <option value="full">100%</option>
                      </select>
                    </label>
                    <label>
                      <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Warna</span>
                      <select
                        v-model="widget.color"
                        class="form-select w-full text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option
                          v-for="color in ['blue', 'emerald', 'amber', 'rose', 'violet', 'slate']"
                          :key="color"
                          :value="color"
                        >
                          {{ color }}
                        </option>
                      </select>
                    </label>
                  </div>
                </div>
              </div>
            </article>
            <div
              v-if="!form.widgets.length"
              class="rounded-xl border-2 border-dashed border-gray-200 px-6 py-16 text-center dark:border-gray-700"
            >
              <PresentationChartLineIcon
                class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600"
              />
              <p class="mt-3 text-sm font-medium text-gray-600 dark:text-gray-300">
                Dashboard masih kosong
              </p>
              <p class="text-xs text-gray-400">Tambahkan komponen dari katalog di sebelah kanan.</p>
            </div>
          </div>
        </div>
      </main>

      <aside class="card overflow-hidden dark:border-gray-700 dark:bg-gray-900">
        <div class="card-header border-gray-200 dark:border-gray-700">
          <h2 class="font-semibold">Katalog Komponen</h2>
          <p class="text-xs text-gray-400">Data aman yang tersedia di sistem</p>
        </div>
        <div class="max-h-[720px] space-y-5 overflow-y-auto p-3">
          <section v-for="(items, group) in groupedCatalog" :key="group">
            <h3 class="mb-2 px-1 text-xs font-bold uppercase tracking-wider text-gray-400">
              {{ group }}
            </h3>
            <div class="space-y-2">
              <button
                v-for="metric in items"
                :key="metric.key"
                type="button"
                class="group w-full rounded-lg border border-gray-200 p-3 text-left hover:border-primary-300 hover:bg-primary-50 dark:border-gray-700 dark:hover:border-primary-700 dark:hover:bg-primary-950/30"
                @click="addWidget(metric)"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-sm font-semibold">{{ metric.label }}</span>
                  <span class="flex items-center gap-1.5">
                    <span
                      class="rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                      :class="typeBadgeClass(metric.type)"
                    >
                      {{ typeLabel(metric.type) }}
                    </span>
                    <PlusIcon
                      class="h-4 w-4 text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400"
                    />
                  </span>
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ metric.description }}</p>
                <p
                  v-if="metric.required_permission"
                  class="mt-2 truncate text-[10px] text-primary-600 dark:text-primary-400"
                >
                  {{ metric.required_permission }}
                </p>
              </button>
            </div>
          </section>
        </div>
      </aside>
    </div>

    <footer
      v-if="!loading"
      class="sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-gray-700 dark:bg-gray-900/95"
    >
      <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
        <CheckCircleIcon
          v-if="selectedTemplate?.status === 'PUBLISHED'"
          class="h-5 w-5 text-emerald-500"
        />
        <span>{{
          selectedTemplate?.status === 'PUBLISHED'
            ? 'Dashboard sedang aktif'
            : 'Perubahan belum dipublish'
        }}</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn btn-outline dark:border-gray-600"
          :disabled="previewing"
          @click="preview"
        >
          <EyeIcon class="h-4 w-4" /> {{ previewing ? 'Menyiapkan...' : 'Preview' }}
        </button>
        <button
          type="button"
          class="btn btn-outline dark:border-gray-600"
          :disabled="saving"
          @click="save()"
        >
          {{ saving ? 'Menyimpan...' : 'Simpan Draft' }}
        </button>
        <button type="button" class="btn btn-primary" :disabled="saving" @click="publish">
          <RocketLaunchIcon class="h-4 w-4" /> Publish
        </button>
      </div>
    </footer>
  </section>

  <Teleport to="body">
    <div
      v-if="previewDashboard"
      class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/70 p-3 backdrop-blur-sm sm:p-6"
      @click.self="previewDashboard = null"
    >
      <div
        class="flex max-h-[94vh] w-full max-w-7xl flex-col overflow-hidden rounded-2xl bg-gray-50 shadow-2xl dark:bg-gray-950"
      >
        <header
          class="flex items-center justify-between border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900"
        >
          <div>
            <h2 class="font-bold text-gray-900 dark:text-white">Preview Dashboard</h2>
            <p class="text-xs text-gray-400">Tampilan aktual dengan data tenant yang dipilih</p>
          </div>
          <button
            type="button"
            class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
            @click="previewDashboard = null"
          >
            <XMarkIcon class="h-6 w-6" />
          </button>
        </header>
        <div class="overflow-y-auto p-5 sm:p-7">
          <DynamicDashboard :dashboard="previewDashboard" compact />
        </div>
      </div>
    </div>
  </Teleport>
</template>
