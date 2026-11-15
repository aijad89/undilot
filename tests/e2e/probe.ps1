<#
 # Runs the browser suite while sampling TCP state, so the NEXT flaky run
 # arrives with its own evidence attached.
 #
 #   powershell -File tests/e2e/probe.ps1                       # 3 spaced, then 3 back-to-back
 #   powershell -File tests/e2e/probe.ps1 -Spaced 0 -BackToBack 5
 #
 # Output goes to /e2e-probe (git-ignored, NOT test-results — Playwright empties
 # that folder at the start of every run): summary.csv, one row per run; a
 # samples CSV per run, one row every three seconds; and each run's log + JSON.
 #
 # WHY IT EXISTS. A session once saw 8-9 failures on back-to-back runs with
 # 18,643 sockets in TIME_WAIT, and proposed widening the machine's ephemeral
 # port pool. Nobody had recorded WHERE those sockets came from. Measured with
 # this script on the same commit (2026-11-15): six runs, all 21/21, zero flaky,
 # including three back-to-back. One run adds about 350 TIME_WAIT; the worst
 # moment held 560 ephemeral ports out of 16,384. The suite cannot produce
 # 18,643 on its own. So the cause of that bad session is still unknown — and
 # the only way to learn it is to measure DURING the failure, not after.
 #
 # Columns worth reading: tw_cli_3306 (PHP opening a fresh MySQL connection per
 # request and per queue poll) and ephem_in_use (ports actually held from the
 # pool, any state). If the pool is ever the problem, ephem_in_use says so.
 #
 # Rest defaults to 200s: Windows holds TIME_WAIT for 120s by default, so a
 # spaced run starts on a clean slate.
 #>
param(
    [int]$Spaced = 3,
    [int]$BackToBack = 3,
    [int]$Rest = 200
)

$ErrorActionPreference = 'Continue'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$out  = Join-Path $root 'e2e-probe'
New-Item -ItemType Directory -Force $out | Out-Null
Set-Location $root

function Snapshot {
    $all = Get-NetTCPConnection -ErrorAction SilentlyContinue
    $tw  = $all | Where-Object State -eq 'TimeWait'
    [pscustomobject]@{
        t            = (Get-Date).ToString('HH:mm:ss')
        tw_total     = $tw.Count
        tw_cli_3306  = ($tw | Where-Object RemotePort -eq 3306).Count
        tw_srv_3306  = ($tw | Where-Object LocalPort -eq 3306).Count
        # 8200-8799 is the range playwright.config.js picks the server port from.
        tw_srv_e2e   = ($tw | Where-Object { $_.LocalPort -ge 8200 -and $_.LocalPort -le 8799 }).Count
        tw_cli_e2e   = ($tw | Where-Object { $_.RemotePort -ge 8200 -and $_.RemotePort -le 8799 }).Count
        ephem_in_use = ($all | Where-Object { $_.LocalPort -ge 49152 -and $_.State -ne 'Listen' } |
                        Select-Object -ExpandProperty LocalPort -Unique).Count
    }
}

function Note($text) {
    "$(Get-Date -f HH:mm:ss) $text" | Tee-Object -FilePath (Join-Path $out 'progress.txt') -Append
}

function Run-Suite($label) {
    $json = Join-Path $out "$label.json"
    $log  = Join-Path $out "$label.log"
    $samples = [System.Collections.ArrayList]::new()
    $env:PLAYWRIGHT_JSON_OUTPUT_NAME = $json
    $before = Snapshot
    $start = Get-Date
    $p = Start-Process -FilePath 'cmd.exe' -WorkingDirectory $root -PassThru -WindowStyle Hidden `
        -ArgumentList '/c', "npx playwright test --reporter=list,json > `"$log`" 2>&1"
    while (-not $p.HasExited) { [void]$samples.Add((Snapshot)); Start-Sleep -Seconds 3 }
    $after = Snapshot
    $samples | Export-Csv (Join-Path $out "$label.samples.csv") -NoTypeInformation

    $stats = $null
    try { $stats = (Get-Content $json -Raw | ConvertFrom-Json).stats } catch {}
    $peak = $samples | Sort-Object tw_total -Descending | Select-Object -First 1
    $row = [pscustomobject]@{
        run = $label; secs = [int]((Get-Date) - $start).TotalSeconds
        passed = $stats.expected; failed = $stats.unexpected; flaky = $stats.flaky
        tw_before = $before.tw_total; tw_peak = $peak.tw_total; tw_after = $after.tw_total
        peak_cli_3306 = $peak.tw_cli_3306; peak_srv_3306 = $peak.tw_srv_3306
        peak_srv_e2e = $peak.tw_srv_e2e; peak_cli_e2e = $peak.tw_cli_e2e
        peak_ephem_in_use = ($samples | Measure-Object ephem_in_use -Maximum).Maximum
        failures = (Select-String -Path $log -Pattern '^\s+\d+\) \[chromium\]' |
                    ForEach-Object { $_.Line.Trim() }) -join ' | '
    }
    $row | Export-Csv (Join-Path $out 'summary.csv') -NoTypeInformation -Append
    Note "$label passed=$($row.passed) failed=$($row.failed) flaky=$($row.flaky) tw_peak=$($row.tw_peak) ephem_peak=$($row.peak_ephem_in_use)"
}

$stamp = Get-Date -f 'yyyyMMdd-HHmm'
for ($i = 1; $i -le $Spaced; $i++) {
    Run-Suite "$stamp-spaced$i"
    Note "resting $Rest s (tw now $((Snapshot).tw_total))"
    Start-Sleep -Seconds $Rest
}
for ($i = 1; $i -le $BackToBack; $i++) { Run-Suite "$stamp-b2b$i" }
Note 'done'
