#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) — refuse d'ecrire un secret en dur.
#
# C'est la faute la plus repandue de meilleursconcours.com : login et mot de
# passe de la regie d'affiliation en clair dans PlateformeTask.php, cle
# reCAPTCHA dans un template, cle d'API dans une URL de tache. Ici tout passe
# par le .env. Bloquant.
set -uo pipefail

FILE="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
[ -z "$FILE" ] && exit 0
[ -f "$FILE" ] || exit 0

# Le .env et son exemple sont les seuls endroits ou une cle a le droit d'exister.
case "$FILE" in
  *.env|*.env.*|*/.claude/hooks/no-secrets.sh) exit 0 ;;
  *.php|*.twig|*.js|*.sh|*.yml|*.yaml) ;;
  *) exit 0 ;;
esac

HITS="$(grep -nEi \
  -e '(login|user(name)?)=[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+' \
  -e '(pass|passwd|password|pwd)=[^"'"'"'&[:space:]]{6,}' \
  -e '(api[_-]?key|apikey|secret|token)["'"'"' ]*[:=]["'"'"' ]*[A-Za-z0-9._\-]{16,}' \
  "$FILE" 2>/dev/null | grep -vE 'getenv|\$_ENV|\$config|->get\(|require\(|\{\{|\{%|<\?=|process\.env' | head -5)"

if [ -n "$HITS" ]; then
  {
    echo "[secrets] valeur qui ressemble a un identifiant en dur dans $FILE :"
    echo "$HITS"
    echo
    echo "Passe par le .env et App\\Core\\Config (voir CLAUDE.md, section Conventions)."
  } >&2
  exit 2
fi
exit 0
