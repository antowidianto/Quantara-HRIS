<script setup>
/**
 * Recursive tree node untuk Cost Center hierarchy view.
 * Dipakai dari Master/CostCenters.vue (tab "Hirarki").
 *
 * Self-imports allowed: Vue SFC mendukung self-recursion via component name.
 */
import { computed } from 'vue'
import {
  ChevronRightIcon, ChevronDownIcon,
  PencilIcon, ChartBarIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from '@/locales'

const { t } = useI18n()

const props = defineProps({
  node:     { type: Object, required: true },
  expanded: { type: Object, required: true }, // { [id]: bool }
  depth:    { type: Number, default: 0 },
  canManage: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'edit', 'view-budget'])

const hasChildren = computed(() =>
  Array.isArray(props.node.children) && props.node.children.length > 0,
)
const isOpen = computed(() => !! props.expanded[props.node.id])
const indentStyle = computed(() => ({ paddingLeft: `${props.depth * 1.5 + 0.5}rem` }))
</script>

<template>
  <div>
    <div
      class="flex items-center gap-2 py-1.5 px-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800"
      :class="{ 'opacity-60': ! node.is_active }"
      :style="indentStyle"
    >
      <button class="text-gray-400 w-4" @click="hasChildren && emit('toggle', node.id)">
        <ChevronDownIcon v-if="hasChildren && isOpen" class="w-4 h-4" />
        <ChevronRightIcon v-else-if="hasChildren" class="w-4 h-4" />
      </button>

      <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700">
        {{ node.code }}
      </span>
      <span class="font-medium">{{ node.name }}</span>
      <span v-if="! node.is_active" class="text-xs text-gray-400">{{ t('cost_center_node.inactive') }}</span>

      <div class="ml-auto flex gap-2">
        <button
          class="text-gray-400 hover:text-primary-600"
          @click="emit('view-budget', node.id)"
        >
          <ChartBarIcon class="w-4 h-4" />
          <span class="sr-only">{{ t('cost_center_node.view_budget') }}</span>
        </button>
        <button
          v-if="canManage"
          class="text-gray-400 hover:text-primary-600"
          @click="emit('edit', node)"
        >
          <PencilIcon class="w-4 h-4" />
          <span class="sr-only">{{ t('cost_center_node.edit') }}</span>
        </button>
      </div>
    </div>

    <div v-if="hasChildren && isOpen">
      <CostCenterNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :expanded="expanded"
        :depth="depth + 1"
        :can-manage="canManage"
        @toggle="(id) => emit('toggle', id)"
        @edit="(n) => emit('edit', n)"
        @view-budget="(id) => emit('view-budget', id)"
      />
    </div>
  </div>
</template>
