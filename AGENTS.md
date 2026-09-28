# Repository Guidance

## Scope and workflow

- Read `docs/MASTER_DEVELOPMENT_PLAN.md` before beginning implementation.
- Implement only the phase explicitly requested by the active task.
- Do not begin a later phase without explicit approval.
- Prefer Laravel framework conventions and first-party features. Add a dependency only when the requirement cannot be met cleanly with the existing stack.

## Architecture

- This application is a modular Laravel monolith. Business capabilities belong in `app/Domain/<Module>`; do not create cross-module business logic in controllers, Inertia pages, or global helpers.
- Keep HTTP concerns in `app/Http`, shared application concerns in `app/Support`, and module-owned application logic inside its domain module.
- Do not add business database tables until the relevant phase authorizes them.

## Frontend

- Use TypeScript and Vue single-file components.
- Reuse the Laravel Vue starter and shadcn-vue components before introducing custom primitives.
- Put shared UI in `resources/js/Components`, reusable stateful logic in `resources/js/Composables`, layouts in `resources/js/Layouts`, route pages in `resources/js/Pages`, and shared client types in `resources/js/Types`.
- Preserve accessibility and responsive behavior when modifying navigation or interactive components.

## Quality gates

- Keep formatting, linting, TypeScript validation, tests, and production builds passing.
- Add or update focused tests for changed behavior.
- Do not commit secrets; keep local configuration values in `.env` and documented safe defaults in `.env.example`.
