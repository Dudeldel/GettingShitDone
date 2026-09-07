# shellcheck shell=bash
# Shared helpers for the PostToolUse hooks wired up in .claude/settings.json.
# Sourced, never executed. The caller must set PROJECT_DIR first.

# Reads the hook payload from stdin and works out what was edited.
# Sets REL (repo-relative path) and KIND (php|frontend).
# Returns 1 when the edit is nothing these hooks care about.
hook_resolve_target() {
    local payload file

    payload=$(cat)
    file=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
    [[ -n $file && -f $file ]] || return 1

    case $file in
        "$PROJECT_DIR"/*) REL=${file#"$PROJECT_DIR"/} ;;
        /*) return 1 ;;  # edited outside this repo
        *) REL=$file ;;  # already repo-relative
    esac

    case $REL in
        vendor/* | */vendor/* | node_modules/* | */node_modules/* | storage/* | bootstrap/cache/* | frontend/dist/*)
            return 1
            ;;
    esac

    # case globs are not pathname expansion here: * spans / too.
    case $REL in
        frontend/*.ts | frontend/*.tsx | frontend/*.js | frontend/*.jsx) KIND=frontend ;;
        app/*.php | tests/*.php | database/*.php | routes/*.php | config/*.php) KIND=php ;;
        *) return 1 ;;
    esac
}

# Blocking failure: stderr reaches the agent's context, exit 2 tells it to react.
hook_fail() {
    printf '%s\n\n%s\n' "$1" "$(printf '%s' "$2" | head -c 8000)" >&2
    exit 2
}
