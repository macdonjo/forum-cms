# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Deploying

There is no build step. Every `git push` deploys via the auto-updater on the live instance.

**Commit** — the pre-commit hook in `.git/hooks/pre-commit` auto-stamps `version.txt` with `1.{unix_timestamp}` on every commit. Never edit `version.txt` manually.

**Deploy to live site** — after pushing, trigger the update via API:
```
curl -s -X POST https://forum.macdonaldmarine.com/api/update \
  -H "Authorization: Bearer <key>"
```
The API key is in the local, gitignored `.env` (`FORUM_URL`, `FORUM_API_KEY`). **The repo is public — never commit keys or credentials.**

**Manual fallback** — visiting `https://forum.macdonaldmarine.com/webhook.php` also triggers an update if the API isn't available.

## Architecture

Single-file front controller. All HTTP requests hit `index.php` via `.htaccess` mod_rewrite.

- `src/Router.php` — regex router. Routes are matched in registration order; static routes must be registered before dynamic `/{slug}` routes, which are always last.
- `src/DB.php` — PDO singleton, `FETCH_ASSOC` only. Use `DB::one()`, `DB::all()`, `DB::execute()`.
- `src/Auth.php` — session-based auth. Roles: `user`, `moderator`, `admin`. `requireAdmin()` / `requireMod()` exit with 403. Call `Auth::start()` once at boot.
- `src/helpers.php` — `render()`, `bbcode()`, `excerpt()`, `upload_image()`, `paginate()`, `unique_slug()`, CSRF helpers.
- `src/Updater.php` — downloads `main.zip` from GitHub, extracts it skipping `config.php` and `uploads/`. `applyZip()` strips the `forum-cms-main/` GitHub prefix automatically.
- `src/API.php` — Bearer token auth against the `settings` table.
  - Read: `GET /api/sections`, `GET /api/users` (`role`, `limit`, `offset`), `GET /api/threads` (`section`, `user_id`, `sort=updated|created|oldest|replies`, `limit` ≤200, `offset`; returns excerpts), `GET /api/threads/{id}` (full body + all replies).
  - Write: `POST /api/users`, `POST /api/users/{id}` (rename: `username` and/or `email`; `source` + `apply_to_posts` to backfill), `POST /api/threads/{id}` (edit `title`/`body`; no bump, slug kept), `POST /api/replies/{id}` (edit `body`), `POST /api/threads`, `POST /api/replies` (each can create content as any `user_id`; threads/replies fall back to the first admin), and `POST /api/update` (triggers the self-updater).
  - All three create endpoints accept optional `created_at` (ISO 8601 / strtotime / unix; up to 1 year ahead). A new thread's `updated_at` starts at its `created_at`; a reply moves the thread's `updated_at` forward only (`GREATEST`), and can't predate its thread.
  - Delete: `POST /api/threads/{id}/delete` (cascades replies), `POST /api/replies/{id}/delete`. POST, not DELETE, to stay clear of host/WAF method filtering.
  - GET responses flag `scheduled` on threads/replies; `GET /api/threads?scheduled=include|only|exclude`; `GET /api/sections` returns `server_time` (PHP vs MySQL clocks).
  - **GET requests must send a `forum_sess` cookie (any value)** or `API::auth()` returns 400 — otherwise Cloudflare would edge-cache the authenticated JSON and serve it to anyone (see Cloudflare section).

Templates use `render('name', $vars)` which extracts vars, captures output into `$content`, then includes `templates/layout.php`. The `$config` global is always available inside templates.

## Key conventions

**URLs** — `/{section-slug}` and `/{section-slug}/{thread-slug}`. No `/s/` prefix. Reserved slugs (login, register, cp, api, upload, etc.) are blocked at section-create time.

**BB code** — post bodies are stored raw and rendered via `bbcode()` in templates. Allowed tags: `[b]`, `[i]`, `[img]`. Images must use `https://`, `http://`, or `/uploads/` URLs. Never render post bodies with `nl2br(h())` — always use `bbcode()`.

**Migrations** — schema changes go in `index.php` as idempotent SQL (`ALTER TABLE ... IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). Never use `array_column($result, 0)` for `SHOW TABLES` — use `array_column($result, 'Field')` for `SHOW COLUMNS` or check `!empty()` on the result.

**Source tracking** — `users`, `threads` and `replies` have `source ENUM('web','api')` (default `web`). Every API insert sets `'api'`; site forms leave it `web`. Seeded accounts show an "API" badge in the admin CP. Any new API insert must set `source = 'api'`.

**Scheduled content** — a future `created_at` (API only) schedules a thread or reply. Every public query filters `created_at <= NOW()` (home counts, section list, thread page + replies, sitemap, web reply target); **any new public query must do the same.** `threads.reply_count` / `updated_at` hold *visible* values only: a scheduled reply doesn't touch them on insert, and `publish_due()` (helpers.php, run on each origin request, throttled to 1/min via the `publish_last_run` setting) recomputes them for threads whose scheduled replies have gone live. The thread page computes its reply count live, so it is exact even before that catch-up. Cloudflare's 5-min forum edge cache means anonymous visitors may see scheduled items up to ~5 min late, never early.

**`updated_at` gotcha** — `threads.updated_at` is `ON UPDATE CURRENT_TIMESTAMP`, so *any* `UPDATE` to a thread row auto-bumps it (which drives the "Updated" column / activity sort). An `UPDATE` that must not count as activity — e.g. the view-count increment — has to set `updated_at = updated_at` explicitly to suppress the auto-bump.

**CSRF** — all POST forms include `<?= csrf_field() ?>` and handlers call `csrf_verify()`. The AJAX upload endpoint also verifies CSRF via the `csrf` FormData field (read from the `<meta name="csrf">` tag in layout).

**Timestamps** — always output as `<time datetime="<?= date('c', strtotime($ts)) ?>" data-fmt="date|datetime">`. The JS in layout.php rewrites them to the viewer's local timezone.

**CSS versioning** — `style.css` is loaded as `/assets/style.css?v=<?= Updater::currentVersion() ?>` for Cloudflare cache-busting on each deploy.

## Live instance

- URL: `https://forum.macdonaldmarine.com`
- Host: SiteGround shared hosting, Cloudflare CDN in front
- Admin panel: `/cp` (not `/admin` — blocked by SiteGround ModSecurity)
- Git remote: `git@github-macdonjo:macdonjo/forum-cms.git` (uses `id_ed25519_github_josh` key via the `github-macdonjo` SSH host alias)

## Cloudflare / caching

Cloudflare sits in front. Cache Rules (zone `macdonaldmarine.com`, evaluated top→bottom, later match wins) **override the origin's `Cache-Control`** — sending `no-store` from PHP does **not** stop edge caching. So a caching problem here **cannot be fixed in code**; it must be fixed in the Cloudflare Cache Rules:

1. **"Cache All Pages"** — the main site: edge 24h, browser 8h, query strings ignored. It **excludes** `forum.macdonaldmarine.com` (`http.host ne ...`).
2. **"Forum: short cache"** — forum host only: edge **5 min**, browser **1 min**, default cache key (so `?page=N` is cached separately). New/scheduled posts show to anonymous visitors within ~5 min. (A custom cache key isn't allowed on this plan, which is why the forum is excluded from rule 1 rather than overridden.)
3. "webcams" — no cache.
4. "Bypass cache for sessions and auth" — see below.

API access: `CF_API_TOKEN`, `CF_ACCOUNT_ID`, `CF_ZONE_ID` are in the gitignored `.env` (token scope: this zone, Cache Purge + cache rules edit). The cache-settings ruleset id is `406b3313a28f4d51be132d685e94d577`. Purge the forum: `POST /zones/$CF_ZONE_ID/purge_cache {"hosts":["forum.macdonaldmarine.com"]}`.

The rule that makes auth work is **"Bypass cache for sessions and auth"**: bypass when `http.cookie contains "forum_sess"` OR path is `/login`/`/register`. Without it, cached `/login` serves a stale CSRF token + strips `Set-Cookie` ("Invalid CSRF token"), and logged-in users are served the cached anonymous page. Cookieless requests (Googlebot, first-time readers) still cache, preserving SEO.

- Check behavior with `curl -s -i <url>` and read `CF-Cache-Status` (HIT/MISS = cached; DYNAMIC/BYPASS = origin). Add/remove a `forum_sess` cookie to test the bypass.
- To debug the origin directly (bypassing Cloudflare), resolve the domain to the SiteGround origin: `curl --resolve forum.macdonaldmarine.com:443:<origin-ip> ...` (origin = the FTP host `gtxm1048.siteground.biz`).
- New auth/session/form routes must either set the `forum_sess` cookie or get their path added to the bypass rule.
