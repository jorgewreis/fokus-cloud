# Reviewed font setup and offline build

The application's unchanged Vite configuration requests Instrument Sans, normal weights 400/500/600, latin subset, from Bunny Fonts. The setup container runs `node tests/Deployment/font-cache.mjs setup` after locked dependency installation and before stopping the download environment. This preparatory Vite build is not a passing offline test.

Setup intercepts fetch, rejects unreviewed URLs and redirects, and records the actual CSS/font response bytes, full URLs, sizes, SHA-256 hashes and plugin cache keys. A preexisting cache is moved aside, never silently reused. The plugin's existing cache location and key convention are preserved; no product configuration or dependency lock changes are involved.

Instrument Sans is licensed under SIL Open Font License 1.1. The license is retrieved from the Google Fonts repository at https://raw.githubusercontent.com/google/fonts/main/ofl/instrumentsans/OFL.txt, whose copyright identifies the Instrument Sans project and links https://github.com/Instrument/instrument-sans. The complete license, source URL, size and SHA-256 are retained with the evidence. Its conditions remain applicable when distributing font files.

Inside the existing internal-only test network, `node tests/Deployment/font-cache.mjs offline` verifies the cache, temporarily removes it and requires the complete `npm run build` to fail specifically at a blocked font fetch. It restores and rechecks the exact bytes, then requires the complete build to pass with all fetch calls blocked by a Node preload. This supplements rather than replaces Docker network isolation. It verifies cache bytes again after the build and preserves both logs.

Control tests: `node --test tests/Deployment/font-cache.test.mjs`.

Evidence: font-cache-manifest.json, instrument-sans-OFL.txt, font-cache-missing-negative.log, font-cache-present-offline.log and font-cache-offline.json. CI run 42334604949 remains a failed attempt; its font-cache failure is not converted to a pass by this fix. Each later validation must identify its own source and runtime.
