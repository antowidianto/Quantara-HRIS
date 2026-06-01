/**
 * Icon mapping untuk menu sidebar dinamis.
 *
 * Backend menyimpan kolom `icon` sebagai nama komponen Heroicons (mis.
 * "UsersIcon"). File ini menyediakan whitelist whitelist nama → komponen,
 * supaya:
 *
 *   1. Bundle hanya include ikon yang benar-benar dipakai (tree-shaking
 *      Heroicons jalan).
 *   2. Admin tidak bisa inject sembarang import — hanya nama yang
 *      terdaftar di sini yang valid.
 *   3. Nama yang tidak dikenal fallback ke ikon default (Squares2X2Icon)
 *      sehingga UI tidak crash kalau admin salah ketik.
 *
 * Kalau perlu ikon baru, tambahkan import + mapping di sini lalu admin
 * boleh pakai nama tersebut di kolom Icon.
 */
import {
  HomeIcon,
  HomeModernIcon,
  UsersIcon,
  UserGroupIcon,
  UserMinusIcon,
  UserCircleIcon,
  ClockIcon,
  CalendarDaysIcon,
  BanknotesIcon,
  CurrencyDollarIcon,
  Cog6ToothIcon,
  BellIcon,
  ArrowLeftStartOnRectangleIcon,
  ChartBarIcon,
  BuildingOffice2Icon,
  ShieldCheckIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  Bars3Icon,
  XMarkIcon,
  ClipboardDocumentListIcon,
  SunIcon,
  MoonIcon,
  ComputerDesktopIcon,
  LanguageIcon,
  CheckIcon,
  ArrowsRightLeftIcon,
  ExclamationTriangleIcon,
  ChatBubbleLeftRightIcon,
  ChatBubbleLeftEllipsisIcon,
  DocumentChartBarIcon,
  NoSymbolIcon,
  GiftIcon,
  FunnelIcon,
  CalculatorIcon,
  ShareIcon,
  AcademicCapIcon,
  KeyIcon,
  SparklesIcon,
  HeartIcon,
  PresentationChartLineIcon,
  ScaleIcon,
  TrophyIcon,
  Squares2X2Icon,
} from '@heroicons/vue/24/outline'

const map = {
  HomeIcon,
  HomeModernIcon,
  UsersIcon,
  UserGroupIcon,
  UserMinusIcon,
  UserCircleIcon,
  ClockIcon,
  CalendarDaysIcon,
  BanknotesIcon,
  CurrencyDollarIcon,
  Cog6ToothIcon,
  BellIcon,
  ArrowLeftStartOnRectangleIcon,
  ChartBarIcon,
  BuildingOffice2Icon,
  ShieldCheckIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  Bars3Icon,
  XMarkIcon,
  ClipboardDocumentListIcon,
  SunIcon,
  MoonIcon,
  ComputerDesktopIcon,
  LanguageIcon,
  CheckIcon,
  ArrowsRightLeftIcon,
  ExclamationTriangleIcon,
  ChatBubbleLeftRightIcon,
  ChatBubbleLeftEllipsisIcon,
  DocumentChartBarIcon,
  NoSymbolIcon,
  GiftIcon,
  FunnelIcon,
  CalculatorIcon,
  ShareIcon,
  AcademicCapIcon,
  KeyIcon,
  SparklesIcon,
  HeartIcon,
  PresentationChartLineIcon,
  ScaleIcon,
  TrophyIcon,
}

export const DEFAULT_ICON = Squares2X2Icon

/**
 * Resolve nama → komponen icon. Aman dipanggil dengan string null/undefined.
 */
export function resolveIcon(name) {
  if (! name) return DEFAULT_ICON
  return map[name] || DEFAULT_ICON
}

/**
 * Daftar nama icon yang tersedia (untuk admin dropdown saat edit menu).
 */
export function availableIconNames() {
  return Object.keys(map).sort()
}

export default map