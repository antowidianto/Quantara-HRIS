import { describe, it, expect, beforeEach } from 'vitest'
import {
  confirm,
  alertDialog,
  promptDialog,
  closeConfirm,
  confirmState,
} from '../useConfirm'

/**
 * useConfirm — Sprint E / E3.
 *
 * Composable global untuk dialog konfirmasi/alert/prompt. Diuji dengan
 * memanggil API publik secara langsung dan mengecek `confirmState` plus
 * resolusi promise.
 *
 * Reset state sebelum tiap test supaya isolasi antar tes — state global
 * (resolver, defaultState) bisa bocor antar tes kalau tidak di-reset.
 */
describe('useConfirm', () => {
  beforeEach(() => {
    // Tutup tanpa resolusi di akhir untuk pastikan resolver bersih
    if (confirmState.value.open) {
      closeConfirm(false)
    }
  })

  describe('confirm()', () => {
    it('opens dialog with title + message and resolves true on confirm', async () => {
      const promise = confirm({
        title: 'Hapus karyawan?',
        message: 'Tindakan tidak bisa dibatalkan',
      })

      expect(confirmState.value.open).toBe(true)
      expect(confirmState.value.title).toBe('Hapus karyawan?')
      expect(confirmState.value.message).toBe('Tindakan tidak bisa dibatalkan')
      expect(confirmState.value.alertOnly).toBe(false)

      closeConfirm(true)
      await expect(promise).resolves.toBe(true)
      expect(confirmState.value.open).toBe(false)
    })

    it('resolves false when user cancels', async () => {
      const promise = confirm({ title: 'Lanjut?' })
      closeConfirm(false)
      await expect(promise).resolves.toBe(false)
    })

    it('uses default values when options omitted', async () => {
      const promise = confirm()
      expect(confirmState.value.title).toBe('Konfirmasi')
      expect(confirmState.value.okText).toBe('OK')
      expect(confirmState.value.cancelText).toBe('Batal')
      expect(confirmState.value.variant).toBe('primary')
      closeConfirm(false)
      await promise
    })

    it('passes through danger variant for destructive actions', async () => {
      const promise = confirm({ variant: 'danger', okText: 'Hapus' })
      expect(confirmState.value.variant).toBe('danger')
      expect(confirmState.value.okText).toBe('Hapus')
      closeConfirm(false)
      await promise
    })

    it('replaces previous open dialog and resolves first with false', async () => {
      const first = confirm({ title: 'First' })
      // Dialog kedua harus mengganti yang pertama
      const second = confirm({ title: 'Second' })
      expect(confirmState.value.title).toBe('Second')

      // First promise harus resolve false (di-replace)
      await expect(first).resolves.toBe(false)

      closeConfirm(true)
      await expect(second).resolves.toBe(true)
    })
  })

  describe('alertDialog()', () => {
    it('opens with alertOnly=true and empty cancelText', async () => {
      const promise = alertDialog({
        title: 'Berhasil',
        message: 'Slip terkirim',
      })
      expect(confirmState.value.alertOnly).toBe(true)
      expect(confirmState.value.cancelText).toBe('')
      expect(confirmState.value.okText).toBe('OK')

      closeConfirm(true)
      await expect(promise).resolves.toBe(true)
    })

    it('coerces close result to boolean', async () => {
      const promise = alertDialog({ title: 'Info' })
      // Pass non-boolean truthy value — should still resolve true
      closeConfirm('yes')
      await expect(promise).resolves.toBe(true)
    })
  })

  describe('promptDialog()', () => {
    it('returns input string when user confirms', async () => {
      const promise = promptDialog({
        title: 'Alasan',
        defaultValue: 'cuti tahunan',
      })
      expect(confirmState.value.needsInput).toBe(true)
      expect(confirmState.value.inputValue).toBe('cuti tahunan')

      // Simulasi user mengubah input lalu klik OK
      confirmState.value.inputValue = 'sakit'
      closeConfirm(true)
      await expect(promise).resolves.toBe('sakit')
    })

    it('returns null when user cancels prompt', async () => {
      const promise = promptDialog({ title: 'Catatan' })
      closeConfirm(false)
      await expect(promise).resolves.toBeNull()
    })

    it('uses empty string when defaultValue not provided', async () => {
      const promise = promptDialog({ title: 'Komentar' })
      expect(confirmState.value.inputValue).toBe('')
      closeConfirm(false)
      await promise
    })
  })

  describe('closeConfirm()', () => {
    it('resets state.open to false', async () => {
      const promise = confirm({ title: 'Test' })
      expect(confirmState.value.open).toBe(true)
      closeConfirm(false)
      expect(confirmState.value.open).toBe(false)
      await promise
    })

    it('is no-op when no dialog is pending', () => {
      // Should not throw
      closeConfirm(true)
      expect(confirmState.value.open).toBe(false)
    })
  })
})