#!/usr/bin/env bash
# PostToolUse (Write|Edit) — static analysis on the file the agent touched.
# PHP under app/ -> Larastan level 6 (phpstan.neon). frontend TS -> tsc -b.
set -uo pipefail

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)}"
# shellcheck source=_common.sh
source "$PROJECT_DIR/.claude/hooks/_common.sh"

cd "$PROJECT_DIR" || exit 0
hook_resolve_target || exit 0

case $KIND in
    php)
        # phpstan.neon only declares app/ as a path; tests/ would be noise at level 6.
        [[ $REL == app/*  && -x ./vendor/bin/phpstan ]] || exit 0
        out=$(./vendor/bin/phpstan analyse --no-progress --no-interaction \
            --memory-limit=512M --error-format=raw "$REL" 2>&1) ||
            hook_fail "Larastan (level 6) errors in $REL:" "$out"
        ;;
    frontend)
        [[ -d frontend/node_modules ]] || exit 0
        out=$(cd frontend && npx --no-install tsc -b 2>&1) ||
            hook_fail "TypeScript errors after editing $REL:" "$out"
        ;;
esac

exit 0
