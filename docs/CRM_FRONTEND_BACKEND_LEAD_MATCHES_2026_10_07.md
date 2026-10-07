# Lead matched properties contract — 2026-10-07

**Backend ready for frontend integration.** These routes are additive to the existing Matchmaker page and lead requirements API. Apply `2026_10_07_000001_create_crm_lead_listing_matches.php` before use. All requests use the authenticated web session; writes need CSRF. Send `Accept: application/json`. No existing route names changed.

## Lead match list and suggestions

| Method | URI                                                       | Route name                  | Body |
| ------ | --------------------------------------------------------- | --------------------------- | ---- |
| GET    | `/crm/leads/{lead}/matches?page=1&per_page=25`            | `crm.leads.matches.index`   | none |
| GET    | `/crm/leads/{lead}/matches/suggest?q=&page=1&per_page=25` | `crm.leads.matches.suggest` | none |

`lead` is an existing numeric lead ID in the current organization. `page` starts at 1 and `per_page` is 1–100 (default 25). `q` is optional text up to 100 characters; it searches listing reference, property name and city. Unknown/invisible/foreign leads return 404. Both endpoints require existing `viewCrm` permission and lead visibility; they work for read-only users.

GET list returns HTTP 200:

```json
{
    "matches": {
        "current_page": 1,
        "data": [
            {
                "id": 91,
                "lead_id": 17,
                "listing_id": 38,
                "version": 2,
                "listing": {
                    "id": 38,
                    "reference": "L-38",
                    "status": "active",
                    "purpose": "sale",
                    "market_segment": "secondary",
                    "price": "1000000.00",
                    "currency": "AED",
                    "unit": {
                        "id": 25,
                        "number": "A-12",
                        "type": "apartment",
                        "area": "1200.00",
                        "area_unit": "sq_ft"
                    },
                    "property": {
                        "id": 7,
                        "name": "Palm Tower",
                        "city": "Dubai"
                    },
                    "community": null
                },
                "match_percent": 80,
                "match_percent_override": null,
                "score_source": "automatic",
                "matched_on": ["purpose", "property_type", "budget", "size"],
                "evaluated_on": [
                    "purpose",
                    "property_type",
                    "location",
                    "budget",
                    "size"
                ],
                "shared": false,
                "viewing_status": "scheduled",
                "viewing_at": "2027-01-02T10:00:00+00:00",
                "notes": null,
                "permissions": { "read": true, "edit": true, "delete": true }
            }
        ],
        "total": 1,
        "last_page": 1,
        "per_page": 25
    },
    "permissions": { "read": true, "add": true },
    "configuration": {
        "id": null,
        "version": 0,
        "viewing_statuses": [
            {
                "value": "not_scheduled",
                "label": "Not scheduled",
                "active": true
            },
            { "value": "scheduled", "label": "Scheduled", "active": true },
            { "value": "completed", "label": "Completed", "active": true },
            { "value": "cancelled", "label": "Cancelled", "active": true },
            { "value": "no_show", "label": "No show", "active": true }
        ],
        "permissions": { "read": true, "edit": true }
    }
}
```

`matches` is a Laravel paginator; it also has the usual `links`, `from`, `to`, `path`, etc. A listing already matched remains visible in this list if its status later changes. `community` is currently `null` because the property/listing schema has no community field yet; the Phase 2 listing work can populate it without changing this shape. Neither listing details nor suggestion responses include public access tokens.

GET suggestions returns HTTP 200:

```json
{
    "candidates": {
        "data": [
            {
                "listing": {
                    "id": 38,
                    "reference": "L-38",
                    "status": "active",
                    "purpose": "sale",
                    "market_segment": "secondary",
                    "price": "1000000.00",
                    "currency": "AED",
                    "unit": {
                        "id": 25,
                        "number": "A-12",
                        "type": "apartment",
                        "area": "1200.00",
                        "area_unit": "sq_ft"
                    },
                    "property": {
                        "id": 7,
                        "name": "Palm Tower",
                        "city": "Dubai"
                    },
                    "community": null
                },
                "match_percent": 80,
                "matched_on": ["purpose", "property_type", "budget", "size"],
                "evaluated_on": [
                    "purpose",
                    "property_type",
                    "location",
                    "budget",
                    "size"
                ],
                "permissions": { "read": true, "add": true }
            }
        ],
        "total": 1,
        "current_page": 1,
        "per_page": 25,
        "last_page": 1
    },
    "permissions": { "read": true, "add": true }
}
```

Suggestions include only **active listings with available units** whose listing, unit and property all belong to the current organization. Existing matches are excluded. Results sort by match percent descending, then listing ID ascending. This is a local deterministic rule, not an external AI call or a saved match; opening this endpoint never writes data. Its ranking reads the current [lead requirements](CRM_FRONTEND_BACKEND_LEAD_REQUIREMENTS_2026_10_06.md): purpose (`buy`/`sell` map to listing `sale`; `rent`/`lease` map to `rent`), exact unit type, property name/city text or emirate when location is blank, budget when currencies match, and unit area when units match. Every criterion that can be evaluated has equal weight. `matched_on` lists criteria that passed; `evaluated_on` lists the criteria used as the denominator. Unknown community, bedroom count, mismatched currency/area units and missing inputs are **not scored**. With no evaluable criteria, score is 0. A score of 100 with only one evaluated criterion means that one criterion matched; display `evaluated_on` if interpreting confidence. Saved matches with no manual override recompute their score when requirements, listing price or unit details change.

## Create, update and remove a saved match

| Method | URI                                 | Route name                  |
| ------ | ----------------------------------- | --------------------------- |
| POST   | `/crm/leads/{lead}/matches`         | `crm.leads.matches.store`   |
| PUT    | `/crm/leads/{lead}/matches/{match}` | `crm.leads.matches.update`  |
| DELETE | `/crm/leads/{lead}/matches/{match}` | `crm.leads.matches.destroy` |

POST exact example (only `listing_id` is required):

```json
{
    "listing_id": 38,
    "match_percent_override": null,
    "shared": false,
    "viewing_status": "scheduled",
    "viewing_at": "2027-01-02T10:00:00+00:00",
    "notes": "Client requested a viewing"
}
```

HTTP 201 response is `{ "match": <the full match object shown in the list> }`, including ID, `version:1` and `permissions`. A listing can be matched once per lead. POST accepts only an active, available, same-organization listing. `shared` is an **internal tracking flag**; setting it does not send a message, publish a listing or call an external provider. `match_percent_override` may be an integer 0–100; `null` uses the computed percentage and `score_source:"automatic"`.

PUT accepts a partial body, with mandatory `expected_version` and any of `match_percent_override`, `shared`, `viewing_status`, `viewing_at`, `notes`. Example:

```json
{
    "expected_version": 1,
    "match_percent_override": 73,
    "shared": true,
    "viewing_status": "completed",
    "notes": "Viewing complete"
}
```

HTTP 200 response is `{ "match": <full match object> }` with version incremented. Send `match_percent_override:null` to restore automatic scoring, `viewing_at:null` to clear a date, or `notes:null` to clear notes. A `scheduled` status requires a nonempty `viewing_at`; send an ISO 8601 timestamp with timezone. The response formats it in UTC. `notes` has a 5,000-character limit. `viewing_status` must be an active organization option unless the record already has that archived status and it is unchanged. A listing that becomes paused/closed after matching can still have its existing match edited or removed.

DELETE body is `{"expected_version":2}`. HTTP 200 response is `{"deleted":true,"id":91,"version":2}`; no record remains. PUT and DELETE require the **match's own** latest `version`, independent of the lead requirements/settings versions. Missing or stale versions return HTTP 422 `errors.expected_version`, leaving the record unchanged. New matches have version 1 immediately.

Writes require CRM edit permission and visibility of the lead. A read-only user sees `permissions.add:false` and `match.permissions.edit/delete:false` and receives 403 on writes. Converted leads remain readable, with mutation permissions false; writes return 422 `errors.lead`. A match ID outside the lead/organization returns 404. Writes create lead history/audit events (`crm.lead.match_added`, `crm.lead.match_updated`, `crm.lead.match_removed`) with IDs and changed field names; free-text notes are excluded from audit properties.

Validation errors are always HTTP 422 with the standard Laravel `{message,errors:{field:[message]}}` shape. For example:

```json
{
    "message": "This match changed. Reload it and try again.",
    "errors": {
        "expected_version": ["This match changed. Reload it and try again."]
    }
}
```

Other possible keys: `listing_id` (missing, foreign, unavailable or duplicate); `match_percent_override` (outside 0–100 or wrong type); `shared`; `viewing_status` (unknown/inactive); `viewing_at` (invalid or required for scheduled); `notes` (length/type); `lead` (converted). Query validation uses `page`, `per_page`, `q`.

## Organization-editable viewing statuses

| Method | URI                          | Route name                         |
| ------ | ---------------------------- | ---------------------------------- |
| GET    | `/crm/settings/lead-matches` | `crm.settings.lead-matches.show`   |
| PUT    | `/crm/settings/lead-matches` | `crm.settings.lead-matches.update` |

GET needs `viewCrm` and has no body. It returns `{ "configuration": {"id":null,"version":0,"viewing_statuses":[...],"permissions":{"read":true,"edit":true}} }` until first save, with the five defaults in the list response above. GET does not create a row. All CRM viewers can read; only organization owners/administrators can edit. Settings are independent per organization.

PUT exact body example:

```json
{
    "expected_version": 0,
    "viewing_statuses": [
        { "value": "not_scheduled", "label": "Open", "active": true },
        { "value": "scheduled", "label": "Scheduled", "active": true },
        { "value": "confirmed", "label": "Confirmed by client", "active": true }
    ]
}
```

HTTP 200 returns the full `configuration` envelope with a real ID, incremented version (1 on first save), choices and permissions. The array replaces the complete status list, preserving order. Keep 1–100 entries; each entry has exactly `value`, `label`, `active`. Values must be unique lowercase codes matching `^[a-z][a-z0-9_]*$` and max 60 characters. Labels are nonempty text max 120 characters. Existing codes may be relabeled, reordered and archived (`active:false`). An **in-use** code cannot be removed; archive it to retain existing matches. An archived code cannot be newly assigned. The status `scheduled` has special date-required behavior only while that code is used; custom codes have no automatic scheduling policy.

PUT requires the latest configuration `expected_version`; stale/missing values return 422 `errors.expected_version`. Other 422 keys include `viewing_statuses` (empty, duplicate, removing used option), `viewing_statuses.<index>` (unknown keys), and `viewing_statuses.<index>.value|label|active`. A CRM viewer who is not an owner/administrator gets 403 on PUT. Settings changes are audited as `crm.lead_match_settings.updated`.
