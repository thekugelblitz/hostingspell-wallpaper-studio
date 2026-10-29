# HostingSpell Wallpaper Studio

A standalone AMOLED wallpaper gallery and private, upload-only logo wallpaper maker for PHP hosting. Version 1.3.0.

## Features

- Our Collection reads public images exclusively from the `walls/` directory beside `index.php`.
- Fullscreen collection viewer, search, names, dimensions, file sizes and original downloads.
- Upload a wallpaper and company logo; drag to move and use the orange corner handle to resize.
- Grid visibility and snapping switch independently. Use pixel spacing or adjustable rows/columns with subdivisions.
- Desktop/mobile presets, custom canvas dimensions, opacity, image fit and optional quick placement.
- PNG/JPG/WebP export in the browser, without selection or grid guides.
- Private cookie-isolated projects with fixed 24-hour expiry, automatic cleanup and immediate deletion.
- No photo-link input, remote image importing, outbound photo requests or cURL dependency.

## Installation

Requires PHP 8.2+, Fileinfo and GD with PNG/JPEG/WebP support. No database, Node.js runtime or build step is needed in production.

1. Upload `index.php` to your installation directory, for example `/public_html/walls/index.php`.
2. Put public wallpapers inside the child directory `/public_html/walls/walls/`. Private uploads never enter the collection.
3. Ensure PHP can create and write `process/`. Prefer storage outside the web root when possible by setting `WPS_STORAGE` to an absolute private path.
4. Configure supported PHP settings through cPanel MultiPHP INI Editor or `.user.ini`:

   ```ini
   upload_max_filesize = 25M
   post_max_size = 26M
   memory_limit = 256M
   max_execution_time = 60
   max_input_time = 60
   file_uploads = On
   ```

   The app enforces 25 MB / 24 MP wallpapers, 4 MB / 4 MP logos, and 16 MP exports. Raising server limits does not raise these limits. `.htaccess` `php_value` directives are handler dependent and may cause HTTP 500.
5. Add the required cleanup cron, using your actual PHP binary and path:

   ```cron
   */5 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/CPANEL_USER/public_html/walls/index.php --cleanup >> /home/CPANEL_USER/wallpaper-cleanup.log 2>&1
   ```

6. Verify direct access to `/walls/process/secret.php` is denied with 403/404. The app creates an Apache/LiteSpeed denial rule and uses guarded PHP asset files. On Nginx add a location rule:

   ```nginx
   location ^~ /walls/process/ { deny all; return 404; }
   ```

7. Bypass CDN, LiteSpeed and reverse-proxy caching for application HTML and API endpoints; public collection images can be cached. Keep the Rocket Loader exclusion on the app script.
8. Upload `sitemap.xml`, update its URLs and the canonical/social URLs in `index.php` if deploying elsewhere, and submit it to your search console. Metadata and structured data support indexing but do not guarantee rankings.

## Upgrading

Replace `index.php` and `sitemap.xml`, then purge cached HTML and old app JavaScript. Preserve public `walls/` images and private `process/` data. Existing project images and layouts still load. The removed `action=import` endpoint returns JSON 404. No remote-source links are displayed for older projects.

## Privacy and limits

Necessary HttpOnly, SameSite=Strict cookies identify private projects; HTTPS deployments use Secure cookies. Fixed expiry is 24 hours and does not extend with activity. Expired assets become inaccessible; the cron removes files within approximately five minutes when running correctly. Cookie expiry alone cannot execute server cleanup. Delete my project removes the workspace immediately.

Uploads replace the previous background/logo. Limits include 20 uploads per hour per network address, 120 API requests per ten minutes, 100 active projects and 1 GB temporary storage. Shared networks share rate limits. The application trusts `REMOTE_ADDR`, never arbitrary forwarding headers; configure the host's trusted proxy real-IP handling when behind a CDN.

The server validates MIME/type and image dimensions, normalizes logos through GD, requires CSRF tokens, restricts cross-site mutations and isolates assets by signed cookies. Keep PHP patched and never commit `process/`, logs, `.env` files or visitor uploads.

## Troubleshooting

Upload failures display HTTP status and available request references. PHP errors after startup produce buffered JSON where possible. A small reserved buffer permits reporting many PHP memory failures. A firewall block, PHP startup failure or externally killed worker can still return HTML or no response; the browser identifies those cases.

Read `process/diagnostics.php` through SSH or cPanel File Manager. This PHP-guarded private application log is bounded to approximately 512 KB and contains references, timestamps and errors rather than cookies or request bodies. PHP's configured server error log also receives failures. Never make diagnostics publicly downloadable.

If an error never reaches PHP, inspect the matching Cloudflare event, ModSecurity audit entry or Apache/LiteSpeed access/error log. Do not disable firewalls globally.

Print CLI configuration and recent application diagnostics using the matching PHP binary:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php /home/CPANEL_USER/public_html/walls/index.php --diagnostics
```

CLI INI settings can differ from the website handler. Do not expose a public phpinfo page.

## Development checks

```bash
npm ci
npm test
python3 tests/security_smoke.py http://127.0.0.1:8765/index.php
npm run test:recovery
npm run test:browser
```

The HTTP smoke suite requires a disposable, fresh PHP development server with writable isolated `WPS_STORAGE`. Never run mutation tests against production. Browser and recovery suites create their own disposable application/storage copies. Install a Playwright-compatible Chromium for browser tests. `PHP_BIN`, `PHP_EXTRA_ARGS` (a JSON array), `PHP_LIBRARY_PATH`, and `CHROMIUM_PATH` can select development runtimes. Production does not require these development packages.
