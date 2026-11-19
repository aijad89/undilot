# CLAUDE.md

Panduan untuk sesiapa — manusia atau agen — yang bekerja dalam repo ini.

Fail ini memegang **peraturan dan keputusan sahaja**: perkara yang tidak boleh
diterbitkan semula dengan membaca kod.

---

## Apa ini

**undilot** — sistem cabutan lot untuk acara usahawan kraf. Laravel 12, PHP
`^8.4`, MySQL pada dev dan produksi, SQLite dalam-memori untuk ujian.

Ia dipisahkan daripada **`daftar`** (`f:\herd\daftar`) pada 2026-10-13. `daftar`
membawa 285 route, 156 migration dan 62 entri backlog, dan backlog itu tertumpu
pada modul yang bukan produk. undilot membawa cabutan lot dan apa yang cabutan
perlukan.

**`daftar` kekal sebagai arkib dan rujukan.** Apa pun yang ditinggalkan masih ada
di sana untuk disalin bila diperlukan. Jangan tulis semula sesuatu yang boleh
disalin.

---

## Persekitaran tempatan

| | |
|---|---|
| Laluan | `f:\herd\undilotlook` |
| URL | `http://undilotlook.test` (Herd) |
| DB | `undilotlook` pada `127.0.0.1` — sendiri, tidak dikongsi dengan undilot |
| PHP CLI | `/c/Users/ALOY/.config/herd/bin/php84.bat` |
| MySQL CLI | `/c/Program Files/MySQL/MySQL Server 9.5/bin/mysql.exe` |
| Admin pertama | dari `.env` — `ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD` |
| Log masuk pantas | `http://undilotlook.test/auto` — sebarang akaun, tanpa kata laluan, plus butang *Work the queue* |
| DB suite pelayar | `undilotlook_e2e`, dicipta dan ditetapkan semula oleh `tests/e2e/serve.js` |
| Git | `https://github.com/aijad89/undilotlook` (peribadi), cawangan `main` |

`artisan tinker <fail>` **tergantung** pada mesin ini. Guna klien MySQL untuk
menanya pangkalan data, atau skrip PHP yang memuat `bootstrap/app.php`.

Setiap notifikasi berbaris (queue). Pada mesin ini tiada pekerja berjalan, jadi
pengumuman dan peringatan kekal dalam `jobs` sehingga butang pada `/auto` ditekan.

**Jerangkap shell pada mesin ini:** `php` tiada dalam PATH bash — guna
`php84.bat` di atas (Node dengan `shell: true` menemuinya). Heredoc bash yang
mengandungi apostrof gagal dalam alat Bash; tulis skrip ke fail dahulu.

---

## Peraturan

### Satu baseline migration, dan ia kekal satu

`database/migrations/0001_01_02_000000_create_undilot_schema.php` memegang
keseluruhan skema aplikasi. Selagi belum ada data sebenar, **ubah fail itu dan
jalankan `migrate:fresh`** — jangan tambah migration berperingkat.

Itu berhenti pada hari pejabat memasukkan data sebenar. Selepas itu, migration
baharu seperti biasa.

### Tiada lajur `enum`, selamanya

Perbendaharaan status tinggal dalam const model (`Event::STATUSES`,
`Event::POLLING_STATUSES`), bukan dalam jenis lajur.

Sebabnya diukur: enum adalah serupa pada MySQL dan SQLite sehingga seseorang
guna `ALTER TABLE … MODIFY COLUMN`, yang **SQLite langkau senyap**. Dalam
`daftar` penyimpangan itu tidak disedari selama lapan bulan — sehingga tiada
ujian boleh menetapkan `polling_status = 'closed'`, iaitu keadaan yang modul
cabutan itu sendiri mengenainya.

`tests/Feature/SchemaHoldsTheSameShapeOnBothDriversTest.php` mengunci ini.

### Setiap migration dijalankan pada **dua** pemacu sebelum dipercayai

```
php artisan migrate:fresh --force                 # MySQL, dari .env
vendor/pestphp/pest/bin/pest                      # SQLite dalam-memori
```

### Nav layout ialah senarai, bukan markup

`resources/views/layouts/admin.blade.php` dan `partials/portal-bar.blade.php`
(bar peserta, dikongsi portal dan halaman awam) masing-masing mengisytiharkan
`$nav` / `$apNav`. **Tambah modul =
satu entri array.**

Jangan kembali kepada mengeja `route()` dalam markup. `daftar` ada 22 dalam satu
layout, dan satu route yang dibuang di mana-mana membunuh **setiap** skrin admin
sekali gus, dengan trace yang menyalahkan layout.

### Factory menamakan setiap lajur, termasuk yang nullable

`Model::preventAccessingMissingAttributes()` hidup di luar produksi, dan
`actingAs($user)` menyerahkan view **instance factory itu sendiri**. Lajur yang
factory tidak namakan tidak hadir pada instance itu — jadi view campak
`MissingAttributeException` di bawah `actingAs` sambil berfungsi sempurna
selepas log masuk sebenar. Kegagalan itu kelihatan seperti view yang rosak.

`null` ialah nilai. Namakan lajur itu.

### Tiada kata laluan lalai dalam kod

`AdminSeeder` menolak berjalan tanpa `ADMIN_SEED_PASSWORD` dalam `.env`.
**Jangan beri ia fallback.** Kata laluan ber-fallback dalam fail yang dijejaki
ialah kata laluan dalam sejarah git pada hari ia ditulis.

### Semua `.md` dijejaki

Tiada peraturan `.gitignore` mengecualikannya. Keputusan ialah bahagian yang
paling sukar dipulihkan.

### Data rujukan disemai, data demo tidak

`DatabaseSeeder` memanggil `AdminSeeder` dan `KrafCodeSeeder` (helaian pengekodan
HKK 2025 + lima kategori) — tanpanya pemasangan baharu tidak boleh memberi kod
kepada sesiapa. Kedua-duanya boleh dijalankan semula. Data demo, jika ada, pergi
**di bawah** pengawal `isProduction()`. `E2eSeeder` hanya berjalan dalam `e2e`.

### `/auto` tidak pernah wujud di pelayan

Ia log masuk sebagai sesiapa tanpa kata laluan. Didaftar hanya dalam `local`,
`testing` dan `e2e`, dan pengawal yang sama dalam controller. **Jangan** bawa
pintu ber-flag `daftar` (`DEV_AUTO_LOGIN` + senarai IP) — itu keputusan.

### Aplikasi mesti kekal selamat-subpath

Ia mungkin dihidang di `/undilotnew`, bukan akar domain. Setiap pautan dan ubah
hala melalui `route()` / `url()` — **tiada** `redirect('/')` atau `href="/…"`.
`/` ialah senarai acara awam, jadi destinasi selepas log masuk mesti dinamakan
(`dashboard`, `admin.dashboard`).

Diukur: `redirect('/laluan')` **sebenarnya selamat-subpath** — Laravel
menyelesaikannya terhadap akar permintaan (`/undilotnew/laluan`). Bahaya
`redirect('/')` ialah **destinasinya** (senarai awam), bukan ia terlepas. Yang
benar-benar terlepas ialah `href="/…"`, string laluan dalam skrip sebaris, dan
URL relatif-akar daripada cakera. `EveryLinkSurvivesASubpathInstallTest`
melekapkan aplikasi di `/undilotnew` dan menyapu setiap skrin untuk keempat-empat
peranan.

### Setiap peraturan baharu dibuktikan menggigit

Selepas ujian lulus, buang peraturan itu sementara dan pastikan ujian **gagal**,
kemudian pulihkan. Beberapa ujian dalam repo ini lulus atas sebab yang salah
sehingga diperiksa begini (pengawal yang dilangkau oleh ralat lain, mutasi yang
dilindungi laluan lain).

---

## Seni bina dan jerangkap

### Dua guard

`web` (`App\Models\User`) dan `admin` (`App\Models\Admin`). Ini keputusan paling
membentuk dalam aplikasi, dan hampir semua kejanggalan auth berpunca daripadanya:
**admin ialah tetamu pada guard `web`**, dan peserta ialah tetamu pada `admin`.

`bootstrap/app.php` menjawabnya dengan dua closure. Pecahkan salah satu dan
kegagalannya bukan 404 — ia gelung redirect, atau admin yang sudah log masuk
merenung borang log masuk **peserta**.

`tests/Feature/BothGuardsSignInTest.php` menahan kedua-duanya.

### Tiga peranan admin

`admin` (berskop satu negeri), `hq`, `super`. Perbendaharaan dan siapa boleh
memberi apa ada dalam `Admin::ROLES` dan `Admin::assignableRolesFor()`.

`assignableRolesFor()` **diterbitkan daripada pemanggil**, bukan senarai tetap.
Peraturan `Rule::in(array_keys(Admin::ROLES))` tidak peduli siapa bertanya —
tidak berbahaya selagi skrin itu super-sahaja, dan membenarkan hq mencetak akaun
super bagi dirinya sendiri sebaik skrin itu diluaskan.

Skrin akaun admin ialah `Admin\AdminController`, dalam dua senarai di bawah **User** pada sidebar: **HQ Admins** (hq + super,
super sahaja) dan **State Admins** (super dan hq). Gate `manage-admins` membuka
skrin; di dalamnya **dua soalan berasingan**, dan kedua-duanya perlu:
`authorizeTarget()` (hq hanya menyentuh admin negeri — bukan super, bukan rakan
hq) dan `authorizeRole()` (`assignableRolesFor()`). Menyekat super sahaja tidak
cukup: hq masih boleh menurunkan pangkat hq lain.

### Skop negeri melalui `EventVisibility`, bukan middleware

Tiada alias middleware yang kelihatan seperti pengawal negeri. Pengawal pada
setiap route admin **membaca** seperti penguatkuasaan dan tidak menguatkuasakan
apa-apa. `TrackAdminLastSeen` ialah penjejakan kehadiran, dan namanya berkata
begitu.

`EventVisibility` memegang **satu** peraturan: acara draf hanya untuk super/hq.
**Tiada peraturan *own-state*** — `daftar` menyembunyikan acara daripada admin
negeri sehingga syarikat negerinya berada dalam cabutan, yang betul bila peserta
mendaftar sendiri. Di sini pejabat yang mengisi senarai cabutan; peraturan itu
akan menyembunyikan setiap acara kosong daripada orang yang perlu mengisinya.
Baris **di dalam** acara tetap ditapis mengikut zon.

### Tulis digate di route, baca menskop sendiri

Tindakan tulis berada di bawah `can:` dalam `routes/web.php` (`manage-events`,
`manage-settings`, `manage-site-content`, `send-announcements`,
`read-audit-trail`, `verify-draw-list`), ditakrif dalam `AppServiceProvider`. Skrin
baca terbuka kepada semua peranan dan menapis sendiri. Pautan nav untuk skrin
bergate disorok dengan `when` yang bertanya soalan yang sama.

Satu pengecualian: route senarai cabutan melayan admin negeri juga (lulus,
tambah, sunting, buang), jadi `EntRegistrationController` bertanya
`verify-draw-list` per permintaan untuk tahu sama ada tulisan itu juga
pengesahan HQ. Hanya `return` membawa gate itu di route.

### `kraf_bidangs` memegang **dua** senarai

| `code` | Apa ia | Diurus di |
|---|---|---|
| `NULL` | kategori kraf (TEKSTIL, HASIL RIMBA…) | Craft Hierarchy |
| berisi | kod kraf (TBPS, RKP…) dari helaian pengekodan | Craft Category Code |

Keduanya **tidak berkait**. Kod kraf datang daripada pensijilan lain, bukan
daripada hierarki; syarikat membawa kodnya sendiri dalam `companies.kraf_code`,
yang mencadangkan kod pada senarai cabutan.

**Setiap query mesti menyatakan yang mana** — `KrafBidang::categories()` atau
`::codes()`. Senarai yang terlupa memaparkan kedua-duanya; padam yang terlupa
membuang baris daripada senarai yang tiada siapa sedang lihat. Ini punca tiga
entri backlog dalam `daftar`.

Peruntukan pelan dan cat plot menerima **kod sahaja**. Plot yang ditanda dengan
kategori tidak boleh dipadankan oleh mana-mana pendaftaran; `daftar` menerimanya.

Padam baris kraf **ditolak** selagi ada apa-apa menunjuk kepadanya (setiap FK
hidup diperiksa), supaya cascade tidak mengambil apa-apa tanpa jejak audit.

Kategori kraf **tetap** — disemai, tidak ditambah atau dipadam di skrin. Di bawah
sub-kategori ada **klasifikasi** dan **produk**, kedua-duanya pilihan, dan
syarikat menandanya per pilihan (`company_kraf_pengelasan`,
`company_kraf_produk`). `kraf_produks` membawa `sub_bidang_id` **dan**
`bidang_id`: `bidang_id` dikekalkan selari kerana kod lama
masih membacanya, dan produk dengan `sub_bidang_id` NULL ialah baris lama yang
"belum diletakkan" — tidak boleh ditanda oleh syarikat.

### Pemilikan ialah `company_id`, bukan `user_id`

`User::registrations()` ialah `hasManyThrough` melalui `Company`. Lajur
`company_id` ialah `NOT NULL` dan ber-foreign-key; `user_id` nullable dan tiada
apa dalam skema menghalang null tiba.

`user_id` **tetap ditulis dan dibaca** — oleh `plots.allocated_to`, `polls`, dan
peruntukan awal. **Dua kunci, dua soalan, dengan sengaja.**

### Dua semakan: baris ialah kelulusan zon, `verified_at` ialah HQ

Tiada lajur `status` pada `event_registrations`. **Admin negeri menyemak
semua** — butiran syarikat, bukti bayaran, kod kraf, saiz gerai — dan
meletakkannya ke senarai cabutan. Baris itu menunggu HQ.

**HQ menyemak semula** (gate `verify-draw-list`): sahkan (boleh membetulkan kod
atau saiz dalam langkah yang sama), atau pulangkan kepada zon dengan nota
(`hq_note`). Zon membetulkan, baris menunggu HQ lagi.

**HQ boleh memintas zon, dengan sengaja.** Permintaan yang HQ luluskan atau
syarikat yang HQ tambah terus disahkan — tiada semakan zon. Itu keputusan, bukan
lubang; jangan "betulkan".

- Permintaan sertai kekal `pending` dan peserta **tidak diberitahu** sehingga
  HQ mengesahkan. Bacaan portal menggunakan skop `announced()`
  (`first_verified_at`), **bukan** `verified_at`: syarikat yang sudah
  diberitahu tidak hilang dari portalnya sendiri bila zon menyunting dan HQ
  menyemak semula.
- `PollingGuard` menahan cabutan selagi ada baris belum disahkan.
- Perubahan kod atau saiz oleh admin negeri — dari mana-mana pintu, termasuk
  halaman syarikat melalui `syncBoothSize()` — **mengosongkan** `verified_at`.
  Ini satu tempat: `EventRegistration::booted()`.
- Syarikat yang ditambah pejabat tiada permintaan, jadi tiada bukti bayaran:
  zon menulis `payment_note` (no. resit, tarikh, cara). Mengubahnya juga
  mengosongkan `verified_at`. Tidak dibekukan bila cabutan bermula.
- Kod dan saiz **dibekukan** sebaik `polling_status` bukan `draft`, untuk HQ
  juga — jika tidak, suntingan zon mengosongkan pengesahan dan syarikat hilang
  dari papan pemuka pesertanya sendiri di tengah cabutan.
- Admin negeri hanya membuang baris yang **belum** diberitahu kepada syarikat
  (`first_verified_at` null). Mengeluarkan syarikat daripada cabutan yang ia
  sudah diberitahu ialah keputusan HQ (`zoneMayRemove()`). HQ mesti memberi
  sebab; syarikat menerima `RemovedFromDraw` dan sebab itu disimpan pada
  `event_join_requests.removed_reason` (baris pendaftaran sudah tiada).
- Sebaik cabutan bermula, admin negeri **tidak menambah atau meluluskan**
  sesiapa (`zoneLocked()`); hanya HQ, dan baris HQ disahkan serta-merta. Tiada
  baris menunggu HQ boleh wujud semasa cabutan berjalan. **Undo juga menambah
  baris**, jadi ia ditolak untuk semua orang sebaik cabutan bermula — ia
  memulihkan baris seperti asal (mungkin masih menunggu HQ) dan tanpa
  pemberitahuan kepada syarikat yang sudah menerima `RemovedFromDraw`. Ditemui
  oleh ujian 2026-11-15; sebelum itu undo tiada pengawal ini.
- **Unpublished (`closed`) bukan "dibatalkan".** `Event::isCalledOff()` hanya
  cabutan `cancelled` atau acara kembali ke `draft`. auto_close menyahterbit
  acara sendiri bila pendaftaran tutup, sementara permintaan yang dihantar
  sebelum itu masih di pejabat — ia mesti boleh dijawab.
- Penolakan zon terus kepada peserta; HQ melihatnya selepas itu (papan pemuka
  7 hari, dan "Rejected by zones" pada senarai cabutan) — `rejectedByZone()`.
- `first_verified_at` tidak pernah dikosongkan; ia membezakan semakan semula
  daripada yang pertama, supaya peserta diberitahu sekali sahaja.

### Baris `plot_sizes` ialah pasangan (lokasi, saiz)

Borang tidak menaip pasangan itu terus: satu baris pada borang ialah **lokasi**
dengan kiraan, dan setiap peruntukan kraf yang bersarang di bawahnya menamakan
**saiznya sendiri**. `PollingController::savePlan()` menyelesaikannya kembali
kepada baris, memadan pada (lokasi, saiz) supaya pasangan yang terselamat
mengekalkan id-nya. `total_plots` dikira di sana, tidak pernah diambil daripada
borang.

Saiz gerai sebuah pendaftaran ialah `event_registrations.plot_size` — satu
**string**, lalai `3x3`. Bukan rujukan `plot_sizes`: pejabat menyatakannya
sebelum sebarang pelan wujud, kerana pelan dibina daripada nombor itu.

**Setiap lokasi menamakan saiz plot lebihannya** (`plot_sizes.*.size`, wajib).
Lot hanya ditarik pada saiz gerai pendaftaran itu sendiri, jadi plot tanpa saiz
tidak pernah boleh dimenangi. Borang `daftar` tidak pernah menghantar medan itu,
dan setiap plot lebihan yang dibinanya mati.

`LotAllocator` ialah satu-satunya tempat peraturan lot. Confirm peserta dan larian
pukal admin kedua-duanya memanggilnya; jangan beri mana-mana pintu salinan
peraturannya sendiri. `PollingGuard` begitu juga untuk "boleh dibuka?" — butang
dan scheduler bertanya soalan yang sama.

### `event_registrations.code` ialah string, bukan foreign key

Ia memegang kod kraf (TBPS, RKP) dan dipadankan terhadap `code` pada baris
`kraf_bidangs` yang plot dikategorikan dengannya. Itu ketidakpadanan
string-lawan-FK, dan ia **sengaja dibiarkan**: itulah yang pengalokasi
bandingkan, dan mengubahnya bermakna menulis semula pengalokasi dan bukan
membawanya masuk utuh.

Pengesahannya juga **sengaja longgar** (`'string', 'max:50'`, bukan
`Rule::in` helaian pengekodan): kod berubah dari semasa ke semasa, dan baris
yang membawa kod yang sudah ditarik balik atau dinamakan semula mesti masih
boleh disimpan. Dropdown hanya menawarkan kod aktif; pelayan tidak menolak yang
lain. Keputusan pemilik (2026-11-15) — jangan ketatkan.

**Menamakan semula kod membawa barisnya**, seperti `AdminZones::rename()`.
Kod dipegang dua cara: plot dan preset melalui `bidang_id` (ikut sendiri), dan
`event_registrations.code`, `companies.kraf_code`, `polls.code`,
`preset_allocations.code` sebagai teks. Sebelum ini rename hanya menggerakkan
separuh kunci — diukur: TBPS → TBPX meninggalkan setiap syarikat TBPS tanpa plot
yang boleh dimenangi, tanpa ralat. `SettingController::updateKrafCode()` kini
mengemas kini keempat-empat lajur teks dalam satu transaksi, menulis baris audit
`kraf_code_renamed`, dan menolak rename selagi cabutan yang menggunakannya
sedang dibuka. `plots.plot_code` (label gerai bercetak) tidak disentuh. Lajur
teks kod baharu **mesti** ditambah ke senarai itu.

### `event_registrations.plot_id` tiada klausa `ON DELETE`

Jadi pangkalan data menyekat. Plot tidak boleh dipadam selagi satu entri cabutan
masih menunjuk kepadanya — keputusan cabutan **tidak boleh** hilang kerana
seseorang menyunting pelan.

Pelan **dibekukan** sebaik satu lot dipegang, preset termasuk
(`PollingController::planIsFrozen()`): sunting, kosongkan dan cat semuanya
ditolak. Jadi tiada penulisan semula yang perlu mengosongkan `plot_id` — cabang
"pulihkan preset" `daftar` tidak pernah boleh berjalan, dan tidak dibawa.

### Audit cabutan sebahagiannya ditulis dengan tangan

Trait `Auditable` menggunakan Eloquent model event, dan **mass delete tidak
mencetuskan satu pun**. `PollingController` memasukkan barisnya sendiri
(`plan_rewrite`, `plan_cleared`, `plots_generated`) untuk menampungnya, dan
`AuditTrail::getChangedFieldsAttribute()` ada cabang khas untuknya.

Begitu juga `ON DELETE CASCADE`: baris yang hilang melaluinya **tidak
meninggalkan satu pun baris audit**. Model yang boleh menyatakan jangkauannya
menutupnya dengan `auditDeletionSummary()`, mengira **sebelum** DELETE.

**Jangan ukur jangkauan cascade daripada fail migration.** Tanya pangkalan data
hidup:

```sql
select rc.REFERENCED_TABLE_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME, rc.DELETE_RULE
from information_schema.REFERENTIAL_CONSTRAINTS rc
join information_schema.KEY_COLUMN_USAGE kcu
  on kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 and kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
where rc.CONSTRAINT_SCHEMA = 'undilotlook' and rc.DELETE_RULE = 'CASCADE';
```

### Tiada langkah bina

Tiada Vite, tiada npm build, tiada Alpine. Setiap stylesheet ialah inline dalam
partial Blade; setiap pustaka datang dari CDN dengan versi dipin. `npm` hanya
untuk Playwright. **Node tidak diperlukan pada produksi.**

### Dua design system, dua prefix

`.mac-` (admin, amber) dan `.ap-` (portal peserta, biru). Tiada halaman sah
menggunakan kedua-duanya. View auth membawa rupa tersendiri — jangan kenakan
kelas `mac`/`ap` padanya.

### Zon waktu ialah `Asia/Kuala_Lumpur`, dalam `config/app.php`

Admin menaip tetingkap cabutan dalam waktu tempatan dan ia disimpan seperti
ditaip. `now()` mesti pada jam yang sama, atau scheduler membuka cabutan 09:00
pada 17:00 — rangka Laravel datang dengan UTC, dan undilot berjalan begitu
sehingga larian pelayar pertama menunjukkan "Confirmed On" lapan jam awal.

### `SESSION_DRIVER` mesti `database`

`NewPasswordController::logOutOtherDevices()` mengusir dengan memadam baris
daripada jadual `sessions`. Pada pemacu lain ia memadan **tiada apa**: reset
melaporkan berjaya dan peranti lain kekal log masuk. Tiada ralat, tiada ujian
gagal — hanya satu `Log::warning`.

### Fail muat naik yang dikawal tinggal di cakera `local`

Sepanduk dan lampiran pengumuman disimpan di cakera `local` dan dihidang melalui
controller yang bertanya siapa yang dicapai (`announcements.file`). Hanya imej
yang tidak rahsia (sepanduk acara, ikon sosial) ke cakera `public`. **Tiada SVG
ke cakera `public`** — pada origin ini ia boleh membawa skrip.

**`php artisan storage:link` ialah langkah deploy, dan ia senyap bila
terlupa.** `public/storage` ialah symlink dan diabaikan git, jadi ia tidak
datang bersama repo. Tanpanya `/storage/…` jatuh ke Laravel dan bukan ke
pelayan web — dan sepanduk **hilang hanya di pelayan**, kerana nginx Herd
menghidang URI itu sebagai fail statik pada mesin pembangunan. Diukur: header
`ETag`/`Accept-Ranges` tanpa header PHP untuk fail yang ada, 403 untuk yang
tiada.

**Sebut cakera `public` pada nama bila menjana URL sepanduk.** `Storage::url()`
tanpa nama menanya cakera **lalai**, iaitu `local` (`.env`), direktori lain yang
tiada sepanduk. Ia nampak betul kerana kedua-dua cakera menjawab di `/storage`
— tetapi cakera `local` memulangkan `/storage/…` **relatif akar**, yang
menunjuk ke akar domain dan memusnahkan pemasangan subpath. Cakera `public`
membina atas `APP_URL`.

**`'serve'` pada cakera `local` ialah `false`, dan Laravel menghantarnya
`true`.** Dihidupkan, ia mendaftar dua route di `/storage/{path}` — GET membaca
`storage/app/private` dan PUT **menulis** ke sana. Kedua-duanya menuntut URL
bertandatangan dengan `APP_KEY`, jadi bukan lubang; tetapi aplikasi ini tidak
pernah menyentuh cakera `local` langsung, dan GET itu menaungi URI sepanduk.

### Laman awam: `Event::PUBLIC_STATUSES`

Hanya `open` (Published) dan `completed` (Ended) dipaparkan kepada awam dan
peserta. `closed` (Unpublished) disorok dari senarai untuk semua; halamannya
404 kecuali kepada admin yang log masuk, dengan sepanduk (K10, keputusan
pemilik 2026-11-16). Papan pemuka, halaman lot dan slip peserta **tidak**
menyemak status terbit — peserta dalam cabutan tidak boleh terkunci daripadanya.

**Awas:** penjadual menukar `open` → `closed` bila `registration_close_at` lepas
jika `auto_close` hidup — acara itu kini hilang dari laman awam pada saat itu.

### IC pada slip ialah suis, `SLIP_SHOWS_IC`

Lalai **on** — IC dicetak (keputusan pemilik 2026-11-16). Suis
(`config('app.slip_shows_ic')`) dikekalkan kerana menyorokkannya pernah
dipertimbangkan pada hari yang sama: slip dikongsi dari telefon, dan QR sahaja
sudah mengenal pasti pemegang. `SLIP_SHOWS_IC=false` menyorokkannya.

### `$unreadNotificationCount` disediakan oleh senarai view, bukan `*`

View composer dalam `AppServiceProvider` menamakan setiap view yang membacanya.
View baharu yang membacanya **mesti** ditambah pada senarai itu, atau ia 500.

---

## Bila menyalin daripada `daftar`

Empat perkara menggigit setiap kali:

1. **`LotAllocator` dan `PlotAllocationAlgorithm` ada dalam `app/Services/`**,
   bukan `app/Support/`, dan kedua-duanya dipanggil FQCN *inline* tanpa `use`.
   Grep untuk `use App\Support\LotAllocator` tidak menjumpai apa-apa.
2. **`app/Exceptions/LotUnavailable.php`** ialah mod kegagalan kongsi kedua-dua
   pengalokasi, dan tiada dalam mana-mana senarai `Support/`.
3. **Komen membawa rujukan mati** — "backlog entry 17", "office question 15b",
   dan beberapa ayat yang berakhir dengan "See" tanpa apa-apa selepasnya. Jadikan
   ia berdiri sendiri; rujukan yang tidak membawa ke mana-mana lebih buruk
   daripada tiada rujukan.
4. **Kod mati untuk data lama.** `daftar` membawa cabang untuk rekod yang
   mendahului sesuatu migration. Pangkalan data kosong tidak boleh mengandungi
   satu pun. Periksa sebelum menyalin.

---

## Ujian

```
vendor/pestphp/pest/bin/pest              # SQLite dalam-memori
DB_CONNECTION=mysql DB_DATABASE=undilotlook_test vendor/pestphp/pest/bin/pest   # MySQL, pangkalan data sendiri
npx playwright test                        # MySQL undilotlook_e2e, melalui tests/e2e/serve.js
```

### Suite Pest pada MySQL, dan apa yang ia jumpa

Pemboleh ubah persekitaran menang atas `phpunit.xml`, jadi baris kedua di atas
menghalakan suite yang sama ke `undilotlook_test` (cipta sekali,
`utf8mb4_unicode_ci`). **Jangan** halakan ia ke `undilotlook`:
`RefreshDatabase` mengosongkan apa sahaja yang ditunjuk.

Larian pertama (2026-11-15) gagal empat ujian yang lulus pada SQLite, dan tiada
satu pun pepijat aplikasi:

- **Lajur `json` MySQL menyusun semula kunci** (ikut panjang, kemudian abjad).
  `toBe` pada tatasusunan bersekutu yang dibaca dari `audit_trails.old_values`
  / `new_values` gagal atas susunan sahaja. Guna **`toEqual`** untuk nilai dari
  lajur `json`.
- Ujian "refuses to delete a plot" hanya membaca `pragma foreign_keys` — tidak
  pernah cuba memadam, dan ralat sintaks pada MySQL. Kini ia benar-benar cuba,
  pada kedua-dua pemacu, dan disahkan gagal bila `plot_id` diberi
  `nullOnDelete()`.

Satu ujian dilangkau pada MySQL dengan sengaja (membaca `sqlite_master`).

### Suite pelayar memiliki persekitarannya sendiri

`tests/e2e/serve.js` menulis `.env.e2e` (dari `.env` + `tests/e2e/env.js`),
menetapkan semula `undilotlook_e2e`, dan menghidupkan pelayan **serta** pekerja queue.
Jangan jalankan suite terhadap pelayan yang dihidupkan dengan tangan.

`.env.e2e` itu **fail**, bukan pemboleh ubah proses, dengan sebab yang diukur:
`artisan serve` hanya meneruskan senarai pemboleh ubah tertentu kepada anaknya.
`APP_ENV` lepas, `DB_DATABASE` tidak — larian pertama menghidang pangkalan data
**dev**. `fixtures/run.php` menolak sebarang pangkalan data yang bukan `*_e2e`.

### Port berputar setiap larian, dan itu pembetulan bukan hiasan

Pada port tetap suite ini **berhenti bermula langsung** pada Windows, dan
ralatnya menyalahkan benda yang salah: `Timed out waiting 180000ms from
config.webServer`, sedangkan pelayan di atasnya sudah mencetak *Server running*.

Diukur: **1,442 soket TIME_WAIT pada port 8124 sahaja**. Setiap poll kesediaan
membuka sambungan baharu, Windows menahan setiap satu beberapa minit, dan
larian menimbun pada port yang sama. Sebaik 4-tuple satu poll berlanggar dengan
yang berlegar, SYN itu **digugurkan** dan bukan ditolak — jadi permintaan
tergantung dan bukan gagal, dan semakan kesediaan Playwright tiada tamat masa
per-permintaan, jadi satu poll tergantung memakan kesemua 180 saat.

**Apa yang TIDAK terbukti:** kiraan TIME_WAIT seluruh mesin pernah mencecah
16,735 berbanding julat ephemeral 16,384, dan pada ketika itu sambungan keluar
masih berjaya (3/3 HTTP 200 di bawah 150ms). Jadi ini perlanggaran
**per-port**, bukan kolam yang habis. Jangan tulis cerita kehabisan kolam di
sini tanpa mengukurnya semula.

Diukur semula 2026-11-15 dengan `tests/e2e/probe.ps1`, pada komit yang pernah
gagal 8–9 spek berturut-turut: enam larian (tiga berjarak, tiga berturut),
**kesemuanya 21/21, sifar flaky**. Satu larian menambah ~350 TIME_WAIT; puncak
terburuk memegang **560 port ephemeral daripada 16,384**. Suite ini tidak boleh
menghasilkan 18,643 TIME_WAIT yang pernah dilihat — sumbernya **tidak
diketahui**, kerana tiada siapa merekod pecahannya ketika itu. Jadi
`netsh int ipv4 set dynamicport` tiada asas. Bila kegoyahan kembali, jalankan
suite **melalui** `probe.ps1`, supaya bukti diambil semasa kegagalan, bukan
selepasnya.

Tandanya ada dalam `DEBUG=pw:webserver`: poll awal menjawab `ECONNREFUSED`
dalam milisaat, kemudian satu baris `HTTP GET` duduk sendirian selama tiga
minit. Itu SYN digugurkan, bukan pelayan perlahan.

`playwright.config.js` kini memilih port dalam julat 8200–8799 dan
**menulisnya semula ke `process.env`**. Itu bahagian yang menggigit: config
dinilai semula dalam setiap proses pekerja, dan pekerja ialah anak dengan pid
sendiri — menerbitkan port daripada pid sahaja memberi pekerja port **berbeza**
daripada pelayan, dan setiap spek gagal dengan `net::ERR_ABORTED` terhadap port
yang tiada sesiapa mendengar. `PLAYWRIGHT_PORT` masih menang bila ditetapkan.

### Yatim daripada larian terganggu ialah enjin sebenar

`serve.js` **menyapu yatim sebelum ia bermula**, dan itu bukan kebersihan — ia
pembetulan. Bila larian terganggu keras (tamat masa pembungkus, Playwright
terhempas, terminal ditutup) tiada isyarat tiba, dan pada Windows membunuh node
tidak pernah mencapai cucu yang `shell: true` cipta. `artisan serve` dan
`queue:work` terus hidup.

Pekerja yang dilupakan **bukan melahu**: `--sleep=1` bermakna ia menyoal MySQL
sekali **sesaat**, setiap satu sambungan baharu. Diukur: 4,328 TIME_WAIT ke
`127.0.0.1:3306` daripada yatim, selain 1,442 pada port tetap itu sendiri.

Dua perkara yang menggigit semasa membinanya:

- **`child.pid` ialah pid SHELL, bukan PHP.** Merekod pid ke fail tidak
  berguna: shell mati, PHP hidup dengan pid yang tiada siapa catat. Sapuan
  memadan **baris arahan**, bukan pid.
- **Laluan Windows tidak peka huruf besar; padanan string peka.** Node
  menyelesaikan `F:\herd\undilotlook` sedangkan senarai proses mengeja
  `F:\Herd\undilotlook`, jadi `includes()` memadan tiada apa dan sapuan
  mengambil satu proses daripada tiga. Dibandingkan huruf kecil sekarang.
- **`artisan serve` ialah penyelia.** Bunuh hanya router di bawahnya dan ia
  memulakan yang baharu. Induk dicapai melalui anak, bukan dipadan terus —
  argumen penyelia tidak menamakan projek, dan pembangun mungkin menjalankan
  satu sendiri.

### `--no-reload` + `PHP_CLI_SERVER_WORKERS`, bersama atau tiada langsung

Tanpa `--no-reload`, artisan mengabaikan `PHP_CLI_SERVER_WORKERS` dan melayan
**satu permintaan pada satu masa** — ia mencetak amaran dan teruskan. Satu
permintaan pada satu masa salah untuk suite ini: spec mengklik sambil pekerja
queue bercakap dengan MySQL, dan permintaan beratur di belakang satu sama lain
sehingga `page.check` dan `page.selectOption` mencecah had 30s Playwright tanpa
apa-apa sebenarnya rosak.

Diukur sebelum dan selepas, lima larian penuh setiap satu:

| | sebelum | selepas |
|---|---|---|
| larian hijau penuh | 3 / 5 | **4 / 5** |
| masa larian berturut | merosot 40s → 1.8m | **tetap ~38s** |

Memuat semula tiada nilai di sini: pelayan dihidupkan segar setiap larian dan
kod tidak boleh berubah di bawahnya.

**Tetap baca kiraan `flaky`.** Satu larian hijau masih bukan bukti.

Log masuk dalam spec melalui `/auto` (berdaftar dalam `local`, `testing`, `e2e`
sahaja) — tiada kata laluan ditaip. `@playwright/test` dipin pada versi yang
sepadan dengan Chromium dalam cache; menaikkannya bermakna memuat turun pelayar.

Hanya MySQL boleh menguji dua transaksi bertindih. `03-concurrent-confirm`
membuktikan kunci pada `LotAllocator`; ia disahkan gagal bila
`lockForUpdate()` dibuang.

`RefreshDatabase` dikenakan pada `Feature` tetapi **bukan** `Regression`. Ujian
yang perlu menanam keadaan yang skema larang — `plot_id` tergantung, dengan
menogol `PRAGMA foreign_keys` — tidak boleh berbuat demikian di dalam transaksi.

**Satu larian hijau bukan bukti pada suite pelayar.** Ini dipelajari dengan mahal
dalam `daftar`: empat larian penuh pada kod yang sama memberi empat set kegagalan
berbeza. `playwright.config.js` membawa `retries: 1` dan
`trace: 'on-first-retry'` — **baca kiraan `flaky`**, bukan kiraan `passed`, dan
jalankan lebih daripada sekali sebelum mempercayai keputusan.

Jangan tulis `page.once('dialog', d => d.accept())`. Guna `acceptDialog()` dalam
`tests/e2e/helpers.js`, yang merekod jenis dan teks dialog; `strayAlerts()`
digagalkan dalam `afterEach`. `alert` yang diterima secara buta ialah tindakan
yang tidak berlaku, dan ujian gagal empat baris kemudian atas sesuatu yang tidak
berkaitan.

Dalam ujian Pest, `actingAs()` tanpa guard menggunakan guard **lalai**, yang
permintaan admin terdahulu dalam ujian yang sama mungkin telah tukar kepada
`admin`. Namakan guard (`actingAs($user, 'web')`) dalam ujian yang mencampur
kedua-duanya, dan `auth()->forgetGuards()` sebelum menguji akses orang lain.
