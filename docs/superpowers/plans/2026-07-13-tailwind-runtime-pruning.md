# Tailwind Runtime Pruning Plan

**Goal:** Remove unused rules from the 2.93 MB full Tailwind v2.2.19 stylesheet while preserving every statically referenced project utility, the full source for rollback, and the existing DOM/templates.

**Measured reason:** After entry minification and cached level-6 gzip, `tailwind.min.css` remains the largest first-load resource at 302,381 transferred bytes. The file is already text-minified, so the remaining gain requires removing unused rules rather than whitespace.

**Architecture:**

- Rename the current complete stylesheet to `public/tailwind.full.css` as the canonical rollback source.
- Generate `public/tailwind.min.css` deterministically with a pinned PurgeCSS dependency.
- Scan `public/index.html`, canonical first-party JavaScript/components, and backend PHP source for class literals.
- Fail if project source contains unresolved dynamic Tailwind construction such as `bg-${...}`.
- For every extracted token that exists as a selector in the full Tailwind source, require the generated stylesheet to retain that selector.
- Keep keyframes, font faces, CSS variables, element resets, and the license comment.
- Version the runtime link with the generated content hash.

## Acceptance checks

- [ ] Capture the pre-change login screenshot and computed-style/DOM signature.
- [ ] Write a failing deterministic build/coverage test before implementation.
- [ ] Full source remains tracked and the runtime artifact exactly matches an in-memory rebuild.
- [ ] No unresolved dynamic Tailwind class construction is present in scanned runtime source.
- [ ] Every statically referenced selector that existed before is retained.
- [ ] Runtime raw and level-6 gzip sizes are materially lower.
- [ ] P0/business contracts remain green.
- [ ] Login DOM signature is unchanged and screenshot pixel difference is zero or only rendering-noise level.
- [ ] Real browser mounts with no asset, console, request, or page error.
- [ ] Authenticated-only visual verification remains explicitly unverified if valid credentials are still unavailable.
