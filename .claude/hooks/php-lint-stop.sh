#!/usr/bin/env bash
# Stop — `php -l` sur tous les fichiers PHP modifies sur la branche avant que
# Claude ne rende la main. Bloquant, avec garde anti-boucle (stop_hook_active).
set -uo pipefail
ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
# shellcheck source=lib-php-lint.sh
. "$ROOT/.claude/hooks/lib-php-lint.sh"

PAYLOAD="$(cat)"
ACTIVE="$(printf '%s' "$PAYLOAD" | jq -r '.stop_hook_active // false' 2>/dev/null)"
[ "$ACTIVE" = "true" ] && exit 0

cd "$ROOT" || exit 0

BASE="$(git merge-base HEAD main 2>/dev/null || echo HEAD)"
FILES="$( { git diff --name-only "$BASE" 2>/dev/null; \
            git diff --name-only --cached 2>/dev/null; \
            git ls-files --others --exclude-standard 2>/dev/null; } \
          | grep '\.php$' | sort -u )"
[ -z "$FILES" ] && exit 0

FAILED=""
while IFS= read -r f; do
  [ -f "$f" ] || continue
  if ! OUT="$(tsw_php_lint "$f")"; then
    FAILED+="$(echo "$OUT" | grep -iE 'error|parse' | head -3)"$'\n'
  fi
done <<< "$FILES"

if [ -n "$FAILED" ]; then
  {
    echo "[php -l] des fichiers PHP modifies ont une erreur de syntaxe :"
    echo "$FAILED"
  } >&2
  exit 2
fi
exit 0
