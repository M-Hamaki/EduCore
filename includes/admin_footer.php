                </main>
            </div>
        </div>
    </div> <!-- Close main-content added by admin_header.php -->

    <?php
    $adminAssetOptions = array_merge([
        'datatables' => true,
        'sortable' => true,
        'instant_attachment_upload' => true,
        'dashboard_sortable' => true,
    ], isset($adminAssetOptions) && is_array($adminAssetOptions) ? $adminAssetOptions : []);
    ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($adminAssetOptions['datatables']): ?>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?php echo asset_url('../assets/js/datatables-ar.js'); ?>"></script>
    <?php endif; ?>
    <?php if ($adminAssetOptions['sortable']): ?>
    <!-- SortableJS -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <?php endif; ?>
    <!-- Premium Dashboard JS -->
    <script src="<?php echo asset_url('../assets/js/premium-dashboard.js'); ?>"></script>
    <!-- Custom JS -->
    <script src="<?php echo asset_url('../assets/js/main.js'); ?>"></script>
    <!-- Air Datepicker (حامل التاريخ الموحد) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.js"></script>
    <script src="<?php echo asset_url('../assets/js/air-datepicker-init.js'); ?>"></script>
    <?php if ($adminAssetOptions['instant_attachment_upload']): ?>
    <script src="<?php echo asset_url('../assets/js/instant_attachment_upload.js'); ?>"></script>
    <?php endif; ?>
    <?php if ($adminAssetOptions['dashboard_sortable']): ?>
    <script src="<?php echo asset_url('../assets/js/dashboard_sortable.js'); ?>"></script>
    <?php endif; ?>
    <script>
    (function(){
        // Sidebar Toggle functionality
        const sidebar = document.getElementById('adminSidebar');
        const toggler = document.getElementById('sidebarToggleBtn');
        const togglerMobile = document.getElementById('sidebarToggleBtnMobile');
        const overlay = document.getElementById('sidebarOverlay');
        const openMenusStorageKey = 'sidebar_open_menus';

        function getCollapseTarget(trigger) {
            if (!trigger) return null;
            const target = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
            return target && target.charAt(0) === '#' ? target.slice(1) : null;
        }

        function findCollapseTrigger(menuId) {
            if (!sidebar || !menuId) return null;
            return Array.from(sidebar.querySelectorAll('[data-bs-toggle="collapse"]'))
                .find(function(trigger) { return getCollapseTarget(trigger) === menuId; }) || null;
        }

        function getActiveMenuIds() {
            if (!sidebar) return [];
            const menuIds = new Set();

            sidebar.querySelectorAll('.nav-link.active').forEach(function(link) {
                let menu = link.closest('.collapse');
                if (!menu && link.getAttribute('data-bs-toggle') === 'collapse') {
                    const targetId = getCollapseTarget(link);
                    menu = targetId ? document.getElementById(targetId) : null;
                }

                while (menu) {
                    if (menu.id) menuIds.add(menu.id);
                    menu = menu.parentElement ? menu.parentElement.closest('.collapse') : null;
                }
            });

            return Array.from(menuIds);
        }

        function getStoredOpenMenuIds() {
            try {
                const stored = JSON.parse(sessionStorage.getItem(openMenusStorageKey) || '[]');
                return Array.isArray(stored) ? stored.filter(Boolean) : [];
            } catch (error) {
                return [];
            }
        }

        function saveOpenMenuIds(menuIds) {
            const uniqueIds = Array.from(new Set((menuIds || []).filter(Boolean)));
            try {
                if (uniqueIds.length) sessionStorage.setItem(openMenusStorageKey, JSON.stringify(uniqueIds));
                else sessionStorage.removeItem(openMenusStorageKey);
            } catch (error) {
                // تجاهل فشل التخزين في المتصفحات التي تمنع sessionStorage.
            }
        }

        function getCollapseDepth(menu) {
            let depth = 0;
            let parent = menu ? menu.parentElement : null;
            while (parent) {
                if (parent.classList && parent.classList.contains('collapse')) depth++;
                parent = parent.parentElement;
            }
            return depth;
        }

        function restoreOpenMenus(preferredIds) {
            if (!sidebar) return;

            const requestedIds = Array.isArray(preferredIds) && preferredIds.length
                ? preferredIds
                : getStoredOpenMenuIds().concat(getActiveMenuIds());
            const menuIds = Array.from(new Set(requestedIds.filter(Boolean)));
            const menus = menuIds
                .map(function(menuId) { return document.getElementById(menuId); })
                .filter(function(menu) { return menu && sidebar.contains(menu); })
                .sort(function(a, b) { return getCollapseDepth(a) - getCollapseDepth(b); });

            menus.forEach(function(menu) {
                const instance = bootstrap.Collapse.getOrCreateInstance(menu, { toggle: false });
                instance.show();
                const trigger = findCollapseTrigger(menu.id);
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'true');
                    trigger.classList.remove('collapsed');
                }
            });

            if (menus.length) saveOpenMenuIds(menus.map(function(menu) { return menu.id; }));
        }

        function openSidebar() {
            if(sidebar) sidebar.classList.add('show');
            if(overlay) overlay.classList.add('active');
            document.body.style.overflow = 'hidden'; // prevent bg scrolling on mobile when sidebar is open
        }

        function closeSidebar() {
            if(sidebar) sidebar.classList.remove('show');
            if(overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        function handleToggle(e) {
            e.preventDefault();
            if (window.innerWidth >= 992) {
                // Desktop: Toggle collapsed state on body
                document.body.classList.toggle('sidebar-collapsed');

                // Persist state via cookie (so PHP can read it on next load)
                const isCollapsed = document.body.classList.contains('sidebar-collapsed');
                if (isCollapsed) {
                    const openMenuIds = Array.from(document.querySelectorAll('#adminSidebar .collapse.show'))
                        .map(function(menu) { return menu.id; })
                        .filter(Boolean);
                    saveOpenMenuIds(openMenuIds.length ? openMenuIds : getActiveMenuIds());
                }
                document.cookie = "sidebar_collapsed=" + (isCollapsed ? "1" : "0") + "; path=/; max-age=" + (60 * 60 * 24 * 30); // 30 days
                sessionStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');

                // Close all open bootstrap accordions smoothly so they don't break UI when collapsed
                if(document.body.classList.contains('sidebar-collapsed')) {
                    document.querySelectorAll('#adminSidebar .collapse.show').forEach(function(el) {
                        var bsCollapse = bootstrap.Collapse.getInstance(el);
                        if(bsCollapse) bsCollapse.hide();
                        else {
                            // fallback if not properly booted by BS
                            el.classList.remove('show');
                        }
                    });

                    // Set aria-expanded to false on triggers
                    document.querySelectorAll('#adminSidebar [data-bs-toggle="collapse"][aria-expanded="true"]').forEach(function(btn) {
                        btn.setAttribute('aria-expanded', 'false');
                        btn.classList.add('collapsed');
                    });
                } else {
                    // Restore the menu that was open before the sidebar was collapsed.
                    restoreOpenMenus();
                }
            } else {
                // Mobile: Toggle off-canvas
                if (sidebar && sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }
        }

        if (sidebar) {
            if (toggler) toggler.addEventListener('click', handleToggle);
            if (togglerMobile) togglerMobile.addEventListener('click', handleToggle);
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // Auto close on window resize to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992 && sidebar && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });

        // Collapsed Sidebar Click -> Expand Sidebar on Main Category Icons Only
        const sidebarNav = document.getElementById('sidebarNavAccordion');
        if (sidebarNav) {
            sidebarNav.addEventListener('click', function(e) {
                if (document.body.classList.contains('sidebar-collapsed')) {
                    // Match only the main category links that toggle collapsible sub-menus
                    const navLink = e.target.closest('.nav-link[data-bs-toggle="collapse"]');
                    if (navLink) {
                        // Expand the sidebar
                        const targetId = getCollapseTarget(navLink);
                        document.body.classList.remove('sidebar-collapsed');

                        // Persist state via cookie/sessionStorage
                        document.cookie = "sidebar_collapsed=0; path=/; max-age=" + (60 * 60 * 24 * 30);
                        sessionStorage.setItem('sidebar_collapsed', 'false');
                        restoreOpenMenus(targetId ? [targetId] : null);
                    }
                }
            });
        }

        // Note: the previous in-sidebar nav filter (which hid/expanded sidebar
        // items on input) was removed because it conflicted with the global
        // search dropdown (admin-global-search.js) bound to the same input.
        // Global search now owns the input and renders a results dropdown that
        // covers pages, sections, students, staff, classes, subjects, and buses.
    })();
    </script>

    <script src="<?php echo asset_url('../assets/js/form-safety.js'); ?>"></script>
    <script src="<?php echo asset_url('../assets/js/admin-global-search.js'); ?>"></script>

    <?php require __DIR__ . '/undo_toast.php'; ?>
<?php include_once __DIR__ . '/push_init.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const token = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (form) {
        if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = token;
            form.appendChild(input);
        }
    });

    // مطابقة وتلوين أيقونة عنوان الصفحة مع أيقونة القائمة الجانبية
    try {
        var currentPage = window.location.pathname.split('/').pop() || 'index.php';
        var sidebarLink = document.querySelector('.admin-sidebar a[href="' + currentPage + '"]');
        if (!sidebarLink) {
            var cleanPath = currentPage.split('?')[0];
            sidebarLink = document.querySelector('.admin-sidebar a[href^="' + cleanPath + '"]');
        }
        if (!sidebarLink) {
            var links = document.querySelectorAll('.admin-sidebar a.nav-link');
            for (var i = 0; i < links.length; i++) {
                var href = links[i].getAttribute('href');
                if (href && (href === currentPage || href.split('?')[0] === currentPage.split('?')[0])) {
                    sidebarLink = links[i];
                    break;
                }
            }
        }
        if (sidebarLink) {
            var sidebarIcon = sidebarLink.querySelector('i');
            if (sidebarIcon) {
                var pageHeaderIcon = document.querySelector('h1 i, h1.h2 i, h1 .fas, h1 .far, h1 .fal, h1 .fad, main h1 i, main .h2 i, h1.h3 i');
                if (pageHeaderIcon && pageHeaderIcon !== sidebarIcon) {
                    pageHeaderIcon.className = sidebarIcon.className;
                    pageHeaderIcon.classList.remove('nav-icon');
                    if (!pageHeaderIcon.classList.contains('me-2')) {
                        pageHeaderIcon.classList.add('me-2');
                    }
                    if (sidebarIcon.getAttribute('style')) {
                        pageHeaderIcon.setAttribute('style', sidebarIcon.getAttribute('style'));
                    } else {
                        pageHeaderIcon.removeAttribute('style');
                    }
                }
            }
        }
    } catch(e) {
        console.error('Error matching page icon:', e);
    }
});

async function revealUserPassword(userId, inputId, button, accountType = 'user') {
    const input = document.getElementById(inputId);
    const icon = button ? button.querySelector('i') : null;
    if (!input) {
        window.alert('تعذر العثور على حقل كلمة المرور');
        return;
    }

    if (input.dataset.passwordLoaded === 'true') {
        const shouldHide = input.type === 'text';
        input.type = shouldHide ? 'password' : 'text';
        if (icon) icon.className = shouldHide ? 'fas fa-eye' : 'fas fa-eye-slash';
        return;
    }

    try {
        if (button) button.disabled = true;
        const response = await fetch('ajax/get_password.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': <?php echo json_encode(csrfToken()); ?>},
            body: JSON.stringify({user_id: userId, account_type: accountType})
        });
        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error('تعذر قراءة استجابة الخادم. أعد تحميل الصفحة وحاول مرة أخرى.');
        }
        if (!response.ok || !result.success) throw new Error(result.message || 'تعذر عرض كلمة المرور');
        input.value = result.password;
        input.type = 'text';
        input.dataset.passwordLoaded = 'true';
        if (icon) icon.className = 'fas fa-eye-slash';
        if (input.passwordClearTimer) window.clearTimeout(input.passwordClearTimer);
        input.passwordClearTimer = window.setTimeout(function () {
            input.value = '';
            input.type = 'password';
            input.dataset.passwordLoaded = 'false';
            input.passwordClearTimer = null;
            if (icon) icon.className = 'fas fa-eye';
        }, Math.max(1, result.hide_after_seconds || 15) * 1000);
    } catch (error) {
        window.alert(error.message);
    } finally {
        if (button) button.disabled = false;
    }
}
</script>
</body>
</html>
