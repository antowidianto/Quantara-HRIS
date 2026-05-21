import { ref } from 'vue'

/**
 * useConfirm — pengganti window.confirm() dan window.alert() dengan modal
 * konsisten yang dirender lewat <ConfirmDialog />.
 *
 * Usage:
 *   const ok = await confirm({
 *     title: 'Hapus karyawan?',
 *     message: 'Tindakan ini tidak dapat dibatalkan.',
 *     okText: 'Hapus',
 *     variant: 'danger',
 *   })
 *   if (! ok) return
 *
 *   await alertDialog({ title: 'Berhasil', message: 'Slip gaji terkirim' })
 *
 * State direactif global; hanya satu modal aktif dalam satu waktu —
 * panggilan kedua otomatis "menggantikan" yang pertama (resolve dengan false).
 */

const defaultState = {
  open: false,
  title: 'Konfirmasi',
  message: '',
  okText: 'OK',
  cancelText: 'Batal',
  variant: 'primary',  // primary | danger | warning | success
  alertOnly: false,    // jika true, hanya tombol OK yang muncul
  needsInput: false,   // jika true, render input + return string
  inputLabel: '',
  inputPlaceholder: '',
  inputValue: '',
  inputType: 'text',   // 'text' | 'textarea'
  inputRequired: true,
}

export const confirmState = ref({ ...defaultState })

let resolver = null

export function confirm(options = {}) {
  if (resolver) resolver(false)
  confirmState.value = {
    ...defaultState,
    ...options,
    open: true,
    alertOnly: false,
  }
  return new Promise((resolve) => {
    resolver = resolve
  })
}

export function alertDialog(options = {}) {
  if (resolver) resolver(false)
  confirmState.value = {
    ...defaultState,
    okText: 'OK',
    cancelText: '',
    ...options,
    open: true,
    alertOnly: true,
  }
  return new Promise((resolve) => {
    resolver = resolve
  })
}

/**
 * Pengganti window.prompt(). Mengembalikan string saat OK, atau null saat
 * Batal. `inputRequired: true` membuat tombol OK disable saat input kosong.
 */
export function promptDialog(options = {}) {
  if (resolver) resolver(null)
  confirmState.value = {
    ...defaultState,
    title: 'Masukkan informasi',
    okText: 'Simpan',
    ...options,
    open: true,
    needsInput: true,
    inputValue: options.defaultValue ?? '',
  }
  return new Promise((resolve) => {
    resolver = resolve
  })
}

export function closeConfirm(result) {
  const wasInput = confirmState.value.needsInput
  if (resolver) {
    const r = resolver
    resolver = null
    if (wasInput) {
      r(result ? confirmState.value.inputValue : null)
    } else {
      r(!! result)
    }
  }
  confirmState.value = { ...defaultState, open: false }
}

export function useConfirm() {
  return { confirm, alertDialog, promptDialog }
}
