=== FormTheory ===
Contributors: explorecarbon
Tags: forms, form builder, contact form, conditional logic, webhooks
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications and webhooks. No build step.

== Description ==

FormTheory is a lightweight WordPress form builder.

* Fields: text, email, phone, number, date, textarea, select, radio, checkbox, file, page break, hidden, consent and HTML, plus calculated, star rating, address, signature and repeater fields.
* Save and continue later: visitors get a private link to resume a form.
* Conditional logic, evaluated in the browser for display and again on the server on submit.
* Multi-page forms, AJAX submit with a no-JavaScript fallback.
* Multiple conditional email notifications with merge tags and attachments.
* Page and redirect confirmations.
* Spam protection: honeypot, signed time-trap, Cloudflare Turnstile and Google reCAPTCHA (v2 or v3).
* Signed webhooks with a delivery log and retry.
* Entries screen with search, read/unread filter, bulk actions and notes; CSV export.
* Duplicate forms, and import/export forms as JSON.
* Privacy tools: retention period, WordPress personal-data exporter and eraser, suggested privacy-policy text.
* Translation ready (text domain `formtheory`) and checked against WCAG 2.2 AA.

Embed a form with the shortcode `[formtheory id="123"]` or the `formtheory/form` block, which has a form picker and a live preview in the block editor.

= New field types (1.3) =

* Calculated: read-only result of a formula using numbers, `{field:ID}`, `+ - * /`, parentheses and unary minus. Recomputed in the browser and again on the server. Decimals 0-6; prefix and suffix are display only. A calculated field hidden by conditional logic counts as empty.
* Rating: stars backed by real radio buttons, 3-10 stars, stored as "1" to the maximum.
* Address: street, street 2, city, state, ZIP and country parts you choose; stored as a set of parts. Not usable in conditional logic.
* Signature: draw with a mouse, pen or finger, or type your name. Drawn signatures are PNG images up to 200 KB and 2000 x 1000 px, stored privately, viewable only through the logged-in admin and not attached to emails. Not usable in conditional logic.
* Repeater: up to 6 columns (text, email, number, phone, date, textarea, select) and 0-20 rows with Add and Remove buttons (JavaScript). Stored as a list of rows; webhooks receive the structured rows. Not usable in conditional logic.

= Save and continue later =

Turn on "Allow visitors to save and continue later" for a form. Visitors can save their progress and get a private link (`?ft_resume=`), optionally emailed. The answers and current page are saved; uploaded files and drawn signatures are not. Links expire after 30 days (filter `formtheory_draft_ttl`, seconds, between 1 hour and 90 days). Only a hash of the link token is stored. Saving is limited per IP to 5 emailed links and 20 saves per hour, needs JavaScript, and does not require Turnstile or reCAPTCHA (those still apply when the form is submitted). Saved progress is covered by the personal-data eraser and the suggested privacy-policy text.

== Installation ==

1. Upload the `formtheory` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate FormTheory under Plugins.
3. Go to FormTheory > New Form, build your form and save.
4. Embed it with `[formtheory id="123"]` or the FormTheory block.

Activation creates the plugin's database tables and a private upload directory. Updating from an earlier version adds any new table automatically. Admin screens require the `manage_options` capability.

== Frequently Asked Questions ==

= How do I protect uploaded files on nginx? =

Uploaded files are stored in `wp-content/uploads/formtheory-private/` under random names. Apache honours the `.htaccess` deny rule FormTheory writes there; nginx ignores `.htaccess`, so add this rule to your server config:

`location ^~ /wp-content/uploads/formtheory-private/ { deny all; }`

Files are only downloadable through an authenticated admin handler.

= Webhooks or the retention purge never run. Why? =

Webhook deliveries (and their retries) and the daily retention purge run on WP-Cron. If `DISABLE_WP_CRON` is set to true, WordPress will not trigger them, so you must call `wp-cron.php` from a real system cron job, for example every few minutes. Without it, webhooks stay pending and old entries are not purged.

= What happens when I delete the plugin? =

By default nothing is removed: forms, entries, uploaded files and settings are kept. To remove everything, enable "Delete all FormTheory data when the plugin is deleted" under FormTheory > Settings before deleting the plugin. This permanently removes forms, entries, uploaded files, the webhook log, options and scheduled events. Single-site only; multisite per-blog cleanup is not handled.

= What happens when a webhook fails? =

Each delivery is attempted up to 3 times: the first retry is after 60 seconds and the second after 240 seconds. Failed deliveries show under FormTheory > Webhook Log, where you can retry them manually.

= Are webhook secrets included in form exports? =

No. Webhook secrets are blanked in exported JSON.

= How do I keep entries only for a limited time? =

Set the retention period (days) under FormTheory > Settings. 0 keeps entries forever; the maximum is 3650. A daily job deletes older entries and their webhook log rows.

== Changelog ==

= 1.3.0 =
* New field: calculated (formula with numbers, field references, + - * /, parentheses and unary minus; recomputed on the server).
* New field: star rating (3-10).
* New field: address (selectable parts).
* New field: signature (drawn PNG or typed name, stored privately, viewed only through the admin).
* New field: repeater (up to 6 columns, 0-20 rows; webhooks get structured rows).
* Save and continue later: private resume links (30-day expiry, `formtheory_draft_ttl` filter, hashed tokens, per-IP rate limits). Adds a drafts table; the database upgrades automatically.
* Block editor: form picker and live (non-interactive) preview.
* Privacy: saved drafts are covered by the personal-data eraser and the privacy-policy text.
* Manual WCAG 2.2 AA review of the new fields (see docs/wcag-audit-1.3.md).
* Fixed a fatal error when submitting a form containing a checkbox field with a selected option on PHP 8 (TypeError in validation).

= 1.2.0 =
* Entries: search, read/unread filter, bulk actions and notes.
* Forms: duplicate, and import/export as JSON (webhook secrets blanked on export).
* Opt-in "delete all data on uninstall" setting and uninstall routine.
* Privacy: retention period (0 = keep forever, max 3650 days) with daily purge, WordPress personal-data exporter and eraser, privacy-policy text.
* Webhook delivery log (FormTheory > Webhook Log) with 3 attempts (retries after 60 s and 240 s) and manual retry for failed deliveries.
* Full internationalisation with `languages/formtheory.pot`.
* WCAG 2.2 AA accessibility pass (see docs/wcag-audit-1.2.md).

= 1.1.0 =
* Multiple conditional notifications with attachments and merge tags.
* Page and redirect confirmations.
* Hidden, consent and HTML fields with query-string population.
* Signed time-trap and Cloudflare Turnstile spam checks.
* Signed, non-blocking webhooks.
* AJAX submit sharing a single processing pipeline.
* Google reCAPTCHA v2/v3 support and post-submit redirect fix.

= 1.0.0 =
* Initial release: form builder, conditional logic, file uploads, multi-page forms, entries, CSV export and email notifications.

== Upgrade Notice ==

= 1.3.0 =
Recommended for all sites: fixes a fatal error (TypeError) in 1.2.0 and earlier on PHP 8 when a form with a checkbox field is submitted with an option selected. Also adds new field types, save and continue later, and a block editor picker. The database upgrades automatically (a drafts table is added); existing forms and entries are unchanged.

= 1.2.0 =
Adds entry status and notes and a webhook log table; the database upgrades automatically. Existing entries are marked read. Data is still kept on uninstall unless you opt in.

== Privacy ==

FormTheory stores submitted entries (field values, submission time and uploaded files) in your WordPress database and in `wp-content/uploads/formtheory-private/`. It sends data to third parties only where you enable it: Cloudflare Turnstile or Google reCAPTCHA (visitor data is sent to the provider for verification) and webhooks you configure (entry data is posted to your endpoint).

Set a retention period under FormTheory > Settings to delete old entries automatically. If you enable save and continue later, the answers entered so far (never uploaded files or drawn signatures) are stored with a hashed random token, and the link is emailed only if the visitor enters an address; drafts are deleted on submission or after 30 days by default. Entries (and saved drafts holding the same email address) are included in WordPress personal-data export and erasure requests. Suggested text is added to Settings > Privacy > Policy Guide.
