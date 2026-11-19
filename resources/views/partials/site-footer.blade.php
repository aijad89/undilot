{{--
    THE participant footer: the portal (layouts.frontend) and the public pages
    (layouts.public) both include it, so it never changes between them.

    Melon Ticket's measure: one row on the 1000px column, 64px below the content,
    as tall as the top bar (64px), small grey type. It carries the mark and the
    copyright on the left and nothing on the right: the office's footer pages and
    social links were dropped with the fork, so there is no panel above this and
    no link row beside it. On a phone it stacks, centred.

    Kept short on purpose: both layouts are a full-height flex column whose <main>
    grows, so on a short page this sits at the bottom of the window — but only if
    content + 64px + this footer fits. A taller footer pushed it below the fold
    at 1440 × 900 with two events on the list.
--}}
<style>
    .site-footer-wrap { width: 100%; margin-top: 64px; flex-shrink: 0; }

    /* As tall as the top bar — min-height, not height, so a copyright line that wraps can still grow it. */
    .site-footer { padding: 0; background: var(--ui-surface); border-top: 1px solid var(--ui-border); font-size: 0.875rem; color: var(--ui-muted); }
    .site-footer-inner { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px; min-height: calc(var(--topbar-h) - 1px); max-width: var(--ui-page-max); margin: 0 auto; padding: 0 var(--ui-page-pad); }
    .site-footer-left { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; }
    .site-footer-brand { display: inline-flex; align-items: center; gap: 8px; color: var(--ui-text); font-weight: 700; text-decoration: none; }
    .site-footer-brand:hover { color: var(--ui-text); }
    .site-footer-icon { width: 24px; height: 24px; flex-shrink: 0; display: grid; place-items: center; border-radius: 6px; background: var(--ui-gold); color: var(--ui-navy-950); font-size: 0.75rem; }

    @media (max-width: 640px) {
        .site-footer-inner, .site-footer-left { flex-direction: column; justify-content: center; text-align: center; }
        .site-footer { padding: 20px 0; }
    }
</style>

<div class="site-footer-wrap">
    <footer class="site-footer">
        <div class="site-footer-inner">
            <div class="site-footer-left">
                <a href="{{ route('home') }}" class="site-footer-brand">
                    <span class="site-footer-icon" aria-hidden="true"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                    {{ \App\Models\Setting::get('site_name', 'undilot') }}
                </a>
                <span>&copy; {{ date('Y') }} Perbadanan Kemajuan Kraftangan Malaysia</span>
            </div>
        </div>
    </footer>
</div>
