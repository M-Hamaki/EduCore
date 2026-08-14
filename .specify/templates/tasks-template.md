---

description: "Task list template for feature implementation"
---

# Tasks: [FEATURE NAME]

**Input**: Design documents from `/specs/[###-feature-name]/`

**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: Tests are REQUIRED when behavior, security, authorization, public contracts, schema/data,
transactions, module boundaries, or risky legacy code changes. A docs-only change may omit tests only with
an explicit verification rationale.

- Characterization tests for existing behavior MUST pass before and after a refactor.
- Tests for genuinely new behavior SHOULD be written first and observed failing before implementation.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- HTTP/view entrypoints: `admin/`, `teacher/`, `student/`, `specialist/`, `supervisor/`, `external/`,
  `api/`, `ajax/`, `auth/`.
- Existing shared/application/infrastructure code: `classes/`, `includes/`, `config/`.
- Incremental modular extraction: `src/Modules/<Module>/`; proven cross-module primitives: `src/Shared/`.
- Extracted rendering: `views/`; existing role pages remain compatible entrypoints.
- Schema evolution: `database/migrations/` only.
- Tests and documentation: `tests/`, `docs/`.
- Use exact paths from the plan; do not create an empty generic `src/` tree or a new root module without an
  ADR-backed, tested extraction.

<!--
  ============================================================================
  IMPORTANT: The tasks below are SAMPLE TASKS for illustration purposes only.

  The /speckit-tasks command MUST replace these with actual tasks based on:
  - User stories from spec.md (with their priorities P1, P2, P3...)
  - Feature requirements from plan.md
  - Entities from data-model.md
  - Endpoints from contracts/

  Tasks MUST be organized by user story so each story can be:
  - Implemented independently
  - Tested independently
  - Delivered as an MVP increment

  DO NOT keep these sample tasks in the generated tasks.md file.
  ============================================================================
-->

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and basic structure

- [ ] T001 Confirm exact existing target paths and preserve unrelated dirty-worktree changes
- [ ] T002 Record affected contracts, callers, roles, data, rollback, and stop conditions
- [ ] T003 [P] Confirm existing helpers/services and dependencies before adding abstractions

Do not initialize a new framework, router, auth stack, or parallel project structure.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

Examples of foundational tasks (adjust based on your project):

- [ ] T004 Add a guarded migration only when the specification requires schema evolution
- [ ] T005 [P] Reuse and verify existing authentication/authorization/CSRF boundaries
- [ ] T006 [P] Capture current entrypoint/API contracts with characterization tests
- [ ] T007 Add the smallest shared service/domain/repository boundary required by the plan
- [ ] T008 Reuse existing error handling and server-side logging without exposing details
- [ ] T009 Reuse environment configuration through `env()`; never add hardcoded secrets

**Checkpoint**: Foundation ready - user story implementation can now begin in parallel

---

## Phase 3: User Story 1 - [Title] (Priority: P1) 🎯 MVP

**Goal**: [Brief description of what this story delivers]

**Independent Test**: [How to verify this story works on its own]

### Tests for User Story 1 (REQUIRED when the constitution test gate applies) ⚠️

> **NOTE: Characterization tests pass on current behavior; tests for new behavior fail before implementation.**

- [ ] T010 [P] [US1] Contract test for [endpoint] in tests/[name]_contract_test.php
- [ ] T011 [P] [US1] Guarded integration test for [user journey] in tests/[name]_integration_test.php

### Implementation for User Story 1

- [ ] T012 [P] [US1] Add or extend [Entity1] in the exact class/service path from plan.md
- [ ] T013 [P] [US1] Add or extend [Entity2] in the exact class/service path from plan.md
- [ ] T014 [US1] Implement [Service] in the owned `classes/` or module path (depends on T012, T013)
- [ ] T015 [US1] Implement [endpoint/feature] in the existing role/API entrypoint from plan.md
- [ ] T016 [US1] Add validation and error handling
- [ ] T017 [US1] Add logging for user story 1 operations

**Checkpoint**: At this point, User Story 1 should be fully functional and testable independently

---

## Phase 4: User Story 2 - [Title] (Priority: P2)

**Goal**: [Brief description of what this story delivers]

**Independent Test**: [How to verify this story works on its own]

### Tests for User Story 2 (REQUIRED when the constitution test gate applies) ⚠️

- [ ] T018 [P] [US2] Contract test for [endpoint] in tests/[name]_contract_test.php
- [ ] T019 [P] [US2] Guarded integration test for [user journey] in tests/[name]_integration_test.php

### Implementation for User Story 2

- [ ] T020 [P] [US2] Add or extend [Entity] in the exact class/service path from plan.md
- [ ] T021 [US2] Implement [Service] in the owned `classes/` or module path
- [ ] T022 [US2] Implement [endpoint/feature] in the existing role/API entrypoint from plan.md
- [ ] T023 [US2] Integrate with User Story 1 components (if needed)

**Checkpoint**: At this point, User Stories 1 AND 2 should both work independently

---

## Phase 5: User Story 3 - [Title] (Priority: P3)

**Goal**: [Brief description of what this story delivers]

**Independent Test**: [How to verify this story works on its own]

### Tests for User Story 3 (REQUIRED when the constitution test gate applies) ⚠️

- [ ] T024 [P] [US3] Contract test for [endpoint] in tests/[name]_contract_test.php
- [ ] T025 [P] [US3] Guarded integration test for [user journey] in tests/[name]_integration_test.php

### Implementation for User Story 3

- [ ] T026 [P] [US3] Add or extend [Entity] in the exact class/service path from plan.md
- [ ] T027 [US3] Implement [Service] in the owned `classes/` or module path
- [ ] T028 [US3] Implement [endpoint/feature] in the existing role/API entrypoint from plan.md

**Checkpoint**: All user stories should now be independently functional

---

[Add more user story phases as needed, following the same pattern]

---

## Phase N: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] TXXX [P] Documentation updates in docs/
- [ ] TXXX Code cleanup and refactoring
- [ ] TXXX Performance optimization across all stories
- [ ] TXXX [P] Additional unit tests (if requested) in tests/unit/
- [ ] TXXX Security hardening
- [ ] TXXX Run quickstart.md validation
- [ ] TXXX Run touched-file PHP lint and relevant contract/unit tests
- [ ] TXXX Run guarded integration tests only against a confirmed non-production database
- [ ] TXXX Run `composer architecture-audit` and review any findings/baseline change
- [ ] TXXX Update `docs/architecture-decisions.md` and focused docs when boundaries changed
- [ ] TXXX Verify rollback and staged paths/diff before the focused commit

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3+)**: All depend on Foundational phase completion
  - User stories can then proceed in parallel (if staffed)
  - Or sequentially in priority order (P1 → P2 → P3)
- **Polish (Final Phase)**: Depends on all desired user stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Foundational (Phase 2) - No dependencies on other stories
- **User Story 2 (P2)**: Can start after Foundational (Phase 2) - May integrate with US1 but should be independently testable
- **User Story 3 (P3)**: Can start after Foundational (Phase 2) - May integrate with US1/US2 but should be independently testable

### Within Each User Story

- Required characterization/contract tests MUST exist before changing risky legacy behavior
- Models before services
- Services before endpoints
- Core implementation before integration
- Story complete before moving to next priority

### Parallel Opportunities

- All Setup tasks marked [P] can run in parallel
- All Foundational tasks marked [P] can run in parallel (within Phase 2)
- Once Foundational phase completes, all user stories can start in parallel (if team capacity allows)
- All tests for a user story marked [P] can run in parallel
- Models within a story marked [P] can run in parallel
- Different user stories can be worked on in parallel by different team members

---

## Parallel Example: User Story 1

```bash
# Launch all tests for User Story 1 together (when the constitution test gate applies):
Task: "Contract test for [endpoint] in tests/[name]_contract_test.php"
Task: "Guarded integration test for [user journey] in tests/[name]_integration_test.php"

# Launch all models for User Story 1 together:
Task: "Add or extend [Entity1] in the exact class/service path from plan.md"
Task: "Add or extend [Entity2] in the exact class/service path from plan.md"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL - blocks all stories)
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE**: Test User Story 1 independently
5. Demo locally if ready; deploy only when the user explicitly authorizes the target environment and action

### Incremental Delivery

1. Complete Setup + Foundational → Foundation ready
2. Add User Story 1 → Test independently → Local demo / authorized release preparation (MVP!)
3. Add User Story 2 → Test independently → Local demo / authorized release preparation
4. Add User Story 3 → Test independently → Local demo / authorized release preparation
5. Each story adds value without breaking previous stories

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: User Story 1
   - Developer B: User Story 2
   - Developer C: User Story 3
3. Stories complete and integrate independently

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- Each user story should be independently completable and testable
- Verify characterization tests pass; observe genuinely new-behavior tests fail before implementing
- Commit after each task or logical group
- Stop at any checkpoint to validate story independently
- Avoid: vague tasks, same file conflicts, cross-story dependencies that break independence
