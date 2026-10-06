# CRM calendar and provider version checks

Backend ready for frontend integration. Local migrations are applied; browser acceptance and pushing this increment remain pending. Existing route names and methods are unchanged.

Apply migration `2026_10_06_000003_version_crm_calendar_and_providers.php` before deploying these endpoints. Existing rows start at version 1. The migration preserves their settings.

## Working calendar

`GET /crm/settings/calendar` keeps its `calendar` wrapper and null value when no calendar exists. Saved calendars now also contain `version` and `permissions:{read,edit}`. `working_days` uses ISO weekday keys 1–7, and timezone remains the organization's timezone.

GET has no request body. Sample response:

```json
{
    "calendar": {
        "working_days": { "1": { "start": "09:00", "end": "17:00" } },
        "holidays": [],
        "timezone": "Asia/Dubai",
        "version": 1,
        "permissions": { "read": true, "edit": true }
    }
}
```

`PUT /crm/settings/calendar` requires:

```json
{
    "expected_version": 0,
    "working_days": { "1": { "start": "09:00", "end": "17:00" } },
    "holidays": []
}
```

Use zero only for the first save. Send the returned version on the next save. Every successful save increments it. The calendar screen now carries this version automatically. Sample PUT response:

```json
{ "saved": true, "version": 1, "permissions": { "read": true, "edit": true } }
```

GET requires `viewCrm`; PUT requires the current organization owner or administrator. The read response's `permissions.edit` reflects that distinction. Validation fields: `expected_version` (required nonnegative integer, stale version), `working_days` (1–7 days, valid ISO keys, end after start), `working_days.<day>.start` and `.end` (required `HH:mm`), `holidays` (required array, at most 366), and `holidays.<index>` (distinct valid `YYYY-MM-DD`). Empty `holidays:[]` is valid.

## Provider profiles

`GET /organization/reference-settings/providers` retains its existing wrapper and capability list. Each profile now includes `version` and `permissions:{read:true,edit:true}`; this endpoint remains settings-admin-only.

GET has no request body. Sample response (timestamps are also returned on each row):

```json
{
    "kind": "providers",
    "records": [
        {
            "id": 7,
            "organization_id": 1,
            "name": "Sales email",
            "capability": "email",
            "provider": null,
            "active": 0,
            "settings": { "label": "Sales" },
            "version": 1,
            "permissions": { "read": true, "edit": true },
            "created_at": "2026-10-06 12:00:00",
            "updated_at": "2026-10-06 12:00:00"
        }
    ],
    "providerCapabilities": [
        "sms",
        "payment",
        "accounting_export",
        "email",
        "whatsapp",
        "property_portal",
        "signature"
    ],
    "deliveryEnabled": false
}
```

`POST /organization/reference-settings/providers` request:

```json
{
    "name": "Sales email",
    "capability": "email",
    "provider": null,
    "active": false,
    "settings": { "label": "Sales" }
}
```

POST response:

```json
{ "id": 7, "version": 1, "permissions": { "read": true, "edit": true } }
```

`PUT /organization/reference-settings/providers/{id}` requires the full profile fields plus `expected_version`:

```json
{
    "expected_version": 1,
    "name": "Sales email",
    "capability": "email",
    "provider": null,
    "active": false,
    "settings": { "label": "Sales" }
}
```

PUT response:

```json
{ "id": 7, "version": 2, "permissions": { "read": true, "edit": true } }
```

All three provider endpoints require `manageSettings` for the current organization. `name` is required (max 100); `capability` is required, must be one of `providerCapabilities`, and cannot change on update. `provider` is optional/null or a string (max 100), and does not select or connect a live provider. `active` is required and must be false. `settings` is optional and accepts only these optional nullable keys: `sender` and `label` (strings, max 100), `print_title` (string, max 255), `terms` (string, max 2000), `daily_limit` (integer 0–100000). Omitting settings on PUT preserves saved settings. Setting `settings:{}` clears them. PUT `expected_version` is required and at least 1.

Validation errors use `name`, `capability`, `provider`, `active`, `settings`, `settings.<key>`, or `expected_version`. Providers remain unselected; enabling delivery is still rejected. Other reference-settings kinds retain their existing contracts.

## Conflicts and permissions

Missing or stale versions return HTTP 422 in Laravel's standard `errors.expected_version` shape. On a conflict, reload the record and let the user review their edits before retrying. Rejected writes do not change data or increment versions. Organization locks serialize creation and updates, including simultaneous first calendar saves. Foreign provider IDs return 404; unauthorized writes return 403.

Sample stale calendar response (HTTP 422):

```json
{
    "message": "This calendar changed. Reload it and try again.",
    "errors": {
        "expected_version": ["This calendar changed. Reload it and try again."]
    }
}
```

Provider conflicts use the same shape with `This provider profile changed. Reload it and try again.` Missing fields and other invalid values use Laravel validation messages under the relevant field key.

Regression coverage: `SettingsVersionTest` exercises missing/stale versions, increments, organization isolation, denied writes, and disabled provider delivery. Existing calendar page and automation tests also use the updated request contract.

Validation completed: full suite on PHP 8.4.1 and MySQL 8.4 database `testing` passed **478 tests / 6,944 assertions**. Full PHPStan passed, Pint passed 751 files, Vue TypeScript passed, all five calendar frontend tests passed, and the production build passed. Both pending CRM version migrations were applied to the local development database. Browser acceptance remains with FE.
