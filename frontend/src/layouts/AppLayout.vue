<script setup>
/**
 * AppLayout — wrapper utama dashboard (sidebar + topbar + main content).
 *
 * Sidebar di-render dari menu store yang tarik tree dari backend
 * (GET /menus/me, lihat migration #076 + MenuController). Sebelumnya
 * menu didefinisikan inline sebagai array `groups` di file ini, sekarang
 * sumbernya database supaya admin bisa atur urutan/parent/label tanpa
 * rebuild frontend. Komponen `SidebarMenu` me-render tiap node SECTION/
 * PARENT/ITEM secara recursive.
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useMenuStore } from '@/stores/menu'
import api from '@/api/client'
import {
  // Hanya ikon yang dipakai topbar/branding. Ikon sidebar resolved via
  // components/menu/iconMap.js dari kolom `icon` di tabel menus.
  BellIcon, ArrowLeftStartOnRectangleIcon,
  BuildingOffice2Icon, ShieldCheckIcon, ChevronDownIcon, Bars3Icon, XMarkIcon,
  UserCircleIcon,
  SunIcon, MoonIcon, ComputerDesktopIcon, LanguageIcon, CheckIcon,
} from '@heroicons/vue/24/outline'
import SidebarMenu from '@/components/menu/SidebarMenu.vue'
import { useDarkMode } from '@/composables/useDarkMode'
import { useI18n } from '@/locales'

// Sprint 5-6 item S — toggle dark mode di topbar.
const { mode: darkMode, toggle: toggleDarkMode } = useDarkMode()

// i18n — language switcher di topbar.
const { t, locale, setLocale, availableLocales } = useI18n()
const langs = availableLocales()

const auth = useAuthStore()
const menuStore = useMenuStore()
const router = useRouter()

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)
const notifMenuOpen = ref(false)
const langMenuOpen = ref(false)
const companyMenuOpen = ref(false)
const unreadCount = ref(0)
const notifications = ref([])

// Tree dari MenuStore — sudah ter-filter sesuai permission user di backend.
const menuTree = computed(() => menuStore.tree)
const menuLoading = computed(() => menuStore.loading)

// Cari profil company yg sedang aktif untuk ditampilkan di tombol dropdown.
// Dipakai super admin saja — user biasa tinggal lihat nama company-nya.
const activeCompany = computed(() => {
  if (! auth.activeCompanyId) return null
  return auth.availableCompanies.find(c => Number(c.id) === Number(auth.activeCompanyId)) || null
})

function pickCompany(id) {
  // Pilih perusahaan dari custom dropdown. Sama logic dgn select lama:
  // simpan ke store + reload supaya semua list di halaman aktif refetch
  // dengan header X-Company-Id baru.
  auth.setActiveCompany(id || null)
  companyMenuOpen.value = false
  if (typeof window !== 'undefined') window.location.reload()
}

async function loadNotifications() {
  try {
    const [count, list] = await Promise.all([
      api.get('/notifications/unread-count'),
      api.get('/notifications', { params: { per_page: 8 } }),
    ])
    unreadCount.value = count.data.data.count
    notifications.value = list.data.data
  } catch (_) {}
}

async function markAllRead() {
  await api.post('/notifications/read-all')
  loadNotifications()
}

async function logout() {
  await auth.logout()
  menuStore.reset()
  router.push('/login')
}

let pollTimer = null
onMounted(() => {
  // Fetch menu sekali saat layout mount. Kalau gagal, MenuStore set
  // fallback minimal supaya user setidaknya bisa logout & ke dashboard.
  menuStore.load()
  loadNotifications()
  pollTimer = setInterval(loadNotifications, 60000)
})
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })

// Re-fetch menu kalau user berubah (mis. login user lain di tab yang sama).
watch(() => auth.user?.id, (newId, oldId) => {
  if (newId && newId !== oldId) menuStore.refresh()
})
</script>

<template>
  <div class="min-h-screen flex bg-gray-50">
    <!-- Sidebar -->
    <aside :class="[
      'fixed inset-y-0 left-0 z-30 w-64 bg-gradient-to-b from-slate-900 via-slate-900 to-slate-800 border-r border-slate-800/60 shadow-xl transform transition-transform lg:translate-x-0 lg:static lg:inset-0',
      sidebarOpen ? 'translate-x-0' : '-translate-x-full'
    ]">
      <!-- Brand header — gelap senada dengan body sidebar untuk kesan korporat -->
      <div class="h-16 flex items-center justify-between px-4 border-b border-slate-800/70 bg-slate-900/80">
        <div class="flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center text-white font-bold shadow-md ring-1 ring-white/10">
            H
          </div>
          <div class="flex flex-col leading-tight">
            <span class="font-semibold text-white text-sm tracking-wide">HRIS</span>
            <span class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Workforce Suite</span>
          </div>
        </div>
        <button class="lg:hidden text-slate-400 hover:text-white" @click="sidebarOpen = false">
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>

      <nav class="sidebar-scroll px-3 py-4 overflow-y-auto h-[calc(100vh-4rem)]">
        <!-- Loading state — first paint sebelum /menus/me selesai. -->
        <div v-if="menuLoading && menuTree.length === 0" class="px-3 py-4 space-y-3">
          <div v-for="i in 6" :key="i" class="flex flex-col gap-1.5 animate-pulse">
            <div class="h-2.5 w-20 bg-slate-700/40 rounded"></div>
            <div class="h-7 bg-slate-800/50 rounded-md"></div>
            <div class="h-7 bg-slate-800/50 rounded-md"></div>
          </div>
        </div>

        <!-- Menu tree dari database. SidebarMenu render tiap node recursive. -->
        <template v-else>
          <SidebarMenu
            v-for="node in menuTree"
            :key="node.id || node.code"
            :node="node"
          />
        </template>
      </nav>
    </aside>

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-h-screen">
      <!-- Topbar -->
      <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-4 sticky top-0 z-20">
        <div class="flex items-center gap-3">
          <button class="lg:hidden text-gray-600" @click="sidebarOpen = true"><Bars3Icon class="w-6 h-6" /></button>

          <!-- Branding text untuk user non-super-admin -->
          <div v-if="! auth.isSuperAdmin" class="hidden md:flex items-center gap-2">
            <div class="w-8 h-8 rounded-md bg-primary-50 flex items-center justify-center text-primary-700">
              <BuildingOffice2Icon class="w-4 h-4" />
            </div>
            <div class="text-sm">
              <div class="font-medium text-gray-800 leading-tight">{{ auth.company?.name || 'HRIS' }}</div>
              <div v-if="auth.branch?.name" class="text-xs text-gray-500 leading-tight">{{ auth.branch.name }}</div>
            </div>
          </div>

          <!-- Company switcher khusus super admin — custom pretty dropdown -->
          <div v-else class="hidden md:block relative">
            <button
              @click="companyMenuOpen = !companyMenuOpen; userMenuOpen = false; notifMenuOpen = false; langMenuOpen = false"
              class="company-switch group inline-flex items-center gap-2 pl-2 pr-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 transition-colors min-w-[14rem]"
              :class="auth.needsCompanyContext
                ? 'border-amber-300 bg-amber-50 hover:bg-amber-50/80'
                : 'border-gray-200'"
              :title="t('nav.select_company')"
            >
              <span class="w-7 h-7 rounded-md flex items-center justify-center text-sm font-semibold flex-shrink-0"
                :class="activeCompany
                  ? 'bg-primary-100 text-primary-700'
                  : 'bg-amber-100 text-amber-700'">
                <span v-if="activeCompany">{{ (activeCompany.code || activeCompany.name)?.[0]?.toUpperCase() }}</span>
                <BuildingOffice2Icon v-else class="w-4 h-4" />
              </span>
              <span class="flex-1 text-left min-w-0">
                <span v-if="activeCompany" class="block text-xs text-gray-500 leading-tight">{{ t('nav.active_company') || 'Perusahaan aktif' }}</span>
                <span v-else class="block text-xs text-amber-700 leading-tight">{{ t('nav.select_company') }}</span>
                <span class="block text-sm font-medium truncate"
                  :class="activeCompany ? 'text-gray-800' : 'text-amber-900'">
                  {{ activeCompany?.name || '—' }}
                </span>
              </span>
              <ChevronDownIcon class="w-4 h-4 text-gray-400 group-hover:text-gray-600 flex-shrink-0 transition-transform"
                :class="companyMenuOpen ? 'rotate-180' : ''" />
            </button>

            <!-- Dropdown panel -->
            <div v-if="companyMenuOpen"
              class="absolute left-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-30">
              <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                  {{ t('nav.select_company') }}
                </div>
                <div class="text-[11px] text-gray-400 mt-0.5">
                  Data master, karyawan, dan transaksi akan mengikuti perusahaan terpilih.
                </div>
              </div>
              <div class="max-h-80 overflow-y-auto">
                <button
                  type="button"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 text-left"
                  :class="!auth.activeCompanyId ? 'bg-primary-50/40' : ''"
                  @click="pickCompany(null)"
                >
                  <span class="w-8 h-8 rounded-md flex items-center justify-center bg-gray-100 text-gray-500 flex-shrink-0">
                    <BuildingOffice2Icon class="w-4 h-4" />
                  </span>
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-800">Semua perusahaan</span>
                    <span class="block text-xs text-gray-500">Mode super admin global</span>
                  </span>
                  <CheckIcon v-if="!auth.activeCompanyId" class="w-4 h-4 text-primary-600 flex-shrink-0" />
                </button>
                <button
                  v-for="c in auth.availableCompanies"
                  :key="c.id"
                  type="button"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 text-left border-t border-gray-50"
                  :class="Number(auth.activeCompanyId) === Number(c.id) ? 'bg-primary-50/60' : ''"
                  @click="pickCompany(c.id)"
                >
                  <span class="w-8 h-8 rounded-md flex items-center justify-center font-semibold text-sm flex-shrink-0"
                    :class="Number(auth.activeCompanyId) === Number(c.id)
                      ? 'bg-primary-600 text-white'
                      : 'bg-primary-50 text-primary-700'">
                    {{ (c.code || c.name)?.[0]?.toUpperCase() }}
                  </span>
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-800 truncate">{{ c.name }}</span>
                    <span class="block text-xs text-gray-500 truncate">
                      <span v-if="c.code" class="font-mono">{{ c.code }}</span>
                      <span v-if="c.code && c.city"> • </span>
                      <span v-if="c.city">{{ c.city }}</span>
                    </span>
                  </span>
                  <CheckIcon v-if="Number(auth.activeCompanyId) === Number(c.id)"
                    class="w-4 h-4 text-primary-600 flex-shrink-0" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Notifications -->
          <div class="relative">
            <button @click="notifMenuOpen = !notifMenuOpen; userMenuOpen = false; langMenuOpen = false; companyMenuOpen = false"
              class="relative p-2 text-gray-600 hover:text-primary-600 rounded-md hover:bg-gray-100">
              <BellIcon class="w-6 h-6" />
              <span v-if="unreadCount > 0" class="absolute -top-0.5 -right-0.5 w-5 h-5 rounded-full bg-red-500 text-white text-xs flex items-center justify-center">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </button>
            <div v-if="notifMenuOpen" class="absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <span class="font-semibold text-sm">{{ t('nav.notifications') }}</span>
                <button class="text-xs text-primary-600 hover:underline" @click="markAllRead">{{ t('nav.mark_all_read') }}</button>
              </div>
              <div class="max-h-80 overflow-y-auto">
                <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">{{ t('nav.no_notifications') }}</div>
                <RouterLink v-for="n in notifications" :key="n.id" :to="n.link_url || '/notifications'"
                  class="block px-4 py-3 border-b border-gray-100 hover:bg-gray-50 last:border-0"
                  :class="{ 'bg-blue-50/40': ! n.is_read }">
                  <div class="text-sm font-medium text-gray-800">{{ n.title }}</div>
                  <div class="text-xs text-gray-500 line-clamp-2">{{ n.message }}</div>
                  <div class="text-xs text-gray-400 mt-1">{{ new Date(n.created_at).toLocaleString(locale === 'en' ? 'en-US' : 'id-ID') }}</div>
                </RouterLink>
              </div>
              <RouterLink to="/notifications" class="block px-4 py-2 text-center text-sm text-primary-600 hover:bg-gray-50">{{ t('nav.view_all') }}</RouterLink>
            </div>
          </div>

          <!-- Language switcher (i18n) -->
          <div class="relative">
            <button
              @click="langMenuOpen = !langMenuOpen; userMenuOpen = false; notifMenuOpen = false; companyMenuOpen = false"
              class="p-2 rounded-md hover:bg-gray-100 text-gray-600 inline-flex items-center gap-1"
              :title="t('language.label')"
              aria-label="Switch language"
            >
              <LanguageIcon class="w-5 h-5" />
              <span class="text-xs font-medium uppercase">{{ locale }}</span>
            </button>
            <div v-if="langMenuOpen"
              class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden z-30">
              <button v-for="l in langs" :key="l.code"
                class="w-full flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50"
                :class="locale === l.code ? 'bg-primary-50 text-primary-700 font-medium' : 'text-gray-700'"
                @click="setLocale(l.code); langMenuOpen = false">
                <span class="text-base">{{ l.flag }}</span>
                <span>{{ l.label }}</span>
                <span v-if="locale === l.code" class="ml-auto text-primary-600">✓</span>
              </button>
            </div>
          </div>

          <!-- Dark mode toggle (Sprint 5-6 item S) -->
          <button
            @click="toggleDarkMode"
            class="p-2 rounded-md hover:bg-gray-100 text-gray-600"
            :title="`${t('theme.title')}: ${darkMode === 'auto' ? t('theme.auto') : (darkMode === 'dark' ? t('theme.dark') : t('theme.light'))}. ${t('theme.click_to_change')}.`"
            aria-label="Toggle theme"
          >
            <SunIcon v-if="darkMode === 'light'" class="w-5 h-5" />
            <MoonIcon v-else-if="darkMode === 'dark'" class="w-5 h-5" />
            <ComputerDesktopIcon v-else class="w-5 h-5" />
          </button>

          <!-- User menu -->
          <div class="relative">
            <button @click="userMenuOpen = !userMenuOpen; notifMenuOpen = false; langMenuOpen = false; companyMenuOpen = false"
              class="flex items-center gap-2 p-1.5 rounded-md hover:bg-gray-100">
              <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 font-semibold overflow-hidden">
                <img v-if="auth.user?.avatar_url || auth.employee?.avatar_url"
                  :src="auth.employee?.avatar_url || auth.user?.avatar_url"
                  :alt="auth.user?.name"
                  class="w-full h-full object-cover"
                  @error="(ev) => { ev.target.style.display = 'none' }" />
                <span v-else>{{ auth.user?.name?.[0]?.toUpperCase() || 'U' }}</span>
              </div>
              <div class="hidden md:block text-left">
                <div class="text-sm font-medium text-gray-800">{{ auth.user?.name }}</div>
                <div class="text-xs text-gray-500">{{ auth.role?.name }}</div>
              </div>
              <ChevronDownIcon class="w-4 h-4 text-gray-500" />
            </button>
            <div v-if="userMenuOpen" class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <RouterLink to="/profile" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <UserCircleIcon class="w-5 h-5" /> {{ t('nav.profile') }}
              </RouterLink>
              <RouterLink to="/change-password" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <ShieldCheckIcon class="w-5 h-5" /> {{ t('nav.change_password') }}
              </RouterLink>
              <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                <ArrowLeftStartOnRectangleIcon class="w-5 h-5" /> {{ t('nav.logout') }}
              </button>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 p-6">
        <RouterView />
      </main>
    </div>

    <!-- Mobile overlay -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="sidebarOpen = false"></div>
  </div>
</template>

<style scoped>
/*
 * Sidebar scrollbar — slim, tone-on-tone dengan background slate-900,
 * fade-in saat hover supaya nav terasa lebih rapi.
 */
.sidebar-scroll {
  scrollbar-width: thin;
  scrollbar-color: rgba(148, 163, 184, 0.25) transparent;
  scrollbar-gutter: stable;
}
.sidebar-scroll:hover {
  scrollbar-color: rgba(148, 163, 184, 0.55) transparent;
}
.sidebar-scroll::-webkit-scrollbar {
  width: 6px;
}
.sidebar-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.sidebar-scroll::-webkit-scrollbar-thumb {
  background-color: rgba(148, 163, 184, 0.25);
  border-radius: 9999px;
  transition: background-color 200ms ease;
}
.sidebar-scroll:hover::-webkit-scrollbar-thumb {
  background-color: rgba(148, 163, 184, 0.55);
}
.sidebar-scroll::-webkit-scrollbar-thumb:hover {
  background-color: rgba(148, 163, 184, 0.75);
}
</style>