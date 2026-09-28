# Provider-neutral integration contracts

The owner selected provider-neutral adapters and local validation on 2026-09-27. No live payment, messaging, accounting, syndication, SSO or signature provider is selected.

`App\Domain\Integrations\Contracts\OutboundProvider` accepts versioned packets with an organization ID and UUID operation key. `LocalOutboundProvider` validates payment, balanced journal export, email, WhatsApp template, listing syndication and signature request payloads. It returns `local_validated` and a deterministic SHA-256 digest. It never sends a request, records payment, posts a journal or marks a document signed. `deliver()` deliberately rejects activation until a named adapter is configured.

`SingleSignOnProvider` separates configuration checking, authorization redirects and verified identity resolution. The local implementation validates HTTPS configuration shape, but cannot authenticate identities or create employee memberships. Named OIDC implementations must verify issuer/audience, state, nonce, PKCE and token signatures; never match or grant membership solely from an unverified email assertion.

Packets are internal application contracts, not public API authorization. Module-owned packet builders must check the actor, organization and current subject state before passing actual records to an adapter. A packet's supplied organization ID alone proves no access rights. Payload shape validation deliberately does not claim that an invoice, listing or document exists or is approved.

Supported outbound payloads:

| Capability        | Payload                                                                                                         |
| ----------------- | --------------------------------------------------------------------------------------------------------------- |
| payment           | invoice_id, reference, positive decimal-string amount, uppercase currency                                       |
| accounting_export | reference, currency, posted_on, lines with account_code/debit/credit; positive one-sided lines and equal totals |
| email             | to, subject, text                                                                                               |
| whatsapp          | E.164 to, template identifier, en/ar language, string parameters                                                |
| property_portal   | listing_id, reference, rent/sale purpose, price, currency, title                                                |
| signature         | document_id, version_number, exact immutable file SHA-256, distinct signer emails                               |

The schema rejects unexpected fields, including credentials accidentally supplied in payloads. Real adapters must obtain credentials from private deployment configuration, preserve idempotency under retries, authenticate webhook callbacks, validate provider receipts and reject unknown or organization-mismatched references. Finance postings and signed-document acceptance must still go through module workflows and current permissions. Remote signature acceptance also requires matching the requested document version and retaining provider evidence; local validation is not legal signature evidence.

Outstanding live gates: named providers, credential provisioning, HTTPS callbacks, consent/template rules where applicable, delivery/receipt reconciliation, external acceptance and deployment. These gates remain deferred by the owner's provider-neutral choice.
