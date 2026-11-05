# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Primary: WordPress site owners/admins who build forms in the FormTheory builder (admin, `manage_options`) and review entries. Secondary: site visitors who fill the embedded front-end forms (multi-step, uploads, conditional fields).

## Product Purpose
Lightweight WordPress form builder (v1.1.0): conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications, webhooks, AJAX submit. No build step. Success: admins ship secure, accessible forms quickly; visitors submit without friction.

## Positioning
Security and accessibility as the differentiators: hardened uploads (extension + real MIME, private dir), nonce-checked actions, signed time-trap, honeypot, fail-closed Turnstile/reCAPTCHA, CSV formula-injection escaping, signed webhooks; plus accessible front-end behavior (inline announced errors, focus moves to first invalid field). Neighbors (Gravity, WPForms, Fluent) are broader; FormTheory stays small and hardened.

## Operating Context
WordPress 6.0+, PHP 7.4+. Admin screens under FormTheory menu (New Form, Entries, Settings). Front end via shortcode `[formtheory id="N"]` or server-rendered block `formtheory/form`. Renders inside arbitrary host themes.

## Capabilities and Constraints
- Field types: text, email, phone, number, date, textarea, select, radio, checkbox, file, page, hidden, consent, html.
- Conditional logic evaluated client-side and re-evaluated server-side.
- Merge tags, multiple notifications, confirmations (message/page/redirect), webhooks via WP-Cron.
- No build step; plain PHP/JS/CSS in `assets/` and `includes/`.
- Front-end form must inherit host theme styles; minimal own CSS.
- Works without JavaScript (falls back to normal POST).
- Distribution channel (wp.org vs private) undecided.

## Evidence on Hand
README.md documents features and security model; PHPUnit tests in `tests/`. No testimonials, customer lists, or benchmarks exist; do not fabricate.

## Product Principles
1. Security and accessibility are the product, not features; never trade them for polish.
2. Front end borrows the host theme; the plugin does not impose a brand on visitors' sites.
3. Works without JS and degrades gracefully; enhancement only.
4. Lightweight: no build step, no bloat, no upsell surfaces.
5. Admin builder is a task tool for non-developers: clear, scannable, native to WordPress admin conventions.

## Accessibility & Inclusion
WCAG 2.2 AA required for both admin builder and front-end forms (focus visible and unobscured, target size ≥24px, single-pointer alternatives to drag, allow paste, no cognitive tests, visible errors announced).
