export default {
  data_quality: {
    nav_label: 'Kualitas Data Karyawan',
    title: 'Kualitas Data Karyawan',
    subtitle: 'Validasi per tenant untuk identitas, kesiapan payroll, pemetaan organisasi, dan data kedaluwarsa.',
    refresh: 'Muat Ulang', loading: 'Memeriksa data karyawan...', load_failed: 'Gagal memuat hasil kualitas data.',
    active_employees: 'Karyawan aktif', total_issues: 'Record bermasalah', generated_at: 'Dibuat',
    no_issues: 'Tidak ada masalah pada kategori ini.', samples_note: 'Menampilkan sampel aman tanpa nilai identitas, pajak, atau nomor rekening.',
    employee_number: 'NIP', employee_name: 'Karyawan', findings: 'Temuan', details: 'Detail',
    categories: {
      identity_tax: 'NIK & NPWP', bank_account: 'Rekening Bank', bpjs: 'Registrasi BPJS',
      expired_records: 'Data Kedaluwarsa', organization_completeness: 'Kelengkapan Organisasi',
      minimum_wage: 'Upah Minimum', orphaned_references: 'Referensi Yatim',
    },
    codes: {
      NIK_MISSING: 'NIK belum diisi', NIK_INVALID: 'Format NIK tidak valid', NPWP_MISSING: 'NPWP belum diisi', NPWP_INVALID: 'Format NPWP tidak valid',
      BANK_ACCOUNT_INCOMPLETE: 'Informasi rekening bank belum lengkap', BANK_ACCOUNT_DUPLICATE: 'Rekening bank dipakai lebih dari satu karyawan',
      BPJS_HEALTH_MISSING: 'Nomor BPJS Kesehatan belum diisi', BPJS_HEALTH_INVALID: 'Nomor BPJS Kesehatan tidak valid',
      BPJS_EMPLOYMENT_MISSING: 'Nomor BPJS Ketenagakerjaan belum diisi', BPJS_EMPLOYMENT_INVALID: 'Nomor BPJS Ketenagakerjaan tidak valid',
      CONTRACT_EXPIRED: 'Kontrak karyawan sudah berakhir', DOCUMENT_EXPIRED: 'Dokumen karyawan sudah kedaluwarsa', CERTIFICATION_EXPIRED: 'Sertifikasi sudah kedaluwarsa',
      MANAGER_MISSING: 'Atasan belum ditentukan', POSITION_MISSING: 'Posisi belum ditentukan', COST_CENTER_MISSING: 'Cost center belum ditentukan', WORK_LOCATION_MISSING: 'Lokasi kerja belum ditentukan',
      SALARY_BELOW_MINIMUM: 'Gaji pokok di bawah upah minimum yang berlaku', USER_ORPHANED: 'Referensi user tidak ada dalam tenant ini',
      MANAGER_ORPHANED: 'Referensi atasan tidak ada dalam tenant ini', BRANCH_ORPHANED: 'Referensi cabang tidak ada dalam tenant ini',
      DEPARTMENT_ORPHANED: 'Referensi departemen tidak ada dalam tenant ini', POSITION_ORPHANED: 'Referensi posisi tidak ada dalam tenant ini',
      COST_CENTER_ORPHANED: 'Referensi cost center tidak ada dalam tenant ini', WORK_LOCATION_ORPHANED: 'Referensi lokasi kerja tidak ada dalam tenant ini',
    },
  },
}
