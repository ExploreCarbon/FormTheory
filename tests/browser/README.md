# Admin browser checks

Uses Chromium and real WordPress admin styles with isolated builder/settings fixtures. No WordPress database or saved form is modified.

From the plugin source root:

```sh
npm install --prefix .qa --no-audit --no-fund playwright
npx --prefix .qa playwright install chromium
php tests/browser/settings.php > .qa/settings.html
node tests/browser/builder.cjs
```

Set `FORMTHEORY_WP_ROOT` to a local WordPress web root containing `wp-admin/css` and `wp-includes/css`. It defaults to two directories above the plugin checkout. PHP needs no extensions for the settings fixture. Browser screenshots are written under `.qa/`.

Checks section navigation, notification mutation status, add/duplicate fields, expand/collapse, unsaved-change protection, serialization, invalid controls in multiple hidden sections and collapsed details, and narrow-screen overflow. Screenshots cover builder and global settings at 1440px and 390px.
