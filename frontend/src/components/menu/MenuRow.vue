<script setup>
/**
 * MenuRow — recursive list row untuk Menu Manager (Admin/Menus.vue).
 *
 * Self-reference: di Vue 3, komponen <script setup> bisa rujuk ke
 * dirinya sendiri via filename. Karena nama default-nya diambil dari
 * nama file ("MenuRow"), kita bisa pakai <MenuRow> di template-nya
 * sendiri. Lihat https://vuejs.org/api/sfc-script-setup.html#defineoptions.
 */
import { resolveIcon } from './iconMap'
import {
  PlusIcon, PencilSquareIcon, TrashIcon, ChevronUpIcon, ChevronDownIcon,
  EyeIcon, EyeSlashIcon,
} from '@heroicons/vue/24/outline'

defineOptions({ name: 'MenuRow' })

defineProps({
  node: { type: Object, required: true },
  siblings: { type: Array, required: true },
  index: { type: Number, required: true },
  depth: { type: Number, default: 0 },
})

const emit = defineEmits(['edit', 'remove', 'toggle', 'add-child', 'move'])

const TYPE_BADGE = {
  SECTION: 'bg-purple-100 text-purple-700',
  PARENT:  'bg-amber-100 text-amber-700',
  ITEM:    'bg-emerald-100 text-emerald-700',
}
</script>

<template>
  <li>
    <div
      class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50"
      :style="{ paddingLeft: `${12 + depth * 22}px` }"
    >
      <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-gray-100 text-gray-500">
        <component :is="resolveIcon(node.icon)" class="w-4 h-4" />
      </span>
      <span class="flex-1 min-w-0 flex items-center gap-2 flex-wrap">
        <span
          class="text-sm font-medium truncate"
          :class="Number(node.is_active) === 0
            ? 'text-gray-400 line-through'
            : 'text-gray-800'"
        >
          {{ node.label }}
        </span>
        <span
          class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
          :class="TYPE_BADGE[node.type] || 'bg-gray-100 text-gray-700'"
        >{{ node.type }}</span>
        <span class="text-xs text-gray-400 font-mono truncate">{{ node.code }}</span>
        <span v-if="node.route_path" class="text-xs text-gray-500 truncate">
          {{ node.route_path }}
        </span>
        <span
          v-if="node.permission"
          class="text-[10px] bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded font-mono"
        >{{ node.permission }}</span>
      </span>
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
          :disabled="index === 0"
          title="Naik"
          @click="emit('move', node, 'up', siblings)"
        >
          <ChevronUpIcon class="w-4 h-4" />
        </button>
        <button
          type="button"
          class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
          :disabled="index === siblings.length - 1"
          title="Turun"
          @click="emit('move', node, 'down', siblings)"
        >
          <ChevronDownIcon class="w-4 h-4" />
        </button>
        <button
          v-if="node.type === 'SECTION' || node.type === 'PARENT'"
          type="button"
          class="p-1 text-gray-400 hover:text-primary-700"
          title="Tambah anak"
          @click="emit('add-child', node)"
        >
          <PlusIcon class="w-4 h-4" />
        </button>
        <button
          type="button"
          class="p-1 text-gray-400 hover:text-amber-700"
          :title="Number(node.is_active) === 0 ? 'Aktifkan' : 'Non-aktifkan'"
          @click="emit('toggle', node)"
        >
          <EyeSlashIcon v-if="Number(node.is_active) === 0" class="w-4 h-4" />
          <EyeIcon v-else class="w-4 h-4" />
        </button>
        <button
          type="button"
          class="p-1 text-gray-400 hover:text-blue-700"
          title="Edit"
          @click="emit('edit', node)"
        >
          <PencilSquareIcon class="w-4 h-4" />
        </button>
        <button
          type="button"
          class="p-1 text-gray-400 hover:text-red-700"
          title="Hapus"
          @click="emit('remove', node)"
        >
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>
    </div>
    <ul v-if="node.children && node.children.length" class="border-t border-gray-50">
      <MenuRow
        v-for="(child, i) in node.children"
        :key="child.id"
        :node="child"
        :siblings="node.children"
        :index="i"
        :depth="depth + 1"
        @edit="(x) => emit('edit', x)"
        @remove="(x) => emit('remove', x)"
        @toggle="(x) => emit('toggle', x)"
        @add-child="(x) => emit('add-child', x)"
        @move="(x, dir, sib) => emit('move', x, dir, sib)"
      />
    </ul>
  </li>
</template>