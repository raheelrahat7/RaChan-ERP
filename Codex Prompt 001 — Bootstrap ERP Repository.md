We are beginning development of a new enterprise real-estate ERP.

Before changing anything:

1. Read AGENTS.md.
2. Read docs/MASTER_DEVELOPMENT_PLAN.md.
3. Inspect the repository.
4. Follow the Laravel 13 documentation and existing project conventions.

If either required file is absent, stop and report the missing prerequisite. Do not invent its contents or continue implementation.

Technology stack:

Laravel 13  
PHP 8.5  
MySQL 8.4  
Inertia.js 3  
Vue 3  
TypeScript  
Tailwind CSS  
shadcn-vue  
Redis

Architecture must be a modular Laravel monolith.

For this task ONLY, implement Phase 0: project foundation.

Do not implement CRM, properties, leasing, accounting or any ERP business modules yet.

Requirements:

- Create/configure the Laravel application using the official Laravel 13 Vue starter with Vue 3, Inertia 3, TypeScript, Tailwind CSS, and shadcn-vue. Use Laravel's built-in authentication option; do not select WorkOS authentication.
- Retain the starter's login, registration, password-reset, and email-verification flows.
- Configure and document MySQL 8.4 environment variables in `.env.example`. Provide the project-standard local-development service configuration only if it is required by AGENTS.md or MASTER_DEVELOPMENT_PLAN.md.
- Configure Redis for both cache and queues: set the documented environment defaults to Redis and retain safe local fallbacks where project conventions require them. Provide a Redis service only if required by the project-standard local-development setup.
- Create the main authenticated ERP layout.
- Create a responsive, accessible collapsible sidebar with a persisted collapsed state, active-route styling, and labels or tooltips when collapsed. Restrict navigation to Dashboard and existing starter-kit account/settings pages; do not add business-module navigation.
- Create top navigation.
- Create placeholder Dashboard page.
- Establish reusable frontend folders:
    - Components
    - Composables
    - Layouts
    - Pages
    - Types
- Establish backend/domain folders defined in MASTER_DEVELOPMENT_PLAN.md.
- Reuse and extend starter-kit or shadcn-vue components before adding new frontend primitives. Add only the following reusable primitives that are not already available:
    - page heading
    - status badge
    - confirmation modal
    - empty state
    - loading state
    - pagination
- Configure code formatting and expose its check command in project scripts or documentation.
- Configure TypeScript validation with a `typecheck` script.
- Configure frontend linting with a `lint` script.
- Configure the Laravel test framework using the starter's existing test conventions.
- Add an authenticated dashboard feature test that verifies guests are redirected to login and authenticated users receive HTTP 200 with the Dashboard Inertia page.
- Ensure the frontend production build succeeds.

Do not prematurely install large numbers of third-party Laravel packages.

Do not build speculative abstractions.

Do not implement business database tables yet.

At completion:

1. Run all Laravel tests (`php artisan test`).
2. Run TypeScript validation (`npm run typecheck`).
3. Run frontend linting (`npm run lint`).
4. Run the configured formatter check.
5. Run the frontend production build (`npm run build`).
6. Explain the final folder structure.
7. List direct dependencies added by the implementation separately from dependencies included by the official starter or installed transitively.
8. List every file changed.
9. Report any decision requiring product-owner approval.

Stop after Phase 0.

Do not start Phase 1 automatically.
