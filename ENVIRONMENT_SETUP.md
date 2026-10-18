# API environments: local, development, production

Use Laravel's native environment loading. Every running installation has one complete `.env`, or environment variables supplied by its server/CI platform. The application code and `config/*.php` are shared across environments; credentials and infrastructure addresses belong to the installation.

| Environment | Purpose | Starting template | Private file on that installation |
| --- | --- | --- | --- |
| `local` | Your workstation, localhost API and SPAs | `.env.example` | `.env` |
| `development` | Shared development/integration server | `.env.development.example` | `.env` |
| `production` | Live store data and services | `.env.production.example` | `.env` |
| `testing` | Automated tests | `phpunit.xml` | No real database credentials needed |

The local `.env` has already been restored to a full configuration with `APP_ENV=local`. Its existing application key, database connection, database name, and credentials were preserved. The original `.development.env` and `.production.env` remain ignored private legacy copies; Laravel no longer reads them. In particular, the old production copy points at the local database and is not a production configuration to deploy.

## Responsibilities

```text
bootstrap/app.php       Standard Laravel app, routes, middleware, exceptions
config/*.php            Typed application configuration using env(...)
.env                    Actual settings for this installation (ignored)
.env*.example           Committed, complete templates without credentials
phpunit.xml             Forced testing configuration and in-memory SQLite
```

Application services read `config('...')`. Environment variables are read inside configuration files. `config/database.php` keeps its normal DB connection mappings; it does not load or switch dotenv files. No custom selector, dotenv loader, environment inheritance, or per-environment application code is needed.

## Local workstation

Keep the existing full `.env`. To apply local changes, stop the running development server, then:

```powershell
php artisan config:clear
php artisan env
php artisan serve --port=8000
```

The environment should be `local`. The owner app stays on `http://localhost:3000`, admin stays on `http://localhost:3001`, and the API stays on `http://localhost:8000`. Restart the server after changing settings, because its child process can inherit an older `APP_ENV`.

For a **new checkout only**, copy `.env.example` to `.env`, fill its local DB settings, then generate an initial key with `php artisan key:generate`. Do not overwrite an existing `.env` or regenerate a key that protects existing encrypted records.

## Development and production installations

Copy the matching template to `.env` on that separate installation. Fill its own database, key, URLs, domains, mail, and any storage credentials. The examples contain blank database credentials and blank app keys; remote installations are not provisioned by this change.

The shared development template uses `APP_ENV=development`, HTTPS, debug off, daily logs, log-only mail, and its own session/cache names. The production template uses `APP_ENV=production`, HTTPS, debug off, warning-level daily logs, and configurable SMTP. Database-backed sessions, queues, and cache use the existing infrastructure; Redis or other services can be selected later through their ordinary Laravel configuration when actually deployed.

Replace **all** example domains together. `APP_URL` identifies the API; `FRONTEND_URL` identifies the owner SPA; `CORS_ALLOWED_ORIGINS` lists the exact owner/admin origins; `SANCTUM_STATEFUL_DOMAINS` lists their hosts (and ports if nonstandard). For the demonstrated sibling-subdomain layout, `SESSION_DOMAIN` is their shared parent so the SPAs can read the XSRF cookie and send authenticated API requests. Use HTTPS with `SESSION_SECURE_COOKIE=true`. Development and production should have separate databases, keys, domains, and service credentials. The API templates do not configure or deploy either frontend.

After configuring a server, verify the name before building its cache:

```powershell
php artisan config:clear
php artisan env
php artisan config:cache
```

The production command must report `production`. Cached configuration is authoritative until cleared/rebuilt, so editing `.env` alone does not update it. Do not build a production config cache on this workstation or copy its cache artifact to another environment; build on the target installation with its own settings. Long-running workers must be restarted by the deployment process after configuration changes. Run migrations separately as part of an authorized deployment; environment setup itself never creates or changes a database.

## Optional named profiles in one checkout

Laravel also supports private `.env.development` and `.env.production` files through `--env` or an external `APP_ENV`. These are optional; separate server installations normally use their own `.env`.

For example, to create a development profile without overwriting an existing one:

```powershell
if (Test-Path -LiteralPath .env.development) { throw 'The development profile already exists.' }
Copy-Item .env.development.example .env.development
```

Fill that file with the intended development settings and initial key before using it. Then:

```powershell
php artisan env --env=development
php artisan serve --env=development --port=8000
```

Use the full names `development` and `production`, matching the profile's own `APP_ENV`. Merely changing `APP_ENV` **inside the default `.env`** changes the environment label; it does not cause a second profile file to load. Native profile selection happens before that file is read.

If a requested named file is missing, Laravel falls back to `.env`. Check that the file exists and inspect `php artisan env --env=...` before running commands against another environment. Do not use `--env=production` on this local checkout without deliberately preparing and verifying its separate profile.

External server/OS variables override dotenv values. Avoid conflicting sources: if an external `APP_ENV` is already set, align it with the intended profile instead of using a conflicting CLI flag. For a deliberate switch in a development PowerShell terminal, clear a stale process-only value with `Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue`, then run the intended command. Server/CI variables are managed by that deployment platform.

## Tests and credentials

`phpunit.xml` forces `APP_ENV=testing`, a public test-only encryption key, SQLite `:memory:`, array sessions/cache/mail, and synchronous queues. The existing database guard refuses RefreshDatabase against a real database. Test overrides take priority even when a shell has database settings. Do not reuse the public test key outside tests.

Actual `.env` and `.env.*` files are ignored; only example files are committed. The two legacy private profiles are also ignored. Do not share environment dumps or full `config:show database` output, which may contain credentials. Native `php artisan env` is sufficient to check the active environment name.

```powershell
php artisan test --compact
php vendor/bin/pint --test bootstrap/app.php tests/Unit/EnvironmentTemplatesTest.php
```

Project tests check template completeness, blank credentials, remote debug/HTTPS settings, consistent SPA domains, separate session/cache names, and forced test isolation. Configuration resolution is left to Laravel's maintained implementation.

References: [Laravel environment configuration](https://laravel.com/docs/12.x/configuration#environment-configuration), [configuration caching](https://laravel.com/docs/12.x/configuration#configuration-caching).
