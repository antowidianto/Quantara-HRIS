import { defineStore } from 'pinia'
import api from '@/api/client'

/**
 * Menu store — fetch & cache sidebar tree dari backend.
 *
 * Tree berasal dari endpoint GET /menus/me yang sudah:
 *   - difilter sesuai permission user,
 *   - di-prune SECTION/PARENT yang tidak ada anak ITEM-nya,
 *   - shaped jadi format minimal (id, type, code, label, translation_key,
 *     icon, route_path, children).
 *
 * Komponen sidebar (SidebarMenu.vue) tinggal render tree ini secara
 * recursive. Tidak ada lagi cek permission di frontend.
 *
 * Cara pakai:
 *   import { useMenuStore } from '@/stores/menu'
 *   const menu = useMenuStore()
 *   await menu.load()        // dipanggil di AppLayout.onMounted
 *   menu.tree                // array<MenuNode>
 *   menu.loading             // boolean
 *
 * Re-load otomatis saat:
 *   - Login (auth.fetchMe) selesai → call menu.load() di sana, atau
 *     panggil menu.refresh() manual setelah company switch.
 */
export const useMenuStore = defineStore('menu', {
  state: () => ({
    tree: [],
    loading: false,
    loaded: false,
    error: null,
  }),

  actions: {
    async load(force = false) {
      if (this.loading) return
      if (this.loaded && ! force) return
      this.loading = true
      this.error = null
      try {
        const res = await api.get('/menus/me')
        this.tree = Array.isArray(res.data?.data) ? res.data.data : []
        this.loaded = true
      } catch (e) {
        this.error = e?.response?.data?.message || e?.message || 'Gagal memuat menu'
        // Fallback minimum: menu dashboard saja supaya user tetap bisa
        // navigasi ke beranda dan logout. Better than empty sidebar.
        this.tree = [
          {
            id: 0,
            type: 'SECTION',
            code: 'home',
            label: 'Beranda',
            translation_key: 'nav.home',
            icon: null,
            route_path: null,
            children: [
              {
                id: 0,
                type: 'ITEM',
                code: 'dashboard',
                label: 'Dashboard',
                translation_key: 'nav.items.dashboard',
                icon: 'HomeIcon',
                route_path: '/dashboard',
                children: [],
              },
            ],
          },
        ]
      } finally {
        this.loading = false
      }
    },

    async refresh() {
      return this.load(true)
    },

    /**
     * Reset state — dipanggil saat logout supaya menu user lain tidak
     * bocor saat user lain login di tab yang sama.
     */
    reset() {
      this.tree = []
      this.loaded = false
      this.loading = false
      this.error = null
    },
  },
})