# Import data lama (`digitalCraft`)

Dipindahkan dari CLAUDE.md (2026-11-24) tanpa diubah. Ia hanya penting
semasa import dan cutover. Peraturan ringkasnya kekal dalam CLAUDE.md.

## Jadual `legacy_*` ialah pelantar pendaratan, bukan data aplikasi

`legacy_upengawai`, `legacy_usahawan` dan `legacy_ukhemah` memegang dump pejabat
**sebijik-sebijik**. Tiada FK, tiada unique, dan **setiap lajur data ialah string
nullable**.

String itu bukan kemalasan. Dump itu MySQL 4: tiada satu lajur pun nullable, jadi
"tiada nilai" ialah `''`; dan `lastlogin`, `votetime`, `votetimepay` diisytihar
`datetime NOT NULL` tanpa default, jadi "belum pernah" ialah
`0000-00-00 00:00:00`. MySQL 8 di sini berjalan dengan
`NO_ZERO_DATE,STRICT_TRANS_TABLES` dan **menolak kedua-duanya** — diukur:
ralat 1292 untuk zero-date pada lajur `datetime`, ralat 1366 untuk `''` pada
lajur `int`. Menaip lajur staging bermakna import mati pada baris pertama.

Nama lajur ialah **nama lama, dihurufkecilkan, tiada apa lagi** — itu sebabnya
`userright`, `loginname`, `sessionid` dan `protokol` kelihatan ganjil. Satu
peraturan bermakna pemuatan tidak perlu pemetaan per-lajur dan audit ialah diff.

`legacy_id` menyimpan `id` asal, kerana tanpanya pautan antara jadual lama tidak
boleh dibina semula (`ukhemah.usahawanid` → `usahawan.id`,
`usahawan.byuser` → `upengawai.id`). `source_table` menamakan jadual asal, dan
dump itu ada **lebih** daripada satu keluarga salinan: jadual `ukhemah*` (hidup,
snapshot setiap jam seperti `ukhemah_day1_2pm`, jadual per-acara bernama seperti
`ukhemah_fkj2024`, dan `ukhemah10` yang berhenti selepas enam lajur), **dan**
`usahawan*` yang ada salinan dalam gaya sama (`usahawan_19 feb 6pm`).
`event` **bukan** milik jadual hidup sahaja — `ukhemah_fkj2024` membawanya juga
(`'FKJ'`).

## `ukhemah` ialah pelan, bukan pendaftaran

Satu baris `ukhemah` ialah **satu gerai**: `boothid` (`KH01`, `TBA02`) berdiri di
lokasi yang `khemah` namakan (`Khemah A`, `Basement`, `PADANG AKASIA , ANGSANA
JOHOR BAHRU MALL`), dikategorikan ikut kraf, dengan `usahawanid` **kosong**
sehingga ada yang memegangnya. Itu jadual `plots` undilot dengan peruntukannya
sebaris. Transform membina **plot**, bukan entri cabutan, daripada sini.
`prio` ialah keutamaan gerai dan `protokol` (`'Ya'`) menandakan gerai simpanan.

## Tiga lajur kraf lama tidak bermaksud apa namanya kata

Ini sambungan langsung kepada amaran dua-senarai `kraf_bidangs`, dibaca
daripada baris sebenar:

| Lajur lama | Sebenarnya | Contoh |
|---|---|---|
| `bidang` | **kategori** kraf | `Tekstil`, `Hasil Rimba`, `Hasil Tanah`, `Aneka Kraf` |
| `crafttype` | **kod** kraf + saiz plot | `Batik-TBA-3x3`, `Buluh/Rotan-RBH-3x3` |
| `category` | **bukan kraf** — jenis perniagaan | `Pentahapan 2 (Pengilang/Pedagang,Pengeluar,Agensi)` |

Jadi lajur bernama `category` pergi ke `companies.business_type`, bukan
ke mana-mana senarai kraf. `bidang` ke kategori (`code` NULL), `crafttype` ke kod
(`code` berisi).

**`crafttype` ada dua era.** Data lama membawa nama kosong tanpa kod dan tanpa
saiz (`Songket`, `Tenun`, `Kayu(Hiasan Ukiran & Kegunaan)`); data baharu
melekatkan kod dan saiz (`Batik-TBA-3x3`). Pecah dari **kanan** — nama kraf
sendiri boleh mengandungi tanda baca. `boothid` mengulang kod itu sebagai prefiks
dalam data baharu (`TBA02` ← TBA), yang boleh digunakan untuk menyemak bacaan
pertama; `boothid` lama (`KH01`, `PS05`) tidak mengikut senarai kod.

`salu` ialah **kata panggilan** (`Puan`), bukan status.

## Setiap lajur setiap baris disimpan, dalam `raw`

**Keputusan pemilik (2026-11-19): pindah data lama tanpa mengorbankan apa-apa.**
Setiap baris staging membawa `raw` — baris sumber penuh sebagai JSON, nama lajur
asal, nilai tidak dipangkas dan tidak dibersihkan. Lajur bertaip di sebelahnya
hanya yang transform baca dan join. Lajur tanpa lajur bertaip sendiri
(`address`, `postcode`, `region`, `register`, `payment`, `bayarstatus`,
`protokol`, `sstatus`…) **masih ada** dalam `raw`.

Sebelum ini 21 lajur **dibuang** semasa muat, dengan alasan "fail dump ialah
arkib". Fail dump tidak bertahan lebih lama daripada ingatan sesiapa tentang di
mana ia disimpan. Sekarang **staging ialah arkib**.

Setiap baris staging dipaut kepada apa yang ia jadi: `legacy_upengawai.admin_id`,
`legacy_usahawan.user_id` / `company_id`, `legacy_ukhemah.plot_id`. Tiada FK,
supaya arkib bertahan selepas syarikat dipadam. Baris tanpa pautan ialah baris
yang tidak digunakan, dan `legacy:migrate` melaporkan setiap satu.

**Satu penukaran semasa membaca, tanpa kehilangan:** teks yang bukan UTF-8 sah
dibaca sebagai Windows-1252 (MySQL `latin1`, setiap jadual lama) dan ditukar.
Tanpanya `é` sebagai bait 0xE9 ditolak oleh lajur utf8mb4 dalam mod ketat dan
baris itu **hilang**. UTF-8 berganda (`Ã©`) kekal dalam staging; ia dibaiki
(`Legacy::repair()`) hanya semasa masuk ke lajur aplikasi.

## Empat fakta dump yang menentukan skop import

1. **`upengawai.UserRight` ada SATU nilai** — `Pengawai`, merentas 137 baris
   semua versi. Sistem lama **tiada peranan**, jadi `admin`/`hq`/`super`
   mesti **ditetapkan**, bukan dimigrasi.
2. **Tiada jadual acara.** `syatemconfiguration` cuma konfigurasi aplikasi lama
   (Clipsoft 2007). Identiti acara hanya ada pada **nama jadual**, plus tiga kod
   dalam lajur `event`: `AACB` (jadual hidup), `FKJ`, `HKK`. Tiada tajuk, tiada
   tarikh — semua yang `events` perlukan.
3. **21% peruntukan rosak.** 6,842 peruntukan gerai; **1,464** menunjuk kepada
   `usahawanid` yang tiada dalam mana-mana jadual `usahawan*`. `usahawan` ialah
   jadual kerja, bukan arkib: yang hidup ada id 204–381, baris 1–203 sudah
   dipadam. HKK 2022/2023/2024 dan FKJ 2023 kehilangan **semua** pesertanya.
4. **`crafttype` berkod hanya 11%** — 2,201 baris ada `-KOD-SAIZ`, 18,218 nama
   kosong tanpa kod dan tanpa saiz.

Skop (pemilik, 2026-11-19): **jadual hidup dan salinan akhir setiap acara**
menjadi acara sebenar — senarai dalam `App\Support\Legacy::EVENTS`. Salinan
setiap jam 2011/2012 kekal dalam staging sahaja. HKK 2011 tiada dalam senarai:
`ukhemah_hkk2011` tiada pasangan `usahawan` bernama sama, dan memadankannya
dengan teka boleh menyerahkan gerai seseorang kepada orang lain.

Fakta 3 masih benar untuk acara lama: gerai yang pemegangnya sudah tiada menjadi
**plot tidak diperuntukkan**, bukan dibuang, dan laporan mengiranya.

Satu amaran tentang pautan: semakan "id ini ada dalam **mana-mana** jadual
`usahawan*`" kuat untuk membuktikan ketiadaan, **lemah** untuk membuktikan
kehadiran — id ialah integer kecil yang diguna semula merentas jadual. Hanya
pasangan bernama sama (`ukhemah_day1_2pm` ↔ `usahawan_day1_2pm`) boleh
dipercayai.

## Dua arahan: `import:legacy` dan `legacy:accounts`

`import:legacy <fail.sql>` memuatkan dump ke tiga jadual staging dan **tidak
memutuskan apa-apa** — ia menyalin teks dan melaporkan apa yang dilihatnya.
`--dry-run`, `--append`, `--only=<prefiks>`. Pada dump penuh: 21,031 baris dalam
5.7 detik, sifar kegagalan.

`App\Support\LegacyDump` **memburu statement `insert`**, bukan memecah fail pada
`;`. Memecah pada `;` nampak betul dan tidak: export ini memotong setiap
`CREATE TABLE` di tengah definisi **tanpa koma bertitik**, jadi pembaca
`;` melekatkan CREATE yang rosak pada INSERT selepasnya dan baris itu hilang
senyap. Komen SQL antara statement buat perkara sama. Itu satu larian ujian
penuh untuk disedari.

`legacy:accounts` membina akaun daripada staging, dan **boleh berjalan di
pelayan** (`--fresh` sahaja ditolak dalam production).

- **Peranan dibaca daripada data lama** (pemilik, 2026-11-19): `fileuser`
  Administrator → super, DepartmentAdmin → hq, setiap pegawai `upengawai` →
  admin negeri zonnya sendiri (beberapa per zon dibenarkan). Akaun `fileuser`
  yang lepas `ExpireDate` diimport dimatikan. Pegawai pada zon yang tidak
  dikenali tiada akaun, dilaporkan, kekal dalam staging. Hanya hak Administrator /
  DepartmentAdmin dari `fileuser` dimuat — selebihnya pengguna sistem dokumen.
- **Satu orang, banyak baris lama.** Peserta yang sama muncul dalam jadual hidup,
  senarai induk dan salinan setiap acara. Mereka satu akaun, dikenal pasti oleh
  IC sebagai digit (`890508-12-0000` = `890508120000`), atau nama + syarikat bila
  tiada IC. Sumber terbaru memberi profil (`Legacy::participantSources()`);
  setiap baris lain dipaut. `comid` `'TIADA'` jadi null.
- **Medan profil lama dibawa**: Gelaran → `users.salutation`, Poskod →
  `postcode`, Daerah (`region`) → `district`, Alamat → `address`, No. Daftar →
  `companies.registration_no`. Disunting pejabat di borang syarikat admin,
  dan peserta di kad *Your account* profil (keputusan pemilik 2026-11-20).
- **Penanda ialah `legacy_id`** = `source_table:id` (`usahawan:204`,
  `upengawai:5`, `fileuser:1`), diisi hanya oleh arahan ini. `--fresh` memadam
  ikut lajur itu, bukan domain emel — sekali dahulu penanda dikongsi dengan
  `DemoSeeder` dan `--fresh` memadam data demo juga.
- **Dua pegawai berkongsi satu `loginname`** (id 5 dan 25, kedua-duanya `haida`).
  `updateOrCreate` pada kunci bertembung **tidak gagal** — ia mengemas kini baris
  pegawai pertama. Slug pertama menang; yang kemudian membawa id (`haida.25`).
- Kata laluan lama **tidak dibawa** (teks biasa dan MD5): rawak, super admin
  menetapkan semula. Peserta log masuk dengan ID Komuniti + IC.

## Zon lama **mesti** diterjemah, tidak boleh disimpan mentah

`App\Support\LegacyZones` memetakan nama zon lama kepada zon undilot. Ia bukan
kemasan — ia pembetulan pepijat yang diukur pada baris sebenar.

Dump membawa **18 nama zon dan 7 bukan zon undilot** (`P.Penang`,
`N.Sembilan`, `Selangor`, `Kuala Lumpur`, `Sabah`, `Kedah(Langkawi)`,
`P.Langkawi (Kedah)`). Pejabat sendiri tidak konsisten: dump ada **kedua-dua**
`N.Sembilan` dan `Negeri Sembilan`, **kedua-dua** `P.Penang` dan `Pulau Pinang`.

Disimpan mentah, skop **nampak** berfungsi — baris import salah dengan cara yang
sama, jadi admin lama padan syarikat lama. Yang pecah ialah **borang**: setiap
dropdown zon dibina daripada `AdminZones`, jadi syarikat pada `'P.Penang'`
membuka borang suntingannya dengan **tiada option `selected`**, dan simpanan
seterusnya memindahkannya ke zon pertama dalam senarai — tanpa ralat, tanpa
pengesahan. Diukur pada baris sebenar, bukan dibayangkan. Itu persis kegagalan
yang docblock `AdminZones` sendiri beri amaran.

Dua zon lama bergabung jadi satu (`Selangor` + `Kuala Lumpur` →
`Selangor, Kuala Lumpur dan Putrajaya`); pegawai kedua-dua pejabat menjadi admin
zon itu.
Sasaran diselesaikan terhadap **senarai hidup**, bukan salinan — zon ialah
`ManagedList` yang boleh dinamakan semula. Nama tanpa pemetaan **tidak disimpan
mentah**: `LegacyZones::translate()` melontar, dan `Legacy::zone()` menjawabnya
sebagai null yang dilaporkan.

`AdminZones::rename()` **menolak nama zon lain**, tidak peka huruf besar (MySQL
membandingkan lajur ini begitu juga) — jika tidak, dua zon bergabung senyap
(K08). Menukar huruf besar/kecil nama sendiri dibenarkan.

## `legacy:migrate`: satu arahan, satu dump, dengan bukti

```
php artisan legacy:migrate <dump.sql>      # import:legacy → legacy:accounts → transform-event × EVENTS
php artisan legacy:migrate x --report-only # hanya laporan rekonsiliasi
```

Ia berakhir dengan **rekonsiliasi**: untuk setiap jadual lama, berapa baris,
berapa dipaut, berapa tidak — kiraan sahaja, tiada nama atau IC. Jadual
"archive" (salinan setiap jam) dijangka tidak dipaut. Selamat dijalankan semula
pada dump sama: akaun ditemui semula ikut identiti, setiap acara menggantikan
yang dibinanya dahulu (kunci `settings` `legacy_event:<jadual>`, bukan
`description` yang pejabat sunting).

Ambil dump segar `digitalCraft` semasa cutover. Dalam pembangunan, **hanya dump
bertopeng**.

## `legacy:transform-event` membina satu acara lama

`legacy:transform-event usahawan_fkj2025 --booths=ukhemah_fkj2025 --code=FKJ`
membina acara `completed` penuh: pelan (pasangan lokasi/saiz), satu plot setiap
baris gerai, pendaftaran, undi, preset. Keputusan dan sebabnya:

- **`plot_code` ialah `boothid` pejabat**, bukan `Plot::codeFor()` — id itulah
  yang dicetak pada gerai dan slip. Boothid berulang tetap dua plot.
- **Preset ikut baris**: plot `preset` bila `usahawan.preset` = gerai itu.
  `preset_allocations` hanya untuk kod pada helaian (ia FK).
- **Setiap pendaftaran ditulis `verified_at`**, kerana cabutan itu selesai.
- **Tetingkap cabutan dari `votetime`**; tiada `votetime` → tahun daripada nama
  jadual, dilaporkan sebagai tekaan. **Tajuk, tempat dan tarikh acara tiada
  dalam dump** — pilihan arahan, atau sunting acara selepas itu.
- **Kod tidak pada helaian, atau tiada kod (89% baris lama)**: plot tanpa kod,
  saiz `unknown`; pendaftaran menyimpan kod sebagai teks. Untuk acara **lama**
  ini tidak menghilangkan apa-apa — dahulu arahan berhenti.
- **Syarikat dengan dua gerai** ("MOHON 2 LOT"): satu pendaftaran (unik
  acara+syarikat), kedua-dua plot diperuntukkan kepadanya.
- **Tiada jadual `ukhemah`** (FKJ 2022): gerai dibina semula dari
  `usahawan.lot` / `location`.
- `bayarstatus` Ya/Tidak → `payment_status` paid/unpaid; `payment` (No. Resit)
  → `payment_note`.

`--fresh` membuang pendaftaran **dahulu**, kemudian acara: `plot_id` tiada
klausa `ON DELETE`.

## Pembersihan yang data sebenar sudah tunjukkan perlu

- **Ruang hujung**: `boothid` `'TBA06 '`, `comid` `'10-1-62  '`, `'14-1-14   '`.
- **Zero-date benar-benar ada**: `votetimepay` = `'0000-00-00 00:00:00'` dalam
  setiap baris sampel.
- **IC dua format**: `'890508-12-0000'` berbanding `'800601100000'`.
- **Huruf besar/kecil tidak sepadan antara jadual**: `usahawan.location` =
  `'KHEMAH A'` sedangkan `ukhemah.khemah` = `'Khemah A'`. Join mesti normalisasi.
- **Bendera bercampur bahasa**: `undi` = `'Yes'`, `bayarstatus` / `sstatus` =
  `'Ya'` / `'Tidak'`.

Transform dari staging ke jadual aplikasi yang membuat keputusan. **Jangan**
tambah lajur status (`sstatus`, `Protokol`) kepada `event_registrations` untuk
menampung dump — ia kekal dalam `raw` — model dua-semakan sudah
menggantikannya, dan `PollingGuard` serta `EventRegistration::booted()`
bergantung padanya.

---

