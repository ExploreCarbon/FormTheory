# FormTheory

Lightweight WordPress form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications. No build step.

- **Version:** 1.1.0
- **Requires:** WordPress 6.0+, PHP 7.4+
- **License:** GPL-2.0-or-later

## Installation

1. Copy the `formtheory` folder to `wp-content/plugins/`.
2. Activate **FormTheory** in **Plugins**.

Activation creates two tables (`{prefix}formtheory_forms`, `{prefix}formtheory_entries`) and a private upload directory. Schema upgrades run automatically when `FORMTHEORY_VERSION` changes.

## Usage

1. **FormTheory → New Form** — build the form in the builder.
2. Embed it:
   - Shortcode: `[formtheory id="123"]`
   - Block: `formtheory/form` with attribute `id` (server-rendered, registered without editor script)
3. View submissions under **FormTheory → Entries**; export with **Export CSV**.

Admin screens require the `manage_options` capability.

## Field types

`text`, `email`, `phone`, `number`, `date`, `textarea`, `select`, `radio`, `checkbox`, `file`, `page`, `hidden`, `consent`, `html`

- `page` is a page break; inserting one splits the form into multiple steps.
- `select`, `radio`, `checkbox` take an options list.
- `file` takes allowed extensions (default `jpg,jpeg,png,pdf`) and max size in MB (1–100, default 5).
- `hidden` stores a default value; `{query:param}` fills it from the page URL (e.g. `{query:utm_source}`).
- `consent` is a single checkbox with HTML text (links allowed); stored as `Accepted`.
- `html` renders sanitized HTML content; never stored.

## Conditional logic

Each field can be shown/hidden based on rules against other fields. Operators:

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

## Hooks

- `formtheory_after_submission( $entry_id, $form, $data )` — action, after an entry is stored.

## Development

```
composer install
vendor/bin/phpunit
```

PHPUnit needs the `mbstring` extension (`php -d extension=mbstring vendor/bin/phpunit` if your CLI lacks it).

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

## Structure

```
formtheory.php               Bootstrap, constants, activation hook
includes/class-db.php        Tables, CRUD, private upload dir
includes/class-fields.php    Field schema, config sanitizing, conditional logic, validation
includes/class-admin.php     Admin menus, builder, entries, CSV export, file download
includes/class-frontend.php  Shortcode, block, rendering, submission pipeline, AJAX, uploads
includes/class-merge.php     Merge tags
includes/class-notify.php    Notifications
includes/class-spam.php      Time-trap + Turnstile
includes/class-webhooks.php  Webhooks
assets/builder.js            Admin form builder
assets/admin.css             Admin styles
assets/front.js              Front-end conditional logic and multi-page navigation
assets/front.css             Front-end styles
```
