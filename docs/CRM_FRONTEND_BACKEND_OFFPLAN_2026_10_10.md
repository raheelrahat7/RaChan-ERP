# Off-plan project backend — Phase 2D

Existing unit, milestone and deal routes remain unchanged. Project `status` is the operational `active` state; `workflow_status` drives the configurable status tabs. Amounts are AED decimal strings. `starting_price_aed` is the lowest **available** unit price, or `null` when none is available. Counts derive from current unit status, so reservation and sale transitions appear without a separate recalculate call.

## Routes and payloads

All routes require authentication and a current organization. `viewCrm` may read; `manageCrm` may create, edit and configure. A cross-organization project or status ID returns 404. Every JSON project has `permissions: {read, edit, configure_statuses}`; the collection also has `permissions: {read, create, configure}`. A forbidden write returns 403.

| Method | URI | Route name | Body/query |
| --- | --- | --- | --- |
| GET | `/real-estate/off-plan/projects/data` | `offplan.projects.data` | Optional `q` (up to 100 chars), `workflow_status`, `page` |
| GET | `/real-estate/off-plan/projects/{project}` | `offplan.projects.show` | None |
| POST | `/real-estate/off-plan/projects` | `offplan.projects.store` | Required `developer_id`, `code`, `name`, `emirate`; optional `cost_centre_id`, `location`, `completion_on`, `commission_rate`, `workflow_status`, `launch_on`, `handover_on`, `assigned_broker_id` |
| PUT | `/real-estate/off-plan/projects/{project}` | `offplan.projects.update` | Required `expected_version` plus any of `workflow_status`, `launch_on`, `handover_on`, `assigned_broker_id`, `completion_on`, `commission_rate` |
| POST | `/real-estate/off-plan/project-statuses` | `offplan.project-statuses.store` | Required `code`, `name`, `position`, `active` |
| PUT | `/real-estate/off-plan/project-statuses/{status}` | `offplan.project-statuses.update` | Required `code` (unchanged), `name`, `position`, `active`, `expected_version` |

Dates use `YYYY-MM-DD`. Commission is 0–100 with up to two decimal places. `assigned_broker_id` must belong to the current organization. Status codes use lowercase letters, digits and underscore, beginning with a letter. Project create also accepts the existing Inertia form and redirects; send `Accept: application/json` for the JSON response below.

```json
{
  "project": {
    "id": 42, "organization_id": 1, "developer_id": 7, "developer_name": "Harbour",
    "code": "HB", "name": "Harbour Bay", "emirate": "Dubai", "location": null,
    "status": "active", "workflow_status": "launched", "launch_on": "2027-01-01",
    "handover_on": "2030-01-01", "assigned_broker_id": null, "assigned_broker_name": null,
    "commission_rate": "2.50", "version": 2,
    "units_total": 2, "units_available": 2, "units_sold": 0, "starting_price_aed": "1200000.00",
    "permissions": {"read": true, "edit": true, "configure_statuses": true}
  }
}
```

Create returns 201 with `version: 1`; update returns 200 with incremented `version`. Detail returns the same project object plus `workflowStatuses`. List returns `{projects: {data: [project], current_page, last_page, total, ...Laravel pagination}, workflowStatuses, statusCounts: [{code, total}], filters, permissions}`. `statusCounts` follows the search term but includes all workflow statuses regardless of the selected status tab. The existing Inertia list/detail pages also receive these additive properties.

Before custom configuration, `workflowStatuses` contains default rows with `id: null`, `version: null`: Planning, Launch Ready, Launched, Selling, Sold Out, On Hold and Completed. On the first custom status write, those defaults become versioned database rows. To rename or deactivate a default, first create a custom status, then refresh the catalog and PUT its default row ID. Status writes return `{workflowStatus: {id, code, name, position, active, version, ...}}`; create is 201, update 200.

Validation failures use Laravel's `422 {"message":"...","errors":{"field":["..."]}}` shape. Relevant fields include `developer_id` (organization scope), `cost_centre_id`, `assigned_broker_id` (organization scope), `workflow_status` (must be active), `launch_on`, `handover_on` (on or after launch), `commission_rate`, `code` (duplicate/immutable), and `expected_version` (missing or stale). Project and status updates require `expected_version`; creates do not. Existing unit/milestone/deal routes are still redirect-based and are unchanged by this phase.
