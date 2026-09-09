#!/usr/bin/env bash
#
# Wysyłka aplikacji na serwer VPS. Uruchom NA SWOIM KOMPUTERZE z katalogu projektu:
#
#   bash deploy/deploy.sh --host=root@1.2.3.4
#   bash deploy/deploy.sh --host=root@1.2.3.4 --dir=/var/www/granice --dry-run
#
# Wysyła tylko kod aplikacji: pomija .git, cache, testy i config/local.php,
# żeby nie nadpisać ustawień serwera.

set -euo pipefail

HOST=""
REMOTE_DIR="/var/www/granice"
PORT="22"
DRY_RUN=""
RESTART="1"

for arg in "$@"; do
    case "$arg" in
        --host=*)    HOST="${arg#*=}" ;;
        --dir=*)     REMOTE_DIR="${arg#*=}" ;;
        --port=*)    PORT="${arg#*=}" ;;
        --dry-run)   DRY_RUN="--dry-run" ;;
        --no-restart) RESTART="0" ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "Nieznany argument: $arg" >&2
            exit 1 ;;
    esac
done

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
die() { printf '\033[1;31mBŁĄD:\033[0m %s\n' "$1" >&2; exit 1; }

[ -n "$HOST" ] || die "Podaj serwer: --host=user@adres"
[ -f "public/index.php" ] || die "Uruchom skrypt z głównego katalogu projektu (brak public/index.php)."
command -v rsync >/dev/null || die "Zainstaluj rsync."

log "Testy przed wysyłką"
php tests/run.php >/dev/null || die "Testy nie przechodzą - przerywam wdrożenie."
echo "Testy OK."

log "Wysyłka plików na $HOST:$REMOTE_DIR"
# shellcheck disable=SC2086
rsync -az --delete $DRY_RUN \
    -e "ssh -p $PORT" \
    --exclude '.git/' \
    --exclude '.github/' \
    --exclude 'cache/*' \
    --exclude 'config/local.php' \
    --exclude 'tests/' \
    --exclude '*.log' \
    --itemize-changes \
    ./ "$HOST:$REMOTE_DIR/"

if [ -n "$DRY_RUN" ]; then
    log "Tryb próbny - nic nie zostało zmienione na serwerze."
    exit 0
fi

log "Uprawnienia i weryfikacja na serwerze"
ssh -p "$PORT" "$HOST" bash -s -- "$REMOTE_DIR" "$RESTART" <<'REMOTE'
set -euo pipefail
APP_DIR="$1"
RESTART="$2"

mkdir -p "$APP_DIR/cache"
chown -R www-data:www-data "$APP_DIR/cache"
chmod -R u+rwX,g+rwX "$APP_DIR/cache"

if [ ! -f "$APP_DIR/config/local.php" ]; then
    echo "UWAGA: brak $APP_DIR/config/local.php - ustaw w nim contact_email."
fi

if [ "$RESTART" = "1" ]; then
    PHP_VER="$(ls -1 /etc/php 2>/dev/null | sort -V | tail -1)"
    if [ -n "$PHP_VER" ]; then
        systemctl reload "php$PHP_VER-fpm" 2>/dev/null || systemctl restart "php$PHP_VER-fpm" 2>/dev/null || true
    fi
fi

echo "--- health ---"
curl -sS "http://127.0.0.1/api.php?action=health" || echo "(nie udało się odpytać localhost - sprawdź konfigurację nginx)"
echo
REMOTE

log "Wdrożenie zakończone."
