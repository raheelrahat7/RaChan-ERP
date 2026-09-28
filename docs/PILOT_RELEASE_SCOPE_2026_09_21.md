# First organization pilot scope — 2026-09-21

The user approved the recommended first-release boundary after the [release gap audit](RELEASE_GAP_AUDIT_2026_09_21.md). This is a **local implementation and acceptance scope**, not approval to deploy or claim regulatory compliance.

## In scope

- Organization membership and tenant-scoped permissions.
- CRM leads in Kanban and list views, contacts, pipeline history, assignment routing, check-in, quotas, follow-ups and in-app notifications.
- Properties, buildings, units, basic listings, owners/tenants/brokers and reservations.
- Lease and sales agreements, handovers, lease service charges, deposits, cheques and operational Ejari tracking where the existing rules apply.
- AED invoice and vendor-bill posting, payments, detailed ledger, accounting periods, manual CSV bank reconciliation and internal reports.
- Maintenance requests, vendors, costs, preventive plans, private documents and compliance expiry alerts.

## Outside the pilot unless separately selected

- FTA submission or a claim that internal VAT/corporate-tax reports are compliant filings; accountant/regulatory sign-off is required before using them operationally.
- Payment gateway, WhatsApp/email campaigns, property-portal syndication, e-signature or other external provider adapters.
- AMC, SLA/helpdesk/customer portal, construction/BOQ, contractor billing, fleet, Arabic localization, SSO and general-purpose API.
- Live Meta Lead Ads delivery until credentials, Page permissions and a public HTTPS callback are configured and verified. Its local configuration UI remains available.

## Release gates still open

1. Owner acceptance of CRM on a physical device and the [leasing scenarios](LEASING_ACCEPTANCE_CHECKLIST.md); record issues and fixes.
2. Production inputs and controls in [DEPLOYMENT_READINESS.md](DEPLOYMENT_READINESS.md): hosting/domain, MySQL, Redis, mail, private storage, backups/restore, monitoring, security review and a release rehearsal. The read-only `php artisan release:check` now verifies basic production settings, but cannot replace these operational checks.
3. Accountant review before relying on financial statements or tax workflows beyond internal preparation.

The automated cross-module journey is covered by [PilotReleaseJourneyTest.php](../tests/Feature/PilotReleaseJourneyTest.php): lead → contact → reservation → lease → lease-linked invoice → AED payment → bank settlement → maintenance, with tenant isolation and bank journal debit assertions. It passed on 2026-09-21 alongside the full backend suite (156 tests, 2,153 assertions). A human rehearsal in a disposable organization remains part of owner acceptance and release rehearsal.

No owner/device, accountant, live Meta or production sign-off is recorded yet.

The current local evidence and the remaining human sign-off fields are collected in [PILOT_RELEASE_SIGNOFF_PACKET_2026_09_21.md](PILOT_RELEASE_SIGNOFF_PACKET_2026_09_21.md).

## Selected Phase 6 extensions — 2026-09-27

The user subsequently selected assigned-only technician job cards with optional manager confirmation, preventive automation, multiple-store quantity tracking, internal business-hours helpdesk/SLA workflows and operations reporting. These extensions are implemented locally. AMC/customer portal and optional provider integrations remain unselected. The latest readiness evidence and owner/device review fields are in [the Phase 6 acceptance packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md); staging/production and accountant gates remain open.
