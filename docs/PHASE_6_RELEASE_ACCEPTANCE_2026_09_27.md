# Phase 6 acceptance and release-readiness packet — 2026-09-27

User decisions: owner/device review has not happened; prepare the checklist. Hosting/domain are not selected; prepare a platform-neutral plan. No human acceptance or deployment is recorded.

## Selected implementation scope

Phase 6.1–6.5 delivers maintenance reliability, assigned-only technician job cards, optional manager confirmation and reasoned reopening, bounded opt-in preventive automation, multiple-store stock movements with negative-stock blocking, internal business-hours helpdesk/SLA workflows and filtered operations reports/CSV.

AMC/customer portal, stock valuation/transfers, optional provider integrations, saved filters and scheduled report delivery remain separate additions. This packet does not claim the entire original ERP roadmap is delivered.

## Local technical evidence

| Check                          | Result                                                                                                                            | Limit                                                                              |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Backend regression             | 277 tests / 4,065 assertions passed in the final readiness run                                                                    | Disposable test database, not human approval                                       |
| Integrated rehearsal           | 4 tests / 147 assertions passed: new operations journey, existing pilot journey, production-check tests                           | HTTP/domain automation; no physical-device review                                  |
| Static/format/frontend checks  | PHP formatting: 450 files passed; latest PHPStan zero, frontend formatting/lint, TypeScript and production build passed           | Local code/artifact checks                                                         |
| Health endpoint                | `/up` returned HTTP 200                                                                                                           | Local PHP container only                                                           |
| Local services                 | Web running; MySQL and Redis healthy and application connectivity passed                                                          | Web container has no Docker healthcheck; production services unselected            |
| Migrations                     | Local migrations through 77 applied                                                                                               | No staging/production migration evidence                                           |
| Scheduler                      | Six authorized commands registered, including preventive generation and SLA breach alerts; one local PHP scheduler worker running | Production supervision/persistence unverified                                      |
| Production configuration check | Exit 1; six development-setting failures: environment, debug, URL, queue, cache and mail                                          | Local configuration is not production-ready; credentials are not printed           |
| Temporary rehearsal cleanup    | Guarded read-only check: zero rows in all 19 checked testing tables                                                               | Automated-test fixtures only; a separate owner-review organization was added later |

See the completed implementation checkpoints linked in [the Phase 6 plan](PHASE_6_IMPLEMENTATION_PLAN_2026_09_27.md). Existing frontend/static results remain valid because this readiness increment changes tests/documentation only. The [runbook](PHASE_6_RELEASE_RUNBOOK.md) records staging, restore and production procedures; none are reported as executed.

## Owner/device review

Use an isolated disposable organization and invented people/properties/amounts. Do not alter live business records merely to perform acceptance. Record reviewer, date, desktop browser, physical phone/browser, observed result and issue reference for each scenario.

| Scenario                  | Expected result                                                                                                                                                               | Reviewer/date/device/result/issues |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Access                    | Manager sees organization jobs; technician sees only assigned jobs across maintenance, job cards, helpdesk, search, overview, reports and CSV; another organization is denied | Pending                            |
| Direct completion         | Unchecked confirmation option lets assigned technician complete after required checklist items; completion is read-only until manager reopens with reason                     | Pending                            |
| Manager confirmation      | Checked option submits first, blocks further changes and waits for manager confirmation; direct status changes cannot bypass it                                               | Pending                            |
| Job work/evidence         | Notes, required checklist, fractional labor/material entries and private evidence work on desktop and physical phone; void reasons and totals are clear                       | Pending                            |
| Preventive catch-up       | Opted-in plan preserves missed occurrences, generates one job per occurrence, inherits confirmation choice and advances its schedule; retry creates no duplicate              | Pending                            |
| Preventive failure        | Invalid links/settings show a failure; correcting settings permits retry; other plans continue                                                                                | Pending                            |
| Multiple-store parts      | Separate balances; referenced receipts; job issue/partial unused return; shortage blocked; technicians view own job parts but cannot record movements                         | Pending                            |
| Stock corrections         | Reasoned full-entry reversal respects consumed receipts/active returns and leaves history; retry does not duplicate stock                                                     | Pending                            |
| SLA acknowledgement/holds | Explicit acknowledgement stops response time; manager's reasoned hold pauses both clocks; holiday/working-day/timezone configuration is understandable                        | Pending                            |
| SLA closure/reopening     | Required confirmation continues resolution time until final completion; reopening creates a fresh cycle preserving prior result; repeated internal alerts do not duplicate    | Pending                            |
| Reports/exports           | Applied filters match all metrics/details/CSV; CSV includes all pages; costs stay separate by currency; preventive unknown dates and SLA old cycles are clear                 | Pending                            |
| Keyboard/mobile           | Controls have readable labels, keyboard focus and error feedback; tables scroll without losing controls; confirmation/reopening/CSV work on the chosen physical device        | Pending                            |

Owner decision: accept / accept with issues / reject. Reviewer: ______ Signed/date: ______ Issue references: ______

## Finance and earlier pilot acceptance

Retain the [pilot owner/leasing review](PILOT_RELEASE_SIGNOFF_PACKET_2026_09_21.md), [leasing checklist](LEASING_ACCEPTANCE_CHECKLIST.md), [finance evidence checklist](PHASE_5_ACCOUNTANT_EVIDENCE_CHECKLIST.md) and [written accountant sign-off](PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md). No written accountant approval or actual filing comparison is recorded. Record corrections and the selected operational-use boundary before relying on financial/tax outputs.

## Operator/staging acceptance

Hosting/domain: unselected. Operator: ______ Staging target: ______ Date: ______

Follow the runbook and attach configuration, migration/backup, restore/checksum, worker/scheduler persistence, monitoring, TLS/private-storage/security and staging-rehearsal evidence. Record issues before a release decision.

Operator decision: ready / blocked. Evidence: ______ Signed/date: ______

## Completion decision

Selected implementation scope is locally delivered. Local readiness review and packet preparation are complete. The packet's 19 local links were verified. Owner/device, accountant/filing and target staging/production gates remain open. Passing local automation does not close those gates or authorize production deployment.

## Retained owner-review organization

The user subsequently selected a disposable local organization with sample jobs for the owner/device review. Organization 26 (DISPOSABLE Phase 6 Review etqgqnnfpo), synthetic owner/technician accounts and six jobs are now retained in the local application database. Follow [the review guide](PHASE_6_OWNER_REVIEW_GUIDE_2026_09_27.md). Authenticated HTTP checks passed for both logins, six/five job visibility, owner-only job denial, technician stock denial, report/CSV access, two preserved SLA cycles and store balances 4.000/2.000. Preventive automation is off. Generated passwords are kept only in an ignored private local file with mode 0600. No external messages were sent. Browser automation timed out; owner/physical-device acceptance remains pending. This retained dataset is separate from the cleared testing database.
