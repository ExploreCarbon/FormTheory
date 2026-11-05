=== FormTheory ===
Contributors: explorecarbon
Tags: forms, form builder, contact form, conditional logic, webhooks
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications and webhooks. No build step.

== Description ==

FormTheory is a lightweight WordPress form builder.

* Fields: text, email, phone, number, date, textarea, select, radio, checkbox, file, page break, hidden, consent and HTML.
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

Embed a form with the shortcode `[formtheory id="123"]` or the `formtheory/form` block.

== Installation ==

1. Upload the `formtheory` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate FormTheory under Plugins.
3. Go to FormTheory > New Form, build your form and save.
4. Embed it with `[formtheory id="123"]` or the FormTheory block.

Activation creates two database tables and a private upload directory. Admin screens require the `manage_options` capability.

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

= 1.2.0 =
Adds entry status and notes and a webhook log table; the database upgrades automatically. Existing entries are marked read. Data is still kept on uninstall unless you opt in.

== Privacy ==

FormTheory stores submitted entries (field values, submission time and uploaded files) in your WordPress database and in `wp-content/uploads/formtheory-private/`. It sends data to third parties only where you enable it: Cloudflare Turnstile or Google reCAPTCHA (visitor data is sent to the provider for verification) and webhooks you configure (entry data is posted to your endpoint).

Set a retention period under FormTheory > Settings to delete old entries automatically. Entries are included in WordPress personal-data export and erasure requests. Suggested text is added to Settings > Privacy > Policy Guide.
