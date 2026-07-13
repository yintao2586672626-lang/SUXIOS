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

- [ ] A failing deterministic build/runtime contract exists before implementation.
- [ ] The canonical template contains the complete normalized root template and remains outside the public document root.
- [ ] The render artifact exactly matches an in-memory rebuild with pinned dependencies.
- [ ] The runtime-only Vue artifact exactly matches the pinned Vue package and is materially smaller than the compiler build.
- [ ] `public/index.html` contains only an empty `#app` mount shell and loads runtime Vue -> render artifact -> app entry in deterministic order.
- [ ] Generated render and app-entry URLs carry their current content hashes.
- [ ] Login mounted DOM, computed styles, and screenshot remain equivalent to the runtime-compiled baseline.
- [ ] Browser startup has no asset, request, console, or page errors.
- [ ] Performance budget and P0/business contracts remain green.
- [ ] Authenticated-only behavior remains explicitly unverified when no valid local credentials are available; local simulated auth is never promoted to real functional evidence.

