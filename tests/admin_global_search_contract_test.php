<?php

declare(strict_types=1);

/**
 * Contract test for the admin global search bar.
 *
 * Guards the fixes for:
 *  - Nested-collapse category attribution (a page nested as
 *    #studentsMenu > #studentDataMenu > students.php must carry BOTH parent
 *    titles, not just the innermost one).
 *  - Section headers (accordion toggles with href="#...") being registered as
 *    clickable results so searching "شؤون الطلاب" surfaces the section itself.
 *  - The deep-search backend delegating to the role-scoped application service.
 *  - The removed conflicting sidebar filter (admin_footer.php must no longer
 *    bind a second `input` handler that hides/expands nav items).
 *  - Visible loading/error states and stable profile-view links.
 */

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/admin-global-search.js');
$footer = (string) file_get_contents($root . '/includes/admin_footer.php');
$lookups = (string) file_get_contents($root . '/classes/Ajax/Handlers/lookups.php');
$ajaxHandlers = (string) file_get_contents($root . '/includes/ajax_handlers.php');
$policy = (string) file_get_contents($root . '/src/Modules/Search/Application/GlobalSearchAccessPolicy.php');
$service = (string) file_get_contents($root . '/src/Modules/Search/Application/GlobalSearchQueryService.php');
$repository = (string) file_get_contents($root . '/src/Modules/Search/Infrastructure/PdoGlobalSearchReadRepository.php');

$checks = [
    // 1. Nested-collapse walk: the index builder must climb the WHOLE ancestor
    //    collapse chain, not just the innermost .collapse. The previous broken
    //    implementation used a single `link.closest('.collapse')` with no
    //    upward walk. The fix reassigns `parentCollapse` to the enclosing
    //    collapse via `parentElement.closest('.collapse')`.
    'index_builder_walks_full_collapse_chain' =>
        strpos($js, "parentCollapse = parentCollapse.parentElement") !== false
        && strpos($js, "? parentCollapse.parentElement.closest('.collapse')") !== false,

    // 2. Section headers (href="#...") must be registered as clickable results
    //    that navigate to their first leaf page.
    'section_toggles_registered_as_clickable_results' =>
        strpos($js, "type: 'section'") !== false
        && strpos($js, "firstLeaf") !== false,

    // 3. The old single-closest attribution must be gone (no bare
    //    `const parentCollapse = link.closest('.collapse');` that does not walk up).
    'old_single_closest_attribution_removed' =>
        strpos($js, "category = '';\n                const parentCollapse = link.closest('.collapse');") === false,

    // 4. renderResults must separate sections from pages and raise the page cap
    //    above the old value of 5 (we now slice to 8).
    'render_results_separates_sections_and_pages' =>
        strpos($js, "i.type === 'section'") !== false
        && strpos($js, "i.type !== 'section'") !== false
        && strpos($js, ".slice(0, 8)") !== false,

    // 5. New DB-backed groups (classes + subjects) must be rendered.
    'render_results_includes_classes_block' =>
        strpos($js, "dbData.classes") !== false
        && strpos($js, 'class_lists.php?class_id=') !== false,
    'render_results_includes_subjects_block' =>
        strpos($js, "dbData.subjects") !== false,

    // 6. The conflicting sidebar filter must be removed from admin_footer.php.
    //    The old code queried `#sidebarNavAccordion > .nav-item` and toggled
    //    their display + expanded collapses on the SAME input.
    'conflicting_sidebar_filter_removed' =>
        strpos($footer, 'Live Menu Search Filter') === false
        && strpos($footer, "#sidebarNavAccordion > .nav-item") === false,

    // 7. The public handler must delegate to the application service; SQL belongs
    //    to the reviewed read repository rather than the HTTP switch.
    'backend_delegates_to_search_service' =>
        strpos($lookups, 'GlobalSearchQueryService') !== false
        && strpos($lookups, 'PdoGlobalSearchReadRepository') !== false
        && strpos($lookups, '$studentStmt = $db->prepare') === false,
    'backend_returns_structured_search_errors' =>
        strpos($lookups, "global_search query failed") !== false
        && strpos($lookups, "تعذر تحميل نتائج البحث الآن") !== false
        && strpos($lookups, '500') !== false,
    'repository_searches_classes_and_subjects' =>
        strpos($repository, 'FROM classes c') !== false
        && strpos($repository, 'FROM subjects') !== false,

    // 8. The application service must always advertise every result group.
    'backend_empty_response_advertises_all_keys' =>
        strpos($service, "['students', 'staff', 'classes', 'subjects', 'buses']") !== false
        && strpos($service, 'emptyResults()') !== false,

    // 9. The section bonus must exist so a matching section ranks above its
    //    child pages (e.g. "شؤون الطلاب" section above "students.php" page).
    'section_match_bonus_present' =>
        strpos($js, "sectionBoost") !== false,

    // 10. Index construction must be wrapped in try/catch so a malformed DOM
    //     node or stray id can NEVER break the whole search instance (which
    //     would silently kill the input listeners and make the bar dead).
    'index_build_is_defensive' =>
        strpos($js, '} catch (indexError) {') !== false
        && strpos($js, 'index build failed') !== false,

    // 11. Section first-leaf lookup must use getElementById (never throws)
    //     instead of building a dynamic CSS selector that can throw and halt
    //     the index build. This is the regression that made the bar go dead.
    'section_lookup_uses_get_element_by_id' =>
        strpos($js, 'document.getElementById(collapseId)') !== false
        && strpos($js, "#adminSidebar #' + cssEscape") === false,

    // 12. Authorization must use the actual active role + page grants, not a
    //     manually enumerated role list that can omit super_admin or cloned roles.
    'deep_search_uses_page_grant_policy' =>
        strpos($ajaxHandlers, 'GlobalSearchAccessPolicy') !== false
        && strpos($ajaxHandlers, 'getAllowedAdminPagesForRole($assignedRole)') !== false,
    'deep_search_policy_allows_super_admin' =>
        strpos($policy, "'super_admin'") !== false
        && strpos($policy, "array_fill_keys(self::GROUPS, true)") !== false,
    'deep_search_manual_role_allowlist_removed' =>
        strpos($ajaxHandlers, "'global_deep_search' => [") === false,

    // 13. The UI must expose backend failures and use stable entity routes.
    'frontend_checks_http_status_and_shows_errors' =>
        strpos($js, 'payload.response.ok') !== false
        && strpos($js, "? 'forbidden'") !== false
        && strpos($js, "type: 'error'") !== false
        && strpos($js, '.catch(function () {});') === false,
    'frontend_cancels_stale_requests' =>
        strpos($js, 'new AbortController()') !== false
        && strpos($js, "error.name === 'AbortError'") !== false,
    'frontend_uses_profile_view_routes' =>
        strpos($js, 'students.php?action=view&id=') !== false
        && strpos($js, 'staff.php?action=view&id=') !== false
        && strpos($js, 'teachers.php?search=') === false,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "Admin global search contract test passed.\n";
