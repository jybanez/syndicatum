# Webroot hardening contract (source candidate)

This is a source-only candidate. It is **not** a live WAMP deployment or a
closure of the separate credential, public-edge, backup, release, or live
deployment gates. The existing emergency WAMP containment remains in effect
until a reviewed deployment contract explicitly replaces it.

The deployable root `.htaccess` runs three deny rules before normal file
passthrough and application rewrites:

1. Dotfiles and dot-directories at any depth are denied, except the required
   `.well-known` segment. Nested hidden material inside `.well-known` remains
   denied. This covers `.git/config`, `.env.example`, and similar local files.
2. `output`, `runtime`, `log`/`logs`, `cache`, `temp`/`tmp`,
   `backup`/`backups`, `dump`/`dumps`, `coverage`, `test-results`, and
   `playwright`, `tests`, `scripts`, `docker`, `schema`, `migrations`, and
   `docs` directories are denied at any depth. These source, verification,
   and operational trees are not public UI assets; server-side code can still
   read them from disk.
3. Files ending in `.sql`, `.sqlite`/`.sqlite3`, `.db`, `.bak`, `.backup`,
   `.dump`, `.log`, `.zip`, `.tar`, `.tgz`, `.gz`, `.7z`, `.rar`, or
   `.syndicatum-backup` are denied. These operational artifacts must be stored
   outside the public webroot; the rule is defense in depth, not permission to
   place them there.

Denials return HTTP 403 (or 404 where server configuration conceals the
path). No application URL, authentication, session, backup/restore, or FRP
behavior is changed. The normal root, API, UI assets, OAuth routes, and
`.well-known` remain on their existing serving paths.

`python tests/webroot-access.py` starts an isolated PHP/Apache fixture using
the committed `.htaccess` and Docker Apache configuration, then verifies both
denials and allow paths. The `webroot-access` CI job executes the same test.
This proves source-rule behavior, not the currently deployed public route;
local-vhost and public-route checks belong to the later reviewed live gate.

The incident's historical public successes included `/.git/config`,
`/.env.example`, and generated `/output` material before emergency containment.
WAMP's retained log records the FRPC socket peer, not an original client;
public FRPS/edge attribution and cache visibility must be reported separately
from this source change. The four historical relay-client `api_key`-shaped
values are not established as current credentials by this rule change.
