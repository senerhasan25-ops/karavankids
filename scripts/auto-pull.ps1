# Auto-pull: periodic git pull from remote, called by scheduled task.
# Behavior:
# - fetch + ff-only pull per current branch.
# - skip if there are uncommitted changes (do not touch user work).
# - on conflict, log failure but do not break commits/push.
# - log to storage/logs/auto-pull.log.

$ErrorActionPreference = 'Continue'
$repoRoot = $PSScriptRoot | Split-Path -Parent
$logFile = Join-Path $repoRoot "storage\logs\auto-pull.log"
$logDir = Split-Path $logFile -Parent
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Force -Path $logDir | Out-Null }

function Write-Log($msg) {
    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $logFile -Value ("[" + $stamp + "] " + $msg) -Encoding utf8
}

Set-Location $repoRoot

$dirty = git status --porcelain
if ($dirty) {
    Write-Log "SKIP: uncommitted changes, pull skipped."
    exit 0
}

$branch = (git rev-parse --abbrev-ref HEAD).Trim()
Write-Log ("PULL begin: branch=" + $branch)

git fetch origin 2>&1 | Out-Null

$behind = (git rev-list --count ("HEAD..origin/" + $branch) 2>$null)
if (-not $behind -or $behind -eq "0") {
    Write-Log ("OK: up to date (branch=" + $branch + ")")
    exit 0
}

$result = git pull --ff-only origin $branch 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Log ("OK: pulled " + $behind + " commits (branch=" + $branch + ")")
} else {
    Write-Log ("FAIL: ff-only pull failed (branch=" + $branch + "): " + $result)
}
