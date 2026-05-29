import { mmkv } from '@store/mmkv';

/**
 * i18n ringan tanpa library — Sprint M3 polish + M4 expansion.
 *
 * Untuk MVP, support 2 bahasa (id, en) dengan dictionary inline.
 * Saat label library jadi banyak, pindah ke `react-i18next` atau
 * `lingui`. Pola di sini sengaja simple supaya tidak nambah deps.
 *
 * Pemakaian:
 *   import { t } from '@/i18n';
 *   <Text>{t('attendance.clock_in')}</Text>
 *
 * Saat user ganti bahasa di Settings:
 *   setLocale('en');
 *
 * Pesan-pesan crucial di alert/error tetap pakai user-language karena
 * mostly hardcoded di screens. Pekerjaan ekstrak full string ke i18n
 * di-defer ke sprint khusus localization.
 *
 * M4: dictionary diperluas untuk MoreMenu, idle-lock, force-update,
 * dan modul Tier 3 (performance/training/tasks).
 */

export type Locale = 'id' | 'en';

const LOCALE_KEY = 'i18n.locale';

const dict: Record<Locale, Record<string, string>> = {
  id: {
    'common.cancel':       'Batal',
    'common.save':         'Simpan',
    'common.submit':       'Kirim',
    'common.loading':      'Memuat...',
    'common.error':        'Terjadi kesalahan',
    'common.retry':        'Coba lagi',
    'common.empty':        'Tidak ada data',
    'common.close':        'Tutup',
    'common.back':         'Kembali',

    'tab.attendance':      'Presensi',
    'tab.leave':           'Cuti',
    'tab.approvals':       'Inbox',
    'tab.payslip':         'Slip',
    'tab.more':            'Lainnya',

    'attendance.clock_in':  'Clock-in',
    'attendance.clock_out': 'Clock-out',
    'attendance.history':   'Riwayat 30 hari',

    'leave.balance':       'Saldo',
    'leave.history':       'Riwayat pengajuan',
    'leave.create':        'Ajukan cuti',

    'more.title':          'Lainnya',
    'more.subtitle':       'Modul tambahan & pengaturan akun.',
    'more.overtime':       'Lembur',
    'more.reimburse':      'Reimburse',
    'more.businesstrip':   'Business Trip',
    'more.helpdesk':       'Helpdesk',
    'more.notif':          'Notifikasi',
    'more.profile':        'Profil',
    'more.settings':       'Pengaturan',
    'more.performance':    'Performance',
    'more.training':       'Training',
    'more.tasks':          'Checklist saya',

    'idle.title':          'Sesi terkunci',
    'idle.body':           'Aplikasi tidak aktif beberapa saat. Verifikasi biometrik untuk lanjut.',
    'idle.unlock':         'Buka kunci',
    'idle.signout':        'Sign out',

    'force.title':         'Update aplikasi diperlukan',
    'force.body':          'Versi Anda sudah tidak didukung. Silakan update untuk lanjut.',
    'force.update':        'Update sekarang',

    'training.tab.catalog':   'Katalog',
    'training.tab.history':   'Riwayat saya',
    'training.register':      'Daftar',
    'training.cancel':        'Batal daftar',
    'training.empty.catalog': 'Belum ada training tersedia.',
    'training.empty.history': 'Anda belum pernah mendaftar training.',
    'training.certificates':  'Sertifikat saya',

    'performance.title':     'Performance',
    'performance.goals':     'Goals saya',
    'performance.reviews':   'Reviews',
    'performance.checkin':   'Check-in',
    'performance.acknowledge': 'Setuju & acknowledge',

    'tasks.title':           'Checklist saya',
    'tasks.tab.onboard':     'Onboarding',
    'tasks.tab.offboard':    'Offboarding',
    'tasks.complete':        'Tandai selesai',
    'tasks.todo':            'To-do',
    'tasks.done':            'Selesai',

    'settings.lang':         'Bahasa',
    'settings.idle_threshold': 'Auto-lock setelah (menit)',
  },
  en: {
    'common.cancel':       'Cancel',
    'common.save':         'Save',
    'common.submit':       'Submit',
    'common.loading':      'Loading...',
    'common.error':        'An error occurred',
    'common.retry':        'Retry',
    'common.empty':        'No data',
    'common.close':        'Close',
    'common.back':         'Back',

    'tab.attendance':      'Attendance',
    'tab.leave':           'Leave',
    'tab.approvals':       'Inbox',
    'tab.payslip':         'Payslip',
    'tab.more':            'More',

    'attendance.clock_in':  'Clock-in',
    'attendance.clock_out': 'Clock-out',
    'attendance.history':   'Last 30 days',

    'leave.balance':       'Balance',
    'leave.history':       'Submission history',
    'leave.create':        'Request leave',

    'more.title':          'More',
    'more.subtitle':       'Additional modules & account settings.',
    'more.overtime':       'Overtime',
    'more.reimburse':      'Reimbursement',
    'more.businesstrip':   'Business Trip',
    'more.helpdesk':       'Helpdesk',
    'more.notif':          'Notifications',
    'more.profile':        'Profile',
    'more.settings':       'Settings',
    'more.performance':    'Performance',
    'more.training':       'Training',
    'more.tasks':          'My checklist',

    'idle.title':          'Session locked',
    'idle.body':           'App was idle for a while. Authenticate to continue.',
    'idle.unlock':         'Unlock',
    'idle.signout':        'Sign out',

    'force.title':         'Update required',
    'force.body':           'Your app version is no longer supported. Please update to continue.',
    'force.update':        'Update now',

    'training.tab.catalog':   'Catalog',
    'training.tab.history':   'My history',
    'training.register':      'Register',
    'training.cancel':        'Cancel registration',
    'training.empty.catalog': 'No trainings available yet.',
    'training.empty.history': 'You have not registered for any training.',
    'training.certificates':  'My certificates',

    'performance.title':       'Performance',
    'performance.goals':       'My goals',
    'performance.reviews':     'Reviews',
    'performance.checkin':     'Check-in',
    'performance.acknowledge': 'Acknowledge',

    'tasks.title':           'My checklist',
    'tasks.tab.onboard':     'Onboarding',
    'tasks.tab.offboard':    'Offboarding',
    'tasks.complete':        'Mark complete',
    'tasks.todo':            'To-do',
    'tasks.done':            'Done',

    'settings.lang':         'Language',
    'settings.idle_threshold': 'Auto-lock after (minutes)',
  },
};

let activeLocale: Locale = (mmkv.getString(LOCALE_KEY) as Locale) || 'id';

export const t = (key: string): string => {
  return dict[activeLocale][key] ?? dict.id[key] ?? key;
};

export const getLocale = (): Locale => activeLocale;

export const setLocale = (loc: Locale): void => {
  activeLocale = loc;
  mmkv.set(LOCALE_KEY, loc);
};

export const availableLocales = (): Locale[] => ['id', 'en'];