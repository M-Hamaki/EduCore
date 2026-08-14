# Implementation Plan: [FEATURE]

**Branch**: `[###-feature-name]` | **Date**: [DATE] | **Spec**: [link]

**Input**: Feature specification from `/specs/[###-feature-name]/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command; its definition describes the execution workflow.

## Summary

[Extract from feature spec: primary requirement + technical approach from research]

## Scope Boundaries *(mandatory)*

- **In scope**: [exact workflows, files/directories, roles, contracts, and data included]
- **Out of scope**: [adjacent workflows, files, schema, UI, infrastructure, and cleanup intentionally excluded]
- **Compatibility baseline**: [observable behavior and public/internal contracts that must remain unchanged]
- **Authorized side effects**: [writes, migrations, external calls, deploy/push actions; use `none` when absent]

## Technical Context

<!--
  ACTION REQUIRED: Replace the content in this section with the technical details
  for the project. The structure here is presented in advisory capacity to guide
  the iteration process.
-->

**Language/Version**: PHP >= 8.0 (from `composer.json`); [feature-specific extension/runtime constraints]

**Primary Dependencies**: PDO, existing Composer packages, Bootstrap 5 RTL, jQuery/DataTables where applicable;
[list only additional existing dependencies used by this feature]

**Storage**: MySQL/MariaDB through PDO; [affected tables/files or N/A]

**Testing**: PHP test scripts under `tests/`, touched-file lint, architecture audit, and guarded integration
tests only against an explicit non-production database

**Target Platform**: Existing Apache/XAMPP-compatible web deployment; production topology is `Not confirmed yet`

**Project Type**: Server-rendered PHP modular monolith with direct role/API entrypoints

**Performance Goals**: [domain-specific, e.g., 1000 req/s, 10k lines/sec, 60 fps or NEEDS CLARIFICATION]

**Constraints**: [domain-specific, e.g., <200ms p95, <100MB memory, offline-capable or NEEDS CLARIFICATION]

**Scale/Scope**: [feature-specific users, records, routes, files, and migration scope or NEEDS CLARIFICATION]

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [ ] **Canonical context**: `AGENTS.md` and relevant focused docs were read; unknowns are marked.
- [ ] **Compatibility**: affected URLs, request/form fields, IDs, session keys, JSON contracts, roles,
      permissions, and behavior are listed with a preservation or migration strategy.
- [ ] **Architecture**: the change uses the existing modular-monolith direction and real repository paths;
      no parallel framework/helper/service is introduced and cross-module dependencies are documented.
- [ ] **Security/data**: auth, authorization, CSRF, escaping, secrets, logging, schema, transaction, upload,
      and production-data impact are explicitly assessed.
- [ ] **Testing/rollback**: characterization and proportional tests, a safe DB guard where applicable,
      rollback steps, and stop conditions are defined.
- [ ] **Governance**: ADR/docs impact is identified; `composer architecture-audit` is planned and no
      baseline expansion is used merely to pass the gate.

Any failed item MUST be resolved or recorded in Complexity Tracking with an approved exception. Security,
production-data, unclear-permission, and unverified-rollback failures are blocking.

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)
<!--
  ACTION REQUIRED: Replace the placeholder tree below with the concrete layout
  for this feature. Delete unused options and expand the chosen structure with
  real paths (e.g., admin/classes.php, classes/AssessmentEngine.php,
  database/migrations/...). The delivered plan must
  not include Option labels.
-->

```text
admin/ | teacher/ | student/ | specialist/ | supervisor/ | external/  # HTTP entrypoints/views
api/ | ajax/ | auth/                                                   # HTTP/API/auth entrypoints
classes/                                                               # existing services/models/infrastructure
includes/                                                              # shared rendering/session/request helpers
config/                                                                # environment and infrastructure configuration
src/Modules/<Module>/                                                   # incremental modular extraction when justified
src/Shared/                                                             # proven cross-module primitives only
views/                                                                 # extracted rendering only
database/migrations/                                                   # schema evolution only
assets/                                                                # shared CSS/JS/images
tests/                                                                 # contract/unit/guarded integration scripts
docs/                                                                  # architecture, decisions, runbooks
```

**Structure Decision**: [List exact existing files/directories used by this feature, new ownership boundaries,
and why each new file belongs there. `src/` is introduced only for a real tested extraction, never as an empty
parallel tree. Reference `docs/project-structure.md`.]

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed / Evidence | Simpler Alternative Rejected | Owner / Approval | Expiry Or Remediation | Rollback |
|-----------|-----------------------|------------------------------|------------------|-----------------------|----------|
| [rule/decision] | [specific evidence] | [why insufficient] | [named owner + approval] | [date/condition + removal plan] | [tested steps] |
