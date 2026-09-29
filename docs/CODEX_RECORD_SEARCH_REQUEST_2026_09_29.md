# Backend request to Codex: a generic record-search endpoint

> **Backend implementation:** `GET /api/records/search` is implemented on `codex/phase-2-integration` with tenant-scoped, permission-scoped results. The optional `assignee_id` query parameter further intersects lead and job visibility with the selected assignee and checks both users' permissions for other types. The frontend picker may pass its form's `assigned_to` value to avoid offering a record that the final create action will reject. Without `assignee_id`, the endpoint scopes to the current actor.

- **Date:** 2026-09-29
- **From:** the frontend team (Claude), building phase 3 (Sales & CRM tools: Approvals, Tasks, Meetings & Viewings, Matchmaker, Broker Performance) on top of the phase-2 backend handoff.
- **Why:** Tasks and Meetings both let a user optionally link a related record (`related_type`/`related_id` on tasks; `lead_id`/`listing_id` on appointments). The owner asked for a real searchable picker (type a name, see matches) rather than a raw ID field. `GlobalSearch`/`GET /search` is an Inertia full-page render, not something a form can call live while typing, and it doesn't cover units, reservations, leases, sales contracts or job cards.

## The endpoint

```
GET /api/records/search?type={type}&q={term}
```

- `type` — one of `lead`, `listing`, `unit`, `reservation`, `lease`, `sale`, `job`. Reject anything else with 422.
- `q` — like `GlobalSearch`: `nullable|string|min:2|max:100`. Return `[]` for a term under 2 characters, don't 422.
- Auth: same as every other route — authenticated member of the current organization. No separate ability gate beyond what each type already implies (a `lead` search should only surface leads the actor can see; a `job` search only job cards the actor can view; etc.) — reuse `LeadVisibility` and `JobCardAccess` exactly as `ManageWorkTask::validateRelated()` and `ManageAppointment::validateLinks()` already do, so what the picker shows and what the create/update endpoint accepts never disagree.
- Response: a plain JSON array (not an Inertia response), capped at 20 rows, ordered by relevance/recency same as `GlobalSearch`:

```json
[{ "id": 42, "label": "Sara Al Marzooqi", "sublabel": "Al Noor Trading LLC" }]
```

`label`/`sublabel` per type, mirroring what `GlobalSearch` already shows for that type:

- `lead` → label: full name, sublabel: company or email
- `listing` → label: reference, sublabel: property name · city
- `unit` → label: unit number, sublabel: property name
- `reservation` → label: reference, sublabel: unit number
- `lease` → label: reference, sublabel: unit number
- `sale` → label: reference, sublabel: unit number
- `job` → label: reference, sublabel: title

A focused feature test per type (visible record matches, another organization's record doesn't, a record outside the actor's visibility doesn't, empty query gives `[]`) is enough — no migration needed, this reads existing tables only.

## While this doesn't exist yet

Frontend already ships without it: linking a task/meeting to a record is optional (`related_type`/`related_id`, `lead_id`/`listing_id` are all nullable), so Tasks and Meetings work fully today. The picker component calls this endpoint, and if it 404s or errors, shows "Search isn't connected yet" inline and lets the rest of the form work normally — never a crash, never fake results.
