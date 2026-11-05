# WCAG 2.2 AA review, FormTheory 1.3 additions

Scope: only the markup and behaviour added in 1.3. Rating, address, signature, repeater and calc fields (`render_field`, `render_repeater_row`), the save-and-resume panel (`render_form`), the resume notice (`resume_state`), `assets/front.js`, `assets/signature.js`, `assets/front.css`, and the block editor placeholder in `assets/block.js`. Everything else was covered by [wcag-audit-1.2.md](wcag-audit-1.2.md).

Method: **manual static review of source only.** No axe, pa11y, screen reader or browser run was performed; nothing here was observed in a rendered page. Statuses:

- **PASS**: the source satisfies the criterion.
- **Theme-dependent**: depends on host colours or fonts the plugin does not control.
- **Needs browser**: cannot be confirmed from source; verify with a browser/AT pass before claiming conformance.

Result: no defect requiring a code change was found in this pass, so no markup, CSS or JS was modified.

## Rating (`fieldset.ft-rating`)

| SC | Status | Notes |
|----|--------|-------|
| 1.3.1 / 4.1.2 | PASS | Real `input[type=radio]` in a `fieldset` with `role="radiogroup"` and a `legend`; `aria-required`, `aria-invalid` and `aria-describedby` live on the group, same pattern as `radio`. Each radio has a label with visually hidden text "N of M". |
| 1.1.1 / 1.4.1 | PASS | Stars are CSS `content` glyphs (alt text empty via `/ ""`), outline vs filled shapes, so state is not colour alone. The name is the hidden "N of M" text. |
| 2.1.1 | PASS | Native radios: arrow keys, Tab, Space. Works without JavaScript. |
| 2.4.7 / 2.4.11 | PASS (source) | The radio is transparent and overlays its label, so the ring is drawn on the label via `input:focus-visible + label` (3px accent outline plus white ring). `scroll-margin` applies. Needs browser to confirm the ring shows in every engine (relies on `:focus-visible` and `:has()` for fill). |
| 2.5.8 | PASS | Label min 32x32px; overlaid input min 24x24px. |
| 1.4.11 | PASS / theme-dependent | Star colour is `--ft-accent` (#1d4ed8, 6.7:1 on white). On a dark host background contrast is host-dependent. |
| 1.4.3 (hover preview) | PASS | Hover fills stars up to the pointer; checked state remains exposed through the radio. |

## Address (`fieldset.ft-address`)

| SC | Status | Notes |
|----|--------|-------|
| 1.3.1 / 3.3.2 | PASS | One `<label for>` per part inside a `fieldset`/`legend`; required parts carry `aria-required` (street2 never); group carries `aria-invalid`/`aria-describedby` for errors; "(required)" hidden text in the legend. |
| 1.3.5 | PASS | `autocomplete` tokens `address-line1`, `address-line2`, `address-level2`, `address-level1`, `postal-code`, `country-name`. |
| 3.3.7 | PASS | Values re-rendered after a failed submit and restored from drafts; no re-entry requested. |
| 1.4.4 / 1.4.10 | PASS | Stacked full-width inputs, `rem` sizing. |

## Signature (`fieldset.ft-signature`, `assets/signature.js`)

| SC | Status | Notes |
|----|--------|-------|
| 2.5.7 Dragging movements | PASS | Drawing is a path gesture, but it is optional: the labelled typed-name input ("Or type your full name to sign") is a single-pointer/keyboard alternative that satisfies the field. Both reach the same server rules. |
| 2.1.1 Keyboard | PASS | The canvas is intentionally not focusable; the typed input and the Clear button are native, tabbable controls. |
| 4.1.2 / 1.1.1 | PASS | Canvas has `role="img"` and an `aria-label` that names the purpose and points to the typed alternative. |
| 2.5.1 Pointer gestures / 2.5.2 | PASS (source) | Single-pointer path; uses Pointer Events (mouse, pen, touch) with pointer capture; `pointerup`/`pointercancel` end the stroke. `touch-action: none` on the canvas only, so the page still scrolls elsewhere. Needs browser to confirm on touch devices. |
| 2.5.8 | PASS | Clear button is `.ft-btn` (44px). Typed input is a normal text input. |
| 1.4.11 | PASS / theme-dependent | Pad has a 2px `currentColor` border on a white fill: strong on light hosts; on a dark host the white pad itself contrasts with the background. |
| 2.4.7 | PASS | Clear button has a 3px accent focus ring. |
| 3.3.1 / 3.3.3 | PASS | Server errors appear in the shared error paragraph referenced by the group's `aria-describedby`, and the error summary links to the field. |
| 1.3.5 | PASS | Typed input has `autocomplete="name"`. |
| Pointer drawing for users who cannot draw | Not applicable | Typed name is accepted instead. |

## Repeater (`fieldset.ft-repeater`, `initRepeater`)

| SC | Status | Notes |
|----|--------|-------|
| 1.3.1 / 4.1.2 | PASS | Fieldset + legend; each row is `role="group"` labelled by its visible "Row N" title; every cell has a `<label for>` with unique ids (template ids are rewritten on add and on re-index). |
| 2.4.3 / 3.2.2 Focus order, no unexpected context change | PASS | Add moves focus to the first control of the new row; Remove moves focus to the same-position row (or the previous one), and to the Add button when no row remains. No navigation happens on activation. |
| 4.1.3 Status messages | PASS | A persistent `role="status"` / `aria-live="polite"` region (visually hidden) announces "Row added" and "Row removed"; the region is not re-created on each change. |
| 4.1.2 Names | PASS | Remove buttons are named "Remove row N", re-numbered on every change; this contains the visible text "Remove row" (2.5.3 Label in Name). |
| 1.3.1 Maximum reached | PASS | At the cap the Add button gets `aria-disabled="true"` (stays focusable) and `aria-describedby` pointing to the visible "Maximum of N rows reached." message; Remove behaves the same at the minimum. |
| 2.1.1 | PASS | All native buttons and inputs. Without JavaScript, Add/Remove stay hidden and the server-rendered rows are fixed (graceful, no dead controls). |
| 2.5.8 | PASS | Buttons are `.ft-btn` (44px). |
| 1.4.11 | Theme-dependent | Row border is `currentColor`, so it follows host text colour. Decorative; the controls inside carry their own borders. |
| 3.3.1 | Limitation | Row/cell errors are reported at field level through the shared error paragraph and summary, not per cell. This is usable but less precise than a per-cell message. Logged as a finding, not changed. |

## Calculated field (`output.ft-calc`)

| SC | Status | Notes |
|----|--------|-------|
| 1.3.1 / 4.1.2 | PASS | `<label for>` names the `<output>` (labelable); `for` lists the referenced controls; `aria-describedby` for help. Read-only by nature, so it is not an input and carries no `required`. |
| 4.1.3 | PASS (judgement) | `aria-live="off"` is set on purpose so every keystroke does not announce a new total. The value is the direct result of the visitor's own input, is reachable in reading order, and is also what is submitted. If you want automatic announcements, change the attribute to `polite`. |
| 1.4.3 | Theme-dependent | The result inherits host text colour. |
| 3.3.7 | PASS | Nothing re-requested; the result is computed. |

## Save and resume (`.ft-save`, `.ft-save-panel`, `.ft-resume-notice`)

| SC | Status | Notes |
|----|--------|-------|
| 4.1.2 / 1.3.1 | PASS | The opener is a `<button>` with `aria-expanded` and `aria-controls` kept in sync across the per-page copies; the panel's email and link inputs have visible `<label for>`; panel inputs carry no `name`, so they never travel with the real submit. |
| 2.4.3 | PASS | Opening moves focus to the email field; after a successful save focus moves to the readonly link field (selected) and a status message is announced; the Copy button falls back to selecting the link and says so. |
| 3.3.1 / 4.1.3 | PASS | Save errors go into a `role="alert"` paragraph; progress and results go into a persistent `role="status"` region; `aria-busy` is set while saving. |
| 3.3.2 | PASS | Email is labelled "(optional)"; help text states what is not saved (files, signatures). |
| 1.3.5 | PASS | Email has `autocomplete="email"`. |
| 2.5.8 / 2.5.3 | PASS | Buttons are 44px; inputs `min-height: 44px`; accessible names equal their visible text. |
| 3.3.8 | PASS | No authentication or cognitive test; the link can be pasted/copied. |
| 3.2.2 | PASS | Enter in the email field saves the draft instead of submitting the whole form; no unexpected navigation. |
| Resume notice | PASS | `role="status"` paragraph ("Your saved answers have been restored." or "This saved link has expired or is not valid.") rendered server-side. |
| 2.4.11 | PASS (source) | Panel is in normal flow below the navigation; `scroll-margin` applies to its controls through `.ft-form` rules. Needs browser to confirm against sticky host headers. |

## Block editor placeholder (`assets/block.js`)

| SC | Status | Notes |
|----|--------|-------|
| 1.3.1 / 3.3.2 / 4.1.2 | PASS | Uses core `Placeholder`, `SelectControl` (visible label "Form") and `Notice` (warning for a deleted form). No custom keyboard handling. |
| 2.1.1 / 2.4.7 | PASS | Core components provide keyboard and focus styling. |
| Preview | PASS | The server-rendered preview is `inert` and every control is `disabled`, so it cannot trap focus or submit. |
| Editor chrome | Not verifiable without a browser | Contrast and focus appearance come from the WordPress admin theme. |

## Cross-cutting 2.2 criteria

- **2.4.11 Focus Not Obscured (Minimum):** the plugin has no sticky UI; `scroll-margin` mitigates host headers (host-dependent residual).
- **2.5.7 Dragging Movements:** only the optional signature drawing is a path gesture, with the typed alternative above. No drag-and-drop anywhere in the new UI.
- **2.5.8 Target Size (Minimum):** all new buttons are at least 44px, rating targets at least 24px.
- **3.3.7 Redundant Entry / 3.3.8 Accessible Authentication:** satisfied; save and resume exists to avoid re-entry.

## Findings not fixed (for follow-up)

1. Repeater errors are field-level, not per cell (3.3.1 is met; precision could improve).
2. Calc `aria-live="off"` is a deliberate choice; revisit if user testing shows a need for announcements.
3. Everything marked "Needs browser" or "Theme-dependent" must be confirmed by a rendered pass (keyboard, screen reader on NVDA/VoiceOver, touch, 400% zoom, forced colours, dark host theme) before claiming full conformance.
