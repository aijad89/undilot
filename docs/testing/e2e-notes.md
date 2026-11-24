# Nota suite ujian (Pest MySQL dan Playwright)

Dipindahkan dari CLAUDE.md (2026-11-24). Cerita penuh di sebalik peraturan
ujian: baca bila suite pelayar goyah. Peraturan ringkasnya kekal dalam CLAUDE.md.

## Suite Pest pada MySQL, dan apa yang ia jumpa

Pemboleh ubah persekitaran menang atas `phpunit.xml`, jadi
`DB_CONNECTION=mysql DB_DATABASE=undilot_test vendor/pestphp/pest/bin/pest`
menghalakan suite yang sama ke `undilot_test` (cipta sekali,
`utf8mb4_unicode_ci`). **Jangan** halakan ia ke `undilot` (pangkalan data dev):
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

## Suite pelayar memiliki persekitarannya sendiri

`tests/e2e/serve.js` menulis `.env.e2e` (dari `.env` + `tests/e2e/env.js`),
menetapkan semula `undilot_e2e`, dan menghidupkan pelayan **serta** pekerja queue.
Jangan jalankan suite terhadap pelayan yang dihidupkan dengan tangan.

`.env.e2e` itu **fail**, bukan pemboleh ubah proses, dengan sebab yang diukur:
`artisan serve` hanya meneruskan senarai pemboleh ubah tertentu kepada anaknya.
`APP_ENV` lepas, `DB_DATABASE` tidak — larian pertama menghidang pangkalan data
**dev**. `fixtures/run.php` menolak sebarang pangkalan data yang bukan `*_e2e`.

## Port berputar setiap larian, dan itu pembetulan bukan hiasan

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

## Yatim daripada larian terganggu ialah enjin sebenar

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

## `--no-reload` + `PHP_CLI_SERVER_WORKERS`, bersama atau tiada langsung

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

