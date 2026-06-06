import api from './client'

export default {
  /**
   * Mendapatkan daftar pinjaman (kasbon).
   * @param {Object} params - Query params (page, per_page, status, employee_id)
   */
  list(params) {
    return api.get('/payroll/loans', { params })
  },

  /**
   * Mengambil detail pinjaman berdasarkan ID.
   */
  get(id) {
    return api.get(`/payroll/loans/${id}`)
  },

  /**
   * Mengajukan pinjaman (kasbon) baru.
   * @param {Object} data - Payload (principal, installments, reason)
   */
  apply(data) {
    return api.post('/payroll/loans', data)
  },

  /**
   * Menyetujui pinjaman.
   * @param {Number} id - Loan ID
   * @param {String} firstInstallmentDate - Tanggal cicilan pertama (YYYY-MM-DD)
   */
  approve(id, firstInstallmentDate) {
    return api.put(`/payroll/loans/${id}`, {
      action: 'approve',
      first_installment_date: firstInstallmentDate
    })
  },

  /**
   * Menolak pinjaman.
   * @param {Number} id - Loan ID
   */
  reject(id) {
    return api.put(`/payroll/loans/${id}`, { action: 'reject' })
  },

  /**
   * Membatalkan pinjaman (jika masih PENDING).
   * @param {Number} id - Loan ID
   */
  cancel(id) {
    return api.delete(`/payroll/loans/${id}`)
  },

  /**
   * Mendapatkan daftar cicilan dari pinjaman.
   * @param {Number} loanId - Loan ID
   */
  getInstallments(loanId) {
    return api.get(`/payroll/loans/${loanId}/installments`)
  }
}
