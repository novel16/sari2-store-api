# Public website, content editing, and SEO

The public home (`/`) and pricing (`/pricing`) pages belong to the existing React frontend on port 3000. They use `PublicLayout`, separately from the owner workspace and its session provider. Laravel supplies JSON and stores content; it does not render the landing page. Public visits do not restore a private `/me` session.

## Local setup

Apply the additive website migration using the existing API environment:

```sh
cd api
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan storage:link
```

The storage link may already exist. The migration seeds the initial public copy, draft, and revision; it does not reset financial data or overwrite edited plans. Keep the existing app key and database credentials.

Run each frontend from its own directory with `npm ci` and `npm run dev`. Use Node 24. The store/public frontend uses port 3000, admin uses 3001. Set each app's `VITE_API_URL` to the API's **published host port**. With this workstation's Sail mapping, that is `http://localhost:80`, not the historical `8000` fallback. Browser URLs must be reachable from the browser; Docker service names are only appropriate for server-to-server traffic on that network.

Open `http://localhost:3000/` or `/pricing`. Local defaults intentionally send `noindex`; the local sitemap contains no indexable URLs.

## Admin workflow

Open **Website & SEO** in the admin sidebar. The editor supports brand/copy, page titles and descriptions, feature/step/FAQ lists, section order and visibility, logo/hero/social images and alt text, and footer contact details. Uploads accept JPG, PNG, or WebP, up to 5 MB and 6000 pixels per dimension. Uploaded files live on Laravel's public storage disk; keep that storage persistent and configure its public HTTPS URL in production.

1. Edit the draft and supply a change note. **Save draft** does not affect the public site.
2. **Preview saved draft** opens `/website-preview` in the frontend. It requires the existing administrator session and stays `noindex`.
3. Supply a publication note and **Publish saved draft**. The saved body and metadata become public together. Unsaved edits must be saved first.
4. Revision history records the action, version, actor's saved name, note, and time. **Restore as draft** records an automatic restoration note and does not publish automatically.

Conflicting edits return HTTP 409 and require a reload. Pricing and allowances remain managed under **Plans**; the website reads their current public projection. Unpublished plans and private account, usage, and payment information are excluded. Coming-soon plans are labelled and cannot be purchased. A Basic signup link preserves plan intent through authentication to the existing subscription checkout.

## Frontend build and production serving

From `frontend/`:

```sh
npm ci
npm run build
npm start
```

The build creates browser assets in `dist/`, a React server renderer in `dist-server/`, and initial public HTML snapshots. Production serving requires Node 24, the `server/` files, both build directories, and runtime React dependencies. Put the Node process behind your HTTPS reverse proxy and process supervisor. `HOST` defaults to `127.0.0.1`; set `HOST=0.0.0.0` when appropriate for a frontend container. `PORT` defaults to 3000. Admin remains a separate static SPA deployment with its own fallback.

Configure the public frontend's environment before its production build and start:

```dotenv
VITE_API_URL=https://api.example.com
SITE_URL=https://example.com
SEO_INDEXABLE=true
# Optional API origin reachable by the frontend Node process:
PUBLIC_API_URL=https://api.example.com
HOST=127.0.0.1
PORT=3000
```

Use the real origins. `SITE_URL` must be an origin without a path/query; indexing requires HTTPS and rejects localhost. It determines canonical, social, schema, hreflang, and sitemap URLs independently of untrusted request headers. `VITE_API_URL` is public build configuration for browser requests. `PUBLIC_API_URL` is only used by the Node renderer and may point to a private Docker-network API origin; it defaults to `VITE_API_URL`. Restart after changing server environment settings; changing a `VITE_` value also requires rebuilding browser assets. Match Laravel's `FRONTEND_URL`, CORS, Sanctum, and cookie configuration to the deployed apps.

The frontend server revalidates the public JSON revision with ETags on public requests. A publication or public plan change updates rendered HTML, metadata, schema, and sitemap together **without a frontend rebuild**. HTML rendering is cached by revision. Concurrent fetches are shared. During an API outage, the latest successful snapshot is usable for at most 24 hours; without one, public pages return a `noindex` HTTP 503 with `Retry-After`.

Do not replace this Node server with a catch-all static SPA fallback: that loses request-time published HTML. A static-only deployment must rebuild and replace all public snapshots after every content or plan publication. An indexable build requires a reachable, migrated public API; a non-indexable CI build can use a maintenance snapshot when the API is unavailable.

## Search and sharing behavior

- Both public routes contain their visible content and SEO head in the initial HTML, before JavaScript runs. React then hydrates the same revision.
- Each page has its own title/description, absolute canonical, `en-PH` and `x-default` alternate links, Open Graph and Twitter metadata, and social image. Only the actual English content is declared; other language versions are not invented.
- JSON-LD describes the organization, website, page, and web application. Offers use current published plan prices in PHP; coming-soon plans are omitted from offers. Pricing includes breadcrumbs. No fabricated ratings or reviews are emitted.
- `/sitemap.xml` includes only `/` and `/pricing` when indexing is enabled, with publication/catalog modification dates. `/robots.txt` advertises that sitemap only when enabled.
- Auth, workspace, preview, and admin pages are `noindex` and excluded from the sitemap. Paths stay crawlable so search engines can read the `noindex` directives; robots rules are not an access-control mechanism.
- Links use real `href` destinations. Images have alt text; headings are semantic. `/pricing/`, `/index.html`, and `/pricing/index.html` redirect to canonical public paths. Unknown production frontend routes return HTTP 404.

Set the actual production domain and `SEO_INDEXABLE=true` only for the live public deployment. Search Console submission, DNS, hosting, and external publication remain deployment tasks.

## API surface

| Method | Path under `/api/v1` | Access / behavior |
| --- | --- | --- |
| GET | `/public/site` | Anonymous published projection, ETag/304 |
| GET / PATCH | `/admin/website` | Administrator document / save draft |
| POST | `/admin/website/publish` | Publish saved draft |
| POST | `/admin/website/restore/{revision}` | Restore historical content as draft |
| GET | `/admin/website/preview` | Private saved draft projection |
| GET | `/admin/website/history` | Paginated revision metadata |
| POST | `/admin/website/media` | Validated public image upload |

Writes use CSRF and existing platform administrator authorization. Save/publish/restore require `expected_version` and `change_note`. Website history is its own feed; it is not added to the combined Activity page.

## Verification on a small development machine

Run checks sequentially. Limit Node test concurrency instead of running both apps' suites, builds, and a browser at once:

```sh
# Run inside each frontend repository, one app at a time:
node --test --test-concurrency=1
npm run lint
npm run build

# Inside api/, with isolated SQLite rather than the application database:
./vendor/bin/sail exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: laravel.test php artisan test --filter=WebsiteContentTest
```

Website API tests cover public isolation/ETags, administrator access, draft/publish/restore, edit conflicts, media validation, and plan revision changes. Frontend tests cover initial HTML, metadata/schema escaping, canonical and sitemap rules, publication revalidation, and outage behavior. Check home/pricing without JavaScript and on mobile, then verify save/preview/publish/restore with a test administrator before a live release.
