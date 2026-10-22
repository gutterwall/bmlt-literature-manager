# BMLT Literature Manager

A WordPress plugin for Narcotics Anonymous service bodies: groups order literature from a public form (they pick
their meeting from the BMLT Root Server, which tells the plugin their Area), each Area keeps its own inventory and
special orders, and the Area's literature workers process orders from a protected page or the WordPress dashboard.
All data lives in protected JSON files outside the WordPress database, so pushing a staging database to production
never overwrites production orders.

| | |
|---|---|
| Plugin | [`bmlt-literature-manager/`](bmlt-literature-manager) — full documentation in its [README](bmlt-literature-manager/README.md) and [administrator guide](bmlt-literature-manager/docs/ADMIN-GUIDE.md) |
| Release file | `bmlt-literature-manager-<version>.zip` from [Releases](../../releases) (Plugins → Add New → Upload Plugin) |
| Requires | WordPress 5.9+, PHP 7.4+ |
| License | GPL-2.0-or-later ([LICENSE](LICENSE)) |

Each release is built by CI from the tagged commit, and the zip carries a GitHub build provenance attestation:

```sh
gh attestation verify bmlt-literature-manager-6.3.0.zip --repo gutterwall/bmlt-literature-manager
sha256sum -c bmlt-literature-manager-6.3.0.zip.sha256
```

Inside the zip, `build.json` names the commit it was built from and `checksums.sha256` lists every file.

## Fake BMLT root server

[`fake-bmlt/`](fake-bmlt) answers the BMLT `client_interface/json/` requests the plugin makes (`GetServerInfo`,
`GetServiceBodies`, `GetSearchResults` with `services[]`, `recursive` and `data_field_key`), with every value a string
as the real server sends it, over a fictional Region: three Areas, a sub-Area whose meetings belong to its parent, an
empty Area, another Region, an unpublished meeting, a meeting without a name and names with markup. It is used
in-process by the PHPUnit tests and over HTTP for the end-to-end test or a local site:

```sh
php -S 127.0.0.1:8090 fake-bmlt/router.php
# Root Server URL: http://127.0.0.1:8090/main_server
# /down/main_server answers HTTP 503, /html/main_server answers an HTML page (a wrong URL)
```

WordPress refuses Root Server URLs on private addresses or unusual ports; allow the local one with the
`http_request_host_is_external` and `http_allowed_safe_ports` filters (bin/e2e.sh shows how).

## Tests

| Command | What it covers | Needs |
|---|---|---|
| `vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist` | 79 WordPress integration tests: the public order flow (server-side pricing, stock and special-order split, review and confirm, tampering, spam checks, rate limit, duplicates, cached forms), order statuses and inventory under each inventory mode, the REST permission matrix by role and Area, BMLT caching and outages, import/export and backup/restore (including hostile files), privacy export and erasure, admin screens and handlers, SMTP, the JSON store (atomic writes, locking, damaged files, nothing readable on nginx), upgrade from 1.0 and 6.2, uninstall on single sites and networks | `WP_TESTS_DIR` (`bin/install-wp-tests.sh`), `composer install`; `WP_MULTISITE=1` for a network |
| `bin/e2e.sh <dir>` | the release zip on Apache and on nginx: an administrator connects BMLT and chooses Areas in Chrome; visitors order through the form; email arrives through the plugin's SMTP setting at a sink; workers (restricted and not) process orders; every file in the data folder is requested over the web on both servers | Apache, nginx, PHP-FPM, WP-CLI, Chrome, MySQL (see the script's header) |
| `vendor/bin/phpcs`, `vendor/bin/phpstan analyse` | WordPress security rules (escaping, nonces, input, SQL, i18n) and PHP 7.4+ compatibility; static analysis at level 5 | `composer install` |

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs all of them on every push: WordPress 5.9 on PHP 7.4,
the latest WordPress on PHP 8.1 to 8.4, single site and multisite, MySQL 5.7 and 8.0, MariaDB 10.6 and 11.4.

## Releasing

`bin/build.sh` checks that every version string agrees and that both changelogs have an entry, then builds the zip
from the committed files (byte-for-byte reproducible). A release is tagged `v<version>`; CI builds, tests and attests
the zip, and that zip is published.
