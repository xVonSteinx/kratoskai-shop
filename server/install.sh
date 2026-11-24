#!/usr/bin/env bash
# Richtet die API für Kratoskais Furzkalender auf diesem Server ein (Ubuntu 24.04, Caddy + PHP 8.3).
# Aufruf als root:  bash install.sh
# Bestehende Websites bleiben unverändert: die API bekommt eine eigene Caddy-Datei, die nur per "import" eingebunden wird.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
cd "$(dirname "$0")"
[ "$(id -u)" = 0 ] || { echo "Bitte als root ausführen."; exit 1; }

command -v caddy >/dev/null || { echo "Caddy fehlt – erwartet wird der vorhandene Caddy-Server."; exit 1; }
dpkg -s php8.3-fpm php8.3-sqlite3 >/dev/null 2>&1 || apt-get install -y -qq php8.3-fpm php8.3-sqlite3 >/dev/null
systemctl enable --now php8.3-fpm >/dev/null

APP=/var/www/kratoskai-kalender; DATA=/var/lib/kratoskai-kalender; ETC=/etc/kratoskai-kalender
mkdir -p "$APP" "$DATA" "$ETC"
install -m 640 -o root -g www-data api.php lib.php admin.php "$APP"/
chown www-data:www-data "$DATA"; chmod 750 "$DATA"
if [ ! -f "$ETC/config.php" ]; then
  install -m 640 -o root -g www-data config.example.php "$ETC/config.php"
  echo ">> Neue Einstellungen angelegt: $ETC/config.php"
fi
chown root:www-data "$ETC" "$ETC/config.php"; chmod 750 "$ETC"; chmod 640 "$ETC/config.php"

# Passwort für die Verwaltung (/admin) – nur beim ersten Mal erzeugen
PWFILE=/root/kratoskai-kalender-admin.txt
if [ ! -f "$PWFILE" ]; then
  PW=$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)
  printf 'Verwaltung: https://api.kratoskai.de/admin\nBenutzer: admin\nPasswort: %s\n' "$PW" > "$PWFILE"; chmod 600 "$PWFILE"
fi
PW=$(sed -n 's/^Passwort: //p' "$PWFILE")
HASH=$(caddy hash-password --plaintext "$PW")

SNIP=/etc/caddy/kratoskai-api.caddy
cat > "$SNIP" <<CADDY
api.kratoskai.de {
	encode zstd gzip
	request_body {
		max_size 1MB
	}
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options nosniff
		Referrer-Policy no-referrer
		-Server
	}
	root * $APP
	@admin path /admin /admin/*
	handle @admin {
		basic_auth {
			admin $HASH
		}
		rewrite * /admin.php
		php_fastcgi unix//run/php/php8.3-fpm.sock
	}
	handle {
		rewrite * /api.php
		php_fastcgi unix//run/php/php8.3-fpm.sock
	}
}
CADDY
grep -qF "import $SNIP" /etc/caddy/Caddyfile || printf '\nimport %s\n' "$SNIP" >> /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl reload caddy

# Datenbank anlegen
sudo -u www-data php -r 'require "'"$APP"'/lib.php"; kk_db(); echo "Datenbank ok\n";'

echo
echo "=== Fertig ==="
cat "$PWFILE"
echo "Einstellungen (Stripe-Geheimnis, Mail-Passwort): $ETC/config.php"
grep -q 'HIER_' "$ETC/config.php" && echo ">> Achtung: In $ETC/config.php fehlen noch Werte (HIER_…)." || true
