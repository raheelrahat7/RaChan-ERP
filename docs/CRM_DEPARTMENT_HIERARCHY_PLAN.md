# CRM department, subdepartment and team scope

## Confirmed behavior

- Each organization may maintain separate departments, subdepartments within departments, and teams within subdepartments.
- An ordinary team member continues to see only leads assigned directly to that member. Team membership alone does not expose colleagues' leads.
- A department manager sees their own assigned leads and leads assigned to members in that department, including its subdepartments and teams.
- A subdepartment manager or supervisor sees their own assigned leads and leads assigned to members in that subdepartment and its teams. Any eligible organization member may be appointed to one of these scopes.
- The existing organization `Manager` role becomes department-scoped for CRM lead access. A Manager without a department view grant sees only their own assigned leads until granted more. Owners and Administrators retain organization-wide access. They may grant any individual extra department, subdepartment, or whole-organization view access. Whole-organization grants include unassigned leads; other restricted users do not see them.

## Proposed data and access rules

Store organization-owned departments, subdepartments, teams, member placements and per-user view grants. Every foreign key and membership assignment must be checked against the current organization. A member has one primary team placement per organization for this increment, so an assigned lead has one unambiguous department/subdepartment/team scope. Additional department or subdepartment grants may be held concurrently; effective visibility is the union of the member's own assigned leads and every granted scope. No lead ownership is inferred from who created or moved a lead.

Organization Owners and Administrators can manage the hierarchy and grants. Existing Managers cannot manage organization membership or expand their own CRM scope. Keep changes audited, including previous and new placement/scope. Hierarchy units are archived rather than deleted; an archived unit retains existing placement visibility until members are reassigned, but cannot receive new placements.

Apply one CRM-owned visibility query consistently to the lead board/list and counts, related activities and follow-ups, pipeline reports, dashboard CRM counts and lead exports. Reject URL filter choices outside the viewer's scope. Existing Managers have `ManageCrm`; direct CRM actions on an existing lead must also require that lead to be in their scope, including movement, conversion, details, assignment and follow-ups. A view grant alone does not grant CRM mutation permission. New lead creation and assignment must not place a lead beyond the actor's scope. Existing organization roles retain their unrelated module permissions in this CRM increment. No cross-pipeline movement or automation is part of this increment.

## Migration and rollout

Existing organizations start with no departments, placements or grants. Existing Managers therefore retain visibility only of leads assigned to themselves until an Owner or Administrator grants a wider scope. Existing assigned leads and their history remain unchanged. Hierarchy setup is available to Owners and Administrators. No automatic department inference from current assignments or lead data is safe.

## Acceptance checks

Verify own-lead, team-member, subdepartment supervisor, department manager and organization-wide visibility; existing Managers with no appointment; cross-organization IDs; overlapping appointments; unassigned leads; reassignment/removal/archive effects; direct filter and mutation bypasses; and the same scope on pages, alerts, reports and CSV exports. Validate PHP formatting, focused and full backend tests, frontend formatting/lint, TypeScript and production build.

The user explicitly requested implementation of the hierarchy and per-user extra visibility on 2026-09-19. Migration `2026_09_19_000054_create_crm_hierarchy.php` adds the hierarchy, placement and grant tables. Owners and Administrators manage them at `/crm/hierarchy`, linked from CRM leads. No cross-pipeline movement or automation is included.

Implemented and migrated locally. Hierarchy creation, one-team placement, user grants/revocation and archive/activation are audited. Managers no longer receive organization-member management permission, so they cannot promote themselves to bypass CRM scope. Full backend regression passed: 131 tests (1,718 assertions). PHP formatting, frontend formatting/lint, TypeScript and production build passed. Owner business/device acceptance remains pending.

The later approved per-user action increment lets Owners and Administrators grant CRM editing to an individual Member or Viewer. This changes `manageCrm` only; pipeline configuration, hierarchy management, other modules, and lead visibility remain governed by their existing rules. A granted user can act only on leads visible through their own assignment and explicit view grants; restricted lead creation assigns the creator. Revoking the grant or removing the member ends the extra access. The change is audited and stored in `crm_edit_grants`.
