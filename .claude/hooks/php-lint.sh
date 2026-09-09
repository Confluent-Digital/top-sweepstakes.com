#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) — `php -l` sur le fichier PHP edite.
# Bloquant : sur erreur de syntaxe, sort en 2 pour que Claude corrige tout de suite.
set -uo pipefail
ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
# shellcheck source=lib-php-lint.sh
. "$ROOT/.claude/hooks/lib-php-lint.sh"

FILE="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
[ -z "$FILE" ] && exit 0

case "$FILE" in
  *.php) ;;
  *) exit 0 ;;
esac
[ -f "$FILE" ] || exit 0

if ! OUT="$(tsw_php_lint "$FILE")"; then
  {
    echo "[php -l] erreur de syntaxe — corrige avant de continuer :"
    echo "$OUT" | grep -iE "error|parse" | head -10
  } >&2
  exit 2
fi
exit 0
