# HR Helpdesk / Ticket System

Dokumen ini menjelaskan modul **HR Helpdesk** yang ditambahkan di iterasi
roadmap fase 5 item #16.

## Latar Belakang

Tanpa modul ini, pertanyaan/keluhan karyawan ke HR umumnya nyebar di chat
WhatsApp/Slack/email — sulit di-track, tidak ada SLA, dan jawaban yang sama
diulang puluhan kali. Modul Helpdesk menyediakan ticket system internal:
karyawan bikin tiket, HR balas, ada SLA otomatis, dan thread komentar
terdokumentasi.

## Apa yang Berubah

### Database

Migration `2024-01-01-000021_CreateHelpdeskTickets.php` — 2 tabel:

**`hr_tickets`** — header tiket:
- `ticket_number` unik per company (`TKT-<YEAR>-<5digit>`)
- `employee_id` pelapor + `created_by_user_id`
- Konten: `subject`, `body`, `attachment_path`, `category` (8 kategori)
- `priority`: LOW/MEDIUM/HIGH/URGENT — auto SLA
- `status` lifecycle: OPEN → IN_PROGRESS → ANSWERED → CLOSED → REOPENED
- Assignee: `assignee_user_id`, `assigned_at`
- SLA tracking: `sla_due_at`, `first_response_at`, `resolved_at`, `closed_at`
- CSAT: `satisfaction_rating` (1-5), `satisfaction_note`

**`hr_ticket_comments`** — thread:
- `author_user_id` + `author_role` (EMPLOYEE/HR/ADMIN/SYSTEM)
- `body`, `attachment_path`
- `is_internal` flag — HR bisa diskusi internal yang tidak terlihat karyawan

4 permission baru dengan role mapping:
- `helpdesk.view` — semua role (lihat tiket sendiri)
- `helpdesk.view_all` — role 1 & 2 (HR/admin)
- `helpdesk.handle` — role 1 & 2 (assign, status, reply)
- `helpdesk.internal` — role 1 & 2 (lihat komentar internal)

### SLA Otomatis

Setiap tiket dapat `sla_due_at` saat dibuat berdasarkan priority:

Priority | SLA
--- | ---
URGENT | 4 jam
HIGH | 24 jam (1 hari)
MEDIUM | 72 jam (3 hari)
LOW | 168 jam (1 minggu)

Tiket yang lewat `sla_due_at` tapi belum CLOSED dihitung sebagai overdue di
endpoint stats. UI menampilkan badge merah dan highlight row.

### Backend

Controller `HelpdeskController` — 11 endpoint:

| Endpoint | Aksi |
|---|---|
| `GET /helpdesk/tickets` | List dengan filter q/status/category/mine + pagination |
| `POST /helpdesk/tickets` | Create tiket (employee) |
| `GET /helpdesk/tickets/(:num)` | Detail + thread comments |
| `PUT /helpdesk/tickets/(:num)` | Update field (subject/body/category/priority) |
| `DELETE /helpdesk/tickets/(:num)` | Soft-delete |
| `POST /helpdesk/tickets/(:num)/assign` | Assign ke HR user |
| `POST /helpdesk/tickets/(:num)/status` | Change status manual |
| `POST /helpdesk/tickets/(:num)/close` | Close + opsional rating CSAT |
| `POST /helpdesk/tickets/(:num)/reopen` | Buka ulang tiket CLOSED |
| `POST /helpdesk/tickets/(:num)/comments` | Tambah komentar (dgn flag is_internal) |
| `GET /helpdesk/stats` | Counter per status + overdue |

Auto state machine:
- HR post komentar publik di OPEN/REOPENED → status auto ANSWERED + first_response_at
- Karyawan post komentar saat status ANSWERED → status auto IN_PROGRESS
- Komentar internal tidak ubah status

Authorization scope:
- Karyawan biasa hanya bisa lihat tiket sendiri
- HR/admin (helpdesk.view_all) lihat semua
- Komentar `is_internal=1` di-filter di GET kalau user tidak punya `helpdesk.internal`

Notifikasi otomatis:
- Tiket baru → notif ke HR (role 1, 2)
- Assign → notif ke assignee
- Komentar HR (publik) → notif ke pelapor
- Komentar pelapor → notif ke assignee (kalau ada)
- Status change → notif ke pelapor

Audit log per aksi (modul `HELPDESK`).

### Frontend

**`Helpdesk/TicketList.vue`** — list dengan:
- Stats cards 5-tier: Total, OPEN, IN_PROGRESS, CLOSED, **Overdue** (highlight merah)
- Filter: cari, status, kategori, mine (HR-only)
- Badge status & priority berwarna
- Row highlight merah kalau overdue
- Click row → detail
- Form modal create dengan kategori 8-pilihan + priority 4-tier

**`Helpdesk/TicketDetail.vue`** — 2-column layout:
- **Main**: header card (subject, status, SLA), body, thread comments (HR + EMPLOYEE + INTERNAL highlight kuning), reply form dengan checkbox internal (HR-only)
- **Sidebar**: info pelapor, detail tiket, action panel (Assign/Mark in-progress/Close/Reopen)
- Modal Close dengan rating CSAT 5-bintang
- Modal Assign dengan dropdown HR users

Sidebar: Group "HR" → "HR Helpdesk".

Router:
- `/helpdesk/tickets` → list
- `/helpdesk/tickets/:id` → detail

## Workflow Skenario

### Skenario 1 — Pertanyaan slip gaji

1. Karyawan buka **HR Helpdesk** → klik "Tiket Baru".
2. Isi: subject "Slip gaji bulan lalu tidak muncul", kategori PAYROLL, priority MEDIUM, body detail.
3. Save → tiket dibuat dgn ref TKT-2026-00001, status OPEN, SLA 72 jam.
4. HR dapat notifikasi 🎫 → buka tiket.
5. HR balas "Sedang dicek tim payroll" → status auto **ANSWERED**, first_response_at terisi.
6. Karyawan dapat notif 💬 balasan → buka tiket, balas "Masih tidak muncul" → status auto **IN_PROGRESS**.
7. HR komentar internal: "Cek dengan finance dulu" (is_internal=1) → karyawan tidak lihat ini.
8. Setelah resolved, HR atau karyawan klik **Tutup Tiket** → modal CSAT, isi rating 5/5.
9. Status CLOSED, closed_at + resolved_at + rating tersimpan.

### Skenario 2 — Tiket urgent

1. Karyawan submit URGENT (mis. login kunci) → SLA 4 jam.
2. HR yang lalai akan lihat badge "⚠ Lewat SLA" di list + counter Overdue di stats.
3. Manager bisa monitor stats dan reassign ke HR lain.

### Skenario 3 — Komentar internal HR

1. HR1 handle tiket complex, perlu konsultasi dengan HR2 lewat komentar internal.
2. HR1 reply dengan checkbox "Catatan internal" → simpan dengan `is_internal=1`.
3. UI: komentar dengan border kuning + label LOCK INTERNAL.
4. Karyawan ybs membuka tiket → komentar internal tidak muncul (filter di backend).
5. HR2 baca, balas internal juga, atau publik kalau sudah jelas.

### Skenario 4 — Reopen

1. Tiket sudah CLOSED dengan rating 4/5.
2. Setelah seminggu, masalah muncul lagi. Karyawan klik **Buka Ulang**.
3. Status REOPENED, closed_at di-clear. Thread komentar tetap utuh sebagai history.
4. HR dapat notif tiket reopened.

## Verifikasi

Smoke test `backend/scripts/test-helpdesk-flow.php` lulus 39/39:
- Schema check 2 tabel + 4 permission
- Insert tiket dgn TKT-YEAR-XXXXX format + SLA 24 jam (HIGH)
- HR komentar PUBLIC → ANSWERED + first_response_at
- Komentar INTERNAL → tidak ubah status, visible HR only (1 public, 1 internal)
- Karyawan reply ANSWERED → IN_PROGRESS
- Assign + assigned_at
- Close + rating 5/5 + closed_at + resolved_at
- Reopen → REOPENED, closed_at null
- SLA computation per priority (URGENT/HIGH/MEDIUM/LOW)
- Stats aggregate
- Class structure 11 method (index/create/show/update/delete/assign/changeStatus/close/reopen/addComment/stats)

## Migrasi Database

```bash
cd backend
php spark migrate
```

Tidak ada seeder default — tiket dibuat oleh user.

## Integrasi dengan Modul Lain

- **Notifikasi**: pakai `Services::notification()`. Notif terjadi di create, assign, comment public, status change.
- **Audit Log**: setiap aksi tercatat di modul `HELPDESK` untuk audit trail.
- **Employees**: detail menampilkan info pelapor (departement, branch, contact).
- **Users**: assign modal load HR users dgn role_id=2.

## Roadmap Selanjutnya

- **File attachment**: upload file CV/screenshot di body & comment (schema sudah siap, perlu endpoint multipart).
- **Email integration**: kirim email saat tiket reply (bukan hanya in-app).
- **Auto-assign**: round-robin assignment kalau ada multiple HR per kategori.
- **Knowledge base**: link FAQ ke tiket (mengurangi tiket berulang).
- **SLA escalation**: auto-eskalasi ke manager kalau tiket overdue 24 jam.
- **Tagging system**: tag bebas untuk filter advance (mis. "BPJS", "ATTENDANCE_BUG").
- **Bulk actions**: close/assign banyak tiket sekaligus.