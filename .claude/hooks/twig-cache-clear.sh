#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) — vide le cache Twig compile des qu'un
# `.twig` est edite. Evite le piege « ma modif de template n'apparait pas ».
# Non bloquant.
set -uo pipefail
ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"

FILE="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
case "$FILE" in
  *.twig) ;;
  *) exit 0 ;;
esac

[ -d "$ROOT/cache/twig" ] && rm -rf "$ROOT"/cache/twig/* 2>/dev/null || true
exit 0
