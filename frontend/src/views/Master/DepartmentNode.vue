<script setup>
/*
 * DepartmentNode — kartu department di bagan.
 *
 * Disederhanakan: kartu cuma menampilkan info kunci (nama, manager,
 * jumlah karyawan, jumlah MPP aktif). Aksi utama satu tombol besar
 * "Lihat & Atur" yang membuka modal detail. Aksi lain (edit, hapus,
 * tambah sub-dept) disembunyikan di menu tiga-titik supaya kartu
 * tidak ramai. Drag-and-drop tetap bekerja untuk power user.
 */
import { ref } from 'vue'
import {
  BuildingOffice2Icon, UserGroupIcon, UserCircleIcon,
  ClipboardDocumentListIcon, EllipsisVerticalIcon,
  PencilIcon, TrashIcon, PlusIcon, ChevronDownIcon, ChevronUpIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  node:        { type: Object, required: true },
  level:       { type: Number, default: 0 },
  collapsedIds:{ type: Set,    required: true },
  dragOverId:  { type: Number, default: null },
})

const emit = defineEmits([
  'toggle', 'drop-on', 'drag-over', 'drag-leave', 'drag-start',
  'edit', 'delete', 'add-child', 'view-detail',
])

const menuOpen = ref(false)
function toggleMenu(e) { e.stopPropagation(); menuOpen.value = ! menuOpen.value }
function pick(action) {
  menuOpen.value = false
  if (action === 'edit')      emit('edit', props.node)
  if (action === 'delete')    emit('delete', props.node)
  if (action === 'add-child') emit('add-child', props.node.id)
}

const isCollapsed = () => props.collapsedIds.has(props.node.id)
const hasChildren = () => (props.node.children?.length ?? 0) > 0
</script>

<template>
  <div class="org-node flex flex-col items-center">
    <!-- Kartu department: minimalis. Klik = buka detail. -->
    <div
      class="org-card group relative rounded-xl border bg-white shadow-sm transition-all overflow-visible"
      :class="dragOverId === node.id
        ? 'border-primary-500 ring-2 ring-primary-200 shadow-lg'
        : 'border-slate-200 hover:border-primary-400 hover:shadow-md'"
      draggable="true"
      @dragstart.stop="emit('drag-start', $event, 'department', node.id)"
      @dragover.stop="emit('drag-over', $event, node.id)"
      @dragleave.stop="emit('drag-leave')"
      @drop.stop="emit('drop-on', $event, node.id)"
      @dblclick.stop="emit('view-detail', node)"
    >
      <!-- Header -->
      <div class="flex items-start gap-2 px-3 pt-3">
        <div class="w-9 h-9 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center flex-shrink-0">
          <BuildingOffice2Icon class="w-5 h-5" />
        </div>
        <div class="flex-1 min-w-0">
          <h3 class="font-semibold text-sm text-slate-900 truncate leading-tight">{{ node.name }}</h3>
          <div class="text-[11px] text-slate-500 flex items-center gap-1 mt-0.5">
            <UserCircleIcon class="w-3.5 h-3.5 flex-shrink-0" />
            <span v-if="node.manager_name" class="truncate">{{ node.manager_name }}</span>
            <span v-else class="italic text-slate-400">Belum ada manager</span>
          </div>
        </div>
        <!-- Menu 3 titik untuk aksi sekunder -->
        <button
          class="p-1 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100 opacity-0 group-hover:opacity-100"
          @click.stop="toggleMenu($event)"
          title="Aksi lain"
        >
          <EllipsisVerticalIcon class="w-4 h-4" />
        </button>
        <!-- Dropdown menu -->
        <div v-if="menuOpen"
          class="absolute right-2 top-10 z-20 w-44 bg-white rounded-md border shadow-lg py-1 text-sm"
          @click.stop>
          <button class="w-full text-left px-3 py-1.5 hover:bg-slate-50 flex items-center gap-2"
            @click="pick('edit')">
            <PencilIcon class="w-4 h-4 text-slate-500" /> Edit nama / manager
          </button>
          <button class="w-full text-left px-3 py-1.5 hover:bg-slate-50 flex items-center gap-2"
            @click="pick('add-child')">
            <PlusIcon class="w-4 h-4 text-emerald-600" /> Tambah sub-department
          </button>
          <button class="w-full text-left px-3 py-1.5 hover:bg-red-50 text-red-600 flex items-center gap-2"
            @click="pick('delete')">
            <TrashIcon class="w-4 h-4" /> Hapus department
          </button>
        </div>
      </div>

      <!-- Stats inline -->
      <div class="flex items-center gap-3 px-3 mt-2 text-[11px] text-slate-600">
        <span class="flex items-center gap-1">
          <UserGroupIcon class="w-3.5 h-3.5 text-slate-400" />
          <span class="font-semibold text-slate-800">{{ node.employee_count || 0 }}</span> orang
        </span>
        <span v-if="(node.open_mpps || []).length"
          class="flex items-center gap-1 text-amber-700">
          <ClipboardDocumentListIcon class="w-3.5 h-3.5" />
          <span class="font-semibold">{{ node.open_mpps.length }}</span> lowongan
        </span>
      </div>

      <!-- Tombol aksi utama -->
      <div class="px-3 pb-3 pt-2 flex items-center gap-2">
        <button
          class="flex-1 px-3 py-1.5 rounded-md bg-primary-50 text-primary-700 text-xs font-medium border border-primary-200 hover:bg-primary-100 transition-colors"
          @click.stop="emit('view-detail', node)"
        >
          Lihat & Atur Karyawan
        </button>
        <button v-if="hasChildren()"
          class="px-2 py-1.5 rounded-md border border-slate-200 hover:bg-slate-50 text-slate-500"
          @click.stop="emit('toggle', node.id)"
          :title="isCollapsed() ? 'Buka anak' : 'Tutup anak'">
          <ChevronDownIcon v-if="isCollapsed()" class="w-3.5 h-3.5" />
          <ChevronUpIcon v-else class="w-3.5 h-3.5" />
        </button>
      </div>

      <!-- Sub-dept count badge (kecil di bawah kartu) -->
      <div v-if="hasChildren()" class="absolute -bottom-2 left-1/2 -translate-x-1/2 bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 rounded-full border">
        {{ (node.children || []).length }} sub-dept
      </div>
    </div>

    <!-- Connector + children -->
    <template v-if="! isCollapsed() && hasChildren()">
      <div class="w-px h-7 bg-slate-300"></div>
      <div class="org-children relative">
        <div v-for="c in (node.children || [])" :key="c.id" class="org-child flex flex-col items-center">
          <div class="w-px h-7 bg-slate-300"></div>
          <DepartmentNode
            :node="c"
            :level="level + 1"
            :collapsed-ids="collapsedIds"
            :drag-over-id="dragOverId"
            @toggle="(id) => emit('toggle', id)"
            @drop-on="(e, id) => emit('drop-on', e, id)"
            @drag-over="(e, id) => emit('drag-over', e, id)"
            @drag-leave="() => emit('drag-leave')"
            @drag-start="(e, t, id) => emit('drag-start', e, t, id)"
            @edit="(d) => emit('edit', d)"
            @delete="(d) => emit('delete', d)"
            @add-child="(id) => emit('add-child', id)"
            @view-detail="(d) => emit('view-detail', d)"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.org-node {
  min-width: 0;
}

.org-card {
  width: min(15rem, calc(100vw - 3rem));
  flex-shrink: 0;
}

.org-children {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: center;
  gap: 1.75rem 1.25rem;
  max-width: min(100%, 72rem);
}

.org-child {
  flex: 0 1 15rem;
  min-width: min(15rem, calc(100vw - 3rem));
  position: relative;
}

.org-child::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  width: min(8rem, 45%);
  height: 1px;
  transform: translateX(-50%);
  background: rgb(203 213 225);
}

[draggable="true"]:active { cursor: grabbing; }
</style>
