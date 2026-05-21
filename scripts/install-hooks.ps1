# Installs git hooks on Windows. Run once after cloning.
# Usage:  powershell -ExecutionPolicy Bypass -File scripts\install-hooks.ps1

$root = git rev-parse --show-toplevel
$hookDir = Join-Path $root ".git\hooks"

New-Item -ItemType Directory -Force -Path $hookDir | Out-Null
Copy-Item -Force (Join-Path $root "scripts\hooks\post-commit") (Join-Path $hookDir "post-commit")

Write-Host "[install-hooks] post-commit hook installed: auto-push to origin after each commit."
