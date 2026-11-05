# Scoring and redirect by score

1. On each radio or select field, add **Points per choice** (one per line, same order as the choices) and a **Score category** (letters, numbers, dashes).
2. Under Form settings, set **After submit** to **Redirect by score**.
3. Set the good-score threshold (default 80%), a page ID for each category, and an overall page ID.

A category's score is earned points / best-possible points x 100 across its fields. Fields hidden by conditional logic count for nothing. The lowest category wins (the first in field order on a tie). When every category is at or above the threshold, the visitor goes to the overall page. A mapped page that is not published falls back to the overall page, then to the confirmation message.

Scoring is computed on the server from the points saved in the form; posted values are never trusted.

**Save entries** off: no entry row is stored (entry id 0). Notifications and webhooks still run; uploaded files are removed after notifications are sent.
