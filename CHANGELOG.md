# Changelog

## 1.4.0

- Add a public GitHub repository link with an inline icon in the header.
- Default collection sorting to natural name order; add seven alternative orders.
- Keep viewer navigation consistent with selected sorting and support sorting without JavaScript.
- 85 checks passed.

## 1.3.0

- Make the editor upload-only; remove Unsplash UI, link handling, remote fetch code and cURL dependency.
- Retain logo dragging/resizing, configurable grids, modern gallery and SEO from 1.2.
- Confirm the retired import endpoint returns JSON 404.
- 71 checks passed.

## 1.2.0 — 29 October 2026

- Add import HTTP diagnostics, bounded private logging, JSON fatal recovery and ID-only import payloads.
- Shorten import transfer budget and retain strict outbound restrictions and upload quotas.
- Add resizable canvas logos, row/column grids and cell subdivisions.
- Simplify editor copy and modernize collection cards.
- Add canonical/social metadata, structured data, sitemap and recovery regression tests.
- 84 checks passed; live server import remains unverified.

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
