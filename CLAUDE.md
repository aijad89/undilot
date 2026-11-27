# CLAUDE.md

Panduan untuk sesiapa — manusia atau agen — yang bekerja dalam repo ini.

Fail ini memegang **peraturan dan keputusan sahaja**: perkara yang tidak boleh
diterbitkan semula dengan membaca kod. Cara kod berfungsi dibaca daripada kod.
Cerita panjang di sebalik peraturan ada dalam:

- `docs/legacy-import.md` — import dump lama (`digitalCraft`), cutover
- `docs/testing/e2e-notes.md` — suite pelayar dan MySQL: port, yatim, pekerja
- `docs/sedia-go-live.md` — senarai semak deploy

**Menambah ke fail ini** (pemilik 2026-11-24): satu keputusan = satu `###`,
**3–5 baris** — peraturannya, satu ayat sebab, tarikh keputusan pemilik, dan
nama kelas/ujian yang memegangnya. Cerita ukuran, sejarah pepijat dan cara kod
berfungsi pergi ke `docs/`, dengan pautan dari sini. Keputusan yang berubah
**menggantikan** bahagian lamanya, tidak ditambah di bawahnya. Sasaran: fail
ini kekal bawah ~400 baris.

---

## Apa ini

**undilot** — sistem cabutan lot untuk acara usahawan kraf. Laravel 12, PHP
`^8.4`, MySQL pada dev dan produksi, SQLite dalam-memori untuk ujian.

Pecahan daripada **`undilotlook`** (2026-11-13), yang dipecah daripada
**`daftar`** (`f:\herd\daftar`, 2026-10-13). undilot hanya membawa log masuk
peserta dan admin, acara, cabutan lot, dan permohonan sertai (dipulihkan
2026-11-19). Pengumuman, footer, pautan sosial dan laporan CSV dibuang. Skrin
audit trail dibuang kemudian dipulihkan (2026-11-24, super sahaja). Data sebenar dimuat dari
dump lama.

**`daftar` kekal sebagai arkib.** Jangan tulis semula sesuatu yang boleh
disalin dari sana.

---

## Persekitaran tempatan

| | |
|---|---|
| Laluan | `f:\herd\undilot` |
| URL | `http://undilot.test` (Herd) |
| DB | `undilot` pada `127.0.0.1` |
| PHP CLI | `/c/Users/ALOY/.config/herd/bin/php84.bat` |
| MySQL CLI | `/c/Program Files/MySQL/MySQL Server 9.5/bin/mysql.exe` |
| Admin pertama | `.env` — `ADMIN_SEED_USERNAME` / `ADMIN_SEED_PASSWORD` |
| Log masuk pantas | `http://undilot.test/auto` — sebarang akaun, plus butang *Work the queue* |
| DB ujian | `undilot_test` (Pest MySQL), `undilot_e2e` (Playwright, diurus `tests/e2e/serve.js`) |
| Git | `https://github.com/aijad89/undilot` (peribadi), cawangan `main` |

- `artisan tinker <fail>` **tergantung** di mesin ini. Guna klien MySQL atau
  skrip PHP yang memuat `bootstrap/app.php`.
- Tiada pekerja queue berjalan di sini: notifikasi kekal dalam `jobs` sehingga
  butang pada `/auto` ditekan.
- `php` tiada dalam PATH bash — guna `php84.bat`. Heredoc bash dengan apostrof
  gagal dalam alat Bash; tulis skrip ke fail dahulu.

---

## Peraturan

### Satu baseline migration
`database/migrations/0001_01_02_000000_create_undilot_schema.php` memegang
seluruh skema. Selagi belum ada data sebenar, **ubah fail itu dan jalankan
`migrate:fresh`** — tiada migration berperingkat. Berhenti pada hari data
sebenar dimasukkan.

### Tiada lajur `enum`, selamanya
Status tinggal dalam const model (`Event::STATUSES`). Sebab: `ALTER … MODIFY`
dilangkau senyap oleh SQLite; dalam `daftar` penyimpangan itu tersembunyi lapan
bulan. Dikunci oleh `SchemaHoldsTheSameShapeOnBothDriversTest`.

### Setiap migration dijalankan pada dua pemacu
`php artisan migrate:fresh --force` (MySQL) **dan** `vendor/pestphp/pest/bin/pest`
(SQLite).

### Nav layout ialah senarai
`layouts/admin.blade.php` (`$nav`) dan `partials/portal-bar.blade.php` (`$apNav`).
Tambah modul = satu entri array. Jangan eja `route()` dalam markup — satu route
yang dibuang membunuh setiap skrin.

### Factory menamakan setiap lajur, termasuk yang nullable
`preventAccessingMissingAttributes()` hidup di luar produksi, dan `actingAs()`
menyerahkan instance factory itu sendiri. Lajur yang tidak dinamakan = view
campak `MissingAttributeException` dalam ujian sahaja.

### Tiada kata laluan lalai dalam kod
`AdminSeeder` menolak berjalan tanpa `ADMIN_SEED_PASSWORD`. Jangan beri fallback
— ia masuk sejarah git.

### Semua `.md` dijejaki
Keputusan ialah bahagian paling sukar dipulihkan.

### Data rujukan disemai, data demo tidak
- `DatabaseSeeder` memanggil `AdminSeeder` dan `KrafCodeSeeder` (boleh diulang).
- **Setiap seeder demo mewarisi `LocalOnlySeeder`**: menolak setiap `APP_ENV`
  selain `local`, `testing`, `e2e` (`DevEnvironment::NAMES`) — senarai benar,
  bukan `isProduction()`. Seeder rujukan baharu → tambah pada `REFERENCE_SEEDERS`
  dalam `DemoSeedersNeverRunOnAServerTest`.
- `db:wipe` dan `migrate:fresh/refresh/reset/rollback` juga ditolak di luar
  senarai itu. `migrate --force` tidak disentuh.

### `/auto` tidak pernah wujud di pelayan
Didaftar hanya dalam `local`, `testing`, `e2e`. Jangan bawa pintu ber-flag
`daftar` (`DEV_AUTO_LOGIN` + IP) — itu keputusan.

### Selamat-subpath
Mungkin dihidang di `/undilotnew`. Setiap pautan melalui `route()` / `url()`;
**tiada** `href="/…"` atau laluan relatif-akar dalam skrip. `redirect('/x')`
sebenarnya selamat, tetapi `/` ialah senarai awam — destinasi selepas log masuk
mesti dinamakan. Dikunci oleh `EveryLinkSurvivesASubpathInstallTest`.

### Setiap peraturan baharu dibuktikan menggigit
Selepas ujian lulus, buang peraturan itu sementara dan pastikan ujian **gagal**.
Beberapa ujian di sini pernah lulus atas sebab yang salah. Selepas menguji view,
`touch` fail itu dan `view:clear`.

---

## Seni bina dan keputusan

### Dua guard
`web` (`User`) dan `admin` (`Admin`). Admin ialah tetamu pada `web`, dan
sebaliknya. Dua closure dalam `bootstrap/app.php` menjawabnya; pecah salah satu
= gelung redirect. `BothGuardsSignInTest`.

### Tiga peranan admin
`admin` (satu negeri), `hq`, `super`. `Admin::assignableRolesFor()` diterbitkan
daripada pemanggil. Skrin akaun: **dua soalan, kedua-duanya perlu** —
`authorizeTarget()` (hq hanya menyentuh admin negeri) dan `authorizeRole()`.

### Skop negeri melalui `EventVisibility`, bukan middleware
Satu peraturan: acara draf untuk super/hq sahaja. **Tiada peraturan
*own-state*** — pejabat yang mengisi senarai, jadi acara kosong mesti kelihatan.
Baris di dalam acara ditapis ikut zon.

### Tulis digate di route, baca menskop sendiri
Gate `manage-events`, `manage-settings`, `verify-draw-list` dalam
`AppServiceProvider`. Route senarai cabutan melayan admin negeri juga;
`EntRegistrationController` bertanya `verify-draw-list` per permintaan.

### `kraf_bidangs` memegang dua senarai yang tidak berkait
`code` NULL = kategori (TEKSTIL…); berisi = kod kraf (TBPS…). **Setiap query
menyatakan yang mana** (`::categories()` / `::codes()`). Pelan dan cat plot
menerima kod sahaja. Padam ditolak selagi ada FK menunjuk. Kategori tetap
(disemai). Produk dengan `sub_bidang_id` NULL = baris lama, tidak boleh ditanda.

### Pemilikan ialah `company_id`
`User::registrations()` ialah `hasManyThrough` melalui `Company`. `user_id`
tetap ditulis dan dibaca (`plots.allocated_to`, `polls`) — dua kunci, sengaja.

### Dua semakan: zon meletakkan, HQ mengesahkan (`verified_at`)
- Tiada lajur `status` pada `event_registrations`. Zon menyemak dan meletakkan;
  HQ sahkan atau pulangkan dengan `hq_note`.
- **HQ boleh memintas zon** — baris HQ terus disahkan. Keputusan, bukan lubang.
- Peserta diberitahu (`AddedToDraw`) sekali, pada pengesahan HQ pertama
  (`first_verified_at`, tidak pernah dikosongkan). Portal membaca `announced()`.
- Perubahan kod, saiz, `payment_note` atau `payment_status` oleh zon
  **mengosongkan** `verified_at` — satu tempat: `EventRegistration::booted()`.
- `PollingGuard` menahan cabutan selagi ada baris belum disahkan.
- Kod dan saiz **dibeku** sebaik `polling_status` bukan `draft`, untuk HQ juga.
- Zon hanya membuang baris yang belum diberitahu (`zoneMayRemove()`). HQ mesti
  memberi sebab; syarikat menerima `RemovedFromDraw`.
- Sebaik cabutan bermula, zon tidak menambah/meluluskan (`zoneLocked()`), dan
  **undo ditolak untuk semua orang** (2026-11-15).
- Syarikat dengan baris yang sudah diberitahu dalam cabutan aktif **tidak boleh
  dipadam** (`Company::booted()`).

### `plot_sizes` ialah pasangan (lokasi, saiz)
Borang tidak menaip jumlah (pemilik 2026-11-17): satu kad = lokasi, plot =
peruntukan kod + plot simpanan. `savePlan()` menjumlahkan per (lokasi, saiz).
`total_plots` dikira. **Setiap plot menamakan saiznya** — lot ditarik pada saiz
gerai pendaftaran (`event_registrations.plot_size`, string, lalai `3x3`).
`LotAllocator` satu-satunya tempat peraturan lot; `PollingGuard` satu-satunya
"boleh dibuka?".

### `event_registrations.code` ialah string, bukan FK
Sengaja — itulah yang pengalokasi bandingkan. Pengesahan **sengaja longgar**
(`string|max:50`): kod yang ditarik balik mesti masih boleh disimpan (pemilik
2026-11-15, jangan ketatkan). **Rename kod membawa barisnya**:
`SettingController::updateKrafCode()` mengemas kini `event_registrations.code`,
`companies.kraf_code`, `polls.code`, `preset_allocations.code`. Lajur teks kod
baharu **mesti** ditambah ke senarai itu. Rename ditolak selagi cabutan yang
menggunakannya dibuka.

### Zon ialah `ManagedList`; rename tidak boleh menggabungkan
`AdminZones::rename()` menolak nama zon lain, tidak peka huruf (K08). Zon yang
tidak dikenali dropdown dipindah senyap ke zon pertama bila disimpan — sebab
itu zon lama mesti diterjemah (lihat *Data lama*).

### `event_registrations.plot_id` tiada `ON DELETE`
Keputusan cabutan tidak boleh hilang kerana pelan disunting. Pelan **dibeku**
sebaik satu lot dipegang (`planIsFrozen()`). Sebelum itu, jika cabutan sudah
bermula, tulis semula memanggil `PollingGuard::refuseIfStartedAndBroken()`
(K05). Tarikh tamat ada pintu sendiri (`updateDeadline()`); *Reopen* ditolak
bila tarikh lepas (K06). *Take back* ditolak pada cabutan `completed` (K04).

### Audit sebahagiannya ditulis dengan tangan
Mass delete dan `ON DELETE CASCADE` tidak mencetuskan model event.
`PollingController` menulis barisnya sendiri; model menyatakan jangkauan cascade
dengan `auditDeletionSummary()`, dikira **sebelum** DELETE. Ukur cascade
daripada `information_schema.REFERENTIAL_CONSTRAINTS` pangkalan data hidup,
bukan fail migration.

### Tiada langkah bina
Tiada Vite/npm build/Alpine. Stylesheet inline dalam partial; pustaka dari CDN
dengan versi dipin. `npm` hanya untuk Playwright. Node tidak diperlukan di
produksi.

### Halaman ralat berdiri sendiri (2026-11-24)
`resources/views/errors/` (403, 404, 419, 429, 500, 503) memakai `errors.layout`:
tiada layout aplikasi, tiada query DB — halaman 500 yang memerlukan DB ialah ralat
kedua. URL tanpa route dijawab tanpa sesi, jadi "siapa log masuk" tidak boleh
dipercayai di sana.

### Dua design system
`.mac-` (admin, amber) dan `.ap-` (peserta, biru). Tiada halaman menggunakan
kedua-duanya. View auth berupa sendiri.

### Zon waktu `Asia/Kuala_Lumpur` (`config/app.php`)
Tetingkap cabutan disimpan seperti ditaip; `now()` mesti pada jam yang sama.

### `SESSION_DRIVER=database`
Reset kata laluan mengusir peranti lain dengan memadam baris `sessions` peserta
itu (`NewPasswordController`); pada pemacu lain ia hanya menulis amaran log.
Jangan tukar.

### Fail: peserta ke cakera `local`, sepanduk ke `public`
- Fail peserta (resit, sijil, foto) di cakera `local`, dihidang **hanya**
  melalui controller (syarikat itu, atau admin zonnya).
- `EventController::storeBanner()` satu-satunya muat naik ke `public`; **tiada
  `svg`** (boleh membawa skrip).
- **Sebut cakera `public` pada nama** bila menjana URL sepanduk —
  `Storage::url()` tanpa nama memberi URL relatif-akar yang memecah subpath.
- `'serve'` cakera `local` kekal `false`.
- `storage:link` ialah langkah deploy yang senyap bila terlupa.

### Permohonan sertai (dipulihkan 2026-11-19)
Zon meluluskan (`approve()` → `addToList()`, menunggu HQ; HQ meluluskan → terus
disahkan) atau menolak dengan sebab (`JoinRequestRejected`). `zoneLocked()`
terpakai. Kedua-dua pintu masuk — pejabat atau permohonan — melalui
`EntRegistrationController::addToList()`.

### Profil syarikat = separuh borang MICF, diisi sekali (pemilik 2026-11-19/20)
- Setiap permohonan sertai bermula daripada profil dan menyimpan **salinan**
  dalam `answers`. Permohonan hanya meminta pengakuan, pengesahan butiran, lima
  foto dan bayaran. `CompanyCredentials::missing()` menyekat menghantar (draf
  boleh) selagi profil belum lengkap.
- **Sijil ialah pilihan peserta**, dengan keistimewaan sendiri (pemilik
  2026-11-24). Yang diisytiharkan mesti ada fail, tetapi **sijil tamat tidak
  menyekat** apa-apa — ia boleh disimpan dan permohonan tetap dihantar.
  Peserta diberi amaran; pejabat nampak tanda *Expired* semasa menyemak.
- **Setiap kad menyimpan bahagiannya sahaja.**
- **Kategori usahawan** (pilihan MICF peserta) ≠ **jenis perniagaan**
  (`business_type`, pejabat sahaja). Dua medan, dua soalan.
- **Zon, IC, ID Komuniti, emel, `kraf_code`, `plot_size` milik pejabat** —
  tidak dibaca daripada permintaan peserta.
- Sijil kualiti hanya bila kraf **utama** ialah TEKSTIL › Batik, Songket atau
  Tenunan (`Company::qualityStandards()`), bukan kod kraf pejabat.
- **Sijil klasifikasi dan kualiti dalam `company_certificates`** (2026-11-24):
  `replaced_at` NULL = semasa, selebihnya sejarah, fail disimpan. **Baca melalui
  `Company::profile()`**, tulis hanya melalui `CompanyCredentials::save()`.
  `$this->original` ialah sifat Eloquent — guna `getAttribute('original')`.

### Laman awam: `Event::PUBLIC_STATUSES`
Hanya `open` dan `completed`. `closed` 404 kecuali kepada admin (K10, pemilik
2026-11-16). Papan pemuka, lot dan slip peserta tidak menyemak status terbit.
**Awas:** `auto_close` menyorokkan acara sebaik `registration_close_at` lepas.

### Peserta log masuk dengan emel + kata laluan (pemilik 2026-11-24)
Menggantikan ID Komuniti + IC (17 Nov), kerana kedua-duanya tercetak pada slip.
- **Emel = nama pengguna**, disimpan huruf kecil dan dipotong. **Pejabat
  sahaja** menetapkannya (borang syarikat, wajib, unik) — peserta yang boleh
  menukar emel boleh mengambil akaun. Tanpa emel, tiada log masuk.
- **Kali pertama, IC ialah kata laluan** (`password_changed_at` NULL, dibanding
  sebagai digit). `EnsurePasswordChanged` (`user.password-set`) menahan peserta
  di `password.edit` sehingga mereka memilih kata laluan; selepas itu IC tidak
  berfungsi lagi. `Password::defaults()` (huruf + nombor) menolak IC sebagai
  kata laluan baharu.
- **Pejabat tidak menaip kata laluan.** "Reset password to IC" di halaman
  syarikat = `password_changed_at` NULL semula.
- Lupa kata laluan → pautan reset melalui emel. Borang memberi jawapan yang sama
  sama ada emel wujud atau tidak.
- Had cuba: 5 per emel + IP.

### Admin boleh membuka portal sebagai peserta (pemilik 2026-11-24)
`App\Support\Impersonation`: admin negeri untuk zonnya, HQ/super semua. Admin kekal
log masuk; setiap tulis direkod atas nama admin; kata laluan peserta **tidak**
boleh ditukar dari situ. *Sign out* = kembali ke admin, dengan
`logoutCurrentDevice()` (bukan `logout()`, yang mengusir "remember me" peserta).

### Skrin audit trail: super sahaja (pemilik 2026-11-24)
Gate `read-audit-trail` = `isSuperAdmin()` — bukan HQ. Dipulihkan supaya apa
yang admin buat semasa *login as* boleh dibaca; tapis `?admin=` atau `?user=`.

### Pegawai log masuk dengan nama pengguna + kata laluan (pemilik 2026-11-17)
`admins.username` disimpan huruf kecil, dipotong — SQLite peka huruf, MySQL
tidak. Kata laluan lama tidak dibawa.

### Satu-satunya emel ialah pautan reset kata laluan (pemilik 2026-11-24)
Pegawai tiada emel. Setiap notifikasi aplikasi `via()` = `['database']` — tiada
`toMail()` dalam `app/Notifications`. `TheAppEmailsOnlyThePasswordResetTest`.
**Jangan tambah saluran `mail` pada notifikasi tanpa keputusan pemilik.**
Pelayan memerlukan `MAIL_*` sebenar; `MAIL_MAILER=log` tidak menghantar apa-apa.

### Nombor lot `TBPS02`, tanpa lokasi (pemilik 2026-11-17)
`Plot::codeFor()`. Pembilang **per kod merentas acara**; jangan tambah lokasi
pada kunci.

### `business_type`: dua nilai (pemilik 2026-11-17/20)
`Pengeluar atau Pedagang`, `Pemborong`. `ManagedList` — nilai lama rekod itu
diterima (`rule($keep)`), atau simpanan memadamnya senyap.

### Status bayaran pilihan, catatan bayaran teks
`payment_status` (`paid`/`unpaid`, string). Tidak menyekat cabutan.

### IC pada slip: suis `SLIP_SHOWS_IC`, lalai on (pemilik 2026-11-16)

### Buku jualan: undilot menjana `.xlsx`, HQ mengisinya (pemilik 2026-11-25)
Halaman per acara (`SalesBookController`): helaian kod dari cabutan, ditambah tab
program luar cabutan (`sales_book_tabs`: nama + bilangan blok kosong). Empat baris
bayaran per lot, satu lajur per hari. Buku yang diisi dimuat naik semula
(`SalesBookImport`, `sales_book_entries`): **semua atau tiada**, dan setiap muat naik
**menggantikan** yang lepas. Jadual jualan dibaca di menu *Sales* (`SalesController`).
Ringkasan di depan: RINGKASAN (nilai tetap; 3x6 = 2 lot), RINGKASAN KESELURUHAN, RING1,
SALEBYDAY (formula). Gate `manage-sales-book` = HQ/super. `TheSalesBookListsEveryLotByCodeTest`.

### Papan pemuka = kerja, analisis jualan di halaman Sales (pemilik 2026-11-25)
Papan pemuka hanya memegang apa yang menunggu tindakan: empat kiraan kerja, *Needs
attention*, acara sedang berjalan, rekod syarikat yang berlubang. Graf jualan, pecahan
dan usahawan terlaris di `admin.sales.index` — bukan perbandingan % antara acara (saiz
dan tempat berbeza). `TheDashboardShowsEachAdminTheirOwnWorkTest`.

### `$unreadNotificationCount` disediakan oleh senarai view
View baharu yang membacanya **mesti** ditambah pada composer dalam
`AppServiceProvider`, atau ia 500.

### Masalah pelayan di papan pemuka admin (2026-11-24)
`DashboardController::serverProblems()`: queue tersangkut, kerja gagal,
penjadual tiada denyutan (`scheduler-heartbeat`, 5 minit), `storage:link`.
Super/HQ sahaja, tidak pernah di mesin dev.

---

## Data lama — ringkasan

Butiran penuh: **`docs/legacy-import.md`**. Peraturan yang tidak boleh dilanggar:

- Jadual `legacy_*` ialah arkib: setiap lajur string nullable, nama lajur lama
  dihurufkecilkan, baris penuh dalam `raw` (pemilik 2026-11-19 — **staging ialah
  arkib**, tiada apa dikorbankan).
- `ukhemah` ialah **pelan** (satu baris = satu gerai), bukan pendaftaran.
- Lajur lama `category` = jenis perniagaan, `bidang` = kategori kraf,
  `crafttype` = kod + saiz (pecah dari kanan).
- Zon lama **mesti** diterjemah (`LegacyZones`); nama tanpa pemetaan tidak
  disimpan mentah. Peranan admin **ditetapkan** daripada `fileuser`/`upengawai`.
- Jangan tambah lajur status dump (`sstatus`, `Protokol`) ke
  `event_registrations` — ia kekal dalam `raw`.
- Dump lama **tiada emel**: setiap akaun lama perlu emel diisi pejabat sebelum
  boleh log masuk (`legacy:accounts` melaporkan kiraannya).
- Dalam pembangunan, **hanya dump bertopeng**.

---

## Bila menyalin daripada `daftar`

1. `LotAllocator` dan `PlotAllocationAlgorithm` dalam `app/Services/`, dipanggil
   FQCN tanpa `use`.
2. `app/Exceptions/LotUnavailable.php` ialah mod kegagalan kongsi.
3. Komen membawa rujukan mati — jadikan ia berdiri sendiri.
4. Kod untuk data lama yang pangkalan data kosong tidak boleh ada — periksa.

---

## Ujian

```
vendor/pestphp/pest/bin/pest                                                # SQLite
DB_CONNECTION=mysql DB_DATABASE=undilot_test vendor/pestphp/pest/bin/pest   # MySQL
npx playwright test                                                         # undilot_e2e, melalui serve.js
```

Cerita di sebalik peraturan ini: **`docs/testing/e2e-notes.md`**.

- **Jangan** halakan Pest ke `undilot` — `RefreshDatabase` mengosongkannya.
- Nilai dari lajur `json` MySQL: guna `toEqual`, bukan `toBe` (kunci disusun
  semula).
- Jangan jalankan Playwright terhadap pelayan yang dihidupkan dengan tangan;
  `serve.js` memiliki persekitaran, port dan yatimnya.
- **Satu larian hijau bukan bukti** pada suite pelayar — baca kiraan `flaky`,
  jalankan lebih sekali. Bila goyah, jalankan melalui `tests/e2e/probe.ps1`.
- Dialog: guna `acceptDialog()` dari `tests/e2e/helpers.js`, bukan
  `page.once('dialog', …)`.
- `RefreshDatabase` pada `Feature`, bukan `Regression`.
- Ujian yang mencampur guard: `actingAs($user, 'web')` dan
  `auth()->forgetGuards()`.
- **`not->toContain('x', 'mesej')` sentiasa lulus** — `toContain` variadic,
  argumen kedua ialah needle. Untuk mesej, bandingkan sendiri dan letak mesej
  pada `toBeTrue`.
