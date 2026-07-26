<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import {
  ArrowRightIcon,
  ChartBarIcon,
  CheckCircleIcon,
  ClockIcon,
  CurrencyDollarIcon,
  PresentationChartLineIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import ChartCanvas from '@/components/dashboard/ChartCanvas.vue'

const props = defineProps({
  dashboard: { type: Object, required: true },
  compact: { type: Boolean, default: false },
})

const widthClass = {
  quarter: 'md:col-span-3',
  half: 'md:col-span-6',
  full: 'md:col-span-12',
}
const tone = {
  blue: {
    icon: 'bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-300',
    bar: 'bg-blue-500',
    text: 'text-blue-600 dark:text-blue-300',
  },
  emerald: {
    icon: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    bar: 'bg-emerald-500',
    text: 'text-emerald-600 dark:text-emerald-300',
  },
  amber: {
    icon: 'bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300',
    bar: 'bg-amber-500',
    text: 'text-amber-600 dark:text-amber-300',
  },
  rose: {
    icon: 'bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300',
    bar: 'bg-rose-500',
    text: 'text-rose-600 dark:text-rose-300',
  },
  violet: {
    icon: 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300',
    bar: 'bg-violet-500',
    text: 'text-violet-600 dark:text-violet-300',
  },
  slate: {
    icon: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    bar: 'bg-slate-500',
    text: 'text-slate-700 dark:text-slate-200',
  },
}
const chartColors = [
  '#3b82f6',
  '#10b981',
  '#f59e0b',
  '#8b5cf6',
  '#f43f5e',
  '#06b6d4',
  '#64748b',
  '#84cc16',
]

const widgets = computed(() => props.dashboard?.widgets || [])

function maxValue(items) {
  return Math.max(1, ...(items || []).map((item) => Number(item.value || 0)))
}
function donutStyle(items) {
  const total = (items || []).reduce((sum, item) => sum + Number(item.value || 0), 0)
  if (!total) return { background: '#e2e8f0' }
  let cursor = 0
  const segments = items.map((item, index) => {
    const start = cursor
    cursor += (Number(item.value || 0) / total) * 100
    return `${chartColors[index % chartColors.length]} ${start}% ${cursor}%`
  })
  return { background: `conic-gradient(${segments.join(',')})` }
}
function iconFor(widget) {
  if (widget.metric_key?.startsWith('headcount')) return UserGroupIcon
  if (widget.metric_key?.startsWith('payroll')) return CurrencyDollarIcon
  if (widget.metric_key?.startsWith('approval')) return CheckCircleIcon
  if (widget.metric_key?.startsWith('attendance')) return ClockIcon
  return ChartBarIcon
}
</script>

<template>
  <section>
    <div v-if="!compact" class="mb-6">
      <div class="flex flex-wrap items-center gap-2">
        <span
          class="rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-primary-700 dark:bg-primary-950/50 dark:text-primary-300"
        >
          Dashboard {{ dashboard.target_role_slug }}
        </span>
        <span v-if="dashboard.status === 'PREVIEW'" class="badge-yellow">Preview</span>
      </div>
      <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ dashboard.name }}</h1>
      <p v-if="dashboard.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ dashboard.description }}
      </p>
    </div>

    <div v-if="widgets.length" class="grid grid-cols-1 gap-4 md:grid-cols-12">
      <article
        v-for="widget in widgets"
        :key="widget.id"
        :class="widthClass[widget.width] || widthClass.half"
        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
      >
        <div v-if="widget.type === 'stat'" class="p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p
                class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              >
                {{ widget.label }}
              </p>
              <div class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">
                {{ Number(widget.data.value || 0).toLocaleString('id-ID') }}
              </div>
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ widget.data.unit || widget.description }}
              </p>
            </div>
            <div :class="tone[widget.color]?.icon || tone.blue.icon" class="rounded-lg p-2.5">
              <component :is="iconFor(widget)" class="h-5 w-5" />
            </div>
          </div>
        </div>

        <div v-else-if="widget.type === 'progress'" class="p-5">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p
                class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              >
                {{ widget.label }}
              </p>
              <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                {{ Number(widget.data.value || 0).toLocaleString('id-ID') }}
                <span class="text-sm font-medium text-gray-400"
                  >/ {{ Number(widget.data.max || 0).toLocaleString('id-ID') }}</span
                >
              </div>
            </div>
            <span :class="tone[widget.color]?.text || tone.emerald.text" class="text-xl font-bold"
              >{{ widget.data.percent || 0 }}%</span
            >
          </div>
          <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            <div
              :class="tone[widget.color]?.bar || tone.emerald.bar"
              class="h-full rounded-full transition-all"
              :style="{ width: `${Math.min(100, Number(widget.data.percent || 0))}%` }"
            ></div>
          </div>
        </div>

        <div v-else-if="widget.type === 'bar'" class="p-5">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ widget.label }}</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ widget.description }}</p>
          <div v-if="widget.data.items?.length" class="mt-5 space-y-3">
            <div v-for="item in widget.data.items" :key="item.label">
              <div class="mb-1 flex items-center justify-between text-xs">
                <span class="truncate text-gray-600 dark:text-gray-300">{{ item.label }}</span>
                <strong class="text-gray-900 dark:text-white">{{
                  Number(item.value || 0).toLocaleString('id-ID')
                }}</strong>
              </div>
              <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                <div
                  :class="tone[widget.color]?.bar || tone.blue.bar"
                  class="h-full rounded-full"
                  :style="{
                    width: `${(Number(item.value || 0) / maxValue(widget.data.items)) * 100}%`,
                  }"
                ></div>
              </div>
            </div>
          </div>
          <p v-else class="py-8 text-center text-sm text-gray-400">Belum ada data.</p>
        </div>

        <div v-else-if="widget.type === 'donut'" class="p-5">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ widget.label }}</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ widget.description }}</p>
          <div
            v-if="widget.data.items?.length"
            class="mt-5 flex flex-col items-center gap-5 sm:flex-row"
          >
            <div
              class="relative h-32 w-32 shrink-0 rounded-full"
              :style="donutStyle(widget.data.items)"
            >
              <div
                class="absolute inset-5 flex items-center justify-center rounded-full bg-white text-xl font-bold text-gray-900 dark:bg-gray-900 dark:text-white"
              >
                {{ widget.data.items.reduce((sum, item) => sum + Number(item.value || 0), 0) }}
              </div>
            </div>
            <div class="min-w-0 flex-1 space-y-2">
              <div
                v-for="(item, index) in widget.data.items"
                :key="item.label"
                class="flex items-center gap-2 text-xs"
              >
                <span
                  class="h-2.5 w-2.5 rounded-full"
                  :style="{ background: chartColors[index % chartColors.length] }"
                ></span>
                <span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-300">{{
                  item.label
                }}</span>
                <strong class="text-gray-900 dark:text-white">{{ item.value }}</strong>
              </div>
            </div>
          </div>
          <p v-else class="py-8 text-center text-sm text-gray-400">Belum ada data.</p>
        </div>

        <div
          v-else-if="widget.type === 'line' || widget.type === 'area'"
          class="p-5"
        >
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ widget.label }}</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ widget.description }}</p>
          <div v-if="widget.data.items?.length" class="mt-4">
            <ChartCanvas
              :type="widget.type"
              :items="widget.data.items"
              :color="widget.color"
              :height="compact ? 200 : 260"
            />
          </div>
          <p v-else class="py-8 text-center text-sm text-gray-400">Belum ada data.</p>
        </div>

        <div v-else-if="widget.type === 'pie'" class="p-5">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ widget.label }}</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ widget.description }}</p>
          <div v-if="widget.data.items?.length" class="mt-4">
            <ChartCanvas
              type="pie"
              :items="widget.data.items"
              :color="widget.color"
              :height="compact ? 220 : 260"
            />
          </div>
          <p v-else class="py-8 text-center text-sm text-gray-400">Belum ada data.</p>
        </div>

        <div v-else-if="widget.type === 'links'" class="p-5">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ widget.label }}</h3>
          <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            <RouterLink
              v-for="item in widget.data.items"
              :key="item.to"
              :to="item.to"
              class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-gray-700 hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-gray-700 dark:text-gray-200 dark:hover:border-primary-700 dark:hover:bg-primary-950/40"
            >
              {{ item.label }} <ArrowRightIcon class="h-4 w-4" />
            </RouterLink>
          </div>
        </div>
      </article>
    </div>

    <div
      v-else
      class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-gray-900"
    >
      <PresentationChartLineIcon class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600" />
      <h3 class="mt-3 font-semibold text-gray-700 dark:text-gray-200">
        Dashboard belum memiliki widget
      </h3>
      <p class="mt-1 text-sm text-gray-400">Tambahkan widget melalui Dashboard Builder.</p>
    </div>
  </section>
</template>
