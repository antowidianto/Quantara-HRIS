<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  MagnifyingGlassIcon, UserGroupIcon, BuildingOffice2Icon,
  BriefcaseIcon, ArrowPathIcon,
} from '@heroicons/vue/24/outline'
import EmployeeModal from './EmployeeModal.vue'
import { useI18n } from '@/locales'

const toast = useToast()
const { t } = useI18n()
const items = ref([])
const loading = ref(false)
const scope = ref('n1')
const search = ref('')
const page = ref(1)
const meta = ref({ total: 0, pages: 1, counts: { n1: 0, n2: 0, all: 0 } })
const selectedEmployeeId = ref(null)

const scopes = computed(() => [
  { value: 'n1', label: t('my_team.scope_n1'), count: meta.value.counts?.n1 || 0 },
  { value: 'n2', label: t('my_team.scope_n2'), count: meta.value.counts?.n2 || 0 },
  { value: 'all', label: t('my_team.scope_all'), count: meta.value.counts?.all || 0 },
])

async function fetchTeam() {
  loading.value = true
  try {
    const res = await api.get('/employees/my-team', {
      params: { scope: scope.value, search: search.value || undefined, page: page.value, per_page: 20 },
    })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('my_team.load_failed'))
    items.value = []
  } finally {
    loading.value = false
  }
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map((part) => part[0]).join('').toUpperCase()
}

let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchTeam() }, 300)
})
watch(scope, () => { page.value = 1; fetchTeam() })
onMounted(fetchTeam)
</script>

<template>
  <div>
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ t('my_team.title') }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ t('my_team.subtitle') }}</p>
      </div>
      <button class="btn-secondary self-start md:self-auto" :disabled="loading" @click="fetchTeam">
        <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> {{ t('my_team.refresh') }}
      </button>
    </div>

    <div class="border-b border-gray-200 mb-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div class="flex overflow-x-auto">
        <button v-for="option in scopes" :key="option.value" type="button"
          class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap"
          :class="scope === option.value
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:text-gray-800'"
          @click="scope = option.value">
          {{ option.label }}
          <span class="ml-1.5 px-1.5 py-0.5 rounded bg-gray-100 text-xs text-gray-600">{{ option.count }}</span>
        </button>
      </div>
      <div class="relative w-full md:w-80 pb-3 md:pb-2">
        <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-[70%] text-gray-400" />
        <label><span class="sr-only">{{ t('my_team.search') }}</span><input v-model="search" class="input pl-9 w-full" /></label>
      </div>
    </div>

    <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="n in 6" :key="n" class="h-32 border border-gray-200 rounded animate-pulse bg-gray-50"></div>
    </div>

    <div v-else-if="items.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
      <button v-for="employee in items" :key="employee.id" type="button"
        class="border border-gray-200 rounded p-4 bg-white text-left hover:border-primary-300 hover:shadow-sm transition"
        @click="selectedEmployeeId = employee.id">
        <div class="flex gap-3 min-w-0">
          <div class="w-11 h-11 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-semibold flex-shrink-0 overflow-hidden">
            <img v-if="employee.avatar_path" :src="employee.avatar_path" :alt="employee.full_name" class="w-full h-full object-cover" />
            <span v-else>{{ initials(employee.full_name) }}</span>
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
              <div class="font-semibold text-gray-900 truncate">{{ employee.full_name }}</div>
              <span class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 flex-shrink-0">N{{ employee.hierarchy_level }}</span>
            </div>
            <div class="text-xs font-mono text-gray-500 mt-0.5">{{ employee.employee_number }}</div>
          </div>
        </div>
        <div class="mt-3 pt-3 border-t border-gray-100 space-y-1.5 text-sm text-gray-600">
          <div class="flex items-center gap-2 min-w-0"><BriefcaseIcon class="w-4 h-4 text-gray-400 flex-shrink-0" /><span class="truncate">{{ employee.position_name || '-' }}</span></div>
          <div class="flex items-center gap-2 min-w-0"><BuildingOffice2Icon class="w-4 h-4 text-gray-400 flex-shrink-0" /><span class="truncate">{{ employee.department_name || '-' }}</span></div>
          <div v-if="employee.manager_name" class="text-xs text-gray-500 truncate">{{ t('my_team.manager', { name: employee.manager_name }) }}</div>
        </div>
      </button>
    </div>

    <div v-else class="border border-dashed border-gray-300 py-16 text-center bg-white">
      <UserGroupIcon class="w-10 h-10 mx-auto text-gray-300" />
      <div class="font-medium text-gray-700 mt-3">{{ t('my_team.empty') }}</div>
      <div class="text-sm text-gray-500 mt-1">{{ t('my_team.empty_note') }}</div>
    </div>

    <div v-if="meta.pages > 1" class="flex items-center justify-between mt-4 text-sm">
      <span class="text-gray-500">{{ t('my_team.employees', { count: meta.total }) }}</span>
      <div class="flex gap-2">
        <button class="btn-secondary" :disabled="page <= 1" @click="page--; fetchTeam()">{{ t('my_team.previous') }}</button>
        <button class="btn-secondary" :disabled="page >= meta.pages" @click="page++; fetchTeam()">{{ t('my_team.next') }}</button>
      </div>
    </div>

    <EmployeeModal
      v-if="selectedEmployeeId"
      :employee-id="selectedEmployeeId"
      mode="view"
      :team-view="true"
      @close="selectedEmployeeId = null"
    />
  </div>
</template>
