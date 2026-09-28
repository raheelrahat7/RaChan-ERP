# Home dashboard and backend red-module scopes — 2026-09-28

The owner explicitly requested completion of the orange and red phases on 2026-09-28. These scopes refine [the backend handoff](CODEX_BACKEND_HANDOFF_2026_09_28.md). Each module is a separate implementation increment with organization authorization, migrations, audit events and focused feature tests. Claude owns the Vue pages and reads the Inertia props recorded here.

## C1 — companies, branches and cost centres

Allow an organization to define legal companies, branches within companies and cost centres within branches. Names and codes are unique within the parent. Only organization owners/administrators manage the hierarchy; finance users may select active dimensions on **new** manual journal lines. A line can have no dimensions, company only, company plus branch, or all three. All supplied IDs must belong to the current organization and form one parent chain. Posted dimensions are immutable except through an audited reversing journal. Historical lines remain unassigned; no backfill, allocation or automatic reclassification occurs before accountant approval. Dashboard company/branch filters and the cost-centre table use only expressly dimensioned entries; unassigned entries remain in all-organization totals. No cross-company consolidation or intercompany posting is inferred.

## C2 — approvals inbox

Show existing pending owner/manager approvals relevant to the current user with organization-safe links and counts. The inbox delegates approve/reject to the owning workflows and never changes those financial/operational decisions itself. A flow with no existing rejection action remains link-only until its module defines one. The shared `counts.approvals_pending` and dashboard `kpis.pending_approvals` become numbers only when the aggregation is implemented.

## C3 — tasks

New assigned tasks have a due timestamp, optional related lead/deal/unit/job, status and audit history. Task links and assignees must belong to the same organization; an actor must be allowed to see the related record. Tasks stay separate from existing CRM follow-up activities, as the owner selected. Dashboard overdue tasks count only tasks visible to the user.

## C4 — meetings and viewings

Calendar appointments include participants, start/end times, status, optional linked lead/listing/agent and viewing outcome. Cross-organization links and time inversions are rejected. Outcomes can trigger a lead-stage action only through the existing CRM pipeline rules and an explicit authorized action; saving an appointment never silently moves a lead.

## C5 — off-plan projects and developers

Use organization-owned developer parties, projects, unit inventory, installment plans/milestones and links to off-plan enquiries/deals. Keep project units distinct from property-management units until a conversion workflow is designed. Do not create VAT or revenue journals merely from project milestones.

## C6–C8 — portal marketing, subscriptions and listing costs

Portal publications use the existing provider-neutral syndication contract; local drafts/validation/status and imported enquiries can exist before a named Bayut, Property Finder or Dubizzle adapter. Subscriptions track package, credits, quotas, renewal and linked vendor bill. Listing spend is tied to the listing, channel and an existing posted vendor bill or explicitly marked operational estimate. Cost-per-lead and cost-per-deal reports distinguish actual paid/postable cost from estimates. No external publication without credentials and provider approval.

## C9 — matchmaker and insights

First increment uses deterministic, explainable lead/listing matching with existing authorized data. No personal data leaves the app. A provider-neutral AI interface can be defined, but provider selection and data-privacy rules precede remote AI requests. Dashboard insights use only deterministic local facts until then.

## C10 — HR and staff services

Employee records connect to organization members, track document kind/expiry with restricted access, and support leave request/approval. Expiry alerts are derived from actual records; do not fabricate government verification.

## C11 — Government Authority Integration Module (GAIM)

The owner defines GAIM as regulatory-form compliance across the seven emirates. `gaim_routing_rules` specify which forms (for example Trakheesi, Ejari, DARI, SRERA and municipality transfer/tenancy forms) apply to a jurisdiction and business event. `gaim_compliance_records.status` tracks each required record's lifecycle, evidence and audit history. Unknown or unconfigured authorities remain empty, never a generic verified flag. Existing Dubai DLD records may be linked explicitly; no authority filing or validation is claimed without an adapter and legal review.

`POST /compliance/gaim/records/sync` accepts `emirate`, `event`, `subject_type` and `subject_id`. It creates missing `required` records from active, effective routing rules and is idempotent; optional and future-effective forms are skipped. The workflow does not guess an emirate from an address. A manager selects the jurisdiction and event, then reviews the generated records.

## Existing sidebar extensions

Add developer party classification, resale listing flag/filter, broker performance props, an organization-wide PDC register, report-centre index, lead-source gateway status and follow-up automation settings through their existing modules. Each extension retains current workflow authorization. Broker-to-user attribution requires an explicit mapping before user-based ranking can be shown.

## Backend-to-frontend route contract

All GET routes below render Inertia components. Claude owns the component files. Mutations redirect back with validation errors in Laravel's standard error bag. Every route requires an authenticated member of the current organization; individual actions enforce their module permissions.

| Area                  | GET route name                                | Component                                           | Main props                                                                                                                                                                   |
| --------------------- | --------------------------------------------- | --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home dashboard        | `dashboard`                                   | `Dashboard`                                         | Existing `metrics`, `alerts`; new `filters`, `kpis`, `trend`, `commission_split`, `top_agents`, `lead_pipeline`, `lead_sources`, `deal_pipeline`, `cost_centres`, `insights` |
| Accounting dimensions | `accounting.dimensions.index`                 | `finance/AccountingDimensions`                      | `companies`, `branches`, `costCentres`, `canManage`                                                                                                                          |
| Approvals             | `approvals.index`                             | `approvals/Index`                                   | `items` (with owning workflow URL), `count`                                                                                                                                  |
| Tasks                 | `tasks.index`                                 | `tasks/Index`                                       | `tasks`, `members`, `canManage`                                                                                                                                              |
| Meetings              | `meetings.index`                              | `meetings/Index`                                    | `appointments`, `members`, `canManage`                                                                                                                                       |
| Off-plan              | `offplan.index`, `offplan.show`               | `real-estate/OffPlan`, `real-estate/OffPlanProject` | `developers`, `projects`, `canManage`; project details include `project`, `units`, `milestones`, `deals`                                                                     |
| Portal marketing      | `marketing.portals.index`                     | `marketing/Portal`                                  | `campaigns`, `publications`, `canManage`, `providerSelected`                                                                                                                 |
| Portal subscriptions  | `marketing.subscriptions.index`               | `marketing/Subscriptions`                           | `subscriptions`, `bills`, `canManage`, `canLinkBill`                                                                                                                         |
| Listing costing       | `marketing.costing.index`                     | `marketing/Costing`                                 | `portals`, `spend`, `canManage`                                                                                                                                              |
| Matchmaker            | `crm.matchmaker.index`, `crm.matchmaker.show` | `crm/Matchmaker`, `crm/MatchmakerLead`              | `leads`, `mode`, `canManage`; lead view includes `leadId`, `preference`, `matches`, `mode`                                                                                   |
| Staff services        | `hr.index`                                    | `hr/Index`                                          | `staff`, `documents`, `leaveRequests`, `members`, `canManage`                                                                                                                |
| GAIM                  | `gaim.index`, `gaim.bulletins.index`          | `compliance/Gaim`, `compliance/GaimBulletins`       | `summary`, `records`, `routingRules`, `canManage`, `statusOptions`, `eventOptions`; bulletins page has `bulletins`, `canManage`                                              |
| Broker performance    | `crm.broker-performance`                      | `crm/BrokerPerformance`                             | `brokers`                                                                                                                                                                    |
| PDC register          | `transactions.pdc`                            | `transactions/PdcRegister`                          | `cheques`, `filters`, `canManage`, `actionRoutePattern`                                                                                                                      |
| Report centre         | `reports.index`                               | `reports/Index`                                     | `reports`                                                                                                                                                                    |
| Lead gateway          | `crm.lead-gateway`                            | `crm/LeadGateway`                                   | `sources`, `metaPages`, `canConfigure`                                                                                                                                       |
| Follow-up settings    | `crm.follow-up-settings`                      | `crm/FollowUpSettings`                              | `reminderDays`, `escalationEnabled`, `stageRules`, `canConfigure`                                                                                                            |

The Home dashboard's `commission_split` stays `null` because the current commission ledger cannot distinguish company, agent, co-broker and referral shares. `top_agents` stays empty because broker parties are not mapped to user accounts. Finance KPIs based on unassigned invoices, bills or commissions remain organization-wide even when a company/branch filter is selected; only journal-derived revenue, expense, profit and cost-centre values have reliable dimensions. The frontend should label that scope clearly. Marketing publications are local validated packets, not confirmed portal publications; subscription usage is still zero until a portal sends usage data. GAIM `approved` is an internally recorded authority outcome with a reference, not electronic verification. These are data-source limits, not zero activity.

When the current user's organization role lacks `viewFinance`, financial KPI values, `commission_payable`, and `metrics.outstandingAed` are `null`; `cost_centres` is empty. When it lacks `viewTransactions`, deal and cheque KPIs, `trend`, and `deal_pipeline` are `null`. The current role matrix grants these view permissions to all organization roles; this check preserves the server boundary if that matrix is tightened later.
