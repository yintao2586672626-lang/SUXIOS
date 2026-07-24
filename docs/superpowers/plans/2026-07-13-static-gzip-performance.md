# Cached Static Gzip Performance Plan

**Goal:** Reduce the largest remaining first-load transfers without deleting Tailwind classes or changing any page/API behavior.

**Measured reason:** After JavaScript minification, `tailwind.min.css` is the largest resource at 354,293 transferred bytes. It is already minified, so another text minifier cannot materially help. The local router currently caches gzip level 1 output; level 6 reduces the combined gzip size of `index.html`, `tailwind.min.css`, and `app-main.min.js` by about 16% in a local deterministic sample, with one-time compression cached under `runtime/static-gzip`.

**Implementation boundary:**

- Change only static response compression level and cache-version identity.
- Keep the decompressed response bytes, CSS selectors, HTML, JavaScript, ETags, routes, and business behavior unchanged.
- Keep on-disk gzip caching so the extra compression CPU cost is paid only when a source/variant changes.
- Do not prune Tailwind classes in this slice.

## Acceptance checks

- [ ] Write a failing router contract test first.
- [ ] Router uses named gzip level 6 and includes that level in the cache filename identity.
- [ ] Cached and freshly compressed paths retain `Content-Encoding` and `Content-Length` behavior.
- [ ] Performance budget models the runtime gzip level.
- [ ] P0 guards remain green.
- [ ] Real HTTP responses decompress to the original files, return 304 conditionally, and show smaller transferred bytes.
- [ ] Browser mount/error checks remain green.
