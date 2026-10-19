# Modular Laravel + Vue App Plan

## App concept

Build **Project Workbench**, a project and task management app used to compare how different coding agents implement consistent Laravel + Vue modules. The Laravel 13 host, Vue 3/Vite setup, SQLite defaults, and Projects module are already in place. Local setup instructions are in [README.md](README.md).

The roadmap contains **25 model-backed modules**. Each contributes one primary Eloquent model, with a final Dashboard page that aggregates data without owning another model. This gives the benchmark more than 20 distinct models and a gradual mix of CRUD, relationships, validation, and UI work.

## Module contract

Every feature module lives under the project-root `Modules/` folder and follows this shape:

```text
Modules/<Module>/
  Models/<Model>.php
  Controllers/<Module>Controller.php
  Views/index.blade.php
  Components/<Module>Index.vue
  route.php
```

Keep migrations in Laravel's standard `database/migrations/` directory so Artisan discovers them. Use ordered migration timestamps that respect the dependency order below.

For each module:

1. Put its primary Eloquent model in `Modules/<Module>/Models` and its page controller in `Modules/<Module>/Controllers`.
2. Put named web routes in `Modules/<Module>/route.php`. `ModulesServiceProvider` discovers the file and applies `web` middleware. Name routes with a plural module prefix, such as `tasks.index`.
3. Put the Blade page in `Modules/<Module>/Views`; the provider registers it under a kebab-case view namespace, such as `tasks::index`.
4. Put its mountable Vue page in `Modules/<Module>/Components/<Module>Index.vue`. `resources/js/app.js` discovers and mounts these pages.
5. Render the Blade view from the controller. Pass page data and route URLs as JSON props; keep the CSRF token available for Vue mutations.
6. Validate writes in Laravel and return JSON. Use Laravel's normal 422 validation response and appropriate status codes.
7. Use REST-style create, list, update, and delete behavior where it fits the entity. Event and lookup modules can use narrower operations, such as mark-read or read-only history.
8. Preserve other modules and shared host files. Add module-owned code and migrations without replacing the shared route provider or Vue entrypoint.

`App\Models\User` is the host identity model used by user-related modules; it is not counted in the 25 feature models. Keep it there unless a later plan revision explicitly moves authentication into a module. Before implementing Comments (module 5), add session-based login/logout around this model and protect routes that read or write user-owned data.

## Model-backed roadmap

Implement modules in order. Do not start a module until its listed prerequisites exist. The “benchmark focus” column signals the main implementation patterns to compare across agents.

| # | Module | Primary model | Responsibility and key behavior | Prerequisites | Benchmark focus |
| ---: | --- | --- | --- | --- | --- |
| 1 | Projects | `Project` | Create and manage projects with name, description, status, and due date. **Complete.** | — | Basic CRUD, validation, Vue forms |
| 2 | Tasks | `Task` | Manage project tasks with title, description, status, priority, due date, and optional assignee. **Complete** (implemented with model: agnes/agnes-3.0-pro). | Projects | Belongs-to relations, enums, filters |
| 3 | Milestones | `Milestone` | Track named goals and due dates within a project; allow linking related tasks. | Projects, Tasks | Parent-child relations, date validation |**Complete** Implemented by Solar Pro 3 (Claude Code) — marked as DONE |
| 4 | Labels | `Label` | Define reusable names and colors, and attach or remove labels from tasks. **Complete** (implemented with model: zm/atria-asi/atria-dawn-preview, running as Claude Code). | Tasks | Many-to-many relationships and pivot sync |**Complete** Implemented by ATRIA AI MAX — marked as DONE(THis took 3/4hours, longer than expected, check file modification date time) |
| 5 | Comments | `Comment` | Add, edit, and delete task discussion with author and timestamps. | Tasks, host User | Ownership fields, chronological lists |**Complete** Implemented by kr/qwen3-coder-next (Qwen 3 Coder Next) — marked as DONE |
| 6 | Attachments | `Attachment` | Upload and remove files attached to a project, task, or comment; store metadata and use Laravel Storage. | Projects, Tasks, Comments | File validation, storage, polymorphic relation |**Complete** Implemented by GPT 4.0 Mini  — marked as DONE |
| 7 | Checklists | `Checklist` | Add and reorder named checklists on a task. | Tasks | Nested resources, ordering |**Complete (Checklist module implemented by GPT-5.4 nano — marked as DONE)** |
| 8 | Checklist Items | `ChecklistItem` | Add, edit, reorder, and complete items within a checklist. | Checklists | Boolean state, scoped model binding ||**Complete** Implemented by GPT 5.4 NANO — marked as DONE(Took too long, check file creation dates, times)|
| 9 | Task Dependencies | `TaskDependency` | Record that one task blocks or depends on another; reject self-links and duplicate links. **Complete** (implemented with model: agnes/agnes-3.0-pro — one row per directed edge, two `BelongsTo` relations onto `Task`; self-link, duplicate-pair, same-project and cycle invariants enforced on write, with a pair-unique index as the database backstop). | Tasks | Self-referencing relations, invariant validation |
| 10 | Time Entries | `TimeEntry` | Record time spent on a task with user, duration, date, note, and billable flag. | Tasks, host User | Numeric/date validation, aggregate totals |
| 11 | Teams | `Team` | Create and manage named teams with an owner. | Host User | Owner relations, authorization boundary |
| 12 | Team Members | `TeamMember` | Add or remove users from teams and manage membership state. | Teams, host User | Pivot model, uniqueness, authorization |
| 13 | Invitations | `Invitation` | Invite an email address to a team with an expiring, single-use invitation. | Teams, Team Members, host User | Token lifecycle, expiry, secure validation |
| 14 | Project Members | `ProjectMember` | Add users to projects and set their project access level. | Projects, Teams, Team Members, host User | Scoped membership and access checks |
| 15 | Roles | `Role` | Define team-scoped roles and assign them to team members. | Teams, Team Members | Scoped uniqueness, role assignment |
| 16 | Permissions | `Permission` | Maintain the application's permission keys and descriptions. | Roles | Stable keys, lookup/catalog UI |
| 17 | Role Permissions | `RolePermission` | Assign or revoke permissions for a role. | Roles, Permissions | Many-to-many sync, pivot validation |
| 18 | Workbench Notifications | `WorkbenchNotification` | List a user's in-app notices and mark one or all as read. Use a distinct model name to avoid confusion with Laravel's notification class. | Host User | Read state, bulk action, pagination |
| 19 | Activity Logs | `ActivityLog` | Show append-only project and task events, including actor, event type, and safe summary data. | Projects, Tasks, host User | Read-only timeline, polymorphic subject |
| 20 | Project Templates | `ProjectTemplate` | Create, edit, and duplicate reusable project templates. | Projects, host User | Duplication flow, transaction boundary |
| 21 | Template Tasks | `TemplateTask` | Define ordered task entries and optional due-date offsets inside a project template. | Project Templates, Tasks | Child CRUD, ordered collection |
| 22 | Custom Fields | `CustomField` | Define typed custom fields (text, number, date, or select) available to projects. | Projects, host User | Type rules, structured validation |
| 23 | Custom Field Values | `CustomFieldValue` | Store and edit a custom field's value for a project. | Custom Fields, Projects | Dynamic validation, uniqueness |
| 24 | Saved Views | `SavedView` | Save a user's named filters, sort order, and visible columns for project or task lists. | Projects, Tasks, host User | JSON settings, allow-listed filters |
| 25 | Report Definitions | `ReportDefinition` | Save report type, scope, filters, and date range; display a generated preview. | Projects, Tasks, Milestones, Time Entries, host User | Aggregation, query safety, date ranges |
| 26 | Dashboard | *(no model)* | Show project/task status counts, upcoming milestones, and recent activity. Keep this a read-only aggregate page. | Projects, Tasks, Milestones, Activity Logs | Cross-module integration, query efficiency |

## Delivery phases

### Phase 0 — Host and baseline

- **Host setup — complete:** Laravel, Vue, Vite, SQLite, module route/view discovery, and component discovery are configured.
- **Projects — complete:** establishes the model, migration, controller, route, Blade, and Vue conventions.

### Phase 1 — Project work

Implement modules 2–10 in sequence: Tasks, Milestones, Labels, Comments, Attachments, Checklists, Checklist Items, Task Dependencies, and Time Entries. These modules cover common CRUD, parent-child and many-to-many relations, uploads, validation rules, and self-relations.

### Phase 2 — Collaboration and access

Implement modules 11–17: Teams, Team Members, Invitations, Project Members, Roles, Permissions, and Role Permissions. Use the existing host User model and session authentication. Add `team_id` to Projects with an additive migration and decide how existing projects are assigned before enforcing team scope. Apply authorization to every read and write so a team member cannot access another team's records by changing an ID.

### Phase 3 — History and reuse

Implement modules 18–21: Workbench Notifications, Activity Logs, Project Templates, and Template Tasks. Keep activity logs append-only and create a template's child tasks in a database transaction.

### Phase 4 — Customization and reporting

Implement modules 22–25: Custom Fields, Custom Field Values, Saved Views, and Report Definitions. Validate dynamic values against field types and allow-list saved filter/sort keys before building queries.

### Phase 5 — Integration review

Build the read-only Dashboard after its source modules exist. Review module boundaries, migration order, route names, relationship constraints, authorization, validation, Vue mounting, and whether shared files were changed only for necessary integration.

## Agent benchmark protocol

Use one module at a time so each agent sees the same baseline and the next task has a clear dependency boundary:

1. Give the agent this plan and the exact next module row.
2. Ask it to implement only that module, its migration, and the smallest required integration changes.
3. Compare the model, controller, routes, Blade view, Vue component, naming, validation, and error handling against Projects and this contract.
4. Check that it preserved earlier modules and did not create files for future modules.
5. Record any plan adjustment before another agent starts work.

Compare coding style separately from feature completeness: the benchmark should expose the same module shape while the model relationships and validation patterns grow in difficulty.

## Current scope

The Laravel host and modules 1–9 (Projects, Tasks, Milestones, Labels, Comments, Attachments, Checklists, Checklist Items, Task Dependencies) are implemented. Modules 10–26 remain to be implemented.

**Blocker for the shared build, owned by module 3:** `Modules/Milestones/Components/MilestonesIndex.vue:251` closes an `<option>` with `</h2>`, so `npm run build` fails for every module, including ones that compile fine on their own. Task Dependencies (module 9) was verified by compiling its own SFC with `@vue/compiler-sfc` (no template errors) and by driving the HTTP kernel end to end; the Milestones typo was left untouched because it is outside module 9's boundary. Whoever owns Milestones should change it to `</option>`.

