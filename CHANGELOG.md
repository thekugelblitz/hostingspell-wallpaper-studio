# Changelog

## 1.1.0 — 29 October 2026

- Fix Create and viewer execution behind Cloudflare Rocket Loader using a same-origin JavaScript endpoint and `data-cfasync="false"`.
- Add server-rendered tab links that still open the correct section without JavaScript.
- Replace understated tabs with large, labeled navigation cards.
- Restrict gallery scans, viewer image URLs and original downloads to the child `walls/` directory.
- Promote custom wallpaper uploads, add single-file drop support and preflight size/dimension validation. Server limits remain authoritative: wallpaper 25 MiB / 24 MP, logo 4 MiB / 4 MP.
- Improve mobile section order and add a workspace retry control.
- Isolate JavaScript scope, retain restrictive CSP, and add LiteSpeed/CDN no-cache headers for private HTML/API responses.
- Add actual Chromium desktop/mobile, upload, export and no-JavaScript regression tests.

## 1.0.0

Initial gallery, viewer, private editor, Unsplash import, rate limits and expiry cleanup.
