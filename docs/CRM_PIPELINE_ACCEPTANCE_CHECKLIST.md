# CRM pipeline board acceptance review

## Automated local browser review — 2026-09-16

Checked the production-built application at `http://localhost:8000/crm/leads` using headless Google Chrome, an isolated temporary organization, and temporary verified owner credentials. No existing business records were changed. All temporary organization data, the test user, and its sessions were removed after each run.

Passed:

- Converted cards have no Move button. Leads on an archived stage remain visible and can move out.
- Real mouse dragging from New Leads to Assigned Leads opens a dialog with the destination preselected. Before confirmation, the database remains unchanged. Cancel leaves the stage and history unchanged.
- A scripted Lost drop through the browser drag handlers shows the required reason selector. Native form validation blocks submission without a reason. Confirming with a valid reason changes the stage and adds history without creating another customer.
- Enter activates the Move button and submits the confirmation form after the destination is selected through browser automation. Reopening Lost clears the current reason and retains historical loss records. Focus returns to the moved card.
- Assignee filtering restricts the cards. Stage filtering restricts visible cards while retaining correctly scoped pipeline/assignee totals.
- At a 375 × 812 viewport the page does not overflow horizontally; the board provides its own horizontal scrolling region.

The temporary driver used Chrome DevTools Protocol and standard-library Python, with no application dependency or authentication bypass. Native keyboard selection inside the platform select popup was not automated; selecting the destination used a DOM change event.

## Fixes from review

- Stage headings now use `board-column-stage-*` IDs, separate from the dialog's `board-stage-*` selector IDs. A lead ID matching a stage ID can no longer create duplicate IDs that confuse labels or selectors.
- Successful movement explicitly clears the board's request state before closing the dialog, ensuring the next movement starts with cancellation available.
- Frontend formatting/lint, TypeScript validation, and the production build passed after these changes. The existing full backend baseline is 120 passing tests (1,388 assertions); these fixes change no backend behavior.

## Owner acceptance still pending

Use sample leads to confirm the business process and your actual device/browser behavior:

- Move using only Tab, native stage/reason selection keys, and Enter; verify focus returns to the card.
- Check touch movement and dialog usability on your phone.
- Rename/reorder stages, then verify the board and preserved historical names.
- Confirm the organization roles and default stages/reasons fit your team.

This review completes technical browser checks. It does not mark owner business sign-off or authorize later team visibility, reporting, or automation increments.

## Stage entry rules — 2026-09-16

The approved rules increment passed local Chrome acceptance with a fresh isolated temporary organization:

- Owner stage configuration saved selected source stages, owner-only entry and required Phone.
- The board Move form explained the missing Phone and disabled movement.
- Lead list details editing saved Phone; the refreshed board allowed the move and added one history entry without creating another customer.
- Existing native drag cancellation, lost-reason validation, keyboard activation/submission, focus restoration, filters and mobile width checks also passed.
- Temporary records, credentials, organization and sessions were removed.

Backend regression: 125 tests, 1,463 assertions. Four frontend board tests, PHP formatting, frontend formatting/lint, TypeScript and production build passed. Backend tests also cover foreign/self source IDs, configuration permissions, conversion restrictions (including already-Won leads), reopening, creation requirements, referenced-stage deletion protection and tenant-scoped details editing. Owner business/device acceptance remains pending.

## Pipeline reporting — 2026-09-16

Local Chrome confirmed the report renders and shows known isolated-fixture totals: three leads, two open, one Won, one explicit customer conversion and 33.3% new-lead conversion. Selecting the current assignee reduced the scope to one lead and zero conversions. Report tables stayed within the mobile page width. All temporary records and sessions were removed.

Focused tests also verified past Lost outcomes despite later reopening, preserved lost-reason names after renaming, open intervals clipped around a Lost gap, customer conversion distinct from manual Won, zero-denominator rates, untracked data and tenant/filter isolation. Full backend regression passed: 128 tests (1,525 assertions), along with PHP formatting, frontend formatting/lint, TypeScript and build. Owner business/device sign-off remains pending.
