# Analytics events (GA4 / GTM)

Every FormTheory form reports choice answers and completion, with no configuration.

For each event FormTheory dispatches a bubbling DOM `CustomEvent` on the form and, if `window.dataLayer` exists (GTM), pushes the same data to it.

| `event` (dataLayer) | DOM event | When |
| --- | --- | --- |
| `formtheory_answer` | `formtheory:answer` | A radio, select, checkbox or rating answer changes |
| `formtheory_complete` | `formtheory:complete` | The submission succeeded |

## Fields

`formtheory_answer`: `form_id`, `question_id` (field id), `question_label`, `question_number` (zero-based page index), `answer` (the chosen option text), `selected` (`false` when a checkbox is unticked).

`formtheory_complete`: `form_id`, `result`. For "Redirect by score" forms `result` is the outcome: the lowest-scoring category key, or `overall` when every category passes. Empty for other forms.

Free-text fields (text, email, textarea, phone, ...) are **never** reported, so names and emails cannot leak into analytics.

## GTM setup

1. Create Data Layer Variables: `question_id`, `question_label`, `question_number`, `answer`, `result`, `form_id`.
2. Create Custom Event triggers for `formtheory_answer` and `formtheory_complete`. Filter by `form_id` or `question_id` to scope them to one form.
3. Create GA4 Event tags, for example `quiz_answer` (params `question_id`, `question_label`, `answer`) and `quiz_complete` (param `result`), each fired by its trigger.

The same data is available without GTM:

```js
document.addEventListener('formtheory:complete', function (e) {
  gtag('event', 'quiz_complete', { result: e.detail.result });
});
```

The complete event fires just before a score redirect. GA4 sends events with `sendBeacon`, so they normally survive the navigation.
