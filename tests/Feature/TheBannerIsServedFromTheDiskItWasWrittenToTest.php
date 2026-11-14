<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
 * The event banner is the only upload this application has, and it travels
 * through three things that must agree: the disk it is WRITTEN to, the disk its
 * URL is ASKED of, and the URI the web server serves that disk from.
 *
 * They did not agree, and it still worked on the machine it was written on,
 * which is why this file exists:
 *
 *   storeBanner() writes to the `public` disk.
 *   getBannerUrlAttribute() asked `Storage::url()` — the DEFAULT disk, which
 *   .env sets to `local`, a different directory holding no banners.
 *
 * Both disks answer at `/storage`, so the path came out right by coincidence.
 * On the dev box Herd's nginx serves that URI as static files, so the picture
 * appeared. With no `public/storage` symlink — and there was none — a real
 * server has nothing at that URI, the request falls through to Laravel, and the
 * `storage.local` route Laravel registers for a `serve => true` disk answers 403
 * to anything unsigned. The banner would have been broken on deploy and perfect
 * in development.
 */

it('builds the banner URL from the disk the banner was written to', function () {
    // NOT Storage::fake(). A faked disk substitutes its own URL generator, which
    // returns a root-relative path whatever the real config says — so faking
    // here would quietly assert the fake's behaviour instead of the app's. The
    // URL is pure string work on config; no file is touched.
    $event = Event::factory()->create(['banner' => 'banners/ujian.jpg']);

    // Absolute and built on APP_URL, NOT the root-relative `/storage/...` the
    // local disk returns. A root-relative URL points at the domain root, so an
    // install served under a subpath loses every banner — the subpath rule,
    // arriving through an <img src> rather than a link.
    expect($event->banner_url)->toBe(config('app.url').'/storage/banners/ujian.jpg')
        ->and(str_starts_with($event->banner_url, '/'))->toBeFalse(
            'The banner URL is root-relative, which breaks a subpath install.'
        );
});

it('has no banner URL when there is no banner', function () {
    expect(Event::factory()->create(['banner' => null])->banner_url)->toBeNull();
});

it('writes an uploaded banner to the public disk and nowhere else', function () {
    Storage::fake('public');
    Storage::fake('local');

    $this->actingAs(superAdmin(), 'admin')->post(route('admin.events.store'), [
        'title' => 'Hari Kraf Ujian',
        'location' => 'Dewan Utama',
        'start_date' => now()->addMonth()->format('Y-m-d H:i:s'),
        'end_date' => now()->addMonth()->addDay()->format('Y-m-d H:i:s'),
        'registration_open_at' => now()->format('Y-m-d H:i:s'),
        'registration_close_at' => now()->addWeeks(2)->format('Y-m-d H:i:s'),
        'max_participants' => 50,
        'status' => 'draft',
        'banner' => UploadedFile::fake()->image('sepanduk.jpg', 600, 300),
    ]);

    $banner = Event::where('title', 'Hari Kraf Ujian')->value('banner');

    expect($banner)->not->toBeNull('No banner was stored.');

    Storage::disk('public')->assertExists($banner);
    Storage::disk('local')->assertMissing($banner);
});

it('registers no route that reads or writes the private disk', function () {
    /*
     * `serve => true` on the `local` disk makes Laravel register both a GET and
     * a PUT at /storage/{path} — the PUT WRITES into storage/app/private. Both
     * demand a signed URL, so neither was a way in, but nothing in this app uses
     * that disk, and the GET shadowed the banner URI.
     *
     * Asserted by route name rather than by reading the config, so it holds
     * whatever future version of Laravel registers them.
     */
    expect(Route::has('storage.local'))->toBeFalse(
        'Laravel is serving the private disk at /storage, which shadows the banners.'
    );

    expect(Route::has('storage.local.upload'))->toBeFalse(
        'There is a PUT route that writes into storage/app/private.'
    );
});
