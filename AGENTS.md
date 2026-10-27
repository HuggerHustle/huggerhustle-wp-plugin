# huggerhustle-wp-plugin

The WordPress plugin for HuggerHustle. It reads this site, starts a campaign from
it, shows the businesses waiting for approval, and lets a person read and approve
each introduction — without leaving `wp-admin`.

It is a **consumer** of the platform's public API and nothing else. The app lives
in `HuggerHustle/platform-v2`; this repository never reads that source, never
shares its database, and has no build-time dependency on it. The contract is
`https://huggerhustle.com/api/openapi.json`, and that distance is the point: a
separate repo cannot quietly import a server-only helper.

## Testing

Three layers, and each one catches something the others cannot.

| Layer | Command | Catches |
| --- | --- | --- |
| PHP parses on the WordPress PHP | `pnpm test:lint` | a syntax error before WordPress does |
| End-to-end against a fake API | `pnpm test` | the plugin's own logic: no key means no request, the dashboard renders, the held business is not offered, an approve sends one PATCH, a 429 and an unreachable API both surface the API's words |
| End-to-end against the real app | manual, see below | anything the fake was too kind about: redaction, real auth, real error codes |

`pnpm test` starts `wp-env` (Docker), a fake API (`tests/fake-api.mjs`) and asserts
on the HTML WordPress actually renders. It needs Docker and takes a few minutes
the first time.

**Against the real app**: run `pnpm dev` in `platform-v2`, mint a key at
`http://hugger.test/api-keys`, then in `wp-admin` set the address to
`http://host.docker.internal:3000` and paste the key. Local `.test` sites are
accepted by the app in development, which is why `huggerhustle_site_url` exists
as a filter.

## Rules that are not style

- **Nothing leaves this site before a key is saved.** WordPress.org Guideline 7,
  and easy to break with an eager `admin_init` fetch. `HuggerHustle_Client`
  refuses with no key, and the test asserts zero requests.
- **Escape on output, sanitize on input, nonce and capability on every write.**
  `manage_options` for both screens, `check_admin_referer` in both handlers,
  `esc_html` / `esc_attr` / `esc_url` on everything printed.
- **Never store a copy of account data.** Campaigns, businesses and
  introductions live in HuggerHustle. This site keeps the key, the address, and a
  60-second transient per read.
- **The plugin never sends email**, and says so in the readme.
- **GPL-2.0-or-later.** Required by WordPress.org; the licence is in `LICENSE`.
- **The readme's `== External services ==` must stay true.** It is what the
  directory review checks, and it is what an owner reads before trusting a plugin
  with a key.

## Layout

| Path | Owns |
| --- | --- |
| `huggerhustle.php` | the plugin header, constants, and the four hooks |
| `includes/class-huggerhustle-client.php` | every HTTP call: key, timeout, cache, error envelope |
| `includes/class-huggerhustle-settings.php` | the key and the API address, and their sanitizers |
| `includes/class-huggerhustle-admin.php` | the dashboard, the approve button, starting a campaign |
| `uninstall.php` | the two options and the transients, nothing else |
| `tests/` | the fake API, the e2e, the syntax check |

## Releasing

The zip must unpack to `huggerhustle/`, which is the slug WordPress.org keys the
plugin by — a zip whose root is `huggerhustle-wp-plugin/` installs as the wrong
slug and cannot be updated. Bump `Version:` in `huggerhustle.php` **and**
`Stable tag:` in `readme.txt` together, then upload.

## Agent skills

`.agents/skills/` holds the official WordPress skillpack
(https://github.com/WordPress/agent-skills). Read `wp-plugin-development`,
`wp-rest-api` and `wp-plugin-directory-guidelines` before changing plugin code;
they are the authority on how WordPress expects this to be written, so prefer
them over memory.
