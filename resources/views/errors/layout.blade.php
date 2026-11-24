{{--
    Every error page (403, 404, 419, 500, 503), in undilot's look instead of
    Laravel's bare one, with a way back. Standalone ON PURPOSE: no app layout,
    no database read, no CDN — a 500 page that itself needs the database or
    the portal bar is a second error on top of the first.

    Where "back" goes: an /admin URL to the admin dashboard — a signed-out
    admin is sent on to the sign-in by the app's own redirect. Anything else
    to the participant's dashboard when signed in, else the event list.

    Measured: a URL that matches NO route is answered without starting the
    session, so nobody looks signed in there. That is why the admin side does
    not ask, and why a participant on such a URL is offered the event list.
    Every link through route(), so it holds under a subpath install.
--}}
@php
    $isAdmin = request()->is('admin', 'admin/*');
    try {
        $participant = ! $isAdmin && auth('web')->check();
    } catch (\Throwable $e) {
        $participant = false;
    }
    $home = match (true) {
        $isAdmin => [route('admin.dashboard'), 'Go to the admin dashboard'],
        $participant => [route('dashboard'), 'Go to your dashboard'],
        default => [route('home'), 'Go to the event list'],
    };
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · undilot</title>
    @include('partials.ui-tokens')
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; box-sizing: border-box; background: var(--ui-ground); color: var(--ui-text); font-family: var(--ui-font); }
        .err { width: 100%; max-width: 460px; padding: 32px 28px; background: var(--ui-surface); border: 1px solid var(--ui-border); border-radius: var(--ui-r-lg); box-shadow: var(--ui-shadow-1); text-align: center; }
        .err-brand { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 24px; font-weight: 700; color: var(--ui-text-2); }
        .err-mark { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: var(--ui-warn, #d98a00); color: #fff; font-size: 14px; }
        .err-code { margin: 0; font-size: 3rem; font-weight: 800; line-height: 1; color: var(--ui-muted); letter-spacing: -0.02em; }
        .err-title { margin: 12px 0 8px; font-size: 1.375rem; }
        .err-text { margin: 0 0 24px; color: var(--ui-text-2); line-height: 1.55; }
        .err-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .err-btn { display: inline-block; padding: 10px 18px; border-radius: var(--ui-r); font-weight: 600; text-decoration: none; border: 1px solid transparent; cursor: pointer; font-size: 1rem; font-family: inherit; }
        .err-btn-primary { background: var(--ui-blue); color: #fff; }
        .err-btn-secondary { background: var(--ui-surface); color: var(--ui-text); border-color: var(--ui-border); }
    </style>
</head>
<body>
    <main class="err">
        <div class="err-brand"><span class="err-mark" aria-hidden="true">▦</span> undilot</div>
        <p class="err-code">@yield('code')</p>
        <h1 class="err-title">@yield('title')</h1>
        <p class="err-text">@yield('message')</p>
        <div class="err-actions">
            @hasSection('action')
                @yield('action')
            @else
                <a class="err-btn err-btn-primary" href="{{ $home[0] }}">{{ $home[1] }}</a>
                <button type="button" class="err-btn err-btn-secondary" onclick="history.back()">Go back</button>
            @endif
        </div>
    </main>
</body>
</html>
