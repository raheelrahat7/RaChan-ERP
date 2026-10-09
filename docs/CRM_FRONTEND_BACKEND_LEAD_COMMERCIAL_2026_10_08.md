# Lead offers/contracts and accounting link — 2026-10-08

Backend ready for frontend integration. These routes are organization- and lead-scoped. They do not create a binding `sales_contracts` record, invoice, journal entry, or payment. CRM offer/contract records track proposals and signed-document metadata; the separate accounting endpoint reads existing estimates and invoices. No route names were renamed. Apply migration `2026_10_07_000003_create_crm_lead_commercial_records.php` before using the API.

## Offers, contracts and linked deal

| Method and URI                              | Exact request body                                                                                                                                                                                                     | Response                                                                                                               |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `GET /crm/leads/{lead}/commercial?page=1`   | none                                                                                                                                                                                                                   | `{ "records": Paginated<CommercialRecord>, "linked_deal": {"id":5,"title":"Sale","pipeline_id":1,"current_stage_id":2} | null, "permissions":{"view":true,"create":true}, "configuration": CommercialSettings }` |
| `POST /crm/leads/{lead}/commercial`         | `{"kind":"offer","title":"Unit 12 offer","status":"submitted","reference":"OFF-12","party_name":"Buyer","amount":"1500000.00","currency":"AED","submitted_on":"2026-10-08","signed_on":null,"notes":null,"deal_id":5}` | 201 `{ "record": CommercialRecord }`                                                                                   |
| `PUT /crm/leads/{lead}/commercial/{record}` | `{"expected_version":1,"status":"accepted"}`; send only fields to change                                                                                                                                               | 200 `{ "record": CommercialRecord }`                                                                                   |

`CommercialRecord` example: `{"id":7,"lead_id":2,"kind":"offer","reference":"OFF-12","title":"Unit 12 offer","party_name":"Buyer","status":"submitted","amount":"1500000.00","currency":"AED","submitted_on":"2026-10-08","signed_on":null,"notes":null,"deal_id":5,"created_at":"2026-10-08T10:00:00Z","updated_at":"2026-10-08T10:00:00Z","version":1,"permissions":{"view":true,"edit":true}}`. `deal_id` is returned only if the linked deal is visible to the caller. The deal must belong to this lead; the lead link and `kind` cannot change after create. Amount and three-letter uppercase currency must be present together. Dates use `YYYY-MM-DD`. `version` starts at 1; updates require `expected_version` and increment it.

The default offer statuses are `draft`, `submitted`, `accepted`, `rejected`, `withdrawn`. The default contract statuses are `draft`, `sent`, `signed`, `void`. The organization can edit labels, order and active state and add codes using the settings API below. Historic codes in use must be retained, but may be marked inactive. Existing records with an inactive status remain editable without forcing a status change. The settings API is the source of truth for the UI; do not hard-code those default lists.

Lead viewers may list records. A user with `manageCrm` who can see the lead may create and edit them. Converted leads cannot receive new records. The `permissions` object describes the current caller's allowed operations. Validation errors use HTTP 422 `{ "message":"...", "errors": { "field": ["..."] } }`; relevant keys are `kind`, `title`, `reference`, `party_name`, `status`, `amount`, `currency`, `submitted_on`, `signed_on`, `notes`, `deal_id`, `expected_version`, `lead`. Other-organization or inaccessible leads/records return 404; forbidden writes return 403. Saves are audited.

## Organization status configuration

| Method and URI                      | Exact request body                                                                                                                                             | Response                                  |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| `GET /crm/settings/lead-commercial` | none                                                                                                                                                           | `{ "configuration": CommercialSettings }` |
| `PUT /crm/settings/lead-commercial` | `{"expected_version":0,"statuses":{"offer":[{"value":"draft","label":"Draft","active":true}],"contract":[{"value":"signed","label":"Signed","active":true}]}}` | `{ "configuration": CommercialSettings }` |

`CommercialSettings` is `{ "statuses": { "offer": Choice[], "contract": Choice[] }, "version": 0, "permissions": { "read": true, "edit": true } }`. A `Choice` has stable `value`, editable `label`, and `active`. Version 0 represents defaults before the first save; subsequent saves increment it. Only organization administrators may edit. Errors are the standard 422 shape under `expected_version`, `statuses.offer`, `statuses.contract`, or a nested choice key. At least one active status per kind is required.

## Accounting Link tab

`GET /crm/leads/{lead}/accounting-links` has no body and returns:

```json
{
    "documents": [
        {
            "kind": "estimate",
            "id": 11,
            "reference": "EST-11",
            "title": "Quote",
            "status": "Accepted",
            "amount": "100.00",
            "vat": null,
            "total": "100.00",
            "currency": "AED",
            "version": 2,
            "permissions": { "view": true, "edit": true }
        },
        {
            "kind": "invoice",
            "id": 21,
            "reference": "INV-21",
            "title": null,
            "status": "draft",
            "amount": "100.00",
            "vat": "5.00",
            "total": "105.00",
            "currency": "AED",
            "version": null,
            "permissions": { "view": true, "edit": false }
        }
    ],
    "permissions": { "view": true }
}
```

Estimates come from lead-linked estimate workflows that the caller can read. Invoice rows come from those estimates and from invoices linked to a visible, amount-readable deal for this lead; duplicate invoices are emitted once. Estimate VAT is `null` because estimates do not determine tax. Invoice `amount` is its subtotal, `vat` is the saved VAT amount, and `total` is the saved total. The endpoint never calculates or posts tax. A lead viewer without `viewFinance` receives `{ "documents": [], "permissions": { "view": false } }`. Inaccessible leads return 404. Invoice records here have `version:null` because this endpoint is read-only; edits use the Finance API and its existing controls.
