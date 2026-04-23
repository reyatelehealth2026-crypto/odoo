#!/usr/bin/env bash
# security-cleanup.sh — Remove backdoor artifacts flagged in 2026-04-23 review.
#
# Targets (all unauthenticated, flagged CRITICAL):
#   * add-ssh-key.php   — public SSH key installer (backdoor)
#   * final_verify.php  — SQL injection + unauthenticated debug
#   * final_check.php   — SQL injection + unauthenticated debug (raw mysqli)
#   * RSA public key fragment matching the backdoor planted by add-ssh-key.php,
#     possibly present in authorized_keys files.
#
# SAFETY
#   - idempotent: re-runnable
#   - reversible: every change has a backup; use --rollback to restore
#   - read-only by default: must pass --apply to mutate
#   - out of scope: .env, git state, systemd, nginx/apache config, database,
#     service restart. Script will refuse to touch them.
#
# USAGE
#   bash security-cleanup.sh              # dry-run (default, shows plan only)
#   bash security-cleanup.sh --apply      # actually do it
#   bash security-cleanup.sh --rollback <trash-dir>
#   bash security-cleanup.sh --help

set -u
set -o pipefail

# ----- Config ----------------------------------------------------------------

# Backdoor RSA public key fingerprint fragment (first 60 chars). Lines in
# authorized_keys containing this substring are treated as backdoor entries.
BACKDOOR_KEY_FRAGMENT='AAAAB3NzaC1yc2EAAAADAQABAAACAQCQMFTgHLN6+JSYBbzJeYpIPRv6zSbjiaaQ'

# Candidate docroot prefixes. Files are matched only if they sit under one of
# these trees — we will NOT recurse into / or untrusted directories.
DOCROOT_CANDIDATES=(
    "/home/zrismpsz/public_html/cny.re-ya.com"
    "/home/zrismpsz/public_html"
    "/www/wwwroot/cny.re-ya.com"
    "/www/wwwroot"
    "/var/www/cny.re-ya.com"
    "/var/www/html"
)

# Target filenames to remove. Relative names only — we match any copy under the
# docroot (including archive/ subdirs) without executing arbitrary rm.
TARGET_FILES=(
    "add-ssh-key.php"
    "final_verify.php"
    "final_check.php"
)

# authorized_keys files to scan.
AUTHKEYS_CANDIDATES=(
    "/root/.ssh/authorized_keys"
    "/home/*/.ssh/authorized_keys"
)

# ----- State -----------------------------------------------------------------

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TRASH_DIR="/root/.security-trash-${STAMP}"
LOG_FILE="/var/log/security-cleanup-${STAMP}.log"
APPLY=0
ROLLBACK_DIR=""

# ----- Helpers ---------------------------------------------------------------

usage() {
    sed -n '2,25p' "$0"
    exit 0
}

log() {
    local line="[$(date -u +%FT%TZ)] $*"
    printf '%s\n' "$line"
    if [[ $APPLY -eq 1 ]]; then
        printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true
    fi
}

die() {
    log "ERROR: $*"
    exit 2
}

require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        die "must run as root"
    fi
}

confirm_not_touching_sensitive() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            *.env|*/.env|*.env.*|*/\.git/*|*/systemd/*|*.service|*.socket)
                die "refusing to operate on sensitive path: $arg"
                ;;
        esac
    done
}

# ----- Actions ---------------------------------------------------------------

action_plan_files() {
    local found=0 path
    for dir in "${DOCROOT_CANDIDATES[@]}"; do
        [[ -d "$dir" ]] || continue
        for name in "${TARGET_FILES[@]}"; do
            while IFS= read -r -d '' path; do
                found=$((found + 1))
                log "FOUND   file  $path ($(stat -c%s "$path" 2>/dev/null || echo ?) bytes)"
            done < <(find "$dir" -xdev -maxdepth 6 -type f -name "$name" -print0 2>/dev/null)
        done
    done
    log "plan: $found target PHP file(s)"
}

action_move_files() {
    local moved=0 path relpath dest
    for dir in "${DOCROOT_CANDIDATES[@]}"; do
        [[ -d "$dir" ]] || continue
        for name in "${TARGET_FILES[@]}"; do
            while IFS= read -r -d '' path; do
                confirm_not_touching_sensitive "$path"
                relpath="${path#/}"
                dest="${TRASH_DIR}/files/${relpath}"
                if [[ $APPLY -eq 1 ]]; then
                    mkdir -p "$(dirname "$dest")" || die "mkdir $dest failed"
                    mv -v -- "$path" "$dest" >> "$LOG_FILE" 2>&1 || die "mv $path failed"
                    moved=$((moved + 1))
                    log "MOVED   file  $path  ->  $dest"
                else
                    log "DRYRUN  would-mv $path  ->  $dest"
                fi
            done < <(find "$dir" -xdev -maxdepth 6 -type f -name "$name" -print0 2>/dev/null)
        done
    done
    log "result: moved $moved file(s)"
}

expand_authkeys() {
    local pattern expanded f
    for pattern in "${AUTHKEYS_CANDIDATES[@]}"; do
        # shellcheck disable=SC2206
        expanded=( $pattern )
        for f in "${expanded[@]}"; do
            [[ -f "$f" ]] && printf '%s\n' "$f"
        done
    done
}

action_scan_authkeys() {
    local f matched=0 hits
    while IFS= read -r f; do
        [[ -n "$f" ]] || continue
        hits=$(grep -c -F -- "$BACKDOOR_KEY_FRAGMENT" "$f" 2>/dev/null || echo 0)
        if [[ "$hits" -gt 0 ]]; then
            log "FOUND   key   $f  (occurrences=$hits)"
            matched=$((matched + 1))
        else
            log "CLEAN   key   $f  (no backdoor fragment)"
        fi
    done < <(expand_authkeys)
    log "plan: $matched authorized_keys file(s) contain backdoor fragment"
}

action_strip_authkeys() {
    local f stripped=0 backup tmp
    while IFS= read -r f; do
        [[ -n "$f" ]] || continue
        grep -q -F -- "$BACKDOOR_KEY_FRAGMENT" "$f" 2>/dev/null || continue
        confirm_not_touching_sensitive "$f"
        backup="${TRASH_DIR}/authkeys${f}.bak"
        if [[ $APPLY -eq 1 ]]; then
            mkdir -p "$(dirname "$backup")" || die "mkdir $backup failed"
            cp -a -- "$f" "$backup" || die "cp $f failed"
            tmp="$(mktemp)"
            grep -v -F -- "$BACKDOOR_KEY_FRAGMENT" "$f" > "$tmp" || true
            chown --reference="$f" "$tmp" 2>/dev/null || true
            chmod --reference="$f" "$tmp" 2>/dev/null || true
            mv -f -- "$tmp" "$f" || die "mv tmp -> $f failed"
            stripped=$((stripped + 1))
            log "STRIPPED key   $f  (backup: $backup)"
        else
            log "DRYRUN  would-strip $f  (backup would be: $backup)"
        fi
    done < <(expand_authkeys)
    log "result: stripped $stripped authorized_keys file(s)"
}

action_rollback() {
    local src="$1"
    [[ -d "$src" ]] || die "rollback dir not found: $src"
    log "ROLLBACK from $src"

    if [[ -d "$src/files" ]]; then
        (cd "$src/files" && find . -type f -print0) | while IFS= read -r -d '' rel; do
            local orig="/${rel#./}"
            if [[ -e "$orig" ]]; then
                log "SKIP    rollback-file $orig (already exists)"
                continue
            fi
            confirm_not_touching_sensitive "$orig"
            mkdir -p "$(dirname "$orig")"
            cp -a -- "$src/files/$rel" "$orig" && log "RESTORED file  $orig"
        done
    fi

    if [[ -d "$src/authkeys" ]]; then
        (cd "$src/authkeys" && find . -type f -name '*.bak' -print0) | while IFS= read -r -d '' rel; do
            local orig_rel="${rel%.bak}"
            local orig="/${orig_rel#./}"
            confirm_not_touching_sensitive "$orig"
            cp -a -- "$src/authkeys/$rel" "$orig" && log "RESTORED key   $orig"
        done
    fi

    log "rollback complete"
}

# ----- Main ------------------------------------------------------------------

main() {
    local arg
    while [[ $# -gt 0 ]]; do
        arg="$1"; shift
        case "$arg" in
            --apply)        APPLY=1 ;;
            --dry-run)      APPLY=0 ;;
            --rollback)     ROLLBACK_DIR="${1:?--rollback requires a path}"; shift ;;
            -h|--help)      usage ;;
            *)              die "unknown arg: $arg" ;;
        esac
    done

    require_root

    if [[ -n "$ROLLBACK_DIR" ]]; then
        action_rollback "$ROLLBACK_DIR"
        exit 0
    fi

    if [[ $APPLY -eq 1 ]]; then
        mkdir -p "$TRASH_DIR" || die "mkdir $TRASH_DIR failed"
        mkdir -p "$(dirname "$LOG_FILE")" || true
        : > "$LOG_FILE" || die "cannot write $LOG_FILE"
        log "APPLY mode: trash=$TRASH_DIR log=$LOG_FILE"
    else
        log "DRY-RUN mode (pass --apply to mutate)"
    fi

    log "=== phase 1: scan PHP targets ==="
    action_plan_files

    log "=== phase 2: scan authorized_keys ==="
    action_scan_authkeys

    log "=== phase 3: move PHP targets ==="
    action_move_files

    log "=== phase 4: strip backdoor key from authorized_keys ==="
    action_strip_authkeys

    if [[ $APPLY -eq 1 ]]; then
        log ""
        log "DONE. To rollback:"
        log "  bash $0 --rollback $TRASH_DIR"
        log ""
        log "Remaining manual steps (NOT performed by this script):"
        log "  1) Set env vars in your deployment environment:"
        log "       CNY_ODOO_USER_TOKEN=<rotated>"
        log "       GEMINI_API_KEY=<rotated>"
        log "       REDIS_PASSWORD=<rotated>"
        log "  2) Rotate all three credentials at the provider."
        log "  3) Change the root password (exposed in chat transcript)."
        log "  4) Audit ~/.ssh/authorized_keys for any other unfamiliar keys."
    else
        log ""
        log "Re-run with --apply to perform the plan above."
    fi
}

main "$@"
