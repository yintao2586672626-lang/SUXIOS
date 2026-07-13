# Vue Template Precompile Performance Plan

**Goal:** Remove the browser-side compilation cost of the 1.8 MB Vue root template without changing the rendered page structure, visible login experience, or existing setup/business logic.

**Measured reason:** After entry minification, level-6 static gzip, and Tailwind pruning, the remaining cold-load bottleneck is the runtime compiler traversing the complete root template. Repeated local browser samples show roughly 379-382 ms of long-task time and a 285-287 ms maximum task. An in-memory precompiled prototype preserves the login DOM byte-for-byte and reduces total long-task time to roughly 170-183 ms.

**Architecture:**

- Preserve the browser-normalized Vue root template as a canonical source outside `public/`.
- Compile that source deterministically with the Vue 3.5.32 compiler and pinned Terser.
- Use `prefixIdentifiers` and avoid static hoists: this produced the smallest tested runtime artifact (about 189 KB at gzip level 6) while keeping login input updates free of long tasks.
- Normalize generated `v-if` comment anchors to the empty production-runtime anchors so the mounted DOM remains byte-identical.
- Serve Vue's runtime-only global build and a content-hashed precompiled render artifact before the existing app bootstrap.
- Keep `public/app-main.js` as the canonical setup/business-logic source and only attach the generated render function to its root component.
- Leave the complete compiler build (`public/vue.global.prod.js`) tracked as a rollback asset but never load it on the normal startup path.

## Acceptance checks

- [x] A failing deterministic build/runtime contract exists before implementation.
- [x] The canonical template contains the complete normalized root template and remains outside the public document root.
- [x] The render artifact exactly matches an in-memory rebuild with pinned dependencies.
- [x] The runtime-only Vue artifact exactly matches the pinned Vue package and is materially smaller than the compiler build.
- [x] `public/index.html` contains only an empty `#app` mount shell and loads runtime Vue -> render artifact -> app entry in deterministic order.
- [x] Generated render and app-entry URLs carry their current content hashes.
- [x] Login mounted DOM, computed styles, and screenshot remain equivalent to the runtime-compiled baseline.
- [x] Browser startup has no asset, request, console, or page errors.
- [x] Performance budget and the P0/business contracts touched by this slice remain green; unrelated live-state contract failures are listed below.
- [x] Authenticated-only behavior remains explicitly unverified when no valid local credentials are available; local simulated auth is never promoted to real functional evidence.

## Execution evidence (2026-07-13)

- Deterministic build: template `1,804,396 B`; render `1,149,816 B` / `189,493 B` gzip; runtime-only Vue `105,401 B` / `39,853 B` gzip. Render hash `04b9825cad`; runtime hash `039d03af6b`; app-entry hash `1395be32e4`.
- Runtime entry: `public/index.html` is `6,748 B`; startup gzip budget is `716,549 B` against an `850,000 B` ceiling; inline script bytes are `4,172 B`; blocking scripts are `0`.
- Comparable cold Chrome samples: runtime-compiled median FCP about `666 ms` -> precompiled median `436 ms` (`-34.5%`); total long tasks about `381 ms` -> `168 ms` (`-55.9%`); maximum long task about `286 ms` -> `92 ms` (`-67.8%`).
- Visual/structure preservation: mounted login DOM hash `f64438e4c304e30fe41d31d7afbd74b4acb8a70921aea810ce3a326316bb58f5` and computed-style hash `94bc1140ad324e4b98cd149835df5944ed827a17cfccfb32cce6ec4e2e20d788` remained unchanged; final three-sample browser run had no console, page, request, or HTTP >= 400 errors.
- Fresh completion smoke: health endpoint returned HTTP `200`; Chrome mounted the visible login page using only runtime Vue, the generated render, and the minified app entry; console/page/request/HTTP errors were all empty.
- Cache boundary: content-hashed JS responses advertise `Cache-Control: public, max-age=2592000, immutable`; fresh Node HTTP probes using the current ETag and Last-Modified both returned `304` with zero response-body bytes. A stale pre-rebuild ETag correctly returned `200` with the new artifact.
- Warm-cache Chrome evidence: across five same-context reloads, median FCP was `44 ms`, median DOM interactive was `10 ms`, `22/23` resource entries transferred `0 B`, and no long task was recorded. The remaining revalidation traffic was about `600 B` per reload including navigation.
- Responsive interaction smoke: desktop `1440x1000`, tablet `1024x768`, and mobile `390x844` all kept the login field inside the viewport; password visibility, login/register switching, and the dedicated-entry modal opened and closed correctly with zero console/page errors.
- Low-end CPU comparison: under identical synthetic `4x` CPU throttling, three intercepted `e7cabc2` runtime-compiler baseline loads versus three current loads changed median FCP `2,524 -> 1,112 ms` (`-55.9%`), DOM interactive `406 -> 93 ms` (`-77.1%`), total long-task time `2,155 -> 845 ms` (`-60.8%`), and maximum long task `2,020 -> 845 ms` (`-58.2%`). Both sides had zero console/page errors; the baseline was served only through Playwright response interception and did not modify the workspace or server.
- Contract gates: `verify:p0-guards` passed, including `33/33` Revenue AI tests, `1,733` Revenue AI closure checks, and `2,219` E2E contract checks.
- Broader regression status: independent Node automation passed `430/437`; the remaining seven failures are existing live-OTA state or stale business-contract expectations and are outside this performance slice. PHPUnit passed `1,287/1,292`; the same five pre-existing Profile/credential-state contract failures remain.
- Authenticated-only modules remain `unverified`: the configured local credentials are rejected, and no password, credential, OTA session, or production data was modified to force a pass.
- Measured stop boundary: `app-main.min.js` (`199,301 B` gzip) plus `app-render.min.js` (`189,493 B` gzip) now account for about `54.4%` of eager startup gzip. The next material reduction requires route/component-level splitting of setup and template code; that higher-risk structural slice is intentionally not mixed into this page-preserving optimization.
