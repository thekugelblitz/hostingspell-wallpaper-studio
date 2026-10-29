# Validation — version 1.1.0, 29 October 2026

## Outcome

PHP 8.3 syntax check passed. There are **71 passing automated checks**:

| Suite | Checks |
|---|---:|
| HTTP security/workflow smoke suite | 39 |
| File-lock contention and actual CLI expiry cleanup | 2 |
| Native raster editor geometry/pixel checks | 14 |
| Real Chromium desktop/mobile end-to-end checks | 16 |

The browser tests cover tab switching, private workspace initialization, custom background and logo uploads, public `walls/` isolation, original-image downloads, collection viewer, exported PNG dimensions/pixels without guides, reload restoration, a JavaScript-disabled tab fallback, and a 390px mobile viewport. They reported no uncaught page JavaScript errors. Desktop and mobile layout screenshots were inspected internally. The test browser was Chromium 153.0.8010.0.

The server checks cover cookie isolation/signatures, CSRF, cross-site rejection, upload byte/pixel limits, fake images/SVG, private asset ownership, traversal, immutable expiry, settings persistence, invalid import URLs, rate limiting, logo removal, deletion, the public JavaScript endpoint and Cloudflare-exclusion markup.

## Live investigation

The reported Create-tab failure was reproduced at `https://manage.hostingspell.com/walls/`: clicking Create left the collection selected. DOM inspection showed Cloudflare Rocket Loader had changed the editor script to a custom `*-text/javascript` type and the original script nonce was absent. Version 1.1 excludes the app script from Rocket Loader and serves it through a same-origin endpoint, while retaining normal-link fallbacks for tab navigation.

The fix was tested locally with the actual PHP application and real Chromium. The updated file has **not** been uploaded to the live HostingSpell server by this task. Applying it and purging old HTML cache remain deployment steps.

## Remaining deployment checks

- Unsplash download success: this environment blocks the required DNS lookup. Error handling returns clean HTTP 502 JSON; the manual upload path passes.
- Actual Apache/LiteSpeed/Nginx protection of the process directory.
- Installed cleanup cron, proxy real-IP configuration and CDN cache-bypass behavior.
- Device-specific Safari/Firefox behavior and mobile memory constraints.

This testing is not a penetration test or a guarantee against every vulnerability.
