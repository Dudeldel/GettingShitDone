#!/usr/bin/env bash
# PostToolUse (Write|Edit) — format/lint just the file the agent touched.
# PHP -> Pint (auto-fix). frontend TS/JS -> ESLint --fix.
set -uo pipefail

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)}"
# shellcheck source=_common.sh
source "$PROJECT_DIR/.claude/hooks/_common.sh"

cd "$PROJECT_DIR" || exit 0
hook_resolve_target || exit 0

case $KIND in
    php)
        [[ -x ./vendor/bin/pint ]] || exit 0
        out=$(./vendor/bin/pint "$REL" 2>&1) ||
            hook_fail "Pint could not format $REL — fix the syntax error." "$out"
        ;;
    frontend)
        [[ -d frontend/node_modules ]] || exit 0
        out=$(cd frontend && npx --no-install eslint --fix "${REL#frontend/}" 2>&1) ||
            hook_fail "ESLint reports problems in $REL (auto-fixable ones are already applied):" "$out"
        ;;
esac

exit 0
