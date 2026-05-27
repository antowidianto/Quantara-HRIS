<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  ShareIcon, ArrowPathIcon, ChevronRightIcon, ChevronDownIcon,
  UserIcon, ChartPieIcon, ExclamationTriangleIcon, ArrowsUpDownIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 11 / H2.2 — Goals Cascading Tree.
 *
 * Visualisasi hierarki goals (parent → child) dari level company sampai
 * individual. Backend menyediakan progress rollup (weighted average dari
 * child → parent).
 *
 * Endpoint:
 *   GET /performance/goals/tree?cycle_id=&root_goal_id=
 *   POST /performance/goals/:id/recompute-rollup
 *
 * Tree node format dari backend (rekursif):
 *   { id, title, owner_name, weight, progress, status, children: [...] }
 */

const toast = useToast()

const cycles = ref([])
const cycleId = ref('')
const tree = ref([])
const loading = ref(false)
const expanded = ref(new Set())

async function loadCycles() {
  try {
    const res = await api.get('/performance-cycles')
    cycles.value = res.data.data || []
    if (cycles.value.length && ! cycleId.value) {
      // Default ke cycle aktif/paling baru
      const active = cycles.value.find(c => c.status === 'OPEN' || c.status === 'IN_REVIEW')
      cycleId.value = (active || cycles.value[0]).id
    }
  } catch (_) {}
}

async function fetchTree() {
  if (! cycleId.value) return
  loading.value = true
  try {
    const res = await api.get('/performance/goals/tree', { params: { cycle_id: cycleId.value } })
    tree.value = res.data.data || []
    // Auto-expand root level
    expanded.value = new Set(tree.value.map(n => n.id))
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tree')
  } finally {
    loading.value = false
  }
}

function toggle(id) {
  const s = new Set(expanded.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  expanded.value = s
}

function expandAll(nodes = tree.value) {
  const s = new Set(expanded.value)
  function walk(arr) {
    arr.forEach(n => {
      s.add(n.id)
      if (n.children?.length) walk(n.children)
    })
  }
  walk(nodes)
  expanded.value = s
}

function collapseAll() {
  expanded.value = new Set()
}

async function recompute(node) {
  try {
    await api.post(`/performance/goals/${node.id}/recompute-rollup`)
    toast.success('Progress rollup di-recompute')
    fetchTree()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  }
}

function progressColor(p) {
  const v = Number(p || 0)
  if (v >= 100) return 'bg-emerald-500'
  if (v >= 75)  return 'bg-emerald-400'
  if (v >= 50)  return 'bg-amber-400'
  if (v >= 25)  return 'bg-amber-500'
  return 'bg-red-400'
}

function statusBadge(s) {
  const map = {
    DRAFT:       'bg-gray-100 text-gray-700',
    SUBMITTED:   'bg-blue-100 text-blue-700',
    APPROVED:    'bg-emerald-100 text-emerald-700',
    IN_PROGRESS: 'bg-blue-100 text-blue-700',
    COMPLETED:   'bg-emerald-100 text-emerald-700',
    CANCELLED:   'bg-gray-100 text-gray-500',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

const stats = computed(() => {
  let total = 0, aligned = 0, completed = 0
  function walk(arr, depth = 0) {
    arr.forEach(n => {
      total++
      if (depth > 0) aligned++
      if ((n.progress || 0) >= 100) completed++
      if (n.children?.length) walk(n.children, depth + 1)
    })
  }
  walk(tree.value)
  return {
    total,
    aligned,
    completed,
    alignedPct: total > 0 ? Math.round((aligned / total) * 100) : 0,
    completedPct: total > 0 ? Math.round((completed / total) * 100) : 0,
  }
})

onMounted(async () => {
  await loadCycles()
  await fetchTree()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShareIcon class="w-6 h-6" /> Goals Cascading Tree
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Visualisasi alignment goals dari level company sampai individual.
          Progress parent dihitung weighted average dari child.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="expandAll()">
          <ChevronDownIcon class="w-4 h-4" /> Expand All
        </button>
        <button class="btn-secondary" @click="collapseAll()">
          <ChevronRightIcon class="w-4 h-4" /> Collapse All
        </button>
        <button class="btn-secondary" @click="fetchTree" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="label">Performance Cycle</label>
          <select v-model="cycleId" class="input" @change="fetchTree">
            <option value="">Pilih cycle…</option>
            <option v-for="c in cycles" :key="c.id" :value="c.id">
              {{ c.name }} ({{ c.status }})
            </option>
          </select>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
        <div class="text-xs text-blue-700 font-medium">Total Goals</div>
        <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
      </div>
      <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
        <div class="text-xs text-purple-700 font-medium">Goals Aligned</div>
        <div class="text-2xl font-bold text-purple-900">{{ stats.aligned }}</div>
        <div class="text-xs text-purple-600 mt-1">{{ stats.alignedPct }}% dari total</div>
      </div>
      <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
        <div class="text-xs text-emerald-700 font-medium">Completed</div>
        <div class="text-2xl font-bold text-emerald-900">{{ stats.completed }}</div>
        <div class="text-xs text-emerald-600 mt-1">{{ stats.completedPct }}% dari total</div>
      </div>
      <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
        <div class="text-xs text-amber-700 font-medium">Root Goals</div>
        <div class="text-2xl font-bold text-amber-900">{{ tree.length }}</div>
        <div class="text-xs text-amber-600 mt-1">level perusahaan</div>
      </div>
    </div>

    <!-- Tree -->
    <div class="card">
      <div class="card-header flex items-center gap-2">
        <ArrowsUpDownIcon class="w-4 h-4" />
        <span class="font-medium">Hierarchy</span>
      </div>
      <div class="p-3">
        <div v-if="loading" class="text-center py-12 text-gray-400">Memuat tree…</div>
        <div v-else-if="tree.length === 0" class="text-center py-12 text-gray-400">
          Belum ada goals di cycle ini, atau cycle belum dipilih
        </div>
        <div v-else>
          <TreeNode
            v-for="n in tree"
            :key="n.id"
            :node="n"
            :depth="0"
            :expanded="expanded"
            @toggle="toggle"
            @recompute="recompute"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { defineComponent, h } from 'vue'

/**
 * Komponen rekursif untuk render satu node + children.
 * Dipisah supaya bisa self-reference tanpa async import.
 */
export const TreeNode = defineComponent({
  name: 'TreeNode',
  props: {
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    expanded: { type: Object, required: true }, // Set
  },
  emits: ['toggle', 'recompute'],
  setup(props, { emit }) {
    function progressColor(p) {
      const v = Number(p || 0)
      if (v >= 100) return 'bg-emerald-500'
      if (v >= 75) return 'bg-emerald-400'
      if (v >= 50) return 'bg-amber-400'
      if (v >= 25) return 'bg-amber-500'
      return 'bg-red-400'
    }
    function statusBadge(s) {
      const map = {
        DRAFT: 'bg-gray-100 text-gray-700',
        SUBMITTED: 'bg-blue-100 text-blue-700',
        APPROVED: 'bg-emerald-100 text-emerald-700',
        IN_PROGRESS: 'bg-blue-100 text-blue-700',
        COMPLETED: 'bg-emerald-100 text-emerald-700',
        CANCELLED: 'bg-gray-100 text-gray-500',
      }
      return map[s] || 'bg-gray-100 text-gray-700'
    }
    return () => {
      const n = props.node
      const hasChildren = n.children && n.children.length > 0
      const isOpen = props.expanded.has(n.id)
      const progress = Number(n.progress || 0)
      return h('div', { class: 'select-none' }, [
        h('div', {
          class: [
            'flex items-center gap-2 py-2 px-2 rounded hover:bg-gray-50 border-l-2',
            props.depth === 0 ? 'border-blue-400' : 'border-transparent',
          ],
          style: { marginLeft: (props.depth * 24) + 'px' },
        }, [
          // Toggle
          hasChildren
            ? h('button', {
                class: 'p-0.5 hover:bg-gray-200 rounded',
                onClick: () => emit('toggle', n.id),
              }, [
                isOpen
                  ? h('svg', { class: 'w-4 h-4 text-gray-500', viewBox: '0 0 20 20', fill: 'currentColor' }, [
                      h('path', { d: 'M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' })
                    ])
                  : h('svg', { class: 'w-4 h-4 text-gray-500', viewBox: '0 0 20 20', fill: 'currentColor' }, [
                      h('path', { d: 'M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z' })
                    ]),
              ])
            : h('span', { class: 'w-5' }),
          // Title + meta
          h('div', { class: 'flex-1 min-w-0' }, [
            h('div', { class: 'flex items-center gap-2 flex-wrap' }, [
              h('span', { class: 'font-medium text-sm' }, n.title || `Goal #${n.id}`),
              n.weight ? h('span', { class: 'text-xs text-gray-500' }, `bobot ${n.weight}%`) : null,
              n.status ? h('span', { class: ['inline-block px-2 py-0.5 rounded-full text-xs', statusBadge(n.status)] }, n.status) : null,
            ]),
            n.owner_name
              ? h('div', { class: 'text-xs text-gray-500 mt-0.5 flex items-center gap-1' }, [
                  h('svg', { class: 'w-3 h-3', viewBox: '0 0 20 20', fill: 'currentColor' }, [
                    h('path', { 'fill-rule': 'evenodd', d: 'M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z', 'clip-rule': 'evenodd' })
                  ]),
                  n.owner_name,
                ])
              : null,
          ]),
          // Progress bar
          h('div', { class: 'flex items-center gap-2 w-48 flex-shrink-0' }, [
            h('div', { class: 'flex-1 bg-gray-200 rounded-full h-2 overflow-hidden' }, [
              h('div', {
                class: ['h-full rounded-full transition-all', progressColor(progress)],
                style: { width: Math.min(100, progress) + '%' },
              }),
            ]),
            h('span', { class: 'text-xs font-mono text-gray-700 w-10 text-right' }, progress.toFixed(0) + '%'),
          ]),
          // Recompute button (only for parent nodes with children)
          hasChildren ? h('button', {
            class: 'btn-icon text-gray-400 hover:text-primary-600',
            title: 'Recompute rollup dari child',
            onClick: () => emit('recompute', n),
          }, [
            h('svg', { class: 'w-4 h-4', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor', 'stroke-width': 2 }, [
              h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', d: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15' })
            ]),
          ]) : null,
        ]),
        // Children
        hasChildren && isOpen ? h('div', null, n.children.map(c =>
          h(TreeNode, {
            key: c.id,
            node: c,
            depth: props.depth + 1,
            expanded: props.expanded,
            onToggle: id => emit('toggle', id),
            onRecompute: node => emit('recompute', node),
          })
        )) : null,
      ])
    }
  },
})
</script>