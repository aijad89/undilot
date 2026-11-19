# Buang permohonan sertai

2026-11-14

## Keputusan

Dalam undilot2026 **pejabat memasukkan data**. Peserta log masuk, kemudian
mengundi. Jadi modul permohonan sertai — borang berbilang langkah di belakang
butang "Register now" — dibuang sepenuhnya.

Peserta tidak lagi memohon. Pejabat menambah syarikat ke senarai cabutan, HQ
mengesahkan, peserta diberitahu, peserta mengesahkan lotnya.

## Apa yang kekal, dan sebabnya

**Consent pada halaman lot tidak disentuh.** `/events/{event}/lot` sudah
membawa penerangan, kotak semak *"I agree to this lot allocation and confirm my
participation"*, dan butang Confirm Lot sebelum lot didedahkan. Itu bukan
sebahagian modul ini dan tidak diubah. Tiada skrin consent baharu dibina —
yang ada sudah memenuhi "klik undi → consent → halaman undi".

**`JoinRequestAnswered` kekal, dinamakan semula.** Ia buat dua kerja:

| Cabang | Dihantar bila | Nasib |
|---|---|---|
| *"Request not approved — Reason: …"* | zon menolak permohonan | dibuang |
| *"You are in the draw"* | **HQ mengesahkan pertama kali** | **mesti kekal** |

Cabang kedua dihantar untuk **setiap** syarikat, termasuk yang pejabat tambah
sendiri tanpa permohonan (`EntRegistrationController::verify()`, cabang
`$first`). Memadam kelas itu bermakna peserta tidak akan pernah diberitahu
mereka ada dalam cabutan — memecahkan perkara yang pembuangan ini sepatutnya
melindungi. Jadi: dinamakan `AddedToDraw`, membawa cabang kelulusan sahaja.

**`beingAdded` kekal.** Ia membaca `event_registrations` dan `first_verified_at`
sahaja — *"pejabat sedang menambah syarikat anda ke cabutan ini"* — dan tidak
menyentuh permohonan sertai. Itu tepat aliran pejabat-masukkan-data yang
pembuangan ini menjadikan satu-satunya laluan, jadi mesejnya jadi **lebih**
penting, bukan kurang.

**Juga tidak disentuh:** model dua-semakan (zon → HQ), `PollingGuard`,
`first_verified_at`, skop `announced()`, `payment_note`, `RemovedFromDraw`,
`EventRegistration::booted()`.

## Apa yang dibuang

### Kod

| Lapisan | Butiran |
|---|---|
| Controller | `app/Http/Controllers/JoinRequestController.php` |
| Model | `app/Models/EventJoinRequest.php` |
| Factory | `database/factories/EventJoinRequestFactory.php` |
| Notifikasi | `app/Notifications/JoinRequestAnswered.php` → dinamakan `AddedToDraw` |
| Route | `events.join`, `join-requests.document`, `join-requests.photos`, `join-requests.photo`, `admin.events.registrations.approve`, `admin.events.registrations.reject` |

### View

| Fail | Tindakan |
|---|---|
| `resources/views/events/show.blade.php` | buang borang berbilang langkah; halaman acara kekal sebagai paparan maklumat |
| `resources/views/partials/join-answers.blade.php` | padam |
| `resources/views/partials/payment-proof.blade.php` | padam |
| `resources/views/request-photos.blade.php` | padam |
| `resources/views/partials/company-record.blade.php` | padam — ia memapar `credentials` |
| `resources/views/dashboard.blade.php` | buang seksyen "Requests to join" dan senarai acara-boleh-disertai |
| `resources/views/past-events.blade.php` | buang lajur jawapan permohonan |
| `resources/views/admin/events/registrations/index.blade.php` | buang panel permohonan menunggu, bukti bayaran, "Rejected by zones" |
| `resources/views/partials/public-event-card.blade.php` | buang butang **"Register now"** |
| `resources/views/partials/public-event-stage.blade.php` | buang label `register` |

### Skema

Dalam **baseline migration** (`0001_01_02_000000_create_undilot_schema.php`) —
belum ada data sebenar, jadi ikut peraturan satu-baseline:

- jadual `event_join_requests` dibuang, dan dari senarai `down()`
- lajur `companies.credentials` dibuang

`credentials` diukur sebelum diputuskan: **5 daripada 5 syarikat `NULL`, 0 fail
dalam `storage/app/private/certificates`, `products`, `join-requests`**. Ia
hanya pernah diisi oleh peserta yang menghantar permohonan sendiri — satu-satunya
penulisnya ialah `JoinRequestController` — dan import tidak pernah menulisnya
kerana dump lama tiada medannya. Lajur itu tidak pernah berisi dalam repo ini.

### Model

| Tempat | Dibuang |
|---|---|
| `Company` | `RECOGNITIONS`, `QUALITY_STANDARDS`, `QUALITY_CRAFTS`, `answersFor()`, `qualityStandard()`, `credentials` dari `$fillable` dan `$casts` |
| `Event` | `joinRequests()`, `acceptsJoinRequests()`, cabang `register` dalam `publicStage()`, `isCalledOff()` |

**`isCalledOff()` menjadi kod mati.** Keempat-empat pemanggilnya ialah kod
permohonan sertai: `approve()`, `reject()`, `HomeController:100`,
`dashboard.blade.php:62`. Ia dibuang bersama mereka, bukan ditinggalkan sebagai
method tanpa pemanggil.

### Controller yang disunting

| Fail | Perubahan |
|---|---|
| `Admin/EntRegistrationController` | buang `approve()`, `reject()`, pengumpulan bukti bayaran, panel permohonan menunggu, `rejectedByZone()`; `verify()` menghantar `AddedToDraw` pada `$first` sahaja |
| `Admin/DashboardController` | buang kiraan `join_pending` dan `zone_rejected` |
| `HomeController` | `show()` buang `joinRequest` **sahaja**; `dashboard()` buang senarai permohonan dan acara-boleh-disertai, tinggal cabutan sahaja |
| `NotificationController` | kemas kini rujukan kepada nama notifikasi baharu |
| `DevAutoLoginController` | kemas kini komen yang menamakan notifikasi |

### Seeder

`DemoEventsSeeder` dan `DemoScenarioSeeder` buang penanaman permohonan sertai.
Syarikat ditambah terus ke senarai cabutan, sama seperti pejabat akan buat.

## Apa yang berubah makna

**Papan pemuka peserta** kini menunjukkan tiga perkara: cabutan yang syarikat
ada, permohonan yang dihantar, dan acara yang boleh disertai. Dua yang terakhir
hilang. Tinggal **cabutan sahaja** — log masuk, kemudian undi.

**Tahap acara awam**: acara sebelum cabutan dibuka jadi `soon`, bukan
`register`. `registration_open_at` dan `registration_close_at` **kekal** —
`auto_close` menggunakannya untuk menyahterbit acara, dan `UpdateEventStatuses`
bergantung padanya.

**Sebab pembuangan daripada senarai cabutan** kini hidup dalam notifikasi
`RemovedFromDraw` sahaja. Mesejnya sudah membawa `"Reason: …"`, dan baris
notifikasi itu kekal dalam jadual `notifications`. Salinan pada
`event_join_requests.removed_reason` hanya menyuap sepanduk pada borang sertai,
yang hilang bersama borang itu.

## Ujian

| Fail | Tindakan |
|---|---|
| `AParticipantAsksToJoinAnEventTest.php` | **padam** — 43 ujian, semuanya tentang borang sertai |
| `TheDrawListIsTheApprovalTest.php` | sunting — buang kes lulus/tolak permohonan, kekalkan dua-semakan |
| `AParticipantSeesTheirPastEventsTest.php` | sunting — buang jawapan permohonan |
| `TheDashboardShowsEachAdminTheirOwnWorkTest.php` | sunting — buang dua kiraan |
| `TheDemoSeederPlantsEveryZoneTest.php` | sunting — jangkaan seeder baharu |
| `tests/e2e/05-join-steps.spec.js` | **padam** — seluruh spec ialah borang sertai |

`tests/e2e/01-smoke.spec.js` **tidak** menyentuh borang sertai — diperiksa,
tiada sebutan `join` atau `register`. Ia tidak perlu disunting.

### Ujian baharu

Satu ujian mesti ditulis, kerana ia menjaga perkara yang pembuangan ini paling
mudah merosakkan:

**Peserta diberitahu bila HQ mengesahkan syarikat yang pejabat tambah.** Tanpa
ia, memadam `JoinRequestAnswered` secara senyap mengambil satu-satunya
pemberitahuan "anda dalam cabutan", dan tiada apa gagal. Ujian menanam syarikat
yang pejabat tambah, HQ mengesahkan, dan menegaskan peserta menerima
`AddedToDraw` — sekali, bukan dua kali apabila HQ menyemak semula.

### Pengesahan

Dua pemacu seperti peraturan repo:

```
php artisan migrate:fresh --force      # MySQL
vendor/pestphp/pest/bin/pest           # SQLite dalam-memori
npx playwright test                    # baca kiraan flaky, bukan passed
```

Dan satu semakan tangan: pandu `/`, `/events/{event}`, `/dashboard`,
`/events/{event}/lot`, `/admin/events/{event}/registrations` sebagai peserta,
super dan admin negeri — mencari halaman yang merujuk route yang sudah tiada.

**Nota:** suite pelayar sedang merosot pada mesin ini (1.9–3.3 minit berbanding
42 saat, ujian berbeza timeout setiap larian). Terbukti bukan daripada kod —
pada commit `8dee630` yang dulu hijau empat kali, ia tetap gagal. Larian
Playwright untuk kerja ini mesti dibandingkan dengan garis dasar itu, bukan
dengan 24-lulus.

## Dokumen

**CLAUDE.md** — empat tempat:

1. Pengenalan: `undilot2026` tidak lagi mengandungi permohonan sertai.
2. Seksyen dua-semakan: buang baris tentang permintaan `pending`, dan baris
   `removed_reason` yang menunjuk ke `event_join_requests`.
3. Baris `isCalledOff()` / unpublished: peraturan itu wujud kerana permohonan
   sertai; ia pergi bersama.
4. Baris 50 (notifikasi berbaris) dan 386 (fail muat naik `local`): kemas kini
   kepada notifikasi dan fail yang masih ada.

**PRODUCT.md** — baris 66 menyebut "join-request answers" dalam notifikasi
portal.

**README.md** — senarai modul.

## Risiko

**Route yang hilang membunuh view.** CLAUDE.md memberi amaran: satu `route()`
yang dibuang mematikan setiap skrin yang memuatkan layout itu. Jadi selepas
membuang route, grep untuk setiap nama route yang dibuang merentas
`resources/` sebelum menjalankan apa-apa.

**Notifikasi lama dalam DB.** Baris `notifications` menyimpan nama kelas penuh,
jadi menamakan `JoinRequestAnswered` kepada `AddedToDraw` akan meninggalkan
baris yang merujuk kelas yang tiada. Diukur: **jadual `notifications` kosong
(0 baris)**, jadi tiada apa untuk dimigrasi. Pada pemasangan yang sudah ada
baris sedemikian, ia perlu dipadam dalam langkah yang sama — tetapi itu bukan
keadaan mesin ini.
