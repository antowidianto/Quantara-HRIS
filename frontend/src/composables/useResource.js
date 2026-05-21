import { ref, reactive } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'

export function useResource(endpoint) {
  const toast = useToast()
  const items = ref([])
  const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
  const loading = ref(false)
  const params = reactive({ page: 1, per_page: 20, search: '' })

  async function fetch(extra = {}) {
    loading.value = true
    try {
      const res = await api.get(endpoint, { params: { ...params, ...extra } })
      items.value = res.data.data || []
      meta.value = res.data.meta || meta.value
    } catch (e) {
      toast.error(e.response?.data?.message || 'Gagal memuat data')
    } finally {
      loading.value = false
    }
  }

  async function remove(id, opts = {}) {
    const ok = await confirmDialog({
      title: opts.title || 'Hapus item',
      message: opts.message || 'Yakin hapus item ini? Tindakan tidak dapat dibatalkan.',
      okText: opts.okText || 'Hapus',
      variant: 'danger',
    })
    if (! ok) return
    try {
      await api.delete(endpoint + '/' + id)
      toast.success('Berhasil dihapus')
      await fetch()
    } catch (e) {
      toast.error(e.response?.data?.message || 'Gagal menghapus')
    }
  }

  return { items, meta, params, loading, fetch, remove }
}

export function fmtCurrency(v) {
  if (v === null || v === undefined || v === '') return '-'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}

export function fmtDate(d) {
  if (! d) return '-'
  const dt = new Date(d.length <= 10 ? d + 'T00:00:00' : d)
  return dt.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function fmtDateTime(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}