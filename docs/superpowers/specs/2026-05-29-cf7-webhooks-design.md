# RAE CF7 Webhooks — Design

**Date:** 2026-05-29
**Status:** Approved, ready for implementation plan

## Goal

Pivot the existing single-destination Zapier plugin into a multi-destination
webhook plugin. Each Contact Form 7 submission can fan out to any number of
configured webhooks. Each webhook is tagged **Zapier** (raw JSON) or **Slack**
(formatted message to a Slack Incoming Webhook URL).

## Data Model

Single WordPress option `rae_cf7_webhooks` holding an array of rows:

```php
[
    [
        'type'    => 'zapier' | 'slack',
        'url'     => 'https://...',
        'form_id' => 0,        // 0 = all forms, otherwise CF7 form id
        'label'   => '',       // optional, for the deliveries log
    ],
    // ...repeatable
]
```

No data migration from the old `rae_cf7_zapier_url` / `rae_cf7_zapier_form_id`
options. Plugin is v0.1; old options are abandoned (and removed on deactivate
for cleanliness).

## Admin Page

Location: submenu under CF7's **Contact** menu. Menu label **Webhooks**, page
title "Contact Form 7 → Webhooks". Capability `wpcf7_edit_contact_forms`.

Repeater table, one row per webhook:

| Column        | Control                                                       |
|---------------|--------------------------------------------------------------|
| Type          | `<select>` Zapier / Slack                                    |
| URL           | `<input type="url">`                                         |
| Limit to form | `<select>` All forms + each CF7 form (per-row filter)        |
| Label         | `<input type="text">` optional                               |
| —             | Remove-row button                                            |

- "Add webhook" button clones a hidden template row via small inline vanilla JS.
  Row inputs use array name notation, e.g.
  `rae_cf7_webhooks[0][type]`, `rae_cf7_webhooks[0][url]`, etc.
- Form submits through `options.php` (Settings API).
- `register_setting` uses an array `sanitize_callback`:
  - whitelist `type` to `zapier|slack`,
  - `esc_url_raw` the `url`,
  - `absint` the `form_id`,
  - `sanitize_text_field` the `label`,
  - drop rows with an empty URL,
  - reindex the surviving rows.

"Recent deliveries" table below the form gains a **Destination** column showing
the row label, or the type if no label set.

## Flow

1. `wpcf7_mail_sent($contact_form)` fires.
2. Build the base payload once:
   ```php
   [
       'form_id'      => $contact_form->id(),
       'form_title'   => $contact_form->title(),
       'site'         => home_url(),
       'submitted_at' => current_time('c'),
       'fields'       => $data,   // posted data, keys starting with _wpcf7 stripped
   ]
   ```
3. For each configured webhook row whose `form_id` is `0` or matches the
   submitted form's id, schedule one background cron event:
   `wp_schedule_single_event(time(), CRON_HOOK, [$type, $url, $payload, $label])`.
   Background send keeps the visitor's response from waiting on the network.
4. `do_send($type, $url, $payload, $label)` formats by type and POSTs:
   - **zapier** → body = `wp_json_encode($payload)`, header
     `Content-Type: application/json` (unchanged behavior).
   - **slack** → body = `wp_json_encode(['text' => $text])` where `$text` is
     mrkdwn:
     ```
     *New submission: {form_title}* ({site})
     {field_label}: {field_value}
     {field_label}: {field_value}
     ...
     ```
     Array field values joined with ", ". Header `Content-Type: application/json`.

## Errors & Logging

- `wp_remote_post` with `timeout => 15`, `blocking => true` (as today).
- `is_wp_error` → log error message; otherwise log `HTTP {code}` and mark ok on
  2xx.
- Log entry: `time`, `form` (title), `dest` (label or type), `ok`, `status`.
- Log capped at `LOG_MAX = 10`, newest first, stored in option `rae_cf7_webhooks_log`.

## Naming

- Class `Rae_CF7_Zapier` → `Rae_CF7_Webhooks`.
- Plugin header `Plugin Name` → "RAE CF7 Webhooks"; description updated to
  describe Zapier + Slack fan-out.
- File stays `rae-cf7-webhooks.php`. Single-file plugin.
- Option/constant names move to a `rae_cf7_webhooks*` prefix.

## Out of Scope

- Retry on failure (single attempt, logged).
- Block Kit / rich Slack formatting (plain mrkdwn text only).
- Data migration from v0.1 options.
- Secret signing / HMAC of payloads.
