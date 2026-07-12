<script setup>
/**
 * SidebarMenu — render satu node menu (SECTION / PARENT / ITEM) recursive.
 *
 * Backend mengirim tree dengan tiga `type`:
 *   - SECTION : header grup (uppercase tracking).
 *   - PARENT  : tombol toggle dengan chevron + indented children.
 *   - ITEM    : RouterLink biasa.
 *
 * Komponen ini self-import (recursive) untuk mendukung kedalaman > 2 level.
 * Auto-expand parent kalau salah satu descendant ITEM-nya match path
 * aktif sekarang — supaya user yang masuk via deep-link langsung lihat
 * menu di mana dia berada.
 *
 * Persistensi state expand:
 *   localStorage `hris_menu_expanded` = JSON array of menu codes.
 *   Refresh halaman → parent yang sebelumnya open tetap open.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { resolveIcon } from './iconMap'
import { useI18n } from '@/locales'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  node: { type: Object, required: true },
  /** Kedalaman dari top-level (untuk indentasi). 0 = section. */
  depth: { type: Number, default: 0 },
})

const route = useRoute()
const auth = useAuthStore()
const { t, locale } = useI18n()

// ─── Persistent expand state (shared antar instance) ──────────────
const EXPAND_EVENT = 'hris-menu-expand-change'
const storageKey = computed(() => {
  const userId = auth.user?.id || 'guest'
  const companyId = auth.activeCompanyId || auth.company?.id || 'global'
  return `hris_menu_expanded:${userId}:${companyId}`
})

function readExpanded() {
  try {
    const raw = localStorage.getItem(storageKey.value)
    if (! raw) return {}
    const value = JSON.parse(raw)
    return value && typeof value === 'object' && ! Array.isArray(value) ? value : {}
  } catch {
    return {}
  }
}
function writeExpanded(value) {
  try {
    localStorage.setItem(storageKey.value, JSON.stringify(value))
  } catch {}
}

const expanded = ref(readExpanded())

function applyExpandChange(event) {
  const detail = event.detail || {}
  if (detail.storageKey !== storageKey.value) return
  expanded.value = { ...detail.expanded }
}

function setLevelOpen(code) {
  const level = String(props.depth)
  const next = { ...expanded.value }
  if (code) next[level] = code
  else delete next[level]
  expanded.value = next
  writeExpanded(next)
  window.dispatchEvent(new CustomEvent(EXPAND_EVENT, {
    detail: { storageKey: storageKey.value, expanded: next },
  }))
}

onMounted(() => {
  window.addEventListener(EXPAND_EVENT, applyExpandChange)
  expanded.value = readExpanded()
})
onUnmounted(() => window.removeEventListener(EXPAND_EVENT, applyExpandChange))
watch(storageKey, () => { expanded.value = readExpanded() })

// Resolusi label: pakai translation_key kalau ada; fallback ke `label`.
const label = computed(() => {
  void locale.value // touch untuk reactive
  if (props.node.translation_key) {
    const tx = t(props.node.translation_key)
    // Kalau t() tidak ketemu, dia kembalikan key apa adanya — pakai
    // label hardcoded supaya tidak nampilin "nav.items.foo".
    if (tx && tx !== props.node.translation_key) return tx
  }
  return props.node.label
})

const iconCmp = computed(() => resolveIcon(props.node.icon))

// Cek apakah path aktif match dengan ITEM ini.
function pathMatches(node) {
  if (! node?.route_path) return false
  const target = node.route_path.split('?')[0]
  return route.path === target || route.path.startsWith(target + '/')
}

// Cek apakah salah satu descendant ITEM match → parent harus auto-expand.
function hasActiveDescendant(node) {
  if (! node?.children?.length) return false
  for (const c of node.children) {
    if (c.type === 'ITEM' && pathMatches(c)) return true
    if (hasActiveDescendant(c)) return true
  }
  return false
}

const isOpen = computed(() => {
  if (props.node.type !== 'PARENT') return true
  return expanded.value[String(props.depth)] === props.node.code
})

// Auto-expand: kalau navigasi masuk ke salah satu descendant (mis. deep-link),
// tambahkan parent ke set expanded. Sifatnya additive — hanya menambah, tidak
// pernah memaksa tetap terbuka — sehingga user tetap bisa menutup dropdown
// secara manual lewat toggle() tanpa langsung "terbuka lagi".
watch(
  () => route.path,
  () => {
    if (props.node.type !== 'PARENT') return
    if (hasActiveDescendant(props.node) && ! isOpen.value) {
      setLevelOpen(props.node.code)
    }
  },
  { immediate: true },
)

function toggle() {
  if (props.node.type !== 'PARENT') return
  const code = props.node.code
  setLevelOpen(isOpen.value ? null : code)
}

// Active state untuk ITEM
const isActive = computed(() => pathMatches(props.node))
</script>

<template>
  <!-- ─── SECTION header ─────────────────────────────── -->
  <div v-if="node.type === 'SECTION'" class="mb-5">
    <h3 class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-[0.18em]">
      {{ label }}
    </h3>
    <ul class="space-y-0.5">
      <li v-for="child in node.children" :key="child.id || child.code">
        <SidebarMenu :node="child" :depth="depth + 1" />
      </li>
    </ul>
  </div>

  <!-- ─── PARENT (dropdown) ──────────────────────────── -->
  <template v-else-if="node.type === 'PARENT'">
    <button
      type="button"
      class="parent-toggle group relative flex items-center w-full gap-3 pl-3 pr-2 py-2 text-sm rounded-md text-slate-300 hover:bg-slate-800/70 hover:text-white transition-colors"
      :class="{ 'is-active': hasActiveDescendant(node) }"
      :aria-expanded="isOpen"
      @click="toggle"
    >
      <component :is="iconCmp"
        class="w-5 h-5 flex-shrink-0 text-slate-400 group-hover:text-slate-200" />
      <span class="flex-1 text-left truncate">{{ label }}</span>
      <span
        class="text-slate-500 transition-transform"
        :class="isOpen ? 'rotate-180' : ''"
      >
        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fill-rule="evenodd"
            d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
            clip-rule="evenodd" />
        </svg>
      </span>
    </button>
    <transition
      enter-active-class="transition-all duration-150 ease-out"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <ul v-show="isOpen" class="mt-0.5 ml-3 pl-3 border-l border-slate-800/80 space-y-0.5">
        <li v-for="child in node.children" :key="child.id || child.code">
          <SidebarMenu :node="child" :depth="depth + 1" />
        </li>
      </ul>
    </transition>
  </template>

  <!-- ─── ITEM (link biasa) ──────────────────────────── -->
  <RouterLink
    v-else
    :to="node.route_path || '#'"
    class="nav-item group relative flex items-center gap-3 pl-3 pr-3 py-2 text-sm rounded-md text-slate-300 hover:bg-slate-800/70 hover:text-white transition-colors"
    :class="{ 'is-active': isActive }"
  >
    <span class="active-indicator absolute left-0 top-1.5 bottom-1.5 w-0.5 rounded-r bg-primary-400 opacity-0 transition-opacity"></span>
    <component :is="iconCmp"
      class="w-5 h-5 flex-shrink-0 text-slate-400 group-hover:text-slate-200" />
    <span class="truncate">{{ label }}</span>
  </RouterLink>
</template>

<style scoped>
/*
 * Active state senada dengan AppLayout original — slate-800 + accent kiri,
 * supaya transisi dari menu lama → menu baru tidak mengubah visual user.
 */
.nav-item.is-active {
  background-color: rgba(30, 41, 59, 0.85);
  color: #fff;
  font-weight: 500;
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.08);
}
.nav-item.is-active .active-indicator {
  opacity: 1;
}
.nav-item.is-active :is(svg) {
  color: #93c5fd;
}

/*
 * Parent toggle yang descendant-nya aktif: tetap kalem (tidak full
 * highlight) supaya user tahu "menu di dalam sini sedang aktif" tanpa
 * menyaingi visual ITEM aktif.
 */
.parent-toggle.is-active {
  color: #fff;
}
.parent-toggle.is-active :is(svg) {
  color: #cbd5e1;
}
</style>
