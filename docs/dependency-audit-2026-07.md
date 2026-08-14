# Dependency audit — 2026-07-15

## Composer direct dependencies

No direct Composer dependency is confirmed unused:

| Dependency | Confirmed active use |
|---|---|
| `phpoffice/phpspreadsheet` | Excel imports, templates, and exports |
| `phpoffice/phppresentation` | `LessonPowerPointGenerator` |
| `dompdf/dompdf` | `classes/pdf_handler.php` and staff attendance PDF export |
| `firebase/php-jwt` | Microsoft SSO token verification |
| `minishlink/web-push` | `PushNotification` |

Composer-managed transitive packages must not be removed individually; Composer resolves them from `composer.lock`.

## Frontend libraries

Bootstrap, Font Awesome, jQuery, DataTables, Chart.js, SortableJS, html2canvas, jsPDF, particles.js, and date libraries all have confirmed active call sites.

SweetAlert2 also has active call sites, so it is not unused. It conflicts with the current UI rule that requires Bootstrap confirmation modals; removal requires migrating those callers first and is a separate compatibility change.

The Animate.css CDN include in `student/index.php` is a candidate for removal: no `animate__*` use was found in that page. It was not removed in this cleanup because the class may be injected by runtime content; confirm in-browser behavior first.

## Conclusion

- Confirmed removable Composer libraries: none.
- Confirmed removable frontend libraries without caller migration: none.
- Architectural cleanup candidate: SweetAlert2 after converting its active callers.
- Low-risk verification candidate: the Animate.css include in `student/index.php`.
