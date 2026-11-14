const { defineConfig, devices } = require('@playwright/test');
const { E2E_ENV } = require('./tests/e2e/env');

// The e2e environment goes on THIS process too, not only on the server: specs
// shell out to tests/e2e/fixtures/run.php, and those children inherit from here.
// Without it they would read `.env` and write into the development database.
Object.assign(process.env, E2E_ENV);

/*
 * A DIFFERENT PORT EACH RUN, and it is a fix rather than a flourish.
 *
 * On a fixed port this suite stopped starting on Windows, and the error blamed
 * the wrong thing: `Timed out waiting 180000ms from config.webServer`, while the
 * server above it had already printed "Server running". Measured — 1,442 sockets
 * in TIME_WAIT on 8124 alone and 12,924 across the machine, against an ephemeral
 * range of 16,384. Every readiness poll opens a connection, Windows holds each
 * one in TIME_WAIT for minutes, and runs accumulate. Once a poll's 4-tuple
 * collides with a lingering one the SYN is DROPPED rather than refused, so the
 * request hangs instead of failing — and Playwright's readiness check has no
 * per-request timeout, so one hung poll eats the whole 180 seconds.
 *
 * The tell is in the debug log (`DEBUG=pw:webserver`): the early polls answer
 * `ECONNREFUSED` in milliseconds, then one `HTTP GET` line sits alone for three
 * minutes. That is a dropped SYN, not a slow server.
 *
 * Moving ports spreads the sockets so no single port's pool is ever spent. The
 * range is above the registered ports and below the ephemeral range, so it
 * cannot collide with what Windows hands out. PLAYWRIGHT_PORT still wins when
 * it is set, which is how a run is pinned for debugging.
 *
 * WRITTEN BACK INTO process.env, and that is the whole trick. This config is
 * re-evaluated inside every worker process, and a worker is a child with its own
 * pid — so deriving the port from the pid alone gave the workers a DIFFERENT
 * port from the one the server was started on, and every spec failed with
 * `net::ERR_ABORTED` against a port nothing was listening on. Setting it here
 * means the workers inherit it and the `||` below short-circuits to the value
 * the parent already chose. Same reason E2E_ENV is assigned above.
 */
process.env.PLAYWRIGHT_PORT = process.env.PLAYWRIGHT_PORT
    || String(8200 + (process.pid % 600));

const PORT = process.env.PLAYWRIGHT_PORT;

module.exports = defineConfig({
    testDir: './tests/e2e',

    // One database, shared and mutated in file order — so one worker, in order.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,

    // ONE RETRY, AS A MEASUREMENT, NOT A MUTE. Playwright reports a test that failed
    // and then passed as `flaky`, on its own line apart from `passed`. One green run
    // is not proof on a browser suite: in `daftar` four full runs of unchanged code
    // gave four different sets of failures. READ THE FLAKY COUNT. Flaky > 0 is not
    // a clean pass; it is a second attempt. A genuinely broken spec fails both and
    // is still red.
    retries: 1,
    reporter: 'list',

    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        trace: 'on-first-retry',
    },

    // serve.js owns the database reset, the server and the queue worker — one
    // lifetime. Never reuse a server started by hand: that is how a run gets the
    // right port and the wrong database.
    webServer: {
        command: 'node tests/e2e/serve.js',
        url: `http://127.0.0.1:${PORT}/login`,
        reuseExistingServer: false,
        timeout: 180 * 1000,
        stdout: 'pipe',
        stderr: 'pipe',
        env: { ...E2E_ENV, PLAYWRIGHT_PORT: PORT },
    },

    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
