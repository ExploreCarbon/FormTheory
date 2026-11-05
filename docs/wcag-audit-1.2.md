# WCAG 2.2 AA audit, FormTheory 1.2

Scope: `includes/class-frontend.php`, `assets/front.js`, `assets/front.css`, `includes/class-admin.php`, `assets/builder.js`, `assets/admin.js`, `assets/admin.css`.
Method: `wcag-audit` skill (manual static analysis; axe/pa11y not run, no browser harness). Re-run after fixes: see "Re-audit".

## Findings and resolution

| # | SC | Level | Finding | File | Status |
|---|----|-------|---------|------|--------|
| 1 | 4.1.2 | A | `aria-required` on `<fieldset>` for checkbox group / consent (not allowed on group role); requirement not exposed to AT | class-frontend.php render_field | Fixed: radio fieldset gets `role="radiogroup"` (supports aria-required); checkbox group and consent get visually hidden "(required)" in legend; consent checkbox carries `aria-required` |
| 2 | 1.3.1 / 3.3.2 | A | File field "Allowed types / max size" hint not programmatically associated | class-frontend.php | Fixed: hint has id, included in `aria-describedby` |
| 3 | 2.5.8 | AA | Front-end radio/checkbox controls 18px, below 24px | front.css | Fixed: 1.5rem, min 24px |
| 4 | 2.4.11 | AA | Focused fields could sit under a sticky theme header after scroll | front.css | Fixed: `scroll-margin-top/bottom` on controls, summary, confirmation (plugin has no sticky UI itself) |
| 5 | 2.4.7 | AA | Focus ring missing on programmatically focused `.ft-error-summary` / `.ft-confirmation` | front.css | Fixed |
| 6 | 2.4.3 | A | Builder: redraw on toggling conditional logic, adding/removing rule, adding/removing notification/webhook, deleting a field, changing "After submit" dropped focus to body | builder.js | Fixed: focus restored to logical control |
| 7 | 4.1.3 | AA | Builder live region was destroyed and recreated on every render (announcements unreliable) | builder.js | Fixed: persistent `role="status"` region |
| 8 | 2.5.8 | AA | Builder "Remove" link-buttons and checkboxes under 24px | admin.css | Fixed: min 24x24 |
| 9 | 1.3.1 / 4.1.2 | A | Builder `<summary>` with `display:flex` loses disclosure marker, no expand/collapse cue | admin.css | Fixed: decorative chevron (open/closed), hidden from AT where supported |
| 10 | 2.4.4 | A | Forms list: Edit / Entries links lacked the form name that Duplicate/Export/Delete have | class-admin.php | Fixed: screen-reader text |
| 11 | 4.1.2 | A | Forms list: malformed `class="ft-danger"href=` (missing space) | class-admin.php | Fixed |

## WCAG 2.2 additions checked

- 2.4.11 Focus Not Obscured: plugin has no sticky/fixed UI; scroll-margin added so host sticky headers do not hide focus (host-dependent residual, see below).
- 2.5.7 Dragging Movements: builder has no drag-and-drop; reordering is via Up/Down buttons with contextual names and a live announcement. Pass.
- 2.5.8 Target Size: front buttons 44px; choices 24px; admin bulk/select-all checkboxes 24px (existing rule); builder buttons use WP `.button` (30px) and fixed link-buttons. Pass.
- 3.3.7 Redundant Entry: values are preserved on validation failure (server re-render and AJAX), across multi-step pages; no data is requested twice. Limitation: browsers cannot re-populate `<input type=file>` after a non-JS failed POST.
- 3.3.8 Accessible Authentication: the plugin has no authentication step. Optional spam checks (reCAPTCHA v2 image challenge, Turnstile) are not authentication so 3.3.8 does not strictly apply, but v2 can present an image/cognitive challenge; reCAPTCHA v3 and Turnstile are non-interactive by default. Recommendation documented: use v3 or Turnstile for users who cannot solve challenges. Third-party widget accessibility is out of the plugin's control. Admin secret-key fields are `type=password` with paste allowed.
- 3.2.6 Consistent Help: help text always renders directly after its control, same position and markup for every field. Pass.

## Passes (no change needed)

1.1.1 (no images), 1.3.1 labels/fieldset/legend, 1.3.5 (`autocomplete` on email/tel; other fields have no known purpose), 1.4.1 (errors have text, border plus message), 1.4.3 (plugin colours: text #111/#fff, error #b91c1c on white 6.5:1, help #4b5563 8:1, button white on #1d4ed8 6.7:1, confirmation #14532d on #f0fdf4), 1.4.4/1.4.10 (rem units, max-width, width 100%), 1.4.11 (input border #6b7280 4.8:1, focus ring 3px accent), 1.4.12 (no !important spacing locks), 2.1.1/2.1.2 (native controls, no traps), 2.4.6, 2.5.3 (builder aria-labels contain visible text), 3.2.2 (no context change on input), 3.3.1 / 3.3.3 (inline error text, `aria-invalid`, `role=alert` summary, focus to first error), 3.3.2, 4.1.3 (`role=status` confirmation, `aria-live` progress, alert summary). Honeypot is `aria-hidden` with `tabindex=-1` (passes aria-hidden-focus).

## Theme-dependent (not changed)

- 1.4.3 / 1.4.11: contrast of form text, labels, legends and help against the host site's background. Inputs set their own white background and dark text; labels/legends/help inherit the theme's text colour.
- 2.4.11: sticky/fixed host headers or cookie banners can still cover focus in edge cases; scroll-margin is a mitigation sized for typical headers.
- 1.4.4 / 1.4.12 text sizing inherited from the theme font settings.

## Out of scope

- WP-core admin chrome (menus, notices dismiss button, `.button`, list tables, `paginate_links` markup).
- Third-party widgets: reCAPTCHA, Cloudflare Turnstile iframes.

## Re-audit

After fixes the checklist above was re-run over all seven files: 0 open Level A and 0 open Level AA items other than the theme-dependent and out-of-scope entries listed. Automated checks (axe) were not available; verification is static plus PHPUnit render assertions in `tests/RenderTest.php` (label association, aria-describedby/invalid on error, required indication for text/radio/checkbox/consent, file hint association, autocomplete). CSS/JS fixes (focus ring, target size, focus restoration) are verified by reading only.
