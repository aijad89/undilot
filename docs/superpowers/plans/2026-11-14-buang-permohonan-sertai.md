# Buang Permohonan Sertai — Pelan Pelaksanaan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buang modul permohonan sertai sepenuhnya, supaya peserta hanya log masuk dan mengesahkan lot, sementara pejabat memasukkan setiap syarikat ke senarai cabutan.

**Architecture:** Pembuangan bawah-ke-atas. Lapisan paparan dahulu (view, route), kemudian lapisan logik (controller, model), kemudian skema. Notifikasi `JoinRequestAnswered` dinamakan `AddedToDraw` dan dikekalkan sebelum apa-apa dipadam — ia membawa satu-satunya pemberitahuan "anda dalam cabutan", dan setiap tugasan selepas itu bergantung pada ia sudah selamat.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Playwright. MySQL pada dev, SQLite dalam-memori untuk ujian.

## Global Constraints

- **PHP CLI:** `/c/Users/DELL/.config/herd/bin/php84.bat` — `php` tiada dalam PATH bash.
- **Pest:** `vendor/pestphp/pest/bin/pest`. **Migration mesti dijalankan pada dua pemacu** sebelum dipercayai: `php artisan migrate:fresh --force` (MySQL) **dan** suite Pest (SQLite).
- **Satu baseline migration:** ubah `database/migrations/0001_01_02_000000_create_undilot_schema.php` terus dan jalankan `migrate:fresh`. Jangan tambah migration berperingkat.
- **Nav layout ialah senarai:** `resources/views/layouts/admin.blade.php` dan `partials/portal-bar.blade.php` mengisytiharkan `$nav` / `$apNav`. Satu route yang dibuang tanpa mengemas kini senarai membunuh **setiap** skrin yang memuatkan layout itu.
- **Factory menamakan setiap lajur, termasuk nullable.** `Model::preventAccessingMissingAttributes()` hidup di luar produksi; lajur yang factory tidak namakan tidak hadir dan view campak `MissingAttributeException` di bawah `actingAs`.
- **`toContain` ialah variadic** — jangan beri mesej sebagai argumen kedua; ia jadi needle kedua dan membunuh assertion. Guna perbandingan eksplisit + `toBeTrue($mesej)`.
- **Aplikasi mesti kekal selamat-subpath:** setiap pautan melalui `route()` / `url()`. Tiada `redirect('/')` atau `href="/…"`.
- **Setiap peraturan baharu dibuktikan menggigit:** selepas ujian lulus, buang pembetulan sementara dan pastikan ujian **gagal**, kemudian pulihkan.
- **Suite pelayar sedang merosot pada mesin ini** (1.9–3.3 minit berbanding 42 saat, ujian berbeza timeout setiap larian). Terbukti bukan daripada kod. Bandingkan larian Playwright dengan garis dasar itu, bukan dengan 24-lulus.

---

## Struktur fail

**Dipadam (10 fail)**

| Fail | Tanggungjawab yang hilang |
|---|---|
| `app/Http/Controllers/JoinRequestController.php` | borang sertai, hidangan dokumen dan foto |
| `app/Models/EventJoinRequest.php` | model permohonan |
| `database/factories/EventJoinRequestFactory.php` | factory |
| `app/Notifications/JoinRequestAnswered.php` | → dinamakan `AddedToDraw.php` |
| `resources/views/partials/join-answers.blade.php` | jawapan permohonan |
| `resources/views/partials/payment-proof.blade.php` | bukti bayaran |
| `resources/views/request-photos.blade.php` | foto produk |
| `resources/views/partials/company-record.blade.php` | memapar `credentials` |
| `tests/Feature/AParticipantAsksToJoinAnEventTest.php` | 43 ujian borang sertai |
| `tests/e2e/05-join-steps.spec.js` | spec pelayar borang sertai |

**Dicipta (2 fail)**

| Fail | Tanggungjawab |
|---|---|
| `app/Notifications/AddedToDraw.php` | satu notifikasi: HQ telah mengesahkan syarikat anda |
| `tests/Feature/TheOfficeTellsACompanyItIsInTheDrawTest.php` | mengunci pemberitahuan itu |

**Disunting (15 fail)** — dinamakan dalam tugasan masing-masing.

---

### Task 1: Notifikasi kekal sebelum apa-apa dipadam

Modul ini memegang satu-satunya pemberitahuan "anda dalam cabutan". Ia diselamatkan dahulu, dengan ujian, supaya setiap tugasan selepas ini boleh memadam dengan bebas.

**Files:**
- Create: `app/Notifications/AddedToDraw.php`
- Create: `tests/Feature/TheOfficeTellsACompanyItIsInTheDrawTest.php`
- Modify: `app/Http/Controllers/Admin/EntRegistrationController.php` (import + `verify()`)
- Delete: `app/Notifications/JoinRequestAnswered.php`

**Interfaces:**
- Consumes: tiada.
- Produces: `App\Notifications\AddedToDraw::__construct(App\Models\Event $event)`. `toArray($notifiable)` mengembalikan `['title' => 'You are in the draw', 'join_event_id' => int, 'approved' => true, 'event_name' => string, 'message' => string, 'type' => 'success']`. Kunci `join_event_id` dan `approved` dikekalkan kerana `NotificationController` dan `resources/views/notifications/index.blade.php` membacanya.

- [ ] **Step 1: Baca kelas lama sebelum menggantikannya**

```bash
cd /f/herd/undilot2026
cat app/Notifications/JoinRequestAnswered.php
grep -rn "join_event_id\|'approved'" app/Http/Controllers/NotificationController.php resources/views/notifications/index.blade.php
```

Catat setiap kunci yang dibaca di luar kelas itu. Kunci yang hilang ialah notifikasi yang render kosong.

- [ ] **Step 2: Tulis ujian yang gagal**

Fail: `tests/Feature/TheOfficeTellsACompanyItIsInTheDrawTest.php`

```php
<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\AddedToDraw;
use Illuminate\Support\Facades\Notification;

/*
 * THE ONE MESSAGE THIS WHOLE MODULE REMOVAL COULD SILENTLY TAKE AWAY.
 *
 * A participant never asks to join: the office adds their company to the draw
 * list and HQ verifies it. This notification is the only thing that tells them
 * so — without it they sign in to a dashboard that says nothing, with a lot to
 * confirm they were never told about.
 *
 * It is sent ONCE, on HQ's FIRST verification. A row HQ verified before, edited
 * by a zone and verified again, says nothing new.
 */

function inDrawFixture(): array
{
    $admin = superAdmin();
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);

    $registration = EventRegistration::factory()->create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'verified_at' => null,
        'first_verified_at' => null,
    ]);

    return [$admin, $user, $event, $registration];
}

it('tells the company when HQ verifies the row the office added', function () {
    Notification::fake();
    [$admin, $user, $event, $registration] = inDrawFixture();

    $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), [
            'code' => 'TBPS',
            'plot_size' => '3x3',
            'verify' => '1',
        ])
        ->assertRedirect();

    Notification::assertSentTo($user, AddedToDraw::class);
});

it('says it once, not again when HQ verifies a second time', function () {
    Notification::fake();
    [$admin, $user, $event, $registration] = inDrawFixture();

    $send = fn () => $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), [
            'code' => 'TBPS',
            'plot_size' => '3x3',
            'verify' => '1',
        ]);

    $send();
    $send();

    // first_verified_at is never cleared, so the second pass is a re-check and
    // a re-check has nothing new to announce.
    Notification::assertSentToTimes($user, AddedToDraw::class, 1);
});

it('carries the keys the notifications screen reads', function () {
    $event = Event::factory()->create(['title' => 'Pesta Kraf']);
    $user = User::factory()->create();

    $payload = (new AddedToDraw($event))->toArray($user);

    expect($payload['join_event_id'])->toBe($event->id)
        ->and($payload['approved'])->toBeTrue()
        ->and($payload['event_name'])->toBe('Pesta Kraf')
        ->and($payload['type'])->toBe('success')
        ->and($payload['title'])->toBe('You are in the draw');

    expect(str_contains($payload['message'], 'Pesta Kraf'))
        ->toBeTrue('The message has to name the event; the screen shows it alone.');
});
```

- [ ] **Step 3: Jalankan ujian dan pastikan ia gagal**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="TellsACompanyItIsInTheDraw"
```

Jangka: FAIL dengan `Class "App\Notifications\AddedToDraw" not found`.

- [ ] **Step 4: Cipta kelas notifikasi**

Fail: `app/Notifications/AddedToDraw.php`

```php
<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The office put a company on an event's draw list and HQ confirmed it.
 *
 * THE ONLY THING THAT TELLS A PARTICIPANT THEY ARE IN A DRAW. There is no
 * self-registration in undilot: the office enters every company, so nothing a
 * participant does could have told them. Without this they sign in to a
 * dashboard that says nothing and a lot they were never asked to confirm.
 *
 * Sent ONCE, on HQ's first verification — `first_verified_at`. A row verified
 * before, edited by a zone and verified again, has nothing new to announce.
 *
 * Queued, like every notification here. On a developer machine no worker runs,
 * so it sits in `jobs` until the button on /auto is pressed.
 */
class AddedToDraw extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * `join_event_id` and `approved` keep their names from the notification this
     * replaced: NotificationController and notifications/index.blade.php read
     * them, and a renamed key renders an empty row rather than failing.
     */
    public function toArray($notifiable): array
    {
        return [
            'title' => 'You are in the draw',
            'join_event_id' => $this->event->id,
            'approved' => true,
            'event_name' => $this->event->title,
            'message' => 'Your company is in the lot draw for '.$this->event->title.'.',
            'type' => 'success',
        ];
    }
}
```

- [ ] **Step 5: Tukar pemanggil dalam `EntRegistrationController`**

Dalam `app/Http/Controllers/Admin/EntRegistrationController.php`, tukar import:

```php
use App\Notifications\JoinRequestAnswered;
```

kepada:

```php
use App\Notifications\AddedToDraw;
```

Kemudian dalam `verify()`, ganti blok penyelesaian permohonan dengan satu syarat. Yang ada sekarang:

```php
        // One at a time, not a mass update, so each is audited.
        $settled = $registration->event->joinRequests()
            ->where('company_id', $registration->company_id)->where('status', 'pending')->get();
        $settled->each->update(['status' => 'approved', 'reviewed_by' => auth('admin')->id(), 'reviewed_at' => now()]);

        if ($first || $settled->isNotEmpty()) {
            $registration->company->user?->notify(new JoinRequestAnswered($registration->event));
        }
```

Gantikan dengan:

```php
        // Told once, on the first verification. A row HQ verified before,
        // edited by a zone and verified again, says nothing new.
        if ($first) {
            $registration->company->user?->notify(new AddedToDraw($registration->event));
        }
```

- [ ] **Step 6: Padam kelas lama**

```bash
cd /f/herd/undilot2026
rm app/Notifications/JoinRequestAnswered.php
grep -rn "JoinRequestAnswered" app resources tests routes database
```

Jangka: grep terakhir tiada output. Kalau ada, betulkan setiap satu sebelum teruskan.

- [ ] **Step 7: Jalankan ujian dan pastikan ia lulus**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="TellsACompanyItIsInTheDraw"
```

Jangka: `Tests: 3 passed`.

- [ ] **Step 8: Buktikan ujian menggigit**

Dalam `verify()`, tukar `if ($first)` kepada `if (false)` sementara, jalankan ujian, dan pastikan dua ujian pertama **gagal**. Kemudian pulihkan.

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="TellsACompanyItIsInTheDraw"
```

Jangka selepas mutasi: `2 failed`. Jangka selepas dipulihkan: `3 passed`.

- [ ] **Step 9: Commit**

```bash
cd /f/herd/undilot2026
git add app/Notifications/AddedToDraw.php tests/Feature/TheOfficeTellsACompanyItIsInTheDrawTest.php app/Http/Controllers/Admin/EntRegistrationController.php
git add -u app/Notifications/JoinRequestAnswered.php
git commit -m "AddedToDraw: selamatkan pemberitahuan 'anda dalam cabutan' sebelum modul dibuang

JoinRequestAnswered buat dua kerja. Cabang 'Request not approved' milik modul
permohonan sertai; cabang 'You are in the draw' dihantar untuk SETIAP syarikat
yang HQ sahkan pertama kali, termasuk yang pejabat tambah tanpa permohonan.

Memadam kelas itu tanpa menggantikannya bermakna peserta tidak akan pernah tahu
mereka ada dalam cabutan - mereka log masuk ke papan pemuka yang tidak berkata
apa-apa, dengan lot yang mereka tidak pernah diminta sahkan.

Dinamakan AddedToDraw, cabang kelulusan sahaja. join_event_id dan approved
mengekalkan namanya kerana NotificationController dan skrin notifikasi
membacanya.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Buang borang sertai dari halaman acara awam

**Files:**
- Modify: `resources/views/events/show.blade.php` (476 baris → lebih kecil)
- Modify: `app/Http/Controllers/HomeController.php` (`show()`)
- Modify: `resources/views/partials/public-event-card.blade.php:48-52`
- Modify: `resources/views/partials/public-event-stage.blade.php:7`
- Modify: `app/Models/Event.php` (`publicStage()`)
- Test: `tests/Feature/ThePublicSiteShowsOnlyWhatIsPublishedTest.php`

**Interfaces:**
- Consumes: `AddedToDraw` daripada Task 1 (tidak dipanggil di sini).
- Produces: `HomeController::show()` menghantar `['event', 'company', 'onList', 'beingAdded']` kepada view — **tiada lagi `joinRequest`**. `Event::publicStage()` mengembalikan salah satu daripada `'ended'|'now'|'open'|'closed'|'soon'` — **tiada lagi `'register'`**.

- [ ] **Step 1: Tulis ujian yang gagal**

Tambah pada hujung `tests/Feature/ThePublicSiteShowsOnlyWhatIsPublishedTest.php`:

```php
it('never invites a visitor to register, because the office enters every company', function () {
    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'draft',
        'registration_open_at' => now()->subDay(),
        'registration_close_at' => now()->addWeek(),
        'start_date' => now()->addMonth(),
        'end_date' => now()->addMonth()->addDay(),
    ]);

    // An event inside its registration window used to read "Register now" and
    // link to a multi-step form. There is no such form: the office adds every
    // company, so inviting a visitor to register promises something nobody can
    // deliver.
    expect($event->publicStage())->toBe('soon');

    $list = $this->get(route('home'))->assertOk();
    expect(str_contains($list->getContent(), 'Register now'))
        ->toBeFalse('The public list still offers to register.');

    $page = $this->get(route('events.show', $event))->assertOk();
    expect(str_contains($page->getContent(), 'Register now'))
        ->toBeFalse('The event page still offers to register.');
});
```

- [ ] **Step 2: Jalankan ujian dan pastikan ia gagal**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="never invites a visitor to register"
```

Jangka: FAIL — `publicStage()` mengembalikan `'register'`.

- [ ] **Step 3: Buang cabang `register` dari `Event::publicStage()`**

Dalam `app/Models/Event.php`, buang baris `$this->acceptsJoinRequests() => 'register',` daripada `match`:

```php
    public function publicStage(): string
    {
        return match (true) {
            $this->end_date->isPast() => 'ended',
            $this->start_date->isPast() => 'now',
            $this->polling_status === 'polling' && ! $this->polling_deadline?->isPast() => 'open',
            in_array($this->polling_status, ['polling', 'closed', 'completed', 'cancelled'], true) => 'closed',
            default => 'soon',
        };
    }
```

- [ ] **Step 4: Buang label dan butang "Register now"**

Dalam `resources/views/partials/public-event-stage.blade.php`, buang baris `'register' => ['Register now', 'ok'],` dari array.

Dalam `resources/views/partials/public-event-card.blade.php`, buang keseluruhan `@case('register')` (baris 48–52):

```blade
                @case('register')
                    <a href="{{ route('events.show', $event) }}" class="ev-cta ev-cta-primary">
                        Register now <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                    @break
```

Dan dalam fail yang sama, buang baris nota pendaftaran:

```blade
            @elseif($stage === 'register')
                <p class="ev-foot-note">Registration closes {{ $event->registration_close_at->format('d M, h:i A') }}</p>
```

- [ ] **Step 5: Buang borang dari halaman acara**

Dalam `resources/views/events/show.blade.php`, buang baris **121 hingga 382** — iaitu komen blade `{{-- Joining happens under the description`, `@if($canJoin)`, borang, skripnya, dan `@endif` yang menutupnya. Sahkan sempadan dahulu, kerana nombor baris bergerak setiap kali fail disunting:

```bash
cd /f/herd/undilot2026
awk 'NR>=119 && NR<=128 {printf "%d: %s\n", NR, $0}' resources/views/events/show.blade.php
awk 'NR>=379 && NR<=385 {printf "%d: %s\n", NR, $0}' resources/views/events/show.blade.php
```

Jangka: baris 121 ialah `{{-- Joining happens under the description`, baris 127 ialah `@if($canJoin)`, baris 381 ialah `</script>`, baris **382** ialah `@endif`, dan baris 383 ialah `</div>` — yang **kekal**, kerana ia menutup `<div>` kolum, bukan borang.

Buang juga baris 72 (`$canJoin = …`) dan sebarang `$startStep` / `$draft` dalam blok `@php` di atas.

- [ ] **Step 6: Tulis semula blok tindakan dalam `<aside>`**

Dalam `resources/views/events/show.blade.php`, ganti blok dari `@if($onList)` hingga `@endif` terakhir sebelum `@guest('web')` dengan:

```blade
            @if($onList)
                <p class="evd-msg evd-msg-ok">Your company is in this event's draw.</p>
                <a href="{{ route('dashboard') }}" class="evd-cta"><i class="bi bi-check-circle" aria-hidden="true"></i> Go to my dashboard</a>
            @elseif($beingAdded)
                {{-- The office put the company on the list and HQ has not confirmed
                     it yet. The only waiting state left, and the one that matters:
                     there is nothing for the participant to do but wait. --}}
                <p class="evd-msg evd-msg-wait">The office is adding your company to this draw. You will be told once HQ confirms it.</p>
            @elseif($drawOpen)
                @auth('web')
                    <a href="{{ route('dashboard') }}" class="evd-cta"><i class="bi bi-check-circle" aria-hidden="true"></i> Go to my dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="evd-cta"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in to confirm your lot</a>
                @endauth
            @endif
            @guest('web')
                <p class="evd-note">Your login is created by the office. The office adds your company to an event's draw; sign in to confirm your lot once it is open.</p>
            @endguest
```

Buang juga dua blok `session()` yang merujuk `$joinRequest`:

```blade
            @if(session('success') && $joinRequest?->status !== 'pending')
```

menjadi:

```blade
            @if(session('success'))
```

- [ ] **Step 7: Buang `joinRequest` dari `HomeController::show()`**

```php
    public function show(Event $event)
    {
        abort_if($event->status === 'draft', 404);

        // What a signed-in participant's company has to do with this event: on
        // the draw list, being added to it, or neither. There is no third state:
        // a participant cannot ask to join.
        $company = auth('web')->user()?->company;

        return view('events.show', [
            'event' => $event,
            'company' => $company,
            'onList' => $company && $event->registrations()->announced()->where('company_id', $company->id)->exists(),
            // Put on the list by the office, waiting for HQ. Reads
            // event_registrations only — nothing here is a join request.
            'beingAdded' => $company && $event->registrations()->whereNull('first_verified_at')->where('company_id', $company->id)->exists(),
        ]);
    }
```

- [ ] **Step 8: Jalankan ujian dan pastikan ia lulus**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter=ThePublicSiteShowsOnlyWhatIsPublished
```

(Betulkan laluan: `/c/Users/DELL/.config/herd/bin/php84.bat`.)

Jangka: semua lulus. Jika ada `Undefined variable $joinRequest`, satu rujukan view tertinggal — grep untuk `joinRequest` dalam `resources/views/events/show.blade.php`.

- [ ] **Step 9: Sahkan tiada rujukan tertinggal**

```bash
cd /f/herd/undilot2026
grep -n 'joinRequest\|canJoin\|startStep\|\$draft\|events.join' resources/views/events/show.blade.php resources/views/partials/public-event-card.blade.php resources/views/partials/public-event-stage.blade.php
```

Jangka: tiada output.

- [ ] **Step 10: Commit**

```bash
cd /f/herd/undilot2026
git add -A resources/views/events/show.blade.php resources/views/partials/public-event-card.blade.php resources/views/partials/public-event-stage.blade.php app/Models/Event.php app/Http/Controllers/HomeController.php tests/Feature/ThePublicSiteShowsOnlyWhatIsPublishedTest.php
git commit -m "Buang borang sertai dari halaman acara awam

Butang 'Register now' menjanjikan sesuatu yang tiada siapa boleh tunaikan:
pejabat memasukkan setiap syarikat, jadi peserta tidak pernah memohon. Label,
butang, borang berbilang langkah dan skripnya dibuang, dan Event::publicStage()
kehilangan cabang 'register' - acara sebelum cabutan dibuka kini 'soon'.

beingAdded KEKAL. Ia membaca event_registrations dan first_verified_at sahaja -
'pejabat sedang menambah syarikat anda' - dan itu kini satu-satunya keadaan
menunggu yang ada, jadi mesejnya lebih penting bukan kurang.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Papan pemuka peserta tinggal cabutan sahaja

**Files:**
- Modify: `app/Http/Controllers/HomeController.php` (`dashboard()`, `todo()`)
- Modify: `resources/views/dashboard.blade.php` (209 baris)
- Modify: `resources/views/past-events.blade.php`
- Delete: `resources/views/partials/company-record.blade.php`
- Test: `tests/Feature/AParticipantSeesTheirPastEventsTest.php`

**Interfaces:**
- Consumes: `HomeController::show()` daripada Task 2.
- Produces: `HomeController::dashboard()` menghantar `['user', 'company', 'entries', 'drawn', 'todo']` — **tiada lagi `requests` atau `openEvents`**. `HomeController::todo($entries, $drawn)` — dua parameter, bukan empat.

- [ ] **Step 1: Tulis ujian yang gagal**

Tambah pada `tests/Feature/AParticipantSeesTheirPastEventsTest.php`:

```php
it('shows a participant their draws and nothing about requesting to join', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

    $event = Event::factory()->create([
        'title' => 'Pesta Kraf Johor',
        'status' => 'open',
        'polling_status' => 'draft',
        'start_date' => now()->addMonth(),
        'end_date' => now()->addMonth()->addDay(),
    ]);

    EventRegistration::factory()->create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'verified_at' => now(),
        'first_verified_at' => now(),
    ]);

    $page = $this->actingAs($user, 'web')->get(route('dashboard'))->assertOk();
    $body = $page->getContent();

    expect(str_contains($body, 'Pesta Kraf Johor'))
        ->toBeTrue('The draw the office put them in has to be on the dashboard.');

    // The dashboard used to carry two more sections: requests the company sent,
    // and events still open to join. Neither can exist now.
    foreach (['Requests to join', 'Ask to join', 'Finish and send'] as $gone) {
        expect(str_contains($body, $gone))
            ->toBeFalse("The dashboard still shows '{$gone}'.");
    }
});
```

- [ ] **Step 2: Jalankan ujian dan pastikan ia gagal**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="nothing about requesting to join"
```

Jangka: FAIL pada salah satu rentetan "Requests to join".

- [ ] **Step 3: Pangkas `dashboard()`**

Dalam `app/Http/Controllers/HomeController.php`, ganti `dashboard()` dengan:

```php
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        $entries = $user->registrations()->announced()
            ->with(['event', 'plot.plotSize'])
            ->get()
            ->reject(fn ($r) => $r->event->polling_status === 'cancelled')
            // An event that has ended is on Past events, not here.
            ->reject(fn ($r) => $r->event->end_date->isPast())
            // Latest event first; on the same day, the newer event first.
            ->sortBy([fn ($a, $b) => [$b->event->start_date->timestamp, $b->event_id] <=> [$a->event->start_date->timestamp, $a->event_id]])
            ->values();

        $drawn = \App\Models\Poll::where('user_id', $user->id)->pluck('event_id')->flip();

        return view('dashboard', [
            'user' => $user,
            'company' => $company,
            'entries' => $entries,
            'drawn' => $drawn,
            'todo' => $this->todo($entries, $drawn),
        ]);
    }
```

- [ ] **Step 4: Pangkas `todo()`**

Ganti `todo()` dengan:

```php
    /**
     * What is left to do, most pressing first.
     *
     * One thing only: confirm a lot while the draw is open. A participant does
     * not ask to join and does not keep certificates here, so there is nothing
     * else they can act on.
     */
    private function todo($entries, $drawn): array
    {
        $todo = [];

        foreach ($entries as $entry) {
            $event = $entry->event;
            if ($event->polling_status === 'polling' && ! $event->polling_deadline?->isPast() && ! isset($drawn[$event->id])) {
                $todo[] = ['bad', "Confirm your lot for {$event->title} before {$event->polling_deadline->format('d M Y, h:i A')}.", route('zvote.index', $event), 'Confirm Lot'];
            }
        }

        return $todo;
    }
```

- [ ] **Step 5: Buang seksyen permohonan dari view papan pemuka**

```bash
cd /f/herd/undilot2026
grep -n '\$requests\|\$openEvents\|company-record\|join-answers\|Requests to join' resources/views/dashboard.blade.php
```

Buang setiap blok yang grep itu namakan: seksyen `@if($requests->isNotEmpty())` sepenuhnya, seksyen `$openEvents`, dan `@include('partials.company-record')`.

Kemudian:

```bash
rm resources/views/partials/company-record.blade.php
```

- [ ] **Step 6: Buang jawapan permohonan dari acara lepas**

```bash
cd /f/herd/undilot2026
grep -n 'join-answers\|JoinRequest\|joinRequest\|RECOGNITIONS' resources/views/past-events.blade.php
```

Buang lajur dan `@include` yang grep itu namakan, termasuk tajuk lajurnya dalam `<thead>`.

- [ ] **Step 7: Jalankan ujian dan pastikan ia lulus**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter=AParticipantSeesTheirPastEvents
```

Jangka: semua lulus.

- [ ] **Step 8: Sahkan tiada rujukan tertinggal**

```bash
cd /f/herd/undilot2026
grep -rn 'requests\b\|openEvents\|company-record\|join-answers' resources/views/dashboard.blade.php resources/views/past-events.blade.php
```

Jangka: tiada output (atau hanya perkataan `requests` dalam konteks lain yang tidak berkaitan — periksa setiap satu).

- [ ] **Step 9: Commit**

```bash
cd /f/herd/undilot2026
git add -A app/Http/Controllers/HomeController.php resources/views/dashboard.blade.php resources/views/past-events.blade.php tests/Feature/AParticipantSeesTheirPastEventsTest.php
git add -u resources/views/partials/company-record.blade.php
git commit -m "Papan pemuka peserta tinggal cabutan sahaja

Dulu ia menunjukkan tiga perkara: cabutan yang syarikat ada, permohonan yang
dihantar, dan acara yang masih boleh disertai. Dua yang terakhir tidak boleh
wujud lagi.

todo() juga mengecut daripada tiga blok kepada satu. Blok permohonan dan blok
tarikh luput sijil kedua-duanya bergantung pada data yang peserta tidak lagi
hantar; yang tinggal ialah 'sahkan lot anda', yang satu-satunya perkara peserta
boleh bertindak.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Buang lulus/tolak dari sebelah admin

**Files:**
- Modify: `app/Http/Controllers/Admin/EntRegistrationController.php` (643 baris)
- Modify: `resources/views/admin/events/registrations/index.blade.php` (501 baris)
- Modify: `app/Http/Controllers/Admin/DashboardController.php:72-78`
- Delete: `resources/views/partials/payment-proof.blade.php`
- Delete: `resources/views/request-photos.blade.php`
- Test: `tests/Feature/TheDrawListIsTheApprovalTest.php`, `tests/Feature/TheDashboardShowsEachAdminTheirOwnWorkTest.php`

**Interfaces:**
- Consumes: `AddedToDraw` daripada Task 1 (sudah dipanggil dalam `verify()`).
- Produces: `EntRegistrationController::index()` menghantar view tanpa `joinRequests`, `rejected`, `proofs`. Method `approve()` dan `reject()` tiada lagi.

- [ ] **Step 1: Tulis ujian yang gagal**

Tambah pada `tests/Feature/TheDrawListIsTheApprovalTest.php`:

```php
it('has no approve or reject route, because there is nothing to approve', function () {
    // The draw list IS the approval: the zone puts a company on it and HQ
    // verifies the row. A separate approve step belonged to join requests.
    expect(Route::has('admin.events.registrations.approve'))->toBeFalse()
        ->and(Route::has('admin.events.registrations.reject'))->toBeFalse();

    expect(method_exists(App\Http\Controllers\Admin\EntRegistrationController::class, 'approve'))
        ->toBeFalse('approve() is still on the controller.');
    expect(method_exists(App\Http\Controllers\Admin\EntRegistrationController::class, 'reject'))
        ->toBeFalse('reject() is still on the controller.');
});
```

Tambah `use Illuminate\Support\Facades\Route;` di kepala fail itu jika belum ada.

- [ ] **Step 2: Jalankan ujian dan pastikan ia gagal**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="nothing to approve"
```

Jangka: FAIL — route dan method masih ada.

- [ ] **Step 3: Buang route lulus/tolak**

Dalam `routes/web.php`, buang baris 136–137:

```php
                Route::post('join/{joinRequest}/approve', [EntRegistrationController::class, 'approve'])->name('approve');
                Route::post('join/{joinRequest}/reject', [EntRegistrationController::class, 'reject'])->name('reject');
```

- [ ] **Step 4: Buang method dan pengumpulan data dari controller**

Dalam `app/Http/Controllers/Admin/EntRegistrationController.php`:

1. Buang import `use App\Models\EventJoinRequest;`
2. Buang method `approve()` dan `reject()` sepenuhnya
3. Dalam `index()`, buang tiga blok: `$proofs`, `$joinRequests`, dan kunci `'rejected'` dalam array view
4. Buang `'joinRequests' => $joinRequests,` dan `'proofs' => $proofs,` dari array view
5. Dalam `removeFromList()`, buang blok yang menulis `removed_reason`:

```php
        $registration->event->joinRequests()
            ->where('company_id', $registration->company_id)->where('status', 'approved')
            ->get()->each->update(['removed_reason' => $reason]);
```

Kekalkan notifikasi `RemovedFromDraw` — mesejnya sudah membawa sebab itu.

- [ ] **Step 5: Buang panel dari view admin**

Dalam `resources/views/admin/events/registrations/index.blade.php`:

1. Buang blok `@if($joinRequests->isNotEmpty())` sepenuhnya (bermula baris 41)
2. Buang blok `@if($rejected->isNotEmpty())` sepenuhnya (bermula baris 126)
3. Pada baris ~272, tukar sel bukti bayaran supaya hanya borang `payment_note` kekal:

```blade
                                <td class="mac-text-sm">
                                    {{-- Every company is added by the office, so the zone always
                                         writes how it paid: receipt no., date, method. --}}
                                    <form method="POST" action="{{ route('admin.events.registrations.payment-note', [$event, $registration]) }}" class="d-flex gap-1">
```

Buang `@if(isset($proofs[...]))` dan cabang `@include('partials.payment-proof')`, kekalkan borang dalam `@else`.

Kemudian:

```bash
cd /f/herd/undilot2026
rm resources/views/partials/payment-proof.blade.php resources/views/request-photos.blade.php
```

- [ ] **Step 6: Buang dua kiraan papan pemuka admin**

Dalam `app/Http/Controllers/Admin/DashboardController.php`, buang dua entri `withCount`:

```php
                'joinRequests as join_pending' => fn ($q) => $inZone($q)->where('status', 'pending')
                    ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('event_registrations')
                        ->whereColumn('event_registrations.event_id', 'event_join_requests.event_id')
                        ->whereColumn('event_registrations.company_id', 'event_join_requests.company_id')),
```

dan

```php
                'joinRequests as zone_rejected' => fn ($q) => $q->rejectedByZone()->where('reviewed_at', '>=', now()->subDays(7)),
```

**Kedua-dua kiraan itu digunakan dalam controller yang sama, bukan dalam view** — diperiksa: `resources/views/admin/dashboard.blade.php` tidak menyebut salah satu pun. Jadi buang juga dua blok yang membinanya menjadi baris tugasan:

Sekitar baris 106–111, buang seluruh blok A8:

```php
        // A8 — companies asking to join, waiting on this admin's zone.
        foreach ($events as $event) {
            if ($event->join_pending > 0) {
                $rows[] = ['warn', "{$event->title}: {$event->join_pending} join ".str('request')->plural($event->join_pending).' to review',
                    route('admin.events.registrations.index', $event).'#join-requests'];
            }
        }
```

Dan dalam blok A9, buang **hanya** cabang `zone_rejected`, kekalkan `hq_waiting` dan `hq_returned` — itu dua-semakan:

```php
            if ($global && $event->zone_rejected > 0) {
                $rows[] = ['warn', "{$event->title}: {$event->zone_rejected} join ".str('request')->plural($event->zone_rejected).' rejected by zones in the last 7 days',
                    route('admin.events.registrations.index', $event).'#rejected'];
            }
```

Sahkan tiada pengguna lain:

```bash
cd /f/herd/undilot2026
grep -rn 'join_pending\|zone_rejected' app resources
```

Jangka: tiada output.

- [ ] **Step 7: Sunting ujian papan pemuka admin**

```bash
cd /f/herd/undilot2026
grep -n 'join_pending\|zone_rejected\|JoinRequest' tests/Feature/TheDashboardShowsEachAdminTheirOwnWorkTest.php
```

Buang kes dan assertion yang grep itu namakan.

- [ ] **Step 8: Sunting `TheDrawListIsTheApprovalTest`**

```bash
cd /f/herd/undilot2026
grep -n 'JoinRequest\|joinRequest\|approve\|reject' tests/Feature/TheDrawListIsTheApprovalTest.php
```

Buang kes yang menguji lulus/tolak permohonan. Kekalkan setiap kes yang menguji dua-semakan (zon meletakkan baris, HQ mengesahkan, HQ memulangkan dengan nota).

- [ ] **Step 9: Jalankan ujian dan pastikan ia lulus**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="TheDrawListIsTheApproval|TheDashboardShowsEachAdmin"
```

Jangka: semua lulus.

- [ ] **Step 10: Commit**

```bash
cd /f/herd/undilot2026
git add -A app/Http/Controllers/Admin/EntRegistrationController.php app/Http/Controllers/Admin/DashboardController.php resources/views/admin/events/registrations/index.blade.php resources/views/admin/dashboard.blade.php routes/web.php tests/Feature/TheDrawListIsTheApprovalTest.php tests/Feature/TheDashboardShowsEachAdminTheirOwnWorkTest.php
git add -u resources/views/partials/payment-proof.blade.php resources/views/request-photos.blade.php
git commit -m "Buang lulus/tolak permohonan dari sebelah admin

Senarai cabutan ITULAH kelulusan: zon meletakkan syarikat padanya dan HQ
mengesahkan baris itu. Langkah lulus/tolak berasingan milik permohonan sertai.

Bukti bayaran juga: dulu ia datang daripada permohonan untuk syarikat yang
memohon, dan payment_note untuk syarikat yang pejabat tambah. Kini setiap
syarikat ditambah pejabat, jadi payment_note ialah satu-satunya laluan - persis
seperti CLAUDE.md sudah menerangkannya.

removeFromList() tidak lagi menulis removed_reason pada permohonan. Sebab itu
hidup dalam mesej RemovedFromDraw, yang kekal dalam jadual notifications.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Buang controller, model, route dan skema

Tugasan terakhir: tiada apa lagi menunjuk kepada modul itu, jadi ia boleh dipadam.

**Files:**
- Delete: `app/Http/Controllers/JoinRequestController.php`
- Delete: `app/Models/EventJoinRequest.php`
- Delete: `database/factories/EventJoinRequestFactory.php`
- Delete: `tests/Feature/AParticipantAsksToJoinAnEventTest.php`
- Delete: `tests/e2e/05-join-steps.spec.js`
- Modify: `routes/web.php`
- Modify: `app/Models/Event.php`, `app/Models/Company.php`
- Modify: `database/migrations/0001_01_02_000000_create_undilot_schema.php`
- Modify: `database/seeders/DemoEventsSeeder.php`, `database/seeders/DemoScenarioSeeder.php`
- Modify: `app/Http/Controllers/NotificationController.php`, `app/Http/Controllers/DevAutoLoginController.php`
- Test: `tests/Feature/TheDemoSeederPlantsEveryZoneTest.php`, `tests/Feature/LegacyStagingTakesTheOldDumpAsItIsTest.php`

**Interfaces:**
- Consumes: Task 1–4 telah membuang setiap pemanggil.
- Produces: Skema tanpa jadual `event_join_requests` dan tanpa lajur `companies.credentials`.

- [ ] **Step 1: Tulis ujian yang gagal**

Tambah pada `tests/Feature/LegacyStagingTakesTheOldDumpAsItIsTest.php` — fail itu sudah menguji bentuk skema:

```php
it('carries no join-request table and no credentials column', function () {
    // The office enters every company, so there is nothing for a participant to
    // request. `companies.credentials` held the answers a request carried:
    // recognition, two certificates, contact person, five product photos. It was
    // written by exactly one place — the join form — and measured empty on every
    // row in this installation before it was dropped.
    expect(Schema::hasTable('event_join_requests'))->toBeFalse();
    expect(Schema::hasColumn('companies', 'credentials'))->toBeFalse();
});
```

Pastikan `use Illuminate\Support\Facades\Schema;` ada di kepala fail.

- [ ] **Step 2: Jalankan ujian dan pastikan ia gagal**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest --filter="no join-request table"
```

Jangka: FAIL — jadual dan lajur masih ada.

- [ ] **Step 3: Padam fail**

```bash
cd /f/herd/undilot2026
rm app/Http/Controllers/JoinRequestController.php
rm app/Models/EventJoinRequest.php
rm database/factories/EventJoinRequestFactory.php
rm tests/Feature/AParticipantAsksToJoinAnEventTest.php
rm tests/e2e/05-join-steps.spec.js
```

- [ ] **Step 4: Buang route**

Dalam `routes/web.php`, buang:

- baris 14: `use App\Http\Controllers\JoinRequestController;`
- baris 45: route `join-requests.photos`
- baris 50: route `events.join`
- baris 64–72: komen dan dua route `join-requests.document` / `join-requests.photo`

- [ ] **Step 5: Buang method model**

Dalam `app/Models/Event.php`, buang `joinRequests()`, `acceptsJoinRequests()` dan `isCalledOff()`.

`isCalledOff()` tiada pemanggil selepas Task 2–4: keempat-empatnya ialah `approve()`, `reject()`, `HomeController:100` dan `dashboard.blade.php:62`. Sahkan sebelum membuang:

```bash
cd /f/herd/undilot2026
grep -rn "isCalledOff\|acceptsJoinRequests\|joinRequests" app resources database tests routes
```

Jangka: tiada output selain definisi dalam `Event.php` sendiri.

Dalam `app/Models/Company.php`, buang `RECOGNITIONS`, `QUALITY_STANDARDS`, `QUALITY_CRAFTS`, `answersFor()`, `qualityStandard()`, dan `'credentials'` dari `$fillable` dan `$casts`.

- [ ] **Step 6: Buang dari skema**

Dalam `database/migrations/0001_01_02_000000_create_undilot_schema.php`:

1. Buang keseluruhan `Schema::create('event_join_requests', …)` termasuk docblocknya
2. Buang `'event_join_requests',` dari senarai dalam `down()`
3. Buang lajur `credentials` daripada `Schema::create('companies', …)`, termasuk komen di atasnya:

```php
            // What the participant last said about the company's recognition and
            // certificates, filled into the next join request so a returning
            // company only confirms it. Company::credentials() names the keys.
            $table->json('credentials')->nullable();
```

- [ ] **Step 7: Buang dari seeder**

```bash
cd /f/herd/undilot2026
grep -n 'JoinRequest\|joinRequest\|credentials' database/seeders/DemoEventsSeeder.php database/seeders/DemoScenarioSeeder.php
```

Buang setiap penanaman permohonan. Syarikat ditambah terus ke `event_registrations`, seperti pejabat akan buat. Kalau seeder menanam permohonan **dan** pendaftaran untuk syarikat yang sama, kekalkan pendaftaran sahaja.

- [ ] **Step 8: Kemas kini controller yang tinggal**

```bash
cd /f/herd/undilot2026
grep -n 'JoinRequest\|join request\|join-request' app/Http/Controllers/NotificationController.php app/Http/Controllers/DevAutoLoginController.php
```

Dalam `NotificationController`, tukar sebarang rujukan kelas kepada `AddedToDraw`. Dalam `DevAutoLoginController`, kemas kini komen supaya ia menamakan notifikasi yang benar-benar ada: peringatan undian, pemberitahuan dalam-cabutan, notis pembuangan.

- [ ] **Step 9: Sunting ujian seeder**

```bash
cd /f/herd/undilot2026
grep -n 'JoinRequest\|joinRequest' tests/Feature/TheDemoSeederPlantsEveryZoneTest.php
```

Betulkan jangkaan supaya ia mengira pendaftaran, bukan permohonan.

- [ ] **Step 10: Jalankan migration pada MySQL**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat artisan migrate:fresh --seed --force
```

Jangka: tiada ralat. Kalau seeder gagal, satu rujukan permohonan tertinggal dalam Step 7.

- [ ] **Step 11: Jalankan suite penuh pada SQLite**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest
```

Jangka: semua lulus. Jumlah ujian turun sekitar 43 (fail yang dipadam) dan naik 6 (ujian baharu dalam Task 1, 2, 3, 4, 5).

- [ ] **Step 12: Grep terakhir merentas seluruh repo**

```bash
cd /f/herd/undilot2026
grep -rniE 'joinrequest|join_request|join-request|events\.join|credentials|RECOGNITIONS|acceptsJoinRequests|isCalledOff' app resources routes database tests --include=*.php --include=*.blade.php --include=*.js | grep -v 'current_password'
```

Jangka: tiada output. Setiap hit ialah rujukan mati atau penggunaan yang tertinggal — periksa satu-satu.

- [ ] **Step 13: Commit**

```bash
cd /f/herd/undilot2026
git add -A
git commit -m "Buang controller, model, route dan skema permohonan sertai

Tiada apa lagi menunjuk kepada modul itu selepas empat tugasan sebelum ini, jadi
ia dipadam: JoinRequestController, EventJoinRequest, factorynya, empat route,
43 ujian borang sertai, dan satu spec Playwright.

Skema: jadual event_join_requests dan lajur companies.credentials dibuang dari
baseline migration. credentials diukur sebelum diputuskan - 5 daripada 5
syarikat NULL, 0 fail dalam certificates/, products/, join-requests/. Satu-satunya
penulisnya ialah borang sertai, dan import tidak pernah menulisnya kerana dump
lama tiada medannya.

Event::isCalledOff() dibuang juga: keempat-empat pemanggilnya ialah kod
permohonan sertai, jadi mengekalkannya bermakna meninggalkan method tanpa
pemanggil.

MySQL migrate:fresh --seed bersih, suite Pest penuh lulus.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Dokumen dan pengesahan hujung-ke-hujung

**Files:**
- Modify: `CLAUDE.md`
- Modify: `PRODUCT.md:66`
- Modify: `README.md`
- Modify: `tests/e2e/01-smoke.spec.js` (hanya jika Step 3 menjumpai sesuatu)

**Interfaces:**
- Consumes: Task 1–5 selesai.
- Produces: tiada kod.

- [ ] **Step 1: Kemas kini CLAUDE.md**

Empat tempat:

1. **Pengenalan** (sekitar baris 15–19): tambah permohonan sertai kepada senarai yang dibuang.
2. **Baris 50**: `peringatan undian, jawapan permohonan sertai dan notis pembuangan` → notifikasi yang benar-benar ada.
3. **Seksyen dua-semakan**: buang baris tentang permintaan `pending`, dan baris yang menamakan `event_join_requests.removed_reason`. Gantikan yang kedua dengan: sebab hidup dalam mesej `RemovedFromDraw`, yang kekal dalam jadual `notifications`.
4. **Baris tentang `isCalledOff()` / unpublished**: peraturan itu wujud kerana permohonan sertai boleh dihantar sebelum auto_close; buang seluruh baris itu.
5. **Baris 386** (fail muat naik `local`): `Dokumen dan foto permohonan sertai` → sepanduk acara sahaja kini pada cakera `public`; nyatakan apa yang masih ke `local`, atau buang baris itu jika tiada apa lagi.

Sahkan setiap nombor baris dahulu — fail telah berubah sepanjang sesi:

```bash
cd /f/herd/undilot2026
grep -nE 'permintaan sertai|permohonan sertai|isCalledOff|removed_reason' CLAUDE.md
```

- [ ] **Step 2: Kemas kini PRODUCT.md dan README.md**

```bash
cd /f/herd/undilot2026
grep -n 'join' PRODUCT.md README.md
```

`PRODUCT.md:66` menyebut "join-request answers" dalam senarai notifikasi portal. `README.md` menyenaraikan modul.

- [ ] **Step 3: Periksa suite pelayar**

```bash
cd /f/herd/undilot2026
grep -niE 'join|register' tests/e2e/*.spec.js
```

`01-smoke.spec.js` diperiksa dan **tidak** menyentuh borang sertai, jadi ia sepatutnya tidak perlu disunting. Kalau grep menunjukkan sebaliknya, betulkan.

- [ ] **Step 4: Jalankan kedua-dua pemacu**

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat artisan migrate:fresh --seed --force
/c/Users/DELL/.config/herd/bin/php84.bat vendor/pestphp/pest/bin/pest
```

Jangka: migration bersih, semua ujian lulus.

- [ ] **Step 5: Jalankan suite pelayar**

```bash
cd /f/herd/undilot2026
npx playwright test --reporter=list
```

Jangka: 20 ujian (24 tolak 4 dalam `05-join-steps` yang dipadam) — **tetapi** baca kiraan `flaky`, bukan `passed`, dan bandingkan dengan garis dasar merosot yang dinyatakan dalam Global Constraints. Jalankan dua kali.

- [ ] **Step 6: Pandu skrin dengan tangan**

Bina data ujian, kemudian lawati setiap skrin sebagai setiap peranan:

```bash
cd /f/herd/undilot2026
/c/Users/DELL/.config/herd/bin/php84.bat artisan import:legacy kraf_lama.sql
/c/Users/DELL/.config/herd/bin/php84.bat artisan legacy:accounts --users=all
/c/Users/DELL/.config/herd/bin/php84.bat artisan legacy:transform-aacb
curl -s -o /dev/null -w "/ -> %{http_code}\n" http://undilot2026.test/
curl -s -o /dev/null -w "/auto -> %{http_code}\n" http://undilot2026.test/auto
```

Kemudian melalui `/auto`, log masuk sebagai peserta, super dan admin negeri, dan lawati:

- peserta: `/dashboard`, `/events/{event}`, `/events/{event}/lot`, `/profile`, `/notifications`, `/past-events`
- super: `/admin/dashboard`, `/admin/events/{event}/registrations`, `/admin/polling/{event}`, `/admin/companies`
- admin negeri: `/admin/dashboard`, `/admin/events/{event}/registrations`

Setiap satu mesti 200 tanpa `Undefined variable` atau `Route … not defined` dalam badan halaman. Satu `route()` kepada nama yang dibuang memberi 500 pada setiap skrin yang memuatkan layout itu.

- [ ] **Step 7: Commit**

```bash
cd /f/herd/undilot2026
git add -A CLAUDE.md PRODUCT.md README.md
git commit -m "Dokumen: permohonan sertai tiada lagi dalam undilot2026

CLAUDE.md: pengenalan, baris notifikasi berbaris, dua baris dalam seksyen
dua-semakan (permintaan pending, dan removed_reason yang menunjuk ke jadual yang
sudah tiada), peraturan isCalledOff/unpublished yang wujud kerana permohonan
boleh dihantar sebelum auto_close, dan baris fail muat naik.

PRODUCT.md dan README.md: senarai notifikasi dan senarai modul.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Semakan sendiri

**Liputan spec.** Setiap item dalam spec dipetakan:

| Keperluan spec | Tugasan |
|---|---|
| `JoinRequestAnswered` → `AddedToDraw`, cabang kelulusan sahaja | 1 |
| Consent halaman lot tidak disentuh | tiada tugasan menyentuhnya — disahkan dalam Task 6 Step 6 |
| `beingAdded` kekal | 2 Step 7 |
| Butang + label "Register now" | 2 |
| Cabang `register` dalam `publicStage()` | 2 |
| Borang sertai dalam `events/show.blade.php` | 2 |
| Papan pemuka peserta tinggal cabutan | 3 |
| `todo()` mengecut | 3 Step 4 |
| `company-record`, `join-answers`, `payment-proof`, `request-photos` | 3, 4 |
| `approve()`, `reject()`, route | 4 |
| Panel permohonan + bukti bayaran + "Rejected by zones" | 4 |
| Dua kiraan papan pemuka admin | 4 Step 6 |
| `removed_reason` tidak lagi ditulis | 4 Step 4 |
| `JoinRequestController`, `EventJoinRequest`, factory | 5 |
| Jadual `event_join_requests`, lajur `companies.credentials` | 5 Step 6 |
| `Company::RECOGNITIONS` dan rakan-rakannya | 5 Step 5 |
| `Event::isCalledOff()` sebagai kod mati | 5 Step 5 |
| Seeder | 5 Step 7 |
| Ujian: 1 padam, 4 sunting, 1 baharu | 1, 3, 4, 5 |
| `05-join-steps.spec.js` padam | 5 Step 3 |
| CLAUDE.md, PRODUCT.md, README.md | 6 |
| Dua pemacu | 5 Step 10–11, 6 Step 4 |

**Jurang yang ditemui dan ditutup:** spec menamakan "dua kiraan papan pemuka"
tanpa menyebut bahawa kiraan itu **digunakan dalam controller yang sama**, dalam
dua blok baris tugasan (A8 dan satu cabang A9) — bukan dalam view. Membuang
`withCount` sahaja akan meninggalkan `$event->join_pending` yang merujuk
property yang tiada. Task 4 Step 6 kini membuang kedua-dua blok itu dan
menyatakan bahawa `admin/dashboard.blade.php` tidak perlu disunting.

**Ketekalan jenis.** `AddedToDraw::__construct(Event $event)` dalam Task 1 Step 4 dipanggil sebagai `new AddedToDraw($registration->event)` dalam Step 5 — padan. `todo($entries, $drawn)` dalam Task 3 Step 4 dipanggil dengan dua argumen dalam Step 3 — padan.

