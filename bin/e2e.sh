#!/usr/bin/env bash
# End-to-end test of the release zip on real servers:
#
#   fake BMLT root server (fake-bmlt/router.php)
#   WordPress (latest, default block theme) with the plugin zip, served by
#     ├─ Apache + PHP-FPM (the .htaccess files the plugin writes are honoured)
#     └─ nginx + PHP-FPM  (WordPress's usual nginx rules: no .htaccess at all)
#   an SMTP sink (bin/smtp-sink.py) receiving the plugin's mail through its own SMTP setting
#   headless Chrome as an administrator, a visitor, literature workers (bin/browser-e2e.py)
#
# and, on both servers, every file of the plugin's data folder requested over the web.
#
# Usage: bin/e2e.sh <work-dir>      (the directory is created; KEEP=1 leaves the servers' files behind)
#
# Environment (defaults suit Ubuntu with apache2, nginx, php-fpm and google-chrome installed):
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_ADMIN_USER=root DB_ADMIN_PASS=
#   APACHE=apache2  APACHE_MODULES=/usr/lib/apache2/modules  NGINX=nginx  PHP_FPM=php-fpm8.3  CHROME=google-chrome
#   WP_CORE=<directory with WordPress files> (default: wp core download)   PORT_BASE=8540
#   PLUGIN_ZIP (default: this version's zip in dist/, built if missing)
set -euo pipefail

[ $# -eq 1 ] || { sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mkdir -p "$1" && cd "$1" && pwd)
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_ADMIN_USER=${DB_ADMIN_USER:-root}
DB_ADMIN_PASS=${DB_ADMIN_PASS:-}
APACHE=${APACHE:-apache2}
APACHE_MODULES=${APACHE_MODULES:-/usr/lib/apache2/modules}
NGINX=${NGINX:-nginx}
PHP_FPM=${PHP_FPM:-php-fpm8.3}
P=${PORT_BASE:-8540}
BMLT_PORT=$((P + 1)) SMTP_PORT=$((P + 2)) APACHE_PORT=$((P + 3)) NGINX_PORT=$((P + 4))
SITE_A="http://127.0.0.1:$APACHE_PORT"
SITE_N="http://127.0.0.1:$NGINX_PORT"
BMLT_ROOT="http://127.0.0.1:$BMLT_PORT/main_server"

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
section() { echo; echo "== $*"; }

V=$(sed -n 's/^ \* Version: *//p' "$ROOT/bmlt-literature-manager/bmlt-literature-manager.php")
# PLUGIN_ZIP tests another zip (for example the previous release, to see a test fail).
PLUGIN_ZIP=${PLUGIN_ZIP:-$ROOT/dist/bmlt-literature-manager-$V.zip}
[ -f "$PLUGIN_ZIP" ] || "$ROOT/bin/build.sh" --dev --allow-dirty

PIDS=()
cleanup() {
	for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
	[ -f "$W/run/apache.pid" ] && kill "$(cat "$W/run/apache.pid")" 2>/dev/null || true
	mysql_admin -e "DROP DATABASE IF EXISTS e2e_lit" 2>/dev/null || true
	if [ "${KEEP:-0}" != 1 ]; then rm -rf "$W"; fi
}
trap cleanup EXIT
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_ADMIN_USER" ${DB_ADMIN_PASS:+-p"$DB_ADMIN_PASS"} "$@"; }

rm -rf "${W:?}"/*
mkdir -p "$W/run" "$W/logs" "$W/mail" "$W/nginx"

section "Fake BMLT root server and SMTP sink"
FAKE_BMLT_LOG="$W/logs/bmlt-requests.log" php -S "127.0.0.1:$BMLT_PORT" "$ROOT/fake-bmlt/router.php" >"$W/logs/bmlt.log" 2>&1 &
PIDS+=($!)
python3 "$ROOT/bin/smtp-sink.py" "$SMTP_PORT" "$W/mail" >"$W/logs/smtp.log" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -sf -o /dev/null "$BMLT_ROOT/client_interface/json/?switcher=GetServerInfo" && (exec 3<>"/dev/tcp/127.0.0.1/$SMTP_PORT") 2>/dev/null && break
	sleep 0.1
done
pass "fake BMLT $(curl -s "$BMLT_ROOT/client_interface/json/?switcher=GetServerInfo" | sed 's/.*"version":"\([^"]*\)".*/\1/') on $BMLT_ROOT; SMTP sink on port $SMTP_PORT"

section "PHP-FPM, Apache, nginx"
cat > "$W/run/fpm.conf" <<EOF
[global]
error_log = $W/logs/fpm.log
pid = $W/run/fpm.pid
daemonize = no
[wp]
listen = $W/run/fpm.sock
pm = static
pm.max_children = 8
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
EOF
"$PHP_FPM" -y "$W/run/fpm.conf" -F >"$W/logs/fpm.out" 2>&1 &
PIDS+=($!)

mod() { echo "LoadModule ${1}_module $APACHE_MODULES/mod_$1.so"; }
{
	echo "ServerRoot $W/run"
	echo "ServerName 127.0.0.1"
	echo "PidFile $W/run/apache.pid"
	echo "ErrorLog $W/logs/apache-error.log"
	echo "Mutex file:$W/run"
	echo "<IfModule !mpm_event_module>"; mod mpm_event; echo "</IfModule>"
	echo "<IfModule !unixd_module>"; mod unixd; echo "</IfModule>"
	for m in authz_core authz_host access_compat dir mime rewrite proxy proxy_fcgi setenvif headers env log_config; do
		[ -f "$APACHE_MODULES/mod_$m.so" ] && echo "<IfModule !${m}_module>" && mod "$m" && echo "</IfModule>"
	done
	cat <<EOF
LogFormat "%h \"%r\" %>s" e2e
TypesConfig /dev/null
AddType text/css .css
AddType application/javascript .js
AddType text/plain .txt .bak .tmp .md
DirectoryIndex index.php index.html
<Directory />
	AllowOverride None
	Require all denied
</Directory>
Listen 127.0.0.1:$APACHE_PORT
<Directory $W/wp>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm.sock|fcgi://wp"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$APACHE_PORT>
	DocumentRoot $W/wp
	CustomLog $W/logs/apache-access.log e2e
</VirtualHost>
EOF
} > "$W/run/apache.conf"
"$APACHE" -f "$W/run/apache.conf" -t >/dev/null 2>"$W/logs/apache-configtest.log" || { cat "$W/logs/apache-configtest.log"; fail "apache configuration"; }

cat > "$W/nginx/nginx.conf" <<EOF
pid $W/run/nginx.pid;
error_log $W/logs/nginx-error.log;
daemon off;
worker_processes 1;
events { worker_connections 64; }
http {
	access_log $W/logs/nginx-access.log;
	client_body_temp_path $W/run/nb; proxy_temp_path $W/run/np; fastcgi_temp_path $W/run/nf; uwsgi_temp_path $W/run/nu; scgi_temp_path $W/run/ns;
	types { text/html html; text/css css; application/javascript js; image/svg+xml svg; }
	default_type application/octet-stream;
	# The usual WordPress server block (wordpress.org's nginx guide): no rules for any plugin's folders.
	server {
		listen 127.0.0.1:$NGINX_PORT;
		server_name 127.0.0.1;
		root $W/wp;
		index index.php;
		client_max_body_size 8m;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ {
			try_files \$uri =404;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_param QUERY_STRING \$query_string; fastcgi_param REQUEST_METHOD \$request_method;
			fastcgi_param CONTENT_TYPE \$content_type; fastcgi_param CONTENT_LENGTH \$content_length;
			fastcgi_param SCRIPT_NAME \$fastcgi_script_name; fastcgi_param REQUEST_URI \$request_uri;
			fastcgi_param DOCUMENT_URI \$document_uri; fastcgi_param DOCUMENT_ROOT \$document_root;
			fastcgi_param SERVER_PROTOCOL \$server_protocol; fastcgi_param REMOTE_ADDR \$remote_addr;
			fastcgi_param REMOTE_PORT \$remote_port; fastcgi_param SERVER_ADDR \$server_addr;
			fastcgi_param SERVER_PORT \$server_port; fastcgi_param SERVER_NAME \$server_name;
			fastcgi_param HTTP_HOST \$http_host;
			fastcgi_pass unix:$W/run/fpm.sock;
		}
	}
}
EOF

section "WordPress with the plugin zip"
WP=(wp --path="$W/wp" --quiet)
if [ -n "${WP_CORE:-}" ]; then
	mkdir -p "$W/wp" && (cd "$WP_CORE" && tar --exclude=./wp-config.php --exclude=./wp-content/uploads --exclude=./wp-content/bmlt-literature-data -cf - .) | tar -xf - -C "$W/wp"
else
	"${WP[@]}" core download --path="$W/wp" >/dev/null
fi
mysql_admin -e "DROP DATABASE IF EXISTS e2e_lit; CREATE DATABASE e2e_lit CHARACTER SET utf8mb4"
"${WP[@]}" config create --dbname=e2e_lit --dbuser="$DB_ADMIN_USER" --dbpass="$DB_ADMIN_PASS" --dbhost="$DB_HOST:$DB_PORT" --skip-check --force \
	--extra-php <<'EOF'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
// The same site behind Apache and nginx (two ports): links follow the address used.
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	define( 'WP_HOME', 'http://' . $_SERVER['HTTP_HOST'] );
	define( 'WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST'] );
}
EOF
"${WP[@]}" core install --url="$SITE_A" --title="E2E Region" --admin_user=admin --admin_password=Admin-pass-1 --admin_email=admin@example.org --skip-email
"${WP[@]}" rewrite structure '/%postname%/'
# WordPress's standard rules (what Settings → Permalinks writes on Apache).
cat > "$W/wp/.htaccess" <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
mkdir -p "$W/wp/wp-content/mu-plugins"
# The test BMLT server is on 127.0.0.1 and an unusual port: the site owner allows it with WordPress's own filters,
# as the README describes.
cat > "$W/wp/wp-content/mu-plugins/e2e-local-bmlt.php" <<EOF
<?php
add_filter( 'http_request_host_is_external', static function ( \$external, \$host ) {
	return \$external || '127.0.0.1' === \$host;
}, 10, 2 );
add_filter( 'http_allowed_safe_ports', static function ( \$ports ) {
	\$ports[] = $BMLT_PORT;
	return \$ports;
} );
EOF
"${WP[@]}" plugin install "$PLUGIN_ZIP" --activate
"$APACHE" -f "$W/run/apache.conf" -k start
"$NGINX" -e "$W/logs/nginx-early.log" -p "$W/nginx" -c "$W/nginx/nginx.conf" >"$W/logs/nginx.out" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -s -o /dev/null "$SITE_A/" && curl -s -o /dev/null "$SITE_N/" && break
	sleep 0.2
done
pass "WordPress $("${WP[@]}" core version), theme $("${WP[@]}" theme list --status=active --field=name), plugin $("${WP[@]}" plugin get bmlt-literature-manager --field=version); Apache $("$APACHE" -v | sed -n 's/Server version: Apache\/\([0-9.]*\).*/\1/p'), nginx $("$NGINX" -v 2>&1 | sed 's/.*nginx\///'), PHP $(php -r 'echo PHP_VERSION;')"
DATA="$W/wp/wp-content/bmlt-literature-data"
[ -f "$DATA/settings.json.php" ] && pass "data folder created on activation: wp-content/bmlt-literature-data" || fail "no data folder"

"${WP[@]}" user create worker worker@example.org --role=bmlt_lit_worker --user_pass=Worker-pass-1
"${WP[@]}" user create riverside riverside@example.org --role=bmlt_lit_worker --user_pass=River-pass-1
ORDER=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title='Order Literature' --post_name=order-literature --post_content='[bmlt_literature_order]' --porcelain)
WORKERS=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title='Literature Workers' --post_name=literature-workers --post_content='[bmlt_literature_manager]' --porcelain)

section "Browser: administrator connects BMLT and chooses Areas"
python3 "$ROOT/bin/browser-e2e.py" setup "$SITE_A" "$BMLT_ROOT"

"${WP[@]}" eval '
	use BMLT_Lit\Settings; use BMLT_Lit\Import_Export; use BMLT_Lit\Inventory; use BMLT_Lit\Catalog;
	wp_set_current_user( 1 );
	$areas = Settings::areas_config();
	$areas[101]["worker_emails"] = "riverside-lit@example.org";
	$areas[101]["special_order_emails"] = "riverside-special@example.org";
	$areas[101]["delivery_instructions"] = "Pick up at the Riverside ASC.";
	$areas[102]["worker_emails"] = "hillcrest-lit@example.org";
	Settings::update( Settings::AREAS, $areas );
	$g = Settings::general();
	$g["email_from_address"] = "literature@example.org";
	$g["min_submit_seconds"] = 1;
	Settings::update( Settings::GENERAL, $g );
	BMLT_Lit\Smtp::save( array( "enabled" => 1, "host" => "127.0.0.1", "port" => '"$SMTP_PORT"', "encryption" => "none", "auth" => 0, "from_email" => "literature@example.org", "from_name" => "E2E Literature" ) );
	$p = Import_Export::preview_catalog( Import_Export::decode( file_get_contents( BMLT_LIT_DIR . "sample-data/naws-catalog-2026.json" ) ), 0, false, false );
	$s = Import_Export::apply( $p );
	echo "catalog " . $s["created"] . "\n";
	foreach ( array( 101, 102 ) as $a ) {
		Inventory::adjust( $a, Catalog::get_by_sku( "1101", 0 )->id, array( "set" => 5, "low_stock_threshold" => 2 ), "first count" );
	}
	BMLT_Lit\Permissions::save_grants( array( get_user_by( "login", "riverside" )->ID => array( "caps" => array(), "areas" => array( 101 ) ) ) );
	file_put_contents( "'"$W/run/items.json"'", wp_json_encode( array( "a" => Catalog::to_array( Catalog::get_by_sku( "1101", 0 ) ), "b" => Catalog::to_array( Catalog::get_by_sku( "1112", 0 ) ) ) ) );
' > "$W/run/setup.out" 2>&1 || true
grep -q "catalog 294" "$W/run/setup.out" && pass "starter catalog imported (294 items), stock counted, SMTP pointed at the sink, a worker restricted to Riverside" || { cat "$W/run/setup.out"; fail "site setup"; }

section "Browser: orders, emails and worker pages (Apache)"
python3 "$ROOT/bin/browser-e2e.py" orders "$SITE_A" "$W/run/items.json" "$W/mail" apache
section "Browser: the same on nginx"
python3 "$ROOT/bin/browser-e2e.py" orders "$SITE_N" "$W/run/items.json" "$W/mail" nginx

section "The data folder over the web"
[ "$(find "$DATA/orders" -name '*.json.php' | wc -l)" -ge 2 ] || fail "no order files written"
mkdir -p "$W/run/probe"
leak=0
while IFS= read -r f; do
	rel=${f#"$W/wp/"}
	for site in "$SITE_A" "$SITE_N"; do
		code=$(curl -s -o "$W/run/probe/body" -w '%{http_code}' "$site/$rel")
		if grep -q 'e2e-visitor\|555-0142\|"orders"\|"items"\|"general"' "$W/run/probe/body"; then
			echo "    $site/$rel answered $code with data" >&2
			leak=1
		fi
	done
done < <(find "$DATA" -type f)
[ "$leak" = 0 ] || fail "files of the data folder are readable over the web"
pass "every one of $(find "$DATA" -type f | wc -l) files in the data folder (orders, their previous versions, settings, cache) shows nothing on Apache or nginx"
code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE_A/wp-content/bmlt-literature-data/orders/")
[ "$code" = 403 ] && pass "Apache: the plugin's .htaccess refuses the data folder (403)" || fail "Apache data folder: $code"

section "REST API without a login"
for site in "$SITE_A" "$SITE_N"; do
	code=$(curl -s -o "$W/run/body" -w '%{http_code}' "$site/wp-json/bmlt-lit/v1/orders")
	[ "$code" = 401 ] || fail "$site orders without a login: $code"
	code=$(curl -s -o "$W/run/body" -w '%{http_code}' "$site/wp-json/bmlt-lit/v1/public/stock?area=101")
	[ "$code" = 200 ] && ! grep -q 'e2e-visitor' "$W/run/body" || fail "$site public stock: $code"
done
pass "orders need a login (401); public stock has no personal data"

section "Order form page caching"
grep -qi '^cache-control:.*no-store\|^cache-control:.*no-cache' <(curl -s -D - -o /dev/null "$SITE_A/order-literature/") && pass "the order page is sent with no-cache headers" || fail "order page cache headers"

section "Logs"
if grep -v 'PHP Deprecated' "$W/logs/php.log" 2>/dev/null | grep -q 'PHP '; then
	grep 'PHP ' "$W/logs/php.log" | tail -20
	fail "PHP errors in the server log"
fi
if [ -s "$W/wp/wp-content/debug.log" ] && grep -q 'bmlt-literature-manager' "$W/wp/wp-content/debug.log"; then
	grep 'bmlt-literature-manager' "$W/wp/wp-content/debug.log" | tail -20
	fail "the plugin logged PHP notices or errors"
fi
pass "no PHP warnings or errors from the plugin"
grep -q "\" 5[0-9][0-9]$" "$W/logs/apache-access.log" && fail "a 5xx answer from Apache: $(grep '" 5' "$W/logs/apache-access.log" | head -3)"
awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | grep -q . && fail "a 5xx answer from nginx: $(awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | head -3)"
pass "no server errors"
echo
echo "End-to-end test passed."
