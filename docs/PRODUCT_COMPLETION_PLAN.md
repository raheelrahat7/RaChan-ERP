# Product Completion Plan

The source-based status of the 15 sections below and a recommended first-release boundary are recorded in [RELEASE_GAP_AUDIT_2026_09_21.md](RELEASE_GAP_AUDIT_2026_09_21.md).

## Delivery principle

Extend the current tenant-scoped ERP as a modular Laravel monolith. Each delivery slice includes tenant isolation, authorization, validation, audit events, migrations, UI, and tests.

## Workstreams

### 1. Real-estate core

1. Owners/landlords, tenants, broker profiles, and commission plans.
2. Listings, availability, inquiries, broker pipeline, and portal syndication contracts.
3. Lease renewals, move-in/out inspections, vacancies, service charges, and owner statements.
4. UAE-specific workflows: Ejari, PDC, and compliance records. **Requires approved regulatory and retention rules.**

### 2. Finance and compliance

1. Chart of accounts, journal lines, accounting periods, and period locks.
2. Accounts payable, procurement, vendors, credit notes, bank reconciliation, budgets, and financial statements.
3. VAT and corporate-tax configuration, filings, and reporting. **Requires approved tax policy and accountant sign-off.**

### 3. Operations

1. Vendors, technicians, job cards, maintenance costs, spare parts, and preventive schedules.
2. AMC contracts, SLA policies, service escalations, helpdesk, and customer portal.
3. Construction BOQ, contractor billing, projects, fleet, and assets.

### 4. Platform

1. Notifications, approval workflows, search, imports, exports, activity feeds, API, webhooks, and scheduled jobs.
2. Document versioning, deletion policy, previews, expiry alerts, e-signatures, and private object storage.
3. Reporting, saved filters, scheduled PDF/XLSX delivery, Arabic localization, mobile field workflows, SSO, and observability.

### 5. Integrations and release

1. Contracts/adapters for payment gateway, accounting export, WhatsApp/email, property portals, and e-signature.
2. Production backups, queue monitoring, security review, penetration testing, restoration drills, and deployment automation.

## Implementation order

Begin with real-estate core records, then operational service delivery, finance depth, platform services, and provider integrations. Regulatory modules remain disabled until their approved rules are documented.
