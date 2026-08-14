# BehaviorEvaluation module

This module owns the behavior-evaluation models and the evaluation AJAX action implementation.

## Compatibility boundary

- Existing pages may continue requiring `classes/evaluation.php` and `classes/evaluation_type.php` and using the global `Evaluation` and `EvaluationType` names.
- `includes/ajax_handlers.php` continues dispatching through `classes/Ajax/Handlers/evaluations.php`; that file is now a thin adapter to this module.
- Public URLs, action names, request fields, authorization, CSRF checks, JSON responses, SQL, and database schema are unchanged.

## Rollback

Move the two model implementations and AJAX handler back to their legacy paths, remove their namespaces/imports, then remove the compatibility adapters and this module directory. No database rollback is required.
