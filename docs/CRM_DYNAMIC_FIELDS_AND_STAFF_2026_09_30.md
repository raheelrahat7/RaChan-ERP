# CRM and staff increment (30 September 2026)

This increment extends the existing Z1 CRM and HR modules. It does not import records or field definitions from the Bitrix reference account.

## CRM delivered

- Owner/administrator field settings with stable keys, type-specific validation, role visibility and editing, and archive without deleting saved values.
- Custom values on lead creation, editing, details, list/search/filter, and CSV import mapping. A canonical `City` column avoids a duplicate custom field for that built-in concept.
- Combined server-side lead and activity filters with a searchable field picker, active filters, pagination, and permission-scoped queries.
- Existing pipeline Kanban and stage rules retained; lead list and detail pages include custom data, activities, and history.
- CSV preview, mapping, validation, duplicate handling, row errors, and a final summary. Files are limited to UTF-8 CSV, 2 MB and 1,000 rows per batch.
- On the import mapping screen, owners and administrators can create a custom field from a source column, choose its type, options, organization-wide required setting, and visibility. Each import can separately require mapped columns; blank values then fail that row without changing the global field definition. Multi-select CSV cells use `|` between choices.
- Owners and administrators can export a permission-checked CSV with a chosen set of built-in and custom columns. Contact details and activity notes are optional and unselected by default. Exported cells are protected against spreadsheet-formula execution. The CRM currently has activity notes, not a stored chat-message stream, so chat export is not offered.
- Stage-entry automation for notifying the assignee or creating a follow-up, with a unique execution record per rule and stage event. Rules cannot mutate stages, preventing automation loops.
- Lead history from organization audit events, including throttled views. Sensitive field values are not copied into audit details.

## Reference-field handling

The supplied screenshots show labels, not a schema export or authoritative value lists. The leading numbers in labels such as “(4) Dealer Category” appear to be reference ordering and are not used as keys. No duplicate `Source`, `City`, or `Last name` field is created. Owner/administrator users can add the N7-specific fields using field settings once their types, options, and intended visibility are confirmed. “Admin” labels should be configured with restricted view/edit roles; this increment does not expose them by default. Reference-only fields that depend on unavailable data (for example customer journey, UTM attribution, CRM form provenance, observers, contacts/companies associations, and activity source) are not presented as working filters until their data sources exist in Z1.

## ERP-wide staff

Hiring an existing organization member creates a staff profile. The staff list and profile show employment status and last active session. Owner/administrator users can dismiss from the profile with an effective date and reason; dismissal revokes organization membership and CRM access. New people first need an organization invitation/account through the existing membership workflow. Historical staff documents and leave requests remain attached to the dismissed profile.

## Validation and follow-up

Run `php artisan test`, `vendor/bin/phpstan analyse`, `npm run types:check`, and `npm run build`. User acceptance at normal browser zoom and a Bitrix data export are still needed to define and import every N7-specific field, option list, and legacy record without guessing. The private Bitrix page could not be read from this development environment.
