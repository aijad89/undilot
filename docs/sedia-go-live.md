# Kesediaan Go-Live — undilot

*Penilaian asal 2026-11-16; dikemas kini 2026-11-24 (commit `8a6f18c`). Kemas
kini fail ini setiap kali satu perkara selesai: tanda `[x]`, tulis tarikh dan
commit.*

## Keputusan

**Belum sedia untuk go-live — yang tinggal ialah kerja pejabat dan pelayan,
bukan kod.** Ciri teras sudah dibina dan diuji: log masuk (peserta emel + kata
laluan, pegawai nama pengguna), senarai cabutan dengan semakan zon → HQ,
permohonan sertai, pelan lot, cabutan, slip PDF, *login as* untuk admin, skrin
audit trail. Pepijat sederhana K03–K10 semuanya dibaiki. Suite: Pest 658 lulus
(SQLite), 657 + 1 dilangkau (MySQL), Playwright 28/28.

| Kategori | Jumlah | Status |
|---|---|---|
| 🔴 Penghalang go-live | 6 | 1 selesai (B5) |
| 🐞 Pepijat sederhana (K03–K10) | 8 | **8 selesai** |
| 🟡 Patut dibuat | 3 | 1 selesai |
| 🟢 Selepas go-live | 12 pepijat rendah | — |

---

## 1. 🔴 Penghalang go-live

### B1. Data peserta sebenar belum dimuat
- [ ] Dapatkan CSV senarai peserta daripada pejabat — **dengan lajur `emel`**
- [ ] `php artisan import:participants <fail.csv>` — semak laporan: tiada baris
      gagal, dan bilangan "no valid email (emel) and CANNOT SIGN IN"
- [ ] Atau, untuk data lama: `php artisan legacy:migrate <dump.sql>` dengan dump
      segar `digitalCraft` (butiran: [`legacy-import.md`](legacy-import.md)).
      Dump lama **tiada emel** — setiap akaun lama perlu emel diisi pejabat.

Lajur CSV: wajib `nama`, `ic`; pilihan `telefon`, `emel`, `syarikat`, `zon`,
`kod_kraf`, `saiz_gerai`, `kategori_usahawan` (`Pengeluar atau Pedagang` /
`Pemborong`), `no_komuniti`, `alamat`. Seluruh fail disemak dahulu — jika satu
baris salah, tiada apa disimpan.

### B2. Akaun dan log masuk
**Dikemas kini 2026-11-24** (menggantikan "ID Komuniti + IC" 17 Nov): peserta
log masuk dengan **emel + kata laluan**. Kali pertama, **IC ialah kata laluan**,
dan peserta wajib memilih kata laluan sendiri sebelum boleh ke mana-mana. Lupa
kata laluan → pautan reset melalui emel — **satu-satunya emel yang aplikasi
hantar**; notifikasi kekal dalam aplikasi.

- [ ] **Setiap peserta ada emel** (pejabat isi di halaman syarikat; hanya pejabat
      boleh menukarnya). Tanpa emel, peserta tidak boleh log masuk langsung.
- [ ] Putuskan: bagaimana peserta tahu akaun mereka sudah wujud, emel mana yang
      didaftarkan, dan bahawa kata laluan pertama ialah IC (surat / WhatsApp /
      taklimat pejabat — sistem tidak menghantar apa-apa sendiri).
- [ ] Cipta akaun setiap pegawai negeri dan HQ di pelayan, serahkan nama
      pengguna + kata laluan kepada mereka sendiri.
- [ ] `.env` pelayan: `ADMIN_SEED_USERNAME` + `ADMIN_SEED_PASSWORD`.

Risiko yang diterima: sebelum peserta menukar kata laluan pertama, sesiapa yang
tahu emel **dan** IC mereka boleh masuk dahulu. Emel tidak dicetak pada slip.

### B3. Persediaan pelayan dan deploy
Senarai semak penuh: [`serahan-pembangun.md` seksyen 13](serahan-pembangun.md#13-deploy-ke-produksi).

- [ ] Maklumat pelayan: hos, laluan, pengguna, URL, subpath (`/undilotnew`?)
- [ ] HTTPS
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database`,
      `QUEUE_CONNECTION=database`, `APP_URL` penuh termasuk subpath
- [ ] `php artisan migrate --force` kemudian `php artisan db:seed --force` —
      super admin pertama, helaian kod kraf, lima kategori, **dan hierarki kraf
      penuh** dari `database/data/hierarki-kraf.csv` (35 sub-kategori, 76
      klasifikasi, 138 produk, disemak ikut buku 2026-11-18). Tiada data demo
      di pelayan. Boleh dijalankan semula: tambah sahaja, tiada yang dipadam.
      Kemas kini hierarki kemudian: sunting fail itu, deploy, jalankan
      `php artisan kraf:import-hierarchy database/data/hierarki-kraf.csv`
- [ ] `php artisan storage:link` (tanpanya sepanduk acara hilang tanpa ralat)
- [ ] Penjadual dari `/etc/cron.d/undilot` (bukan crontab `www-data`)
- [ ] Pekerja baris gilir (systemd `queue:work database`) — tanpanya **tiada
      notifikasi dihantar**
- [ ] `MAIL_*` sebenar dalam `.env` (SMTP pejabat). Tanpanya pautan reset kata
      laluan tidak sampai — `MAIL_MAILER=log` menulisnya ke log sahaja, dan
      peserta tetap diberitahu "a link has been sent". Uji *Forgot password?*
      dengan satu akaun sebelum dibuka.
- [ ] Log masuk sebagai super selepas deploy: kad *Needs attention* tidak
      menunjukkan baris `Server:` (pekerja, penjadual, `storage:link` —
      `DashboardController::serverProblems()`). Penjadual dianggap mati jika
      tiada denyutan dalam 5 minit; tunggu seminit selepas cron dipasang.
      (Mel **tidak** disemak di sini — keputusan pemilik 2026-11-24.)
- [ ] Sandaran pangkalan data automatik (harian) dan uji pulih sekali
- [ ] Tulis skrip deploy dan simpan prosedur dalam `serahan-pembangun.md`

### B4. Ujian manual baru 59% siap
Sumber: `docs/testing/undilot-test-manual.xlsx` (angka di bawah dari 2026-11-16).

| Modul | Kes | Lulus | Gagal | Belum jalan |
|---|---|---|---|---|
| Portal peserta | 9 | 0 | 0 | **9** |
| Senarai cabutan | 25 | 13 | 0 | 12 |
| Cabutan lot | 37 | 18 | 2 | 17 |
| Syarikat | 20 | 11 | 0 | 9 |
| Log masuk & kata laluan | 19 | 13 | 1 | 5 |
| **Keutamaan tinggi** | **82** | **51** | **1** | **30** |

- [ ] Kemas kini helaian untuk undilot hari ini:
      - **buang** kes modul yang dibuang: Reports, Announcements, Site content;
      - **kekalkan / tambah** kes untuk modul yang dipulihkan: permohonan sertai
        (2026-11-19), skrin audit trail (2026-11-24, super sahaja);
      - **tulis semula** kes log masuk peserta: emel + IC kali pertama, wajib
        tukar, lupa kata laluan, "Reset password to IC";
      - **tambah** kes baharu: *login as* (admin zon / HQ, jalur kuning, Return
        to admin, kata laluan ditolak), sejarah sijil, halaman ralat.
- [ ] Jalankan semua kes keutamaan tinggi yang belum dijalankan
- [ ] Jalankan kes portal peserta **pada telefon sebenar** (pengguna utama ialah
      usahawan berusia, di telefon) — termasuk log masuk pertama dan tukar
      kata laluan
- [ ] Uji semula kes yang gagal sebelum K04–K10 dibaiki

### B5. Pepijat sederhana
- [x] K03–K10 semuanya dibaiki 2026-11-15/16 — lihat seksyen 2.

### B6. Tamatkan peraturan "satu baseline migration" pada hari data sebenar dimasukkan
Sekarang perubahan skema dibuat dengan mengedit
`database/migrations/0001_01_02_000000_create_undilot_schema.php` (dan
`0001_01_01_000000_create_users_table.php`) dan `migrate:fresh`. Selepas data
sebenar wujud, cara itu **memadam data**.

- [ ] Pada hari go-live: kemas kini peraturan dalam `CLAUDE.md` — setiap
      perubahan skema selepas ini ialah migration baharu
- [ ] Beritahu semua pembangun

---

## 2. 🐞 Pepijat sederhana — semua dibaiki

Setiap satu ada ujian yang dibuktikan menggigit. Butiran asal (apa berlaku,
kod, cadangan) ada dalam sejarah git fail ini, commit sebelum 2026-11-24.

| ID | Ringkas | Dibaiki | Ujian |
|---|---|---|---|
| K03 | *Undo* selepas cabutan bermula | 2026-11-15 | — |
| K04 | *Take back* pada cabutan `completed` | 2026-11-16, PR #1 | `TheLotPlanBuildsWhatItSaysTest` |
| K05 | Pelan disunting selepas cabutan dibuka | 2026-11-16, PR #1 | `ARunningDrawsPlanCannotBreakItTest` |
| K06 | *Reopen voting* dengan tarikh tamat lepas | 2026-11-16, PR #1 | `AReopenedDrawHasTimeLeftTest` |
| K07 | Zon memadam syarikat yang sudah diberitahu | 2026-11-16, PR #1 | `ACompanyToldItIsInADrawIsNotDeletedSilentlyTest` |
| K08 | Rename zon menggabungkan dua zon | 2026-11-16, PR #1 | `ZonesCarryTheirRowsWhenRenamedTest` |
| K09 | Admin mendarat di log masuk peserta | 2026-11-16, PR #2 | `BothGuardsSignInTest` |
| K10 | Acara *Unpublished* di laman awam | 2026-11-16 | `AnUnpublishedEventIsHiddenFromThePublicTest` |

---

## 3. 🟡 Patut dibuat sebelum go-live

- [ ] **Senarai keputusan untuk pejabat.** Laporan CSV dibuang. Sahkan dengan
      pejabat bagaimana mereka mendapat senarai akhir "syarikat mana dapat lot
      mana" untuk hari acara (cetak / papan). Sekarang hanya slip PDF per
      peserta.
- [ ] **Fixture `tests/Fixtures/legacy-dump-sample.sql` tidak dijejaki git**
      (diabaikan oleh `*.sql` dalam `.gitignore`). Suite lulus pada mesin ini
      kerana fail itu ada di sini; pada klon baharu, ujian import lama
      (`TheLegacyLoaderTakesTheDumpApartTest`,
      `LegacyAccountsCarryTheOfficeNamesTest`) gagal. Semak fail itu tiada data
      peribadi sebenar, kemudian `git add -f` — seperti
      `legacy-migrate-sample.sql`, yang sudah dijejaki.
- [x] **Suite Pest pada MySQL** (`DB_CONNECTION=mysql DB_DATABASE=undilot_test`)
      — 2026-11-24 selepas login-as, audit trail dan halaman ralat: 657 lulus,
      1 dilangkau dengan sengaja. Jalankan sekali lagi sejurus sebelum go-live.

Selesai sejak penilaian asal: suite Pest pada MySQL (2026-11-15, 2026-11-24),
Playwright berulang kali tanpa flaky, kerja 2026-11-16 di-commit.

---

## 4. 🟢 Selepas go-live (pepijat rendah)

Butiran dalam helaian *Known Issues* manual ujian.

| ID | Ringkas |
|---|---|
| K12 | *Set aside* boleh menindih lot yang peserta baru dapat (tiada kunci) |
| K13 | Auto-buka mengabaikan tarikh tamat |
| K14 | Lokasi berbeza huruf besar sahaja memberi nombor lot berganda |
| K15 | Peringatan tutup tidak dihantar semula untuk tetingkap baharu |
| K16 | Simpan kad kod kraf mengosongkan kod / saiz yang sudah ditarik balik |
| K22 | Dua super menurunkan pangkat satu sama lain serentak boleh tinggalkan tiada super |
| K24 | Kalendar hanya guna penapis pertama |
| K26 | "Maximum participants" disimpan tetapi tidak digunakan |
| K27 | Papan pemuka dan halaman acara tidak sepadan untuk syarikat yang ditambah semula |
| K28 | Notifikasi pembuangan ada label butang salah |
| K29 | Halaman acara awam memaparkan zon admin |
| — | Semasa *login as*, admin yang membuka notifikasi menandanya "dibaca" untuk peserta |

- K17 (jenis perniagaan lama dikosongkan bila simpan) — **dibaiki** 2026-11-17:
  `ManagedList::rule($keep)` menerima nilai semasa rekod itu.
- K11, K20, K21, K25 dahulu ditutup kerana merujuk permohonan sertai, yang
  dibuang. **Permohonan sertai dipulihkan 2026-11-19** — semak semula sama ada
  item itu masih berlaku. K18 (sebahagian) merujuk laporan, yang masih dibuang.

---

## 5. Turutan dicadangkan

| Langkah | Perkara | Bergantung pada |
|---|---|---|
| 1 | Minta CSV peserta **dengan emel** daripada pejabat — **mulakan sekarang**, ia paling lama | Pejabat |
| 2 | Putuskan cara memberitahu peserta (emel akaun, IC sebagai kata laluan pertama) | Pemilik + pejabat |
| 3 | Kemas kini helaian ujian; jalankan kes keutamaan tinggi + portal peserta di telefon | Pembangun + HQ |
| 4 | Sediakan pelayan, termasuk SMTP; uji *Forgot password?* | Pentadbir pelayan |
| 5 | Import data sebenar; isi emel yang tiada; tamatkan peraturan baseline migration | 1, 4 |
| 6 | Cabutan perintis di pelayan sebenar bersama HQ + beberapa admin zon | 3–5 |
| 7 | **Go-live** | 6 |

---

## Log kemajuan

| Tarikh | Perkara | Commit | Oleh |
|---|---|---|---|
| 2026-11-16 | Penilaian awal; pengawal seeder demo + `migrate:fresh` di pelayan | `c4afc84` | — |
| 2026-11-16 | K04–K08 dibaiki, 22 ujian baharu, setiap satu dibuktikan menggigit | PR #1 | — |
| 2026-11-16 | K09 dibaiki; label kod kraf pada slip dibetulkan; `.env.example` dibersihkan | PR #2 | — |
| 2026-11-16 | K10 dibaiki; IC pada slip di belakang suis `SLIP_SHOWS_IC` (lalai **on**) | — | — |
| 2026-11-17 | Medan data pejabat: `alamat` dalam import CSV; jenis perniagaan dua nilai; status bayaran; nombor lot `TBPS02` | — | — |
| 2026-11-17 | Log masuk peserta ID Komuniti + IC; tiada emel langsung (kemudian diganti 2026-11-24) | — | — |
| 2026-11-17 | Borang pelan lot: jumlah lokasi dikira daripada peruntukan kod + plot simpanan | — | — |
| 2026-11-19 | Permohonan sertai dipulihkan; profil memegang borang MICF | `dd52a40` | — |
| 2026-11-20 | Profil: kategori usahawan, OKU, kakitangan; kad akaun peserta; sijil kualiti ikut kraf utama | `a050fbf` | — |
| 2026-11-24 | Sijil dalam jadual `company_certificates` dengan sejarah; sijil tamat tidak menyekat | `31455b6`, `979c639` | — |
| 2026-11-24 | Baris `Server:` di papan pemuka admin (queue, penjadual, `storage:link`) | `31455b6` | — |
| 2026-11-24 | Log masuk peserta emel + kata laluan; IC kali pertama; reset melalui emel. CLAUDE.md dipendekkan | `d2200ee` | — |
| 2026-11-24 | *Login as* untuk admin; skrin audit trail dipulihkan (super sahaja); halaman ralat sendiri. Pest 658, Playwright 28/28 | `801f50e`, `8a6f18c` | — |
| 2026-11-24 | Fail ini dikemas kini: B2 log masuk emel, K03–K10 dimampatkan, seksyen 3 dan 4 disemak | — | — |
