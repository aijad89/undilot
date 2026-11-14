#!/usr/bin/env node
//
// The isolated environment the browser suite runs against, as one process.
//
// In `daftar` three consecutive causes of browser-suite failures were hand setup,
// not code: fixtures seeding one database while the browser read another; uploads
// failing because `artisan serve` drops TMP/TEMP when APP_ENV is set; and no
// notification ever arriving because nothing ran `queue:work`. Each was remembered
// once and forgotten once. This file is the remembering: playwright.config.js
// names it in `webServer`, so an isolated run is provided, not assembled.
//
// THE ENVIRONMENT IS A FILE, `.env.e2e`, WRITTEN HERE ON EVERY RUN. Process
// variables are not enough: `artisan serve` hands its PHP child only an allowlist
// of them. APP_ENV gets through and DB_DATABASE does not, so on the first run of
// this suite the server read `.env` and served the DEVELOPMENT database while the
// fixtures wrote to the copy. With APP_ENV=e2e, Laravel loads `.env.e2e` in every
// process, the server's child included. It is `.env` with the e2e keys replaced;
// .gitignore keeps it off the repo, since it carries the app key and DB password.

const fs = require('fs');
const { spawn, spawnSync } = require('child_process');
const path = require('path');
const { E2E_ENV } = require('./env');

const ROOT = path.resolve(__dirname, '..', '..');
const PORT = process.env.PLAYWRIGHT_PORT || '8124';
const HOST = '127.0.0.1';
const PHP = process.env.PHP_BINARY || 'php';

const overrides = { ...E2E_ENV, APP_URL: `http://${HOST}:${PORT}` };
const base = fs.readFileSync(path.join(ROOT, '.env'), 'utf8')
    .split(/\r?\n/)
    .filter((line) => !Object.keys(overrides).some((key) => line.startsWith(`${key}=`)));
fs.writeFileSync(
    path.join(ROOT, '.env.e2e'),
    [...base, '', '# Written by tests/e2e/serve.js — do not edit; it is rewritten every run.',
        ...Object.entries(overrides).map(([key, value]) => `${key}=${value}`), ''].join('\n'),
);

const env = { ...process.env, ...overrides };

function php(args, label) {
    process.stdout.write(`[serve] ${label}\n`);
    const result = spawnSync(PHP, args, { cwd: ROOT, env, stdio: 'inherit', shell: true });

    if (result.status !== 0) {
        process.stderr.write(`[serve] FAILED: ${PHP} ${args.join(' ')} exited ${result.status}\n`);
        process.exit(1);
    }
}

// ── 0. Whatever the last run left running ────────────────────────────────────
//
// THE SIGNAL HANDLERS AT THE BOTTOM CANNOT COVER THIS, because when a run is
// interrupted hard — a wrapper's timeout, a crashed Playwright, a closed
// terminal — no signal arrives, and on Windows a kill of this process never
// reaches the grandchildren `shell: true` creates. `artisan serve` and
// `queue:work` simply keep running.
//
// A forgotten worker is not idle. `--sleep=1` means it asks MySQL for work once
// a SECOND, and each ask is a fresh connection that then sits in TIME_WAIT for
// minutes. Measured on this machine: orphaned workers accounted for 4,328
// TIME_WAIT sockets to 127.0.0.1:3306, and the fixed port they were started on
// carried 1,442 of its own. A readiness poll whose 4-tuple collides with one of
// those gets a DROPPED SYN rather than a refusal, so it hangs instead of
// failing — and Playwright's readiness check has no per-request timeout. That
// is how this suite stopped starting at all, with an error that blamed the web
// server. (The machine-wide count went past the ephemeral range without
// outbound connections failing, so the collision is per-port; see CLAUDE.md.)
//
// FOUND BY COMMAND LINE, NOT BY A RECORDED PID, and that was learned the hard
// way: `shell: true` means `child.pid` is the SHELL's pid, not PHP's. When the
// shell dies and PHP does not, the recorded pid refers to something already
// gone while the process that matters keeps running under a pid nobody wrote
// down. Tested by killing this script and watching the next run sweep nothing.
//
// Two signatures, both narrow enough to be safe:
//   - a PHP process whose command line names THIS project's directory, which is
//     how `artisan serve` spells its router script; and
//   - `queue:work --tries=1 --sleep=1`, the exact flags used below and nowhere
//     else.
// Anything else a developer is running is left alone.
function sweepStrays() {
    const listing = process.platform === 'win32'
        ? spawnSync('powershell', ['-NoProfile', '-Command',
            "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | ForEach-Object { \"$($_.ProcessId)`t$($_.ParentProcessId)`t$($_.CommandLine)\" }"],
        { encoding: 'utf8' })
        : spawnSync('ps', ['-eo', 'pid=,ppid=,args='], { encoding: 'utf8' });

    // LOWERCASED BEFORE COMPARING, because Windows paths are case-insensitive
    // and JavaScript string matching is not. Node resolves this file's directory
    // to `F:\herd\undilot2026` while the process list spells the same directory
    // `F:\Herd\undilot2026`, so a plain `includes` matched nothing and the sweep
    // took one process out of three — the queue worker, whose signature carries
    // no path at all.
    const root = ROOT.toLowerCase();

    const rows = (listing.stdout || '').split(/\r?\n/).filter(Boolean)
        .map((line) => {
            const [pid, parent] = line.trim().split(/[\t\s]+/);

            return { pid, parent, cmd: line.toLowerCase() };
        });

    const doomed = new Set();

    for (const row of rows) {
        if (! row.pid) {
            continue;
        }

        // The router process spells the project directory in its own arguments,
        // which is what makes this safe to match on.
        if (row.cmd.includes(root) || row.cmd.includes('queue:work --tries=1 --sleep=1')) {
            doomed.add(row.pid);

            // AND ITS PARENT. `artisan serve` is a supervisor: kill only the
            // router beneath it and it starts a fresh one, which is why the
            // first version of this swept two processes and left six running.
            // The parent is reached through the child rather than matched
            // directly, because the supervisor's own arguments name no project
            // and a developer may be running one of their own.
            if (rows.some((other) => other.pid === row.parent)) {
                doomed.add(row.parent);
            }
        }
    }

    for (const pid of doomed) {
        process.stdout.write(`[serve] killing a leftover from an interrupted run: ${pid}\n`);

        if (process.platform === 'win32') {
            spawnSync('taskkill', ['/pid', pid, '/T', '/F'], { stdio: 'ignore' });
        } else {
            try { process.kill(Number(pid), 'SIGKILL'); } catch { /* already gone */ }
        }
    }
}

sweepStrays();

// ── 1. A known database, every time ──────────────────────────────────────────
// A run that starts from whatever the last run left is a run whose result
// depends on history. The fixture runner refuses to touch anything but the copy.
php(['tests/e2e/fixtures/run.php', 'ensure-database.php'], `making sure ${E2E_ENV.DB_DATABASE} exists`);
php(['artisan', 'migrate:fresh', '--seed', '--seeder=Database\\Seeders\\E2eSeeder', '--force'], `resetting ${E2E_ENV.DB_DATABASE} and seeding it`);

// ── 2. The application server ────────────────────────────────────────────────
// `--no-reload` AND `PHP_CLI_SERVER_WORKERS`, together or neither: artisan
// prints "Unable to respect the PHP_CLI_SERVER_WORKERS environment variable
// without the --no-reload flag. Only creating a single server" and serves one
// request at a time otherwise.
//
// One request at a time is wrong for this suite specifically. A spec clicks
// while the queue worker is also talking to MySQL, and a single server makes
// those queue behind each other — which is how `page.check` and
// `page.selectOption` reach Playwright's 30s limit with nothing actually broken.
// Reloading is no loss here: the server is started fresh for every run and the
// code cannot change underneath it.
process.stdout.write(`[serve] starting the application on http://${HOST}:${PORT}\n`);
const server = spawn(
    PHP,
    ['artisan', 'serve', `--host=${HOST}`, `--port=${PORT}`, '--no-reload'],
    { cwd: ROOT, env: { ...env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'inherit', shell: true },
);

// ── 3. The queue worker ──────────────────────────────────────────────────────
// Every notification is queued; without this nothing is ever delivered.
// --tries=1: a test run wants the first failure. --sleep=1: a spec waiting for a
// broadcast should not wait three seconds for the worker to look.
process.stdout.write('[serve] starting the queue worker\n');
const worker = spawn(PHP, ['artisan', 'queue:work', '--tries=1', '--sleep=1'], { cwd: ROOT, env, stdio: 'inherit', shell: true });

// ── One lifetime ─────────────────────────────────────────────────────────────
// The children go when Playwright stops this process, or the next run finds the
// port taken and a worker still holding the old database.
let stopping = false;

function stop(code) {
    if (stopping) return;
    stopping = true;

    for (const child of [server, worker]) {
        if (child.pid && !child.killed) {
            try {
                // On Windows a signal does not reach the grandchildren `shell: true`
                // creates, so the tree is taken by pid.
                if (process.platform === 'win32') {
                    spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
                } else {
                    child.kill('SIGTERM');
                }
            } catch { /* already gone */ }
        }
    }

    process.exit(code);
}

for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.on(signal, () => stop(0));
}
process.on('exit', () => stop(0));

server.on('exit', (code) => { process.stderr.write(`[serve] the application server exited (${code})\n`); stop(code ?? 1); });
worker.on('exit', (code) => { process.stderr.write(`[serve] the queue worker exited (${code})\n`); stop(code ?? 1); });
