# FormTheory

Lightweight WordPress form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications. No build step.

- **Version:** 1.3.0
- **Requires:** WordPress 6.0+, PHP 7.4+
- **License:** GPL-2.0-or-later

## Installation

1. Copy the `formtheory` folder to `wp-content/plugins/`.
2. Activate **FormTheory** in **Plugins**.

Activation creates the plugin tables (forms, entries, webhook log and, since 1.3, saved drafts) and a private upload directory. Schema upgrades run automatically when `FORMTHEORY_VERSION` changes, so updating to 1.3 creates the drafts table on existing sites.

## Usage

1. **FormTheory → New Form** — build the form in the builder.
2. Embed it:
   - Shortcode: `[formtheory id="123"]`
   - Block: `formtheory/form` with attribute `id` (server-rendered). In the block editor, pick a form from a dropdown and see a live, non-interactive preview (every control is disabled and nothing can submit). A deleted form shows a warning and the picker.
3. View submissions under **FormTheory → Entries**; export with **Export CSV**.

Admin screens require the `manage_options` capability.

## Field types

`text`, `email`, `phone`, `number`, `date`, `textarea`, `select`, `radio`, `checkbox`, `file`, `page`, `hidden`, `consent`, `html`, `calc`, `rating`, `address`, `signature`, `repeater`

- `page` is a page break; inserting one splits the form into multiple steps.
- `select`, `radio`, `checkbox` take an options list.
- `file` takes allowed extensions (default `jpg,jpeg,png,pdf`) and max size in MB (1–100, default 5).
- `hidden` stores a default value; `{query:param}` fills it from the page URL (e.g. `{query:utm_source}`).
- `consent` is a single checkbox with HTML text (links allowed); stored as `Accepted`.
- `html` renders sanitized HTML content; never stored.
- `calc` is a read-only calculated value shown in an `<output>`.
  - `formula` grammar: unsigned numbers (`12`, `3.5`, `.5`), `{field:ID}`, `+ - * /`, parentheses and unary minus (max 500 characters). Anything else makes the formula invalid and the field is saved without one.
  - The browser recomputes it live; the server recomputes it on submit and ignores any posted value. Non-numeric or missing fields count as 0; division by zero gives an empty result.
  - `decimals` 0-6 (default 2). `prefix` and `suffix` (up to 20 characters each) are display only: the stored value is the plain number.
  - A calc field hidden by conditional logic counts as empty (0) in later formulas and is not stored. Calc is never required and is not saved in drafts.
- `rating` is a row of stars backed by real radio buttons. `max` is 3-10 (default 5). Stored as the string `"1"` to `"max"`. Works without JavaScript.
- `address` is a group of text inputs. `parts` is any of `street`, `street2`, `city`, `state`, `zip`, `country` (default: all except `street2`), always shown in that order; `country_default` pre-fills the country. Stored as an associative array of part => value (shown as comma-separated values in emails and CSV). Not usable as a conditional-logic source.
- `signature` is a drawing pad with a typed-name alternative (the keyboard path).
  - A drawn signature is a PNG of at most 200 KB and 2000 x 1000 px, checked server-side (magic bytes, size and dimensions). If both are given, the drawing wins.
  - Drawn images are stored in the private upload directory under random names and are viewed only through the authenticated admin screen. They are not emailed. A typed name is stored as text.
  - Not usable as a conditional-logic source.
- `repeater` is a list of rows. `columns` has 1-6 columns, each of type `text`, `email`, `number`, `phone`, `date`, `textarea` or `select`, with an optional required flag. `min_rows` and `max_rows` are 0-20 (defaults 1 and 5) and `add_label` is the Add button text. Add and Remove buttons need JavaScript; without it the server-rendered rows are fixed. Stored as a list of rows (column id => value). Webhook payloads keep the structured rows; emails and CSV show a text rendering (rows separated by `; `). Not usable as a conditional-logic source.

## Save and resume

Enable **Allow visitors to save and continue later** in the form settings. Visitors then see a **Save and continue later** button that gives them a private link and can optionally email it to them.

- **What is saved:** the answers entered so far and the current page. **Not saved:** uploaded files and drawn signatures (a typed signature name is saved); calc results are recomputed.
- **Resume link:** `?ft_resume=<token>` on the form's page. The token is random and only its SHA-256 hash is stored. The link is removed from the address bar once the form loads. Unknown, expired, malformed and other-form links all show the same "expired or not valid" message.
- **Expiry:** 30 days by default. Change it with the `formtheory_draft_ttl` filter (seconds, clamped to 1 hour - 90 days). A draft is deleted when the form is submitted, and expired drafts are purged by the daily `formtheory_purge` job.
- **Abuse limits:** per IP and hour, at most 5 saves that send an email and 20 saves of any kind. Saving still needs the form's nonce and the signed time-trap; Turnstile/reCAPTCHA are not required to save a draft (they are still checked on the real submit). Drafts are capped at 256 KB.
- **JavaScript only:** the button and panel are hidden without JavaScript; the form itself still works normally.
- **Privacy:** drafts are included in the personal-data eraser and described in the suggested privacy-policy text.

## Conditional logic

Each field can be shown/hidden based on rules against other fields. `address`, `signature` and `repeater` fields (and a calc field without a valid formula) cannot be rule sources. Operators:

| Operator   | Meaning           |
|------------|-------------------|
| `is`       | equals            |
| `isnot`    | not equal         |
| `contains` | contains          |
| `gt`       | greater than      |
| `lt`       | less than         |
| `empty`    | empty             |
| `notempty` | not empty         |

Logic is evaluated client-side for display and re-evaluated server-side on submit; hidden fields are skipped for validation and not stored.

## Merge tags

Usable in notification subject/body/recipients, confirmation message and redirect URL:

`{field:ID}`, `{all_fields}`, `{form_title}`, `{entry_id}`, `{date}`, `{site_name}`, `{admin_email}`

Values are escaped per context: HTML-escaped in confirmations, URL-encoded in redirects, newlines stripped in email subjects/headers. Unknown tags are left as-is.

## Notifications

Each form can have any number of notifications. Each has: recipients (emails or `{field:ID}`), subject, message (default `{all_fields}`), Reply-To, optional file attachments, and optional conditions (send only when all/any rules match). Invalid resolved addresses are dropped. Forms saved by 1.0 are converted on load to a single notification with the old recipients/subject and Reply-To set to the first email field.

## Confirmations

- **Message** (default) — shown in place of the form; merge tags allowed.
- **Page** — redirect to a published page by ID.
- **Redirect** — redirect to an `http(s)` URL; merge tags allowed in path/query. If a submitted value would change the configured host, the message is shown instead.

## AJAX submit

On by default for new forms (**Submit without page reload**); forms created in 1.0 keep full-page submit until switched on. Without JavaScript, or on network failure, the form falls back to a normal POST. Errors are shown inline, announced, and focus moves to the first invalid field (switching steps on multi-page forms).

## Spam protection

- Honeypot field (always on).
- Signed time-trap: submissions faster than 3 seconds after render are rejected.
- Cloudflare Turnstile: set site + secret keys under **FormTheory → Settings**. Verification failures (including network errors) reject the submission. Turnstile requires JavaScript; no-JS visitors see a notice.

## Webhooks

Each form can post entries to HTTPS endpoints after submission. Requests are queued via WP-Cron so they never delay the visitor (5 s timeout, private/loopback hosts refused; sites with `DISABLE_WP_CRON` need a real cron job). Formats: JSON (`form_id`, `form_title`, `entry_id`, `submitted_at`, `fields`) or form-encoded. With a secret set, requests carry `X-FormTheory-Signature: sha256=<HMAC-SHA256 of body>`. Optional conditions as for notifications.

## Webhook log and retry

Every webhook delivery is recorded under **FormTheory → Webhook Log** (filter by pending / ok / failed; shows HTTP code and attempts). A delivery is attempted up to 3 times: retries run after 60 s and then 240 s. Failed deliveries can be retried manually from the log. Retries run on WP-Cron, so the `DISABLE_WP_CRON` note above applies.

## Entries management

Under **FormTheory → Entries**:

- Search entries, and filter by status (unread / read). New entries are unread; opening an entry marks it read. Entries that predate 1.2 are treated as read.
- Bulk actions on selected entries, scoped to the selected form (including delete).
- Private notes on each entry.
- **Export CSV** as before.

## Duplicate, import and export

- **Duplicate** a form from the Forms list.
- **Export JSON** a form, and **Import** it from the Forms screen (1 MB limit). Webhook secrets are blanked in the export, so re-enter them after importing.

## Privacy and retention

- **Retention** (days) in **FormTheory → Settings**: `0` keeps entries forever (default); maximum `3650`. A daily WP-Cron job purges older entries and their webhook log rows (`DISABLE_WP_CRON` sites need a real cron job).
- Registers a WordPress personal-data **exporter** and **eraser** for entries (the eraser also removes saved drafts holding the same email address), and suggested text for the site Privacy Policy guide (Settings → Privacy), which covers saved progress.

## Internationalisation

Admin and front-end strings are translatable (text domain `formtheory`, loaded from `languages/`). The template is `languages/formtheory.pot`.

## Accessibility

Audited against WCAG 2.2 AA; findings and fixes are recorded in [docs/wcag-audit-1.2.md](docs/wcag-audit-1.2.md). The 1.3 fields (rating, address, signature, repeater, calc), the save-and-resume panel and the block editor placeholder have a manual static review in [docs/wcag-audit-1.3.md](docs/wcag-audit-1.3.md) (no automated or browser run).

## Uninstall

Off by default. Enable "Delete all FormTheory data when the plugin is deleted" in Settings to permanently remove forms, entries, uploaded files, the webhook log, options and scheduled events on deletion. Single-site only; multisite per-blog cleanup is not handled.

## Hooks

- `formtheory_after_submission( $entry_id, $form, $data )` — action, after an entry is stored.
- `formtheory_draft_ttl( $seconds )` — filter, lifetime of a saved draft (default 30 days; clamped to 1 hour - 90 days).
- `formtheory_recaptcha_threshold( $threshold )` — filter, minimum reCAPTCHA v3 score.

## Development

```
composer install
vendor/bin/phpunit
```

PHPUnit needs the `mbstring` extension (`php -d extension=mbstring vendor/bin/phpunit` if your CLI lacks it). The block editor script has a Node test: `node tests/js/block.test.js`.

## Security

- Nonce-checked submissions and admin actions; all builder config sanitized server-side.
- Uploads validated by size, extension and real MIME (`wp_check_filetype_and_ext`).
- Executable/script extensions always blocked (php, phar, js, html, svg, sh, exe, etc.), regardless of form config.
- Files stored in `wp-content/uploads/formtheory-private/` under random names, protected by `.htaccess` deny rules; downloadable only through an authenticated admin handler.
  - **Nginx:** `.htaccess` is ignored. Add a rule denying direct access:
    ```nginx
    location ^~ /wp-content/uploads/formtheory-private/ { deny all; }
    ```
- CSV export cells escaped against formula injection.
- Optional Google reCAPTCHA (v2 checkbox or v3 score) on every form: FormTheory > Settings. Verified server-side (fails closed); v3 also checks action and score threshold (filter `formtheory_recaptcha_threshold`). Keys can be set in `wp-config.php` instead:
  ```php
  define( 'FORMTHEORY_RECAPTCHA_SITE_KEY', '...' );
  define( 'FORMTHEORY_RECAPTCHA_SECRET_KEY', '...' );
  ```

## Structure

```
formtheory.php               Bootstrap, constants, activation hook
uninstall.php                Opt-in data removal on plugin deletion
languages/formtheory.pot     Translation template
docs/wcag-audit-1.2.md       WCAG 2.2 AA audit
docs/wcag-audit-1.3.md       Manual static WCAG review of the 1.3 additions
readme.txt                   wordpress.org readme
.github/workflows/ci.yml     CI: PHPUnit on PHP 7.4/8.1/8.3 + php -l
includes/class-db.php        Tables, CRUD, private upload dir
includes/class-fields.php    Field schema, config sanitizing, conditional logic, validation
includes/class-recaptcha.php Google reCAPTCHA settings, render, verify
includes/class-admin.php     Admin menus, builder, entries, CSV export, file download
includes/class-frontend.php  Shortcode, block, rendering, submission pipeline, AJAX, uploads
includes/class-calc.php      Calculated-field formula parser and evaluator
includes/class-drafts.php    Save-and-resume drafts: tokens, expiry, rate limits
includes/class-merge.php     Merge tags
includes/class-notify.php    Notifications
includes/class-spam.php      Time-trap + Turnstile
includes/class-webhooks.php  Webhooks, delivery log, retry
includes/class-portable.php  Form JSON import/export
includes/class-privacy.php   Retention purge, privacy exporter/eraser/policy text
assets/builder.js            Admin form builder
assets/admin.js              Admin screens: confirm prompts, select-all
assets/admin.css             Admin styles
assets/front.js              Front-end conditional logic, multi-page navigation, calc, repeater, save and resume
assets/front.css             Front-end styles
assets/signature.js          Signature pad (canvas + typed fallback)
assets/block.js              Block editor form picker and live preview
tests/js/block.test.js       Node test for the block editor script (node tests/js/block.test.js)
```
