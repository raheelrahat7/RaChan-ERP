# Arabic first-release scope

The owner selected full Arabic customer-portal and technician workflows first, with specialized finance wording left for accountant review. Other staff screens retain English where specialized translations are not approved.

Implemented scope: English/Arabic language selection for guests and users, persistent user preference, root language/direction metadata, RTL employee sidebar and direction-aware shared primitives, translated navigation/common controls, sign-in/recovery/verification instructions, tenant/landlord invitations and views, service submission, maintenance list/filter states, job-card notes/checklists/completion, SLA explanations and acknowledgement, evidence/version/archive controls, and their normal validation messages. User-entered organization/property/person names, descriptions, notes, references and document names are preserved. Submitted status/priority values and workflow rules remain canonical and unchanged.

`resources/js/locales/ar.json` is the canonical literal translation catalog. After editing it, run `php scripts/sync-arabic-catalog.php` to update Laravel's `lang/ar.json`. Conventional validation/auth/password/pagination messages are in `lang/ar/*.php`. Unknown keys fall back to English rather than disappearing.

Human acceptance remains open: an Arabic-speaking reviewer should check terminology, tenant/landlord finance summaries, mobile RTL focus/keyboard behavior, mixed Arabic/Latin names and date/currency readability. No physical-device or linguistic sign-off has been recorded. This does not claim complete Arabic finance/staff localization, Arabic regulatory wording approval, or verified Arabic shaping in generated PDF exports; operational export headings remain English in this increment.
