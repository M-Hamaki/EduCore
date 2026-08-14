<!--
Sync Impact Report
- Version: unratified template -> 1.0.0
- Principles replaced: five placeholders -> compatibility, modular boundaries, security/data safety,
  evidence-driven delivery, and canonical AI governance
- Added sections: Architecture and Security Constraints; Delivery Workflow and Quality Gates
- Removed sections: placeholder-only content
- Templates: ✅ .specify/templates/plan-template.md; ✅ .specify/templates/spec-template.md;
  ✅ .specify/templates/tasks-template.md; commands directory not present
- Runtime guidance: ✅ AGENTS.md; ✅ README.md; ✅ docs/architecture.md;
  ✅ docs/project-structure.md; ✅ docs/architecture-decisions.md; ✅ docs/ai-change-checklist.md
- Deferred items: none
-->
# EduCore Constitution

## Core Principles

### I. Compatibility Before Refactoring (NON-NEGOTIABLE)

Every change MUST preserve existing URLs, request fields, element IDs, form actions, session keys,
JSON contracts, permissions, and observable behavior unless the specification explicitly defines a
compatible migration. A refactor MUST NOT hide a feature, policy, or schema change. Legacy entrypoints
remain valid adapters until callers and rollback are proven.

### II. One Modular Monolith, Clear Ownership

EduCore evolves as a pragmatic modular monolith, not through a rewrite or parallel framework. New and
extracted responsibilities MUST follow entrypoint → application service → domain/contracts →
repository/infrastructure → view. Dependencies flow inward; domain logic MUST NOT depend on HTTP, PDO,
templates, or another module's private internals. Existing helpers and services MUST be searched before
creating a new abstraction or duplicating a business rule.

### III. Security and Data Safety Are Release Gates

Authentication, authorization, and CSRF checks MUST run server-side before state processing. Secrets
MUST come from environment configuration, user output MUST be escaped, and internal errors MUST NOT be
returned to users. Schema changes MUST use migrations; runtime DDL in request paths is forbidden for new
code. Multi-write invariants require transactions. Tests, migrations, repair tools, and AI changes MUST
NOT touch production data without explicit authority, a backup, and a verified rollback path.

### IV. Evidence-Driven, Reversible Delivery

Plans and implementations MUST cite real files and confirmed behavior. Unknowns MUST be marked and must
not be invented. Risky legacy changes require characterization tests before extraction; security,
contract, schema, and multi-write changes require proportional automated tests. Each phase MUST be
small, independently reviewable, reversible, and committed as one concern after relevant lint, tests,
diff review, and the architecture gate succeed.

### V. One Canonical Instruction System

Root `AGENTS.md` is the authoritative project-instruction source. This constitution is the Spec Kit
adapter and MUST remain consistent with it; on conflict, `AGENTS.md` wins. Focused documents explain
architecture, structure, decisions, and checklists without redefining rules. Tool-specific adapters MAY
only point to the canonical source and MUST NOT invent unsupported formats. The architecture baseline is
a reviewed debt ratchet, never an unrestricted allow-list.

## Architecture and Security Constraints

- Runtime platform is PHP 8.0+ per `composer.json`, MariaDB/MySQL, PDO, and the existing XAMPP topology.
- Role directories remain presentation/HTTP boundaries while application and domain responsibilities
  move incrementally into owned services and repositories.
- Internal source/data directories MUST remain unavailable over HTTP. `includes/` and legacy uploads
  require compatibility-aware migration before tighter boundaries are applied.
- Cross-module calls require a documented service/query contract; direct access to another module's
  private implementation requires an ADR and migration plan.
- Module boundaries, public contracts, storage strategy, dependency direction, and governance changes
  MUST be recorded in `docs/architecture-decisions.md`.
- `tools/architecture_audit_baseline.json` MAY only expand in an isolated, justified change approved as
  intentional debt; existing findings remain remediation work.

## Delivery Workflow and Quality Gates

1. Read `AGENTS.md`, focused architecture documents, and the real target files before planning.
2. Record scope, non-scope, affected contracts, data impact, security impact, tests, rollback, and stop
   conditions in the specification and plan.
3. Protect unrelated dirty-worktree changes and avoid destructive Git operations.
4. Implement the smallest compatible slice; do not combine refactoring with unrelated feature or UI work.
5. Run PHP lint for touched files, relevant unit/contract tests, guarded integration tests only on a
   confirmed test database, UI audit when applicable, and `composer architecture-audit`.
6. Review staged paths and staged diff, update documentation/ADR when boundaries changed, then create one
   focused commit. Push and production changes require explicit authority.

## Governance

- `AGENTS.md` has highest instruction precedence; this constitution and all Spec Kit artifacts MUST align.
- Amendments require evidence, a Sync Impact Report, updates to affected templates/runtime docs, and a
  focused review. Architectural exceptions require rationale, owner, expiry/remediation path, and rollback.
- Constitution versions use semantic versioning: MAJOR for incompatible principle or precedence changes,
  MINOR for new/materially expanded principles, and PATCH for clarifications.
- Every implementation plan MUST complete the Constitution Check before research and after design. Any
  unmet gate requires an explicit justified exception; security, production-data, unclear permission, or
  unverified rollback gates cannot be waived by an implementation plan.
- Compliance is reviewed before staging and again before commit. Architecture-baseline expansions and
  instruction changes MUST be isolated and reviewed as governance changes.

**Version**: 1.0.0 | **Ratified**: 2026-07-12 | **Last Amended**: 2026-07-12
