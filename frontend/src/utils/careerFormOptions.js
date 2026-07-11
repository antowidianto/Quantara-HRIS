export const LOCATION_OPTIONS = [
  { province: 'DKI Jakarta', cities: [
    { city: 'Jakarta Pusat', postal_codes: ['10110', '10220', '10310'] },
    { city: 'Jakarta Selatan', postal_codes: ['12190', '12560', '12950'] },
    { city: 'Jakarta Barat', postal_codes: ['11470', '11530', '11730'] },
    { city: 'Jakarta Timur', postal_codes: ['13310', '13440', '13710'] },
    { city: 'Jakarta Utara', postal_codes: ['14240', '14350', '14440'] },
  ] },
  { province: 'Jawa Barat', cities: [
    { city: 'Bandung', postal_codes: ['40111', '40286', '40614'] },
    { city: 'Bekasi', postal_codes: ['17144', '17148', '17412'] },
    { city: 'Bogor', postal_codes: ['16128', '16143', '16710'] },
    { city: 'Depok', postal_codes: ['16424', '16431', '16514'] },
  ] },
  { province: 'Banten', cities: [
    { city: 'Tangerang', postal_codes: ['15111', '15117', '15143'] },
    { city: 'Tangerang Selatan', postal_codes: ['15310', '15412', '15418'] },
    { city: 'Serang', postal_codes: ['42111', '42117'] },
  ] },
  { province: 'Jawa Tengah', cities: [
    { city: 'Semarang', postal_codes: ['50132', '50241', '50275'] },
    { city: 'Surakarta', postal_codes: ['57131', '57139'] },
    { city: 'Magelang', postal_codes: ['56111', '56117'] },
  ] },
  { province: 'DI Yogyakarta', cities: [
    { city: 'Yogyakarta', postal_codes: ['55111', '55281', '55284'] },
    { city: 'Sleman', postal_codes: ['55281', '55581'] },
    { city: 'Bantul', postal_codes: ['55711', '55184'] },
  ] },
  { province: 'Jawa Timur', cities: [
    { city: 'Surabaya', postal_codes: ['60119', '60271', '60293'] },
    { city: 'Malang', postal_codes: ['65112', '65141', '65145'] },
    { city: 'Sidoarjo', postal_codes: ['61215', '61256'] },
  ] },
  { province: 'Bali', cities: [
    { city: 'Denpasar', postal_codes: ['80111', '80234', '80361'] },
    { city: 'Badung', postal_codes: ['80351', '80361'] },
  ] },
  { province: 'Sumatera Utara', cities: [
    { city: 'Medan', postal_codes: ['20111', '20152', '20222'] },
    { city: 'Deli Serdang', postal_codes: ['20511', '20362'] },
  ] },
  { province: 'Sulawesi Selatan', cities: [
    { city: 'Makassar', postal_codes: ['90111', '90231', '90245'] },
    { city: 'Gowa', postal_codes: ['92111', '92113'] },
  ] },
]

export const INSTITUTION_OPTIONS = [
  'SMA Negeri 1', 'SMK Negeri 1', 'Universitas Indonesia', 'Institut Teknologi Bandung',
  'Universitas Gadjah Mada', 'Institut Pertanian Bogor', 'Institut Teknologi Sepuluh Nopember',
  'Universitas Airlangga', 'Universitas Padjadjaran', 'Universitas Brawijaya',
  'Universitas Diponegoro', 'Universitas Sebelas Maret', 'Universitas Bina Nusantara',
  'Telkom University', 'Universitas Trisakti', 'Universitas Udayana', 'Universitas Hasanuddin',
]

export const provinceOptions = LOCATION_OPTIONS.map((item) => item.province)

export function cityOptionsFor(province) {
  return LOCATION_OPTIONS.find((item) => item.province === province)?.cities || []
}

export function postalOptionsFor(province, city) {
  return cityOptionsFor(province).find((item) => item.city === city)?.postal_codes || []
}

export function onlyDigits(value, max = 16) {
  return String(value || '').replace(/\D/g, '').slice(0, max)
}

export function toDisplayDate(value) {
  if (!value) return ''
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!m) return value
  return `${m[3]}/${m[2]}/${m[1]}`
}

export function toIsoDate(value) {
  const text = String(value || '').trim()
  if (!text) return null
  const iso = text.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (iso) return text
  const m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/)
  if (!m) return text
  const year = m[3].length === 2 ? `20${m[3]}` : m[3]
  return `${year}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`
}

export function splitList(value) {
  return String(value || '')
    .split(/\r?\n|,/)
    .map((item) => item.trim())
    .filter(Boolean)
}

export function joinList(items) {
  return (items || [])
    .map((item) => typeof item === 'string' ? item : Object.values(item).filter(Boolean).join(' - '))
    .map((item) => String(item || '').trim())
    .filter(Boolean)
    .join('\n')
}
