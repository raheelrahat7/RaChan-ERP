# Owners and developers — Phase 2E backend contract

Owners and developers now have `payment_terms`, `commission_notes` (both nullable text, up to 5,000 characters), and a `version`. Existing People page props include these fields. A listing can link to an owner with the existing `owner_id` and to a developer with the new `developer_id`; both IDs must belong to the current organization. Developer links are explicit IDs, not a match on the free-text `developer_name` field.

All routes require authentication and a current organization. `viewCrm` can read; `manageCrm` can create and edit. Cross-organization IDs return 404. Every JSON owner/developer record includes `permissions: {read, edit}`. A forbidden write returns 403.

| Method | URI | Route name | Request |
| --- | --- | --- | --- |
| GET | `/real-estate/people/{type}/data` | `real-estate.people.data` | `type` is `owners` or `developers`; optional `q` (up to 100 chars), `page` |
| GET | `/real-estate/people/{type}/{record}` | `real-estate.people.show` | No body |
| POST | `/real-estate/people/{type}` | `real-estate.people.store` | Required `name`; optional `email`, `phone`, `reference`, `payment_terms`, `commission_notes` |
| PUT | `/real-estate/people/{type}/{record}` | `real-estate.people.update` | Required `expected_version`; optional `name`, `email`, `phone`, `reference`, `payment_terms`, `commission_notes` |

`POST` keeps its existing redirect response for Inertia forms. Send `Accept: application/json` for a 201 JSON response. Create returns `version: 1`; PUT returns the incremented version. `GET data` returns `{records: {data: [record], current_page, last_page, total, ...Laravel pagination}, filters, permissions: {read, create}}`. Search matches name or reference. Detail and JSON create/update return:

```json
{
  "record": {
    "id": 12, "organization_id": 1, "name": "Coastal Homes", "email": null,
    "phone": null, "reference": null, "payment_terms": "Milestone",
    "commission_notes": "Two percent", "version": 2,
    "listings": [{"id": 8, "reference": "COAST-A1", "purpose": "sale", "status": "active", "price": "100000.00", "currency": "AED"}],
    "offplan_projects": [{"id": 4, "code": "COAST", "name": "Coastal Project", "workflow_status": "planning"}],
    "permissions": {"read": true, "edit": true}
  }
}
```

`offplan_projects` is empty for owners. Collection records omit `listings` and `offplan_projects`; open detail to load links. Existing `POST /real-estate/listings` and `PUT /real-estate/listings/{listing}` accept optional `developer_id` (integer or null) in addition to `owner_id`; the listing response includes `developer_id`, `version`, and `permissions`. Listing PUT still requires `expected_version`.

Validation errors use `422 {"message":"...","errors":{"field":["..."]}}`. Fields include `name` (required on create, developer name must be organization-unique), `email`, `phone`, `reference`, `payment_terms`, `commission_notes`, `developer_id` (foreign organization or missing), and `expected_version` (missing or stale). No external provider is involved.
