# Lead requirements contract — 2026-10-06

**Backend ready for frontend integration.** Phase 1A requirements only; the Requirement tab and browser acceptance remain FE work. Existing lead, custom-field, stage, product and estimate contracts are unchanged. No route names were renamed. Run migration `2026_10_06_000004_create_crm_lead_requirements.php` before using these endpoints.

## Read/save a lead's requirements

| Method | URI                              | Route name                      |
| ------ | -------------------------------- | ------------------------------- |
| GET    | `/crm/leads/{lead}/requirements` | `crm.leads.requirements.show`   |
| PUT    | `/crm/leads/{lead}/requirements` | `crm.leads.requirements.update` |

Use the existing authenticated session and CSRF header for writes. No GET body. IDs are numeric lead IDs in the current organization. GET never creates records.

PUT body example (all supported fields):

```json
{
    "expected_version": 0,
    "data": {
        "type": "buyer",
        "purpose": "buy",
        "unit_category": "residential",
        "emirate": "dubai",
        "property_type": "villa",
        "location": "Palm Jumeirah",
        "bedrooms_min": 2,
        "bedrooms_max": 4,
        "bathrooms_min": 2,
        "furnishing": "furnished",
        "size_min": "1200.00",
        "size_max": "1800.50",
        "size_unit": "sq_ft",
        "budget_min": "1500000.00",
        "budget_max": "2500000.50",
        "budget_currency": "AED",
        "rent_frequency": "yearly",
        "timeline": "immediate",
        "completion_status": "off_plan",
        "handover_on": "2027-06-30",
        "payment_method": "mortgage",
        "down_payment_percent": "20.00",
        "roi_percent": "7.50",
        "financing_status": "pre_approved",
        "language": "en",
        "amenities": ["pool", "gym"],
        "preferences": "Quiet street",
        "lead_score": 85,
        "temperature": "hot"
    }
}
```

PUT accepts a **partial `data` object**, with at least one supported key. Omitted keys keep their saved values. Explicit `null` clears any scalar. Clear amenities with `[]`, not `null`. Unknown data keys are rejected. Example later save: `{"expected_version":1,"data":{"lead_score":90,"preferences":null}}`.

GET and PUT return HTTP 200 with the **same envelope**:

```json
{
    "requirement": {
        "id": 42,
        "lead_id": 17,
        "version": 1,
        "data": {
            "type": null,
            "purpose": null,
            "unit_category": null,
            "emirate": null,
            "property_type": null,
            "furnishing": null,
            "rent_frequency": null,
            "timeline": null,
            "completion_status": null,
            "payment_method": null,
            "financing_status": null,
            "language": null,
            "amenities": [],
            "temperature": "hot",
            "size_min": null,
            "size_max": null,
            "budget_min": null,
            "budget_max": null,
            "down_payment_percent": null,
            "roi_percent": null,
            "bedrooms_min": null,
            "bedrooms_max": null,
            "bathrooms_min": null,
            "lead_score": 85,
            "location": null,
            "size_unit": null,
            "budget_currency": null,
            "handover_on": null,
            "preferences": null
        },
        "permissions": { "read": true, "edit": true }
    },
    "configuration": {
        "id": null,
        "version": 0,
        "choices": {},
        "permissions": { "read": true, "edit": true }
    }
}
```

This example represents saving only score/temperature. `configuration.choices` is abbreviated above: the actual response **always contains all 14 choice lists** defined below, each an array of `{value,label,active}`. Requirements always include every documented data key, even when null. Before the first save, `requirement.id` is null and its version is 0, with all scalars null and amenities empty. First save returns a real ID and version 1 immediately. Every successful subsequent PUT increments version, including a no-op save.

Send the latest **requirement.version** as `expected_version`. It is independent of configuration.version and existing lead/stage locking. A stale version returns 422, leaving values and version unchanged; reload before retrying. Scores are manual integer assessments; temperature is independent of score. No automatic scoring thresholds or currency conversion are implied.

### Field rules

| Fields                                                | JSON type and constraints                                                                                                        |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| All choice fields except amenities                    | nullable string code, max 60; choose from active `configuration.choices[field]`                                                  |
| amenities                                             | array of distinct string codes, max 50, each max 60                                                                              |
| bedrooms_min, bedrooms_max, bathrooms_min, lead_score | nullable integer 0–100                                                                                                           |
| size_min/max, budget_min/max                          | nullable nonnegative decimal, up to 12 integer digits and 2 decimals; send strings; response is a string with exactly 2 decimals |
| down_payment_percent                                  | nullable decimal 0–100, max 2 decimals; returned as decimal string                                                               |
| roi_percent                                           | nullable decimal 0–1000, max 2 decimals; returned as decimal string                                                              |
| size_unit                                             | nullable `sq_ft` or `sq_m`; required when either size bound is set                                                               |
| budget_currency                                       | nullable three uppercase letters; required when either budget bound is set; no exchange-rate validation/conversion               |
| location                                              | nullable text, max 255                                                                                                           |
| handover_on                                           | nullable real calendar date in `YYYY-MM-DD`                                                                                      |
| preferences                                           | nullable text, max 10,000                                                                                                        |

For bedrooms, size and budget, maximum must be at least minimum when both are set. Validation uses the **merged saved values**, so changing only a minimum also validates the existing maximum. Existing archived choices remain readable and may remain unchanged during an edit; they cannot be newly selected. Clear an archived value before replacing it if desired; once cleared it cannot be reselected while inactive.

### Permissions and errors

Read requires existing CRM access and lead visibility, including hierarchy grants. Other organizations' or invisible leads return 404. Write additionally requires existing CRM edit permission (including explicit edit grants); otherwise 403. Converted leads remain readable with `permissions.edit:false`; writes return 422 `errors.lead`. Configuration may be read by CRM users, but its edit permission is separately limited to organization owners/administrators.

Validation failures use the standard Laravel response, for example:

```json
{
    "message": "The maximum must be greater than or equal to the minimum.",
    "errors": {
        "data.budget_max": [
            "The maximum must be greater than or equal to the minimum."
        ]
    }
}
```

Possible error keys: `expected_version` (missing, invalid, stale); `data` (missing, empty, unknown key); `data.<field>` (invalid type/code/range/unit/currency/date/length); `data.amenities.<index>` (invalid/duplicate item); `lead` (converted). Render errors by their dotted keys; do not depend on exact translated message text. Successful writes add a `crm.lead.requirements_updated` lead history event with changed field names and the requirement version, without copying preference text or financial values into the audit properties.

## Organization-editable requirement choices

| Method | URI                               | Route name                              |
| ------ | --------------------------------- | --------------------------------------- |
| GET    | `/crm/settings/lead-requirements` | `crm.settings.lead-requirements.show`   |
| PUT    | `/crm/settings/lead-requirements` | `crm.settings.lead-requirements.update` |

GET has no body and returns `{ "configuration": { "id": null, "version": 0, "choices": { ... }, "permissions": {"read":true,"edit":true} } }` with defaults until saved. All CRM viewers can read; only organization owners/administrators can write. No provider configuration is required.

PUT exact example:

```json
{
    "expected_version": 0,
    "choices": {
        "temperature": [
            { "value": "cold", "label": "Cold", "active": true },
            { "value": "warm", "label": "Warm", "active": true },
            { "value": "hot", "label": "Hot", "active": true },
            { "value": "urgent", "label": "Urgent", "active": true }
        ]
    }
}
```

HTTP 200 response: same GET envelope, with a real configuration ID and incremented version (1 on first save), full merged choice map, and permissions. Each supplied field's array **replaces that field's complete list**, preserving order. Omitted fields stay unchanged. Send the latest configuration.version as expected_version. Requirement record versions do not change when labels/options change.

`choices` must contain at least one of the field keys below. Each field supports 0–200 entries. Entries contain exactly `value`, `label`, `active`: value is a unique lowercase code matching `^[a-z][a-z0-9_]*$` (max 60), label is nonempty text (max 120), active is boolean. Add codes, rename labels, reorder, or archive (`active:false`). Removing an in-use code is rejected; archive it to retain historical display. Unused codes may be removed. Empty arrays disable new selections for that field. Custom additional requirement keys are not introduced by this API; existing CRM custom fields remain available separately.

Settings validation errors: `expected_version`; `choices` (unknown field/empty/missing); `choices.<field>` (not a list, too many, duplicate values, removing used value); `choices.<field>.<index>` (unknown entry key); `choices.<field>.<index>.value|label|active` (invalid/missing). Same HTTP422 Laravel errors envelope; unauthorized write is 403. Settings are isolated per organization and audited as `crm.lead_requirement_settings.updated`.

Default codes (labels come from the response, never hard-code them):

| Field             | Initial codes                                                                       |
| ----------------- | ----------------------------------------------------------------------------------- |
| type              | buyer, tenant, investor, seller, landlord                                           |
| purpose           | buy, rent, sell, lease                                                              |
| unit_category     | residential, commercial, industrial, land                                           |
| emirate           | abu_dhabi, dubai, sharjah, ajman, umm_al_quwain, ras_al_khaimah, fujairah           |
| property_type     | apartment, villa, townhouse, penthouse, studio, office, shop, warehouse, land       |
| furnishing        | furnished, semi_furnished, unfurnished                                              |
| rent_frequency    | yearly, quarterly, monthly, weekly, daily                                           |
| timeline          | immediate, one_to_three_months, three_to_six_months, six_to_twelve_months, flexible |
| completion_status | ready, off_plan, under_construction                                                 |
| payment_method    | cash, mortgage, payment_plan                                                        |
| financing_status  | not_started, applied, pre_approved, approved, not_required                          |
| language          | en, ar, ur, hi, ru, zh                                                              |
| amenities         | pool, gym, parking, balcony, garden, security                                       |
| temperature       | cold, warm, hot                                                                     |

An actual choice list example is `"temperature":[{"value":"cold","label":"Cold","active":true},{"value":"warm","label":"Warm","active":true},{"value":"hot","label":"Hot","active":true}]`. The frontend should show archived entries for existing selections and exclude them from new-selection menus.
