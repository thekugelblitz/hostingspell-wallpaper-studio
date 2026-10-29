# HostingSpell Wallpaper Studio

A single-page, self-hosted PHP wallpaper gallery and private logo editor. The complete application is in `index.php` (including a same-file JavaScript endpoint); no database, API key, JavaScript build or external JavaScript library is needed.

## Install on cPanel / LiteSpeed / Apache

1. Back up your existing `index.php`, then upload the new one to the application directory. Create a **child `walls/` directory** and put all public collection images there. Images next to `index.php` are intentionally excluded.
2. Select a currently supported PHP release, **PHP 8.2 or newer**, and enable **cURL, Fileinfo and GD** with PNG, JPEG and WebP support. Use HTTPS.
3. PHP must be able to create a `process` subdirectory. Do not use permissions `777`: directories are created as `0700`, and project files as `0600`.
4. Configure PHP through MultiPHP INI Editor or the appropriate server settings:

   ```ini
   upload_max_filesize = 25M
   post_max_size = 26M
   memory_limit = 256M
   max_execution_time = 60
   max_input_time = 60
   file_uploads = On
   ```

5. Add the cleanup cron below. The application automatically creates `process/.htaccess` to deny all direct access on Apache/LiteSpeed, and uses guarded PHP files as an additional protection. Verify that `https://your-domain/path/process/secret.php` returns **403 or 404**, with no file content. Directory browsing must also be disabled by your host.
6. Disable HTML/page caching for this page and its query-string endpoints at your CDN, LiteSpeed cache and reverse proxy. Each page has its own security token and cookie. Do not set a cache-everything rule here. Static public collection images can be cached.
7. Test the acceptance checklist below before making the tool public.

Existing PHP upload limits can reject a request before application code runs. The tool reports an error rather than treating a rejected upload as a successful save.

## Cleanup cron — required for unattended expiry

Projects and their session cookies have an absolute **24-hour** lifetime, without sliding renewal. Expired assets become inaccessible immediately. The cron removes files within approximately five minutes after expiry, provided cron is running and has write access. Requests that mutate projects also sweep expired directories.

Cookie expiry or closing a browser cannot itself execute deletion on the server. Clearing a cookie only loses access; the original project still expires on its original schedule. The Delete my project button deletes it immediately.

Example cPanel cron (replace both paths with your real PHP CLI binary and application path):

```cron
*/5 * * * * /usr/local/bin/php /home/CPANEL_USER/public_html/wallpapers/index.php --cleanup >> /home/CPANEL_USER/wallpaper-cleanup.log 2>&1
```

Use the same PHP version/configuration as the website. For EasyApache PHP 8.3, a common CLI binary is `/opt/cpanel/ea-php83/root/usr/bin/php`; verify the actual path on your server. Check the cleanup log after installation. Do not expose logs under the public web root. Rotate this log using your server's normal log rotation.

If a PHP worker is forcibly killed while importing, its OS temporary file can survive; normal completion and errors unlink it. Keep OS temporary-file cleanup enabled as well. Saved project images and metadata are under `process` and use the project's own expiry cleanup.

## Nginx / reverse proxies

On Nginx, `.htaccess` has no effect. Add a deny rule for the actual process URL path and reload Nginx:

```nginx
# Change /wallpapers/ to the actual URL prefix, or use /process/ at site root.
location ^~ /wallpapers/process/ { return 404; }
```

The PHP file guard is defense in depth; the deny rule and correct PHP handling are still required. Never configure the server to deliver PHP source as text. Prefer private storage outside the web root when possible:

```text
WPS_STORAGE=/home/CPANEL_USER/private/wallpaper-process
```

Set this environment variable for both PHP-FPM and cron, or change the `$storage` assignment near the top of `index.php` to your absolute private path. The default is the requested `process` folder next to `index.php`.

If TLS terminates at a trusted reverse proxy and PHP does not see `HTTPS=on`, set the fixed server environment variable `WPS_HTTPS=1`. Do not derive this flag from untrusted visitor headers. Cookie Secure is otherwise detected from the web server's HTTPS variable. Keep the public site HTTPS-only.

IP limits use `REMOTE_ADDR`, deliberately ignoring client-supplied forwarding headers. If using Cloudflare or a load balancer, configure your web server to restore the original address **only from known trusted proxy ranges**. Otherwise everyone behind the proxy shares a limit.

## How it works

### Our Collection

Upload collection images into the `walls/` child directory next to `index.php`; only that directory is scanned, never the application root, `process`, symlinks or nested directories. Names, pixel dimensions, file sizes and original downloads are shown. PNG, JPEG, WebP, AVIF, GIF and valid raster `.img` files are recognized. The modal viewer supports previous/next, Escape, arrow keys, fit-to-screen and original-pixel viewing. Search filters by wallpaper name.

### Create a wallpaper

1. Paste a free Unsplash photo page URL. For example:
   - `https://unsplash.com/photos/blue-and-teal-flowing-wave-shapes-cPccYbPrF-A`
   - becomes `https://unsplash.com/photos/cPccYbPrF-A/download?force=true`.
2. The server downloads the image, validates it, and stores a private, guarded asset under this project's random directory inside `process`.
3. Alternatively, upload a PNG, JPEG or WebP wallpaper directly.
4. Upload a company logo; transparent PNG is recommended. The server decodes and re-encodes logos as PNG, retaining transparency and dropping extra file metadata. Uploaded wallpaper metadata is not stripped.
5. Drag the logo with a mouse or touch, use the nine placement buttons, or focus the canvas and use arrow keys. Shift + arrow moves 10 px. Adjust logo width and opacity. A logo's size is constrained so it stays fully on the canvas.
6. Choose desktop, mobile, QHD, 4K or a custom output size. Fill uses a center crop; Fit shows the complete wallpaper with black borders. There is no manual photo cropping/panning.
7. Grid visibility and snap are independent. Snapping rounds the **logo center** to the nearest grid intersection; canvas edges take priority to keep the logo visible. Placement buttons align precisely to an edge or center. Grid and selection outlines are preview-only.
8. Download PNG, high-quality JPEG or WebP. If a browser falls back to PNG for an unsupported export encoder, the filename uses `.png` correctly. The finished export is composed in the browser and is not uploaded or published.

Layout changes are saved after a short debounce, once a project exists. Reloading restores the wallpaper, logo and saved settings while the cookie is valid. There is one project per browser cookie, so two tabs in the same browser share its saved project; the most recent save wins. Private/incognito browsers or separate browser profiles receive different projects. No personal uploads are added to the public collection.

## Unsplash behavior and permissions

This version uses the exact public-download-link workflow requested, with no API credentials. It does **not** scrape the photo page, circumvent a challenge or bypass paid-photo access. Public website endpoints can change or reject server requests. Imports show the actual failure and offer Open download plus Upload wallpaper as a fallback. A successful live import depends on your host allowing outbound HTTPS and DNS, and Unsplash allowing the request; it cannot be guaranteed across hosting providers.

Only `unsplash.com`, `images.unsplash.com` and `plus.unsplash.com` are allowed outbound hosts, with validated HTTPS URLs, no custom ports or credentials, public IPv4 validation, DNS pinning, TLS verification, disabled proxies, manually checked redirects, a 30-second transfer budget and a 25 MB response-body cap. IPv6-only egress environments need an IPv4 route for imports.

For a future official Unsplash API integration, follow its separate hotlinking, attribution and download-tracking requirements rather than merely swapping in an API URL. References:

- https://unsplash.com/documentation
- https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines
- https://unsplash.com/license

Users remain responsible for their image and logo permissions. Unsplash branding or photo links shown in the editor are not stamped onto exports. Do not use the private import feature to republish an Unsplash image library as your own collection.

## Defaults and protections

Defaults are defined near the top of `index.php` or the relevant `rate()` calls:

| Resource | Limit |
|---|---|
| Session/project lifetime | Fixed 24 hours |
| Background upload/import | 25 MiB, 24 million pixels, 12,000 px per side |
| Logo | 4 MiB before and after normalization, 4 million pixels |
| Editor input formats | Real PNG, JPEG and WebP; SVG/GIF uploads rejected |
| Export canvas | 320–7,680 px per side; 16 million pixels total |
| New projects | 6 per hour per IP |
| Unsplash imports | 6 per 10 minutes, 20 per 24-hour window per IP |
| Global Unsplash imports | 100 per hour |
| Uploads | 20 per hour per IP |
| API requests | 120 per 10 minutes per IP and per session |
| Layout saves | 40 per 10 minutes per IP |
| Private asset reads | 120 per 10 minutes and 512 MiB per hour per IP |
| Global saved projects/storage | 100 live projects / 1 GiB soft budget with preflight headroom |
| Minimum free disk space before asset writes | 100 MiB |
| Stored rate-limit buckets | 4,096, pruned after their windows expire |
| Simultaneous mutations | 1 server-wide; competing requests receive HTTP 429 |

Rate limits use atomic file locking and fixed windows; NAT/shared networks share IP limits. Deleting cookies does not reset IP limits. Layout saves, cookie resets and import failures still count toward relevant limits. Busy responses are deliberate to avoid parallel downloads exhausting shared-host resources. Export is local to the browser, so repeated exports do not create server work or extra files.

Other measures: signed random session cookies, HttpOnly and SameSite=Strict, Secure on HTTPS, fixed session expiry, synchronizer CSRF tokens, no permissive CORS, escaped HTML/JSON output, restrictive CSP, nosniff, private no-store responses, no path arguments for assets, no shell execution, non-recursive cleanup, symlink rejection, MIME/header checks, bounded photo dimensions and re-encoded logos. Temporary photos retain their original encoding, are served as their validated raster MIME type and are never executed.

These controls reduce risk; no implementation can honestly promise freedom from every vulnerability or denial-of-service attack. Keep PHP/GD/cURL patched. Enforce request-rate, body-size, connection and bandwidth limits at the web server/CDN too: PHP cannot stop bytes being received before it runs. This file-backed design is intended for modest traffic on one server with a local filesystem supporting `flock`, not multiple replicas on shared NFS. For larger traffic, move state and quotas to a shared transactional store and use a job queue.

## Acceptance checks

- Two separate browser profiles cannot read each other's private assets.
- A missing/incorrect CSRF token returns 403; expired cookies return 410 for private operations.
- SVG, scripts renamed `.png`, oversized files and over-dimension images are rejected.
- Off-domain URLs, custom ports, credentials, invalid photo paths and redirects outside the host allowlist are rejected.
- Import a free Unsplash image or exercise the manual-upload fallback if upstream rejects requests.
- Add a transparent PNG logo; test mouse/touch drag, keyboard movement, grid and snapping separately.
- Reload and verify restoration. Export PNG/JPG/WebP and verify dimensions, logo placement and absence of guides.
- Delete the project and verify its folder disappears.
- Check the cron log and verify expired test projects are removed without affecting public collection files.
- Verify the process URL is blocked, PHP source cannot be downloaded and authenticated HTML/API responses are never cached.

## Files

`index.php` is the entire deployable app. `README.md` is this guide. Upload only `index.php` to the public application directory; retain this guide outside the public directory if preferred. No admin password, API secret or placeholder credentials need to be entered.

## Included automated checks

The tests are development-only; the running application needs neither Python nor Node.

Start a disposable local PHP server with a **fresh, non-production** storage path:

```bash
WPS_STORAGE=/tmp/hs-wallpaper-test php -d upload_max_filesize=25M -d post_max_size=26M -d memory_limit=256M -S 127.0.0.1:8765
```

From a second terminal in this directory:

```bash
python3 tests/security_smoke.py http://127.0.0.1:8765/index.php
npm install --no-save @napi-rs/canvas
node tests/editor.test.cjs
```

The security smoke test uses and deletes temporary test projects, consumes rate-limit buckets, and should never be run against production. Restart it with a new disposable storage directory on each run. The Node test executes the actual editor JavaScript in a DOM harness with a native raster canvas; it checks URL conversion, dragging, keyboard movement, geometry, snapping, resolution caps, fit mode and exported image pixels. It does not simulate the whole browser.

## HostingSpell live installation and upgrading from 1.0

The existing installation is at `https://manage.hostingspell.com/walls/`.

| Item | Server layout relative to the manage subdomain document root | Public URL |
|---|---|---|
| Application | `walls/index.php` | `/walls/` |
| Collection images | `walls/walls/your-wallpaper.png` | `/walls/walls/your-wallpaper.png` |
| Private projects | `walls/process/` by default | Direct access must be blocked |

Move existing public wallpapers from the application directory into the **child `walls/` directory**, then replace `index.php`. Do not move personal temporary files into the gallery. The repository includes the empty child directory marker; it does not include the images on the live server. Back up your files first.

The Create-tab failure was reproduced on the live installation: Cloudflare Rocket Loader rewrote the editor's inline script to a non-executable custom type, and the tab did not switch. Version 1.1 serves JavaScript from `index.php?action=app&v=1.1.0` and marks both its script tag and inert JSON configuration with `data-cfasync="false"`. The attribute is deliberately placed before `src`, as Cloudflare documents. CSP permits the same-origin script. HTML/API replies also opt out of LiteSpeed/CDN caching. The rest of your Cloudflare settings need not be disabled site-wide.

After uploading, purge any cached HTML for `/walls/` and reload. A Cloudflare Cache Everything rule can override origin headers: explicitly bypass HTML and private endpoints for this path. The JavaScript endpoint contains no visitor token and may be cached; the JSON configuration comes from the current private HTML response.

Tabs are now large navigation cards and retain ordinary `?tab=create` / `?tab=collection` links. If JavaScript is disabled, the correct section still opens and a message explains that editing needs JavaScript. Custom wallpaper upload appears first in Create and supports dropping one image or choosing a file; 25 MiB / 24 MP limits are enforced again on the server. The mobile order is upload, preview, logo controls, then output settings.

Cloudflare reference: https://developers.cloudflare.com/speed/optimization/content/rocket-loader/ignore-javascripts/

### Real browser regression tests

```bash
npm install
npx playwright install chromium
npm run test:browser
```

This launches a disposable PHP server/application copy and checks real Chromium tab navigation, custom wallpaper and logo uploads, viewer/download paths, export pixels, reload restoration, a JavaScript-disabled fallback and a 390-pixel mobile viewport. It never visits or modifies the live installation. See the test header for custom PHP/Chromium paths.
