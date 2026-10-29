# Validation — 2.0.0

Validated in an isolated PHP 8.3 / Chromium development environment. No mutation tests were run against production.

- 17 editor checks: generated JavaScript matches source; normalized geometry, rotation, independent grid steps and all 13 shape paths.
- 43 real Chromium desktop/mobile checks: gallery thumbnails and viewer reuse, every filter preset, separate logo colours, shape order/drag/resize/hollow styles, undo/redo, duplicate/lock, text, grids, portable projects, invalid-file rejection, IndexedDB recovery and brand kits, PNG/JPEG/WebP exports, two saved size layouts, independently verified ZIP CRC/directory, no new editor PHP uploads, old server workspace recovery, SEO guide output, 390 px mobile layout, touch pinch zoom and uncaught browser errors.
- 33 HTTP security checks plus mutation-lock and scheduled-cleanup checks for retained private server compatibility APIs.
- 10 server sorting checks: all eight orders, natural default/fallback and public source icon link.
- 5 recovery checks: real PHP memory fatal response, private diagnostics guard, obsolete import rejection, layout bounds and CLI diagnostics.
- PHP syntax and JavaScript syntax checks.

Total: **110 checks** plus syntax validation.

The new editor intentionally replaces automatic temporary server saving with local editing, optional device drafts and explicit portable project files. Old server projects can be opened through the compatibility recovery button before their fixed expiry.

Coverage is not a guarantee across all browsers or device memory budgets. Safari/Firefox, real OS lock-screen safe areas, browser clipboard restrictions and very large images on physical phones still require deployment acceptance. Device previews are illustrative. Inspect production storage denial rules and run the cleanup cron. No live hosting files were changed by these tests.
