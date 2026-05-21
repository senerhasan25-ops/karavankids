#!/bin/sh
# Installs git hooks for this repo. Run once after cloning.
# Usage: sh scripts/install-hooks.sh

set -e
ROOT=$(git rev-parse --show-toplevel)
HOOK_DIR="$ROOT/.git/hooks"

mkdir -p "$HOOK_DIR"
cp "$ROOT/scripts/hooks/post-commit" "$HOOK_DIR/post-commit"
chmod +x "$HOOK_DIR/post-commit"

echo "[install-hooks] post-commit hook installed: auto-push to origin after each commit."
