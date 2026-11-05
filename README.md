# FormTheory

Lightweight WordPress form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications. No build step.

- **Version:** 1.0.0
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

`text`, `email`, `phone`, `number`, `date`, `textarea`, `select`, `radio`, `checkbox`, `file`, `page`

- `page` is a page break; inserting one splits the form into multiple steps.
- `select`, `radio`, `checkbox` take an options list.
- `file` takes allowed extensions (default `jpg,jpeg,png,pdf`) and max size in MB (1–100, default 5).

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

## Notifications

Per-form settings: recipient list (comma-separated), subject, submit button label, confirmation message. Notification email lists submitted values plus a link to the entry in admin. The first valid `email` field value is used as `Reply-To`. File fields are referenced by name only (not attached).

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
includes/class-db.php        Tables, CRUD, private upload dir
includes/class-fields.php    Field schema, config sanitizing, conditional logic, validation
includes/class-recaptcha.php Google reCAPTCHA settings, render, verify
includes/class-admin.php     Admin menus, builder, entries, CSV export, file download
includes/class-frontend.php  Shortcode, block, rendering, submission, uploads, email
assets/builder.js            Admin form builder
assets/admin.css             Admin styles
assets/front.js              Front-end conditional logic and multi-page navigation
assets/front.css             Front-end styles
```
