/**
 * useAvatar — generate inisial + warna avatar deterministik dari nama.
 *
 * Pakai untuk fallback saat employee tidak punya foto profil. Pola seperti
 * Slack / Linear: dua huruf pertama dari nama, dengan background warna
 * yang stabil per orang (hash dari nama → palette index).
 *
 * Usage di template:
 *   <div :class="avatarClass(emp.full_name)" class="avatar">
 *     {{ avatarInitials(emp.full_name) }}
 *   </div>
 *
 * Sprint 5-6 item R.
 */

// 12 pasangan bg + text yang kontras WCAG AA (background gelap, text putih).
// Disorted by hue supaya distribusi hash terlihat random/colorful.
const PALETTE = [
  'bg-red-500    text-white',
  'bg-orange-500 text-white',
  'bg-amber-600  text-white',
  'bg-yellow-600 text-white',
  'bg-lime-600   text-white',
  'bg-green-600  text-white',
  'bg-emerald-600 text-white',
  'bg-teal-600   text-white',
  'bg-cyan-600   text-white',
  'bg-sky-600    text-white',
  'bg-blue-600   text-white',
  'bg-indigo-600 text-white',
  'bg-violet-600 text-white',
  'bg-purple-600 text-white',
  'bg-fuchsia-600 text-white',
  'bg-pink-600   text-white',
  'bg-rose-600   text-white',
  'bg-slate-600  text-white',
]

/**
 * Hash sederhana (djb2) — deterministik, distribusi merata, bukan kriptografis.
 */
function hashString(s) {
  let h = 5381
  const str = String(s ?? '')
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) + h) + str.charCodeAt(i)  // h * 33 + char
    h = h & 0xffffffff  // keep 32-bit
  }
  return Math.abs(h)
}

/** Class Tailwind background + text untuk avatar berdasar nama. */
export function avatarClass(name) {
  const idx = hashString(name) % PALETTE.length
  return PALETTE[idx]
}

/** Inisial 2 huruf (huruf depan kata pertama + huruf depan kata terakhir). */
export function avatarInitials(name) {
  const clean = String(name ?? '').trim()
  if (! clean) return '?'
  const parts = clean.split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) {
    // Satu kata → 2 huruf pertama, atau 1 huruf kalau sangat pendek
    return parts[0].slice(0, 2).toUpperCase()
  }
  // Multi-kata: huruf pertama dari kata pertama + huruf pertama kata terakhir
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

/** Composable wrapper supaya konsisten dengan composable lain. */
export function useAvatar() {
  return { avatarClass, avatarInitials }
}