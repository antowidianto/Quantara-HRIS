<script setup>
/*
 * Recursive tree node untuk halaman OrgStructure.
 *
 * Di-pisah ke file SFC sendiri supaya bisa self-reference: Vue 3 SFC dengan
 * `<script setup>` mendaftarkan komponen sendiri berdasarkan nama file,
 * jadi template di bawah boleh memakai `<DepartmentNode>` tanpa perlu
 * import eksplisit.
 *
 * Posisi diberi warna baris + badge berdasarkan tier (MANAGER /
 * ASST_MANAGER / SUPERVISOR / STAFF) supaya hirarki dalam satu
 * department mudah dibaca dengan cepat.
 */
import {
  ChevronRightIcon, ChevronDownIcon, BuildingOffice2Icon,
  BriefcaseIcon, UserGroupIcon, PlusIcon, PencilIcon, TrashIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  node:        { type: Object, required: true },
  level:       { type: Number, default: 0 },
  expandedIds: { type: Set,    required: true },
  dragOverId:  { type: Number, default: null },
})

const emit = defineEmits([
  'toggle', 'drop-on', 'drag-over', 'drag-leave', 'drag-start',
  'edit', 'delete', 'add-child', 'add-position', 'delete-position', 'edit-position',
])

function isExpanded() {
  return props.expandedIds.has(props.node.id)
}

// Mapping tier → klas Tailwind. Kontras lembut supaya nyaman dibaca
// pada banyak baris dan tidak menyilaukan saat HR bekerja lama.
const TIER_BADGE = {
  MANAGER:      'bg-rose-100 text-rose-700',
  ASST_MANAGER: 'bg-amber-100 text-amber-700',
  SUPERVISOR:   'bg-blue-100 text-blue-700',
  STAFF:        'bg-gray-100 text-gray-600',
}
const TIER_ROW = {
  MANAGER:      'bg-rose-50/60 hover:bg-rose-50',
  ASST_MANAGER: 'bg-amber-50/60 hover:bg-amber-50',
  SUPERVISOR:   'bg-blue-50/60 hover:bg-blue-50',
  STAFF:        'bg-emerald-50/40 hover:bg-emerald-50',
}
const TIER_ICON = {
  MANAGER:      'text-rose-600',
  ASST_MANAGER: 'text-amber-600',
  SUPERVISOR:   'text-blue-600',
  STAFF:        'text-emerald-600',
}
const TIER_SHORT = {
  MANAGER:      'MGR',
  ASST_MANAGER: 'ASST',
  SUPERVISOR:   'SPV',
  STAFF:        'STAFF',
}
function tierBadgeClass(t) { return TIER_BADGE[t] || TIER_BADGE.STAFF }
function tierRowClass(t)   { return TIER_ROW[t]   || TIER_ROW.STAFF }
function tierIconClass(t)  { return TIER_ICON[t]  || TIER_ICON.STAFF }
function tierShort(t)      { return TIER_SHORT[t] || 'STAFF' }
</script>

<template>
  <li>
    <div
      class="group flex items-center gap-2 px-2 py-1.5 rounded-md border transition-colors cursor-move"
      :class="dragOverId === node.id
        ? 'border-primary-500 bg-primary-50'
        : 'border-transparent hover:border-gray-200 hover:bg-gray-50'"
      :style="{ marginLeft: (level * 20) + 'px' }"
      draggable="true"
      @dragstart.stop="emit('drag-start', $event, 'department', node.id)"
      @dragover.stop="emit('drag-over', $event, node.id)"
      @dragleave.stop="emit('drag-leave')"
      @drop.stop="emit('drop-on', $event, node.id)"
    >
      <button
        v-if="(node.children && node.children.length) || (node.positions && node.positions.length)"
        class="text-gray-400 hover:text-gray-700 flex-shrink-0"
        @click.stop="emit('toggle', node.id)"
      >
        <ChevronDownIcon v-if="isExpanded()" class="w-4 h-4" />
        <ChevronRightIcon v-else class="w-4 h-4" />
      </button>
      <span v-else class="w-4"></span>

      <BuildingOffice2Icon class="w-5 h-5 text-blue-600 flex-shrink-0" />

      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="font-medium text-sm text-gray-800 truncate">{{ node.name }}</span>
          <span class="text-xs text-gray-400">{{ node.code }}</span>
          <span v-if="! node.is_active"
            class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-gray-200 text-gray-600">
            Non-aktif
          </span>
        </div>
        <div class="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
          <span v-if="node.manager_name" class="flex items-center gap-1">
            <UserGroupIcon class="w-3.5 h-3.5" /> {{ node.manager_name }}
          </span>
          <span v-else class="italic text-gray-400">Tanpa manager</span>
          <span class="flex items-center gap-1">
            <UserGroupIcon class="w-3.5 h-3.5" /> {{ node.employee_count }} karyawan
          </span>
          <span v-if="(node.positions || []).length" class="flex items-center gap-1">
            <BriefcaseIcon class="w-3.5 h-3.5" /> {{ node.positions.length }} posisi
          </span>
        </div>
      </div>

      <div class="opacity-0 group-hover:opacity-100 flex items-center gap-1 flex-shrink-0">
        <button class="p-1 text-gray-400 hover:text-emerald-600"
          @click.stop="emit('add-child', node.id)" title="Tambah sub-department">
          <PlusIcon class="w-4 h-4" />
        </button>
        <button class="p-1 text-gray-400 hover:text-blue-600"
          @click.stop="emit('add-position', node.id)" title="Tambah posisi">
          <BriefcaseIcon class="w-4 h-4" />
        </button>
        <button class="p-1 text-gray-400 hover:text-primary-600"
          @click.stop="emit('edit', node)" title="Edit">
          <PencilIcon class="w-4 h-4" />
        </button>
        <button class="p-1 text-gray-400 hover:text-red-600"
          @click.stop="emit('delete', node)" title="Hapus">
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>
    </div>

    <ul v-if="isExpanded()" class="mt-1 space-y-1">
      <!-- Posisi — warna kiri sesuai tier supaya hirarki kebaca cepat -->
      <li v-for="p in (node.positions || [])" :key="'p-' + p.id"
        class="flex items-center gap-2 px-2 py-1 rounded text-xs cursor-move group/pos"
        :class="tierRowClass(p.tier)"
        :style="{ marginLeft: ((level + 1) * 20 + 16) + 'px' }"
        draggable="true"
        @dragstart.stop="emit('drag-start', $event, 'position', p.id)"
      >
        <BriefcaseIcon class="w-3.5 h-3.5 flex-shrink-0" :class="tierIconClass(p.tier)" />
        <span class="flex-1 truncate">{{ p.name }}</span>
        <span
          class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded font-medium flex-shrink-0"
          :class="tierBadgeClass(p.tier)">
          {{ tierShort(p.tier) }}
        </span>
        <span class="text-[10px] text-gray-400">{{ p.code }}</span>
        <button class="text-gray-400 hover:text-primary-600 opacity-0 group-hover/pos:opacity-100"
          @click.stop="emit('edit-position', p)" title="Edit posisi (ubah tier dll.)">
          <PencilIcon class="w-3.5 h-3.5" />
        </button>
        <button class="text-gray-400 hover:text-red-600 opacity-0 group-hover/pos:opacity-100"
          @click.stop="emit('delete-position', p)" title="Hapus posisi">
          <TrashIcon class="w-3.5 h-3.5" />
        </button>
      </li>

      <!-- Sub-departments — self-reference via filename (Vue auto-registers SFC name) -->
      <DepartmentNode
        v-for="c in (node.children || [])"
        :key="c.id"
        :node="c"
        :level="level + 1"
        :expanded-ids="expandedIds"
        :drag-over-id="dragOverId"
        @toggle="(id) => emit('toggle', id)"
        @drop-on="(e, id) => emit('drop-on', e, id)"
        @drag-over="(e, id) => emit('drag-over', e, id)"
        @drag-leave="() => emit('drag-leave')"
        @drag-start="(e, t, id) => emit('drag-start', e, t, id)"
        @edit="(d) => emit('edit', d)"
        @delete="(d) => emit('delete', d)"
        @add-child="(id) => emit('add-child', id)"
        @add-position="(id) => emit('add-position', id)"
        @delete-position="(p) => emit('delete-position', p)"
        @edit-position="(p) => emit('edit-position', p)"
      />
    </ul>
  </li>
</template>

<style scoped>
[draggable="true"]:active { cursor: grabbing; }
</style>