# Contributing

Keep reliability first, usability second, and efficiency third. Production is a standalone `index.php`; no Node service or build step is required on the host.

Edit `editor-source.js`, then run `python3 build.py` to embed it in `index.php`. Commit both files. PHP routes, HTML and styles live in `index.php`.

Before a pull request, run the editor, browser, sorting, recovery and HTTP security suites described in README.md. Use disposable local storage and test images. Never run mutation suites against the live site. Include browser screenshots for interface changes, with no visitor images or private data.

Describe the visible behavior, limits and verification. Preserve original image downloads, name sorting, keyboard access, mobile editing and private workspace recovery. Avoid dependencies and outbound image integrations unless a concrete need justifies them.

The MIT licence covers the source code. HostingSpell names, logos and collection images are not automatically licensed for reuse by the code licence. Use your own branding and properly licensed wallpapers.
