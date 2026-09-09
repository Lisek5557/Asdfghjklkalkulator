#!/usr/bin/env bash
#
# Instalator aplikacji "Granice i adresy" na serwerze VPS (Debian 11/12, Ubuntu 20.04+).
# Uruchom NA SERWERZE jako root:
#
#   bash deploy/install-vps.sh --domain=granice.twojadomena.pl --email=admin@twojadomena.pl
#
# Skrypt jest idempotentny - można go uruchamiać wielokrotnie.

set -euo pipefail

APP_NAME="granice"
APP_DIR="/var/www/granice"
DOMAIN=""
EMAIL=""
REPO=""
BRANCH="claude/php-maps-addresses-yi87io"
WITH_SSL="0"
FPM_TIMEOUT="300"

for arg in "$@"; do
    case "$arg" in
        --domain=*)  DOMAIN="${arg#*=}" ;;
        --email=*)   EMAIL="${arg#*=}" ;;
        --dir=*)     APP_DIR="${arg#*=}" ;;
        --repo=*)    REPO="${arg#*=}" ;;
        --branch=*)  BRANCH="${arg#*=}" ;;
        --ssl)       WITH_SSL="1" ;;
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

[ "$(id -u)" -eq 0 ] || die "Uruchom skrypt jako root (sudo bash deploy/install-vps.sh ...)."
[ -n "$EMAIL" ] || die "Podaj adres kontaktowy: --email=admin@twojadomena.pl (wymaga go regulamin API OpenStreetMap)."
[ -n "$DOMAIN" ] || { DOMAIN="_"; echo "Uwaga: bez --domain serwis odpowie na dowolną nazwę (dostęp po IP)."; }
[ "$WITH_SSL" = "0" ] || [ "$DOMAIN" != "_" ] || die "Certyfikat HTTPS wymaga podania --domain."

log "Instalacja pakietów"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-curl php-mbstring git rsync ca-certificates

# Bierzemy pod uwagę wyłącznie wersje, które faktycznie mają zainstalowane FPM.
PHP_VER=""
for dir in /etc/php/*/fpm; do
    [ -d "$dir" ] || continue
    candidate="$(basename "$(dirname "$dir")")"
    PHP_VER="$(printf '%s\n%s\n' "$PHP_VER" "$candidate" | sort -V | tail -1)"
done
[ -n "$PHP_VER" ] || die "Nie znaleziono PHP-FPM w /etc/php - zainstaluj pakiet php-fpm."
systemctl list-unit-files | grep -q "php$PHP_VER-fpm" || die "Brak usługi php$PHP_VER-fpm."
log "Wykryto PHP $PHP_VER"

log "Przygotowanie katalogu aplikacji: $APP_DIR"
if [ -n "$REPO" ]; then
    if [ -d "$APP_DIR/.git" ]; then
        git -C "$APP_DIR" fetch origin "$BRANCH"
        git -C "$APP_DIR" checkout "$BRANCH"
        git -C "$APP_DIR" reset --hard "origin/$BRANCH"
    else
        rm -rf "${APP_DIR:?}"
        git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
    fi
elif [ ! -f "$APP_DIR/public/index.php" ]; then
    die "Brak aplikacji w $APP_DIR. Wgraj pliki (deploy/deploy.sh) albo podaj --repo=<adres git>."
fi

[ -f "$APP_DIR/public/index.php" ] || die "W $APP_DIR nie ma public/index.php - to nie jest katalog aplikacji."

log "Konfiguracja lokalna (adres kontaktowy)"
if [ ! -f "$APP_DIR/config/local.php" ]; then
    cat > "$APP_DIR/config/local.php" <<PHPCONF
<?php
declare(strict_types=1);

return [
    'contact_email' => '${EMAIL}',
];
PHPCONF
    echo "Zapisano $APP_DIR/config/local.php"
else
    echo "config/local.php już istnieje - pozostawiam bez zmian."
fi

log "Uprawnienia"
mkdir -p "$APP_DIR/cache"
chown -R root:www-data "$APP_DIR"
chmod -R o-rwx "$APP_DIR"
chown -R www-data:www-data "$APP_DIR/cache"
chmod -R u+rwX,g+rwX "$APP_DIR/cache"

log "Pula PHP-FPM ($APP_NAME)"
# Osobna pula: własny limit czasu (zapytania do Overpass trwają nawet 2 minuty)
# i własny limit pamięci, bez ruszania domyślnej puli www.
cat > "/etc/php/$PHP_VER/fpm/pool.d/$APP_NAME.conf" <<FPMCONF
[$APP_NAME]
user = www-data
group = www-data
listen = /run/php/$APP_NAME.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Duże miasto potrafi zwrócić kilkadziesiąt tysięcy punktów adresowych.
request_terminate_timeout = ${FPM_TIMEOUT}
php_admin_value[max_execution_time] = ${FPM_TIMEOUT}
php_admin_value[memory_limit] = 512M
php_admin_value[display_errors] = Off
php_admin_value[expose_php] = Off
php_admin_flag[log_errors] = On
FPMCONF

log "Konfiguracja nginx"
cat > "/etc/nginx/sites-available/$APP_NAME" <<NGINXCONF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;

    access_log /var/log/nginx/${APP_NAME}-access.log;
    error_log  /var/log/nginx/${APP_NAME}-error.log;

    client_max_body_size 2m;

    gzip on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json application/geo+json text/plain text/csv;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/${APP_NAME}.sock;
        # Musi być nie mniejszy niż request_terminate_timeout w puli PHP-FPM.
        fastcgi_read_timeout ${FPM_TIMEOUT};
        fastcgi_buffers 16 32k;
        fastcgi_buffer_size 64k;
    }

    location ~* \.(css|js)\$ {
        expires 7d;
        add_header Cache-Control "public";
    }

    # Blokada plików ukrytych (poza /.well-known dla certyfikatów).
    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINXCONF

ln -sfn "/etc/nginx/sites-available/$APP_NAME" "/etc/nginx/sites-enabled/$APP_NAME"
[ -e /etc/nginx/sites-enabled/default ] && rm -f /etc/nginx/sites-enabled/default

log "Sprzątanie cache raz w tygodniu"
cat > "/etc/cron.d/$APP_NAME-cache" <<CRONCONF
# Usuwa wpisy cache starsze niż 14 dni (granice i adresy zmieniają się rzadko).
0 4 * * 0 www-data find ${APP_DIR}/cache -type f -name '*.json' -mtime +14 -delete
CRONCONF

log "Test konfiguracji i restart usług"
nginx -t
systemctl restart "php$PHP_VER-fpm"
systemctl reload nginx
systemctl enable nginx "php$PHP_VER-fpm" >/dev/null 2>&1 || true

if [ "$WITH_SSL" = "1" ]; then
    log "Certyfikat HTTPS (Let's Encrypt)"
    apt-get install -y -qq certbot python3-certbot-nginx
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect
fi

log "Weryfikacja"
HEALTH_URL="http://127.0.0.1/api.php?action=health"
if [ "$DOMAIN" != "_" ]; then
    HEALTH_CHECK="$(curl -sS -H "Host: $DOMAIN" "$HEALTH_URL" || true)"
else
    HEALTH_CHECK="$(curl -sS "$HEALTH_URL" || true)"
fi
echo "$HEALTH_CHECK"

case "$HEALTH_CHECK" in
    *'"ok":true'*)
        if [ "$WITH_SSL" = "1" ]; then
            ADDRESS="https://$DOMAIN"
        elif [ "$DOMAIN" != "_" ]; then
            ADDRESS="http://$DOMAIN"
        else
            ADDRESS="http://$(hostname -I | awk '{print $1}')"
        fi
        log "Gotowe. Aplikacja działa pod adresem: $ADDRESS"
        echo "Jeśli 'contact_configured' powyżej to false, sprawdź $APP_DIR/config/local.php."
        ;;
    *)
        die "Aplikacja nie odpowiedziała poprawnie. Sprawdź: journalctl -u php$PHP_VER-fpm -n 50 oraz /var/log/nginx/${APP_NAME}-error.log"
        ;;
esac
