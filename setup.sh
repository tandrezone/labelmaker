#!/usr/bin/env bash
#
# setup.sh - serves LabelMaker's browser page from Apache over HTTPS on a Linux server
#
#   sudo ./setup.sh --domain=labels.example.com --email=you@example.com   # Let's Encrypt certificate
#   sudo ./setup.sh --domain=labels.test                                  # self-signed certificate
#   sudo ./setup.sh --domain=labels.example.com --email=... --with-printing
#
# Steps (safe to run again):
#   1. Dependencies: checks Apache, PHP 7.4+ with GD/FreeType, OpenSSL, curl (+ certbot, Python)
#      and installs whatever is missing with apt (Debian/Ubuntu) or dnf/yum (RHEL/Fedora/Rocky)
#   2. Composer: runs composer install if composer is there (optional: the app works without it)
#   3. Database: LabelMaker keeps no data, so there is nothing to initialise
#   4. SSL: a Let's Encrypt certificate (renewed automatically), or a self-signed one for
#      local/test domains. HTTPS is required: browsers only allow Web Bluetooth on https pages
#   5. Apache: a virtual host for the domain serving examples/ (index.html, label.php, icons.php),
#      with http redirected to https
#   6. Env vars: with --with-printing, a Python environment with bleak + pillow for
#      `labelmaker --print`, and LABELMAKER_PYTHON pointing at it (/etc/profile.d/labelmaker.sh).
#      The web page needs no environment variables.
#
# Options
#   --domain=NAME      the host name to serve (required), e.g. labels.example.com
#   --email=ADDRESS    for Let's Encrypt expiry notices (required with --ssl=letsencrypt)
#   --ssl=MODE         letsencrypt, self-signed, or auto (default): self-signed for localhost and
#                      .local .test .lan .internal .localhost names, Let's Encrypt otherwise
#   --with-printing    also set up command-line printing to an X5h/X6h Bluetooth printer
#   -h, --help         show this help

set -euo pipefail

# ------------------------------------------------------------------ output

if [ -t 1 ]; then
    BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; RESET=''
fi
step() { printf '\n%s== %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
info() { printf '  %s\n' "$1"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()  { printf '\n%sError:%s %s\n' "$RED" "$RESET" "$1" >&2; exit 1; }
usage() { sed -n '3,/^$/p' "$0" | sed 's/^# \{0,1\}//'; }

# ------------------------------------------------------------------ options

DOMAIN=''
EMAIL=''
SSL='auto'
WITH_PRINTING=0

for arg in "$@"; do
    case "$arg" in
        --domain=*)      DOMAIN="${arg#*=}" ;;
        --email=*)       EMAIL="${arg#*=}" ;;
        --ssl=*)         SSL="${arg#*=}" ;;
        --with-printing) WITH_PRINTING=1 ;;
        -h|--help)       usage; exit 0 ;;
        *)               die "Unknown option: $arg (see --help)" ;;
    esac
done

[ -n "$DOMAIN" ] || die "Give the host name to serve, e.g. --domain=labels.example.com (see --help)"
[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "Not a valid host name: $DOMAIN"

if [ "$SSL" = auto ]; then
    case "$DOMAIN" in
        localhost|*.localhost|*.local|*.test|*.lan|*.internal) SSL=self-signed ;;
    esac
    # A name without a dot can't get a public certificate either
    [[ "$DOMAIN" == *.* ]] || SSL=self-signed
    [ "$SSL" = auto ] && SSL=letsencrypt
fi
case "$SSL" in
    letsencrypt) [ -n "$EMAIL" ] || die "Let's Encrypt needs --email=ADDRESS (or use --ssl=self-signed)" ;;
    self-signed) ;;
    *) die "--ssl must be letsencrypt, self-signed or auto" ;;
esac

[ "$(id -u)" -eq 0 ] || die "Run it as root: sudo $0 $*"

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCROOT="$APP_DIR/examples"
APP_OWNER="$(stat -c %U "$APP_DIR")"
ACME_DIR=/var/lib/labelmaker-acme

# ------------------------------------------------------------------ system

if command -v apt-get >/dev/null 2>&1; then
    PKG=apt
    APACHE_SVC=apache2
    APACHE_USER=www-data
    APACHECTL=apache2ctl
    VHOST_FILE=/etc/apache2/sites-available/labelmaker.conf
    LOG_DIR='${APACHE_LOG_DIR}'   # written literally: Apache fills it in
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    PKG=$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)
    APACHE_SVC=httpd
    APACHE_USER=apache
    APACHECTL=apachectl
    VHOST_FILE=/etc/httpd/conf.d/labelmaker.conf
    LOG_DIR=/var/log/httpd
else
    die "Only Debian/Ubuntu (apt) and RHEL/Fedora/Rocky/Alma (dnf/yum) are supported."
fi

# ------------------------------------------------------------------ 1. dependencies

MISSING=()

# check "what" "shell test" packages-that-provide-it...
check() {
    local what="$1" test="$2"
    shift 2
    if bash -c "$test" >/dev/null 2>&1; then
        ok "$what"
    else
        warn "$what: missing"
        MISSING+=("$@")
    fi
}

check_all() {
    MISSING=()
    local php_ok="php -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);'"
    local gd_ok="php -r 'exit(function_exists(\"imagettftext\") ? 0 : 1);'"
    if [ "$PKG" = apt ]; then
        check "Apache"                  "command -v apache2ctl"                       apache2
        check "PHP 7.4 or newer"        "$php_ok"                                     php-cli
        check "PHP GD with FreeType"    "$gd_ok"                                      php-gd
        check "PHP module for Apache"   "ls /etc/apache2/mods-available/php*.load"    libapache2-mod-php
        check "OpenSSL"                 "command -v openssl"                          openssl
        check "curl"                    "command -v curl"                             curl
        [ "$SSL" = letsencrypt ] && check "certbot" "command -v certbot"              certbot
        if [ "$WITH_PRINTING" = 1 ]; then
            check "Python 3 with venv"  "python3 -m venv --help"                      python3 python3-venv
            check "BlueZ (Bluetooth)"   "command -v bluetoothctl"                     bluez
        fi
    else
        check "Apache"                  "command -v httpd"                            httpd
        check "Apache SSL module"       "ls /etc/httpd/conf.modules.d/*ssl*.conf"     mod_ssl
        check "PHP 7.4 or newer"        "$php_ok"                                     php-cli
        check "PHP GD with FreeType"    "$gd_ok"                                      php-gd
        check "PHP for Apache"          "test -e /etc/httpd/conf.d/php.conf"          php php-fpm
        check "OpenSSL"                 "command -v openssl"                          openssl
        check "curl"                    "command -v curl"                             curl
        [ "$SSL" = letsencrypt ] && check "certbot (from EPEL on RHEL)" "command -v certbot" certbot
        if [ "$WITH_PRINTING" = 1 ]; then
            check "Python 3 with venv"  "python3 -m venv --help"                      python3
            check "BlueZ (Bluetooth)"   "command -v bluetoothctl"                     bluez
        fi
    fi
    return 0
}

step "1. Dependencies"
check_all
if [ ${#MISSING[@]} -gt 0 ]; then
    mapfile -t MISSING < <(printf '%s\n' "${MISSING[@]}" | sort -u)
    info "Installing: ${MISSING[*]}"
    if [ "$PKG" = apt ]; then
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q "${MISSING[@]}"
    else
        "$PKG" install -y -q "${MISSING[@]}" \
            || die "Could not install ${MISSING[*]}. On RHEL/Rocky/Alma, certbot comes from EPEL: $PKG install epel-release"
    fi
    info "Checking again:"
    check_all
    [ ${#MISSING[@]} -eq 0 ] || die "Still missing after installing: ${MISSING[*]}"
fi

for font in BarlowSemiCondensed-Bold.ttf MaterialIcons-Regular.ttf NotoEmoji-Labels.ttf; do
    [ -s "$APP_DIR/fonts/$font" ] || die "fonts/$font is missing: copy the whole project, including fonts/"
done
ok "Fonts"

# ------------------------------------------------------------------ 2. composer

step "2. Composer"
if command -v composer >/dev/null 2>&1; then
    runuser -u "$APP_OWNER" -- composer install --no-dev --no-interaction --optimize-autoloader -d "$APP_DIR"
    ok "composer install done"
else
    info "composer is not installed: not needed, the pages load src/LabelMaker.php directly"
fi

# ------------------------------------------------------------------ 3. database

step "3. Database"
info "LabelMaker keeps no data (every label is drawn on request): there is no database to set up"

# ------------------------------------------------------------------ apache helpers

# Apache must be able to read the project, including the folders above it
check_readable() {
    local file
    for file in "$DOCROOT/index.html" "$DOCROOT/label.php" "$APP_DIR/src/LabelMaker.php" "$APP_DIR/fonts/NotoEmoji-Labels.ttf"; do
        runuser -u "$APACHE_USER" -- test -r "$file" \
            || die "Apache ($APACHE_USER) cannot read $file. Move the project under /var/www, or let others
       into the folders above it, e.g.: chmod o+x \"$(dirname "$APP_DIR")\" && chmod -R o+rX \"$APP_DIR\""
    done
    ok "Apache can read the project"

    if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" = Enforcing ]; then
        if command -v semanage >/dev/null 2>&1; then
            semanage fcontext -a -t httpd_sys_content_t "$APP_DIR(/.*)?" 2>/dev/null \
                || semanage fcontext -m -t httpd_sys_content_t "$APP_DIR(/.*)?"
            restorecon -R -q "$APP_DIR"
        else
            chcon -R -t httpd_sys_content_t "$APP_DIR"
        fi
        ok "SELinux: Apache may read $APP_DIR"
    fi
}

enable_modules() {
    if [ "$PKG" = apt ]; then
        a2enmod -q ssl headers >/dev/null
        # mod_php: enable the installed version if none is on
        if ! ls /etc/apache2/mods-enabled/php*.load >/dev/null 2>&1; then
            local php_mod
            php_mod="$(basename "$(ls /etc/apache2/mods-available/php*.load | sort -V | tail -1)" .load)"
            a2dismod -q mpm_event >/dev/null 2>&1 || true
            a2enmod -q mpm_prefork "$php_mod" >/dev/null
        fi
    elif systemctl list-unit-files php-fpm.service >/dev/null 2>&1; then
        systemctl enable --now php-fpm >/dev/null 2>&1 || true
    fi
    ok "Apache modules: ssl, headers, php"
}

# $1 = 1 to add the https site (needs the certificate), 0 for http only
write_vhost() {
    local with_ssl="$1" hsts=''
    [ "$SSL" = letsencrypt ] && hsts='Header always set Strict-Transport-Security "max-age=31536000"'
    mkdir -p "$ACME_DIR/.well-known/acme-challenge"

    {
        cat <<EOF
# LabelMaker - written by $APP_DIR/setup.sh; running it again overwrites this file

<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot "$ACME_DIR"

    # Let's Encrypt checks
    Alias /.well-known/acme-challenge/ "$ACME_DIR/.well-known/acme-challenge/"
    <Directory "$ACME_DIR">
        Options None
        AllowOverride None
        Require all granted
    </Directory>
EOF
        if [ "$with_ssl" = 1 ]; then
            cat <<EOF

    # Everything else goes to https (Web Bluetooth only works on https pages)
    RedirectMatch 301 "^/(?!\.well-known/acme-challenge/)(.*)$" "https://$DOMAIN/\$1"
EOF
        fi
        cat <<EOF

    ErrorLog $LOG_DIR/labelmaker-error.log
    CustomLog $LOG_DIR/labelmaker-access.log combined
</VirtualHost>
EOF
        if [ "$with_ssl" = 1 ]; then
            cat <<EOF

<VirtualHost *:443>
    ServerName $DOMAIN
    DocumentRoot "$DOCROOT"
    DirectoryIndex index.html

    <Directory "$DOCROOT">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile "$CERT"
    SSLCertificateKeyFile "$KEY"

    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "same-origin"
    $hsts

    ErrorLog $LOG_DIR/labelmaker-error.log
    CustomLog $LOG_DIR/labelmaker-access.log combined
</VirtualHost>
EOF
        fi
    } > "$VHOST_FILE"

    [ "$PKG" = apt ] && a2ensite -q labelmaker >/dev/null
    "$APACHECTL" configtest >/dev/null 2>&1 || { "$APACHECTL" configtest; die "Apache does not accept the configuration above"; }
    systemctl enable "$APACHE_SVC" >/dev/null 2>&1
    if systemctl is-active --quiet "$APACHE_SVC"; then
        systemctl reload "$APACHE_SVC"
    else
        systemctl start "$APACHE_SVC"
    fi
}

open_firewall() {
    if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        firewall-cmd -q --permanent --add-service=http --add-service=https
        firewall-cmd -q --reload
        ok "Firewall (firewalld): http and https open"
    elif command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then
        ufw allow 80/tcp >/dev/null
        ufw allow 443/tcp >/dev/null
        ok "Firewall (ufw): ports 80 and 443 open"
    fi
}

# ------------------------------------------------------------------ 4. ssl

step "4. SSL ($SSL)"
check_readable
enable_modules
open_firewall

if [ "$SSL" = self-signed ]; then
    CERT_DIR=/etc/ssl/labelmaker
    CERT="$CERT_DIR/$DOMAIN.crt"
    KEY="$CERT_DIR/$DOMAIN.key"
    mkdir -p "$CERT_DIR"
    # A new one if there is none, or it runs out within 30 days
    if [ ! -s "$CERT" ] || ! openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null 2>&1; then
        openssl req -x509 -nodes -newkey rsa:2048 -days 825 -sha256 \
            -keyout "$KEY" -out "$CERT" -subj "/CN=$DOMAIN" \
            -addext "subjectAltName=DNS:$DOMAIN" -addext "extendedKeyUsage=serverAuth" 2>/dev/null
        chmod 600 "$KEY"
        ok "Made a self-signed certificate: $CERT"
    else
        ok "Self-signed certificate still valid: $CERT"
    fi
    # A local name has to point at this machine
    if ! getent hosts "$DOMAIN" >/dev/null 2>&1; then
        echo "127.0.0.1 $DOMAIN" >> /etc/hosts
        ok "Added $DOMAIN to /etc/hosts"
    fi
else
    CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
    KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    if [ ! -s "$CERT" ]; then
        info "Serving http first so Let's Encrypt can check the domain…"
        write_vhost 0
        certbot certonly --webroot -w "$ACME_DIR" -d "$DOMAIN" -m "$EMAIL" \
            --agree-tos --non-interactive --deploy-hook "systemctl reload $APACHE_SVC" \
            || die "Let's Encrypt could not issue a certificate. $DOMAIN must point at this server and port 80
       must be reachable from the internet. For a local server use --ssl=self-signed."
        ok "Let's Encrypt certificate: $CERT"
    else
        ok "Let's Encrypt certificate already there: $CERT"
    fi
    info "Renewal: certbot's timer renews it and reloads Apache"
fi

# ------------------------------------------------------------------ 5. apache

step "5. Apache virtual host"
write_vhost 1
ok "$VHOST_FILE"

CURL_OPTS=(-fsS -o /dev/null --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" -w '%{http_code} %{content_type}')
[ "$SSL" = self-signed ] && CURL_OPTS+=(--cacert "$CERT")
result="$(curl "${CURL_OPTS[@]}" "https://$DOMAIN/label.php?text=Setup%20test&qty=1ml&icon=apple" 2>&1)" \
    || die "https://$DOMAIN/label.php does not answer: $result
       See $( [ "$PKG" = apt ] && echo /var/log/apache2 || echo /var/log/httpd )/labelmaker-error.log"
[[ "$result" == "200 image/png"* ]] || die "label.php answered \"$result\" instead of a PNG"
ok "https://$DOMAIN/label.php draws labels"
result="$(curl "${CURL_OPTS[@]}" "https://$DOMAIN/icons.php" 2>&1)" || die "icons.php does not answer: $result"
ok "https://$DOMAIN/icons.php lists the icons"

# ------------------------------------------------------------------ 6. env vars / printing

step "6. Environment"
if [ "$WITH_PRINTING" = 1 ]; then
    VENV="$APP_DIR/printer/.venv"
    runuser -u "$APP_OWNER" -- python3 -m venv "$VENV"
    runuser -u "$APP_OWNER" -- "$VENV/bin/pip" install -q --upgrade pip
    runuser -u "$APP_OWNER" -- "$VENV/bin/pip" install -q -r "$APP_DIR/printer/requirements.txt"
    ok "Python environment with bleak + pillow: $VENV"

    cat > /etc/profile.d/labelmaker.sh <<EOF
# Written by $APP_DIR/setup.sh: the Python that "labelmaker --print" runs
export LABELMAKER_PYTHON="$VENV/bin/python"
EOF
    ok "LABELMAKER_PYTHON=$VENV/bin/python (/etc/profile.d/labelmaker.sh, from the next login)"

    systemctl enable --now bluetooth >/dev/null 2>&1 && ok "Bluetooth service running" \
        || warn "Could not start the bluetooth service (is there a Bluetooth adapter?)"
else
    info "The web page needs no environment variables."
    info "For command-line printing on this machine, run again with --with-printing"
fi

# ------------------------------------------------------------------ done

step "Done"
info "Open https://$DOMAIN"
if [ "$SSL" = self-signed ]; then
    warn "The certificate is self-signed, so browsers warn about it, and Web Bluetooth printing"
    info "  only works once the computer trusts it. Copy $CERT to that computer and import it:"
    info "  Windows: double-click it > Install Certificate > Local Machine >"
    info "           Trusted Root Certification Authorities"
fi
