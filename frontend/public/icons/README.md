# PWA Icons

Folder ini berisi icon untuk PWA manifest.

## File yang dibutuhkan

- `icon-192.png` — 192x192 (Android home screen, apple-touch-icon)
- `icon-512.png` — 512x512 (high-res splash + Android adaptive)
- `icon-maskable-512.png` — 512x512 dengan safe-zone (logo di tengah dengan padding 10%)
- `shortcut-clock.png`, `shortcut-leave.png`, `shortcut-payslip.png`, `shortcut-approval.png` — 96x96 (icon shortcut di app drawer)

## Cara generate

Pakai tool online seperti https://realfavicongenerator.net atau script lokal:

```bash
# pakai sharp (Node.js)
npx sharp-cli resize 192 -i logo.svg -o icons/icon-192.png
npx sharp-cli resize 512 -i logo.svg -o icons/icon-512.png
```

Untuk maskable icon, pastikan logo di tengah dengan padding 10% supaya tidak ke-crop saat
Android adaptive icon menerapkan mask berbentuk circle/squircle.

## Sementara

Sebelum icon final tersedia, browser akan fallback ke `/favicon.svg`. Manifest tetap
valid tapi quality "Add to Home Screen" akan kurang optimal — generate icon real
sebelum production deploy.