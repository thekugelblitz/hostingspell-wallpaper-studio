# Validation — 1.3.0

PHP 8.3 syntax passed. **71 checks passed**:

| Suite | Checks |
|---|---:|
| HTTP security and upload workflow | 33 |
| Lock contention and CLI expiry cleanup | 2 |
| Canvas geometry and pixel checks | 12 |
| Real desktop/mobile Chromium workflow | 19 |
| Real PHP fatal recovery and diagnostics | 5 |

The browser checks cover custom wallpaper and logo uploads, pointer dragging, grid rows/columns and subdivisions, PNG exports without guides, restoration after reload, collection isolation, JavaScript-disabled navigation and a 390px mobile viewport. No uncaught browser JavaScript errors occurred.

Regression coverage confirms removal of link-import controls and rejection of the old import endpoint with JSON 404. PHP recovery checks trigger a real memory-limit failure in a disposable test copy; the delivered app contains no fault-injection hook. No cURL extension was loaded for the browser or recovery checks.

Hosting files were not deployed. Verify storage protection, cleanup cron, cache bypass and host PHP configuration on the actual installation. This validation is not a penetration test or a guarantee against every vulnerability.
