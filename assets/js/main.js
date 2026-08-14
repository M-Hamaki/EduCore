// Main JavaScript file for the reward system

// CSRF token setup for fetch API (intercepts fetch POST requests globally)
(function () {
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta && window.fetch) {
        const token = tokenMeta.content;
        const originalFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            init = init || {};
            const method = String(init.method || 'GET').toUpperCase();
            if (method === 'POST') {
                const headers = new Headers(init.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', token);
                }
                init.headers = headers;
            }
            return originalFetch(input, init);
        };
    }
})();

// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    // CSRF token setup for AJAX (jQuery)
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta && typeof $ !== 'undefined') {
        const token = csrfMeta.getAttribute('content');
        $.ajaxSetup({
            beforeSend: function (xhr, settings) {
                if (settings.type && settings.type.toUpperCase() === 'POST') {
                    if (typeof settings.data === 'string') {
                        settings.data += (settings.data ? '&' : '') + 'csrf_token=' + encodeURIComponent(token);
                    } else if (settings.data instanceof FormData) {
                        settings.data.append('csrf_token', token);
                    } else if (typeof settings.data === 'object' && settings.data !== null) {
                        settings.data.csrf_token = token;
                    }
                }
            }
        });
    }
    // Ensure all logo images are loaded properly - simplified version
    const logoImages = document.querySelectorAll('.logo-img');
    logoImages.forEach(function (img) {
        // Set a default src if image fails to load
        img.onerror = function () {
            // Don't try to reload, just hide the broken image
            img.style.display = 'none';
        };
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-close alerts after 5 seconds (except sticky alerts)
    setTimeout(function () {
        var alerts = document.querySelectorAll('.alert.alert-dismissible');
        alerts.forEach(function (alert) {
            // لا تغلق الرسائل الثابتة مثل حالة النظام
            if (alert.classList.contains('sticky-alert') || alert.classList.contains('sticky-status-alert')) {
                return; // لا تغلق الرسائل sticky
            }

            // أغلق الرسائل العادية فقط
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Handle evaluation form
    const evaluationForm = document.getElementById('evaluationForm');
    if (evaluationForm) {
        evaluationForm.addEventListener('submit', function (e) {
            const selectedStudent = document.querySelector('input[name="student_id"]:checked');
            const selectedEvaluation = document.querySelector('input[name="evaluation_type_id"]:checked');

            if (!selectedStudent) {
                e.preventDefault();
                alert('يرجى اختيار طالب');
            }

            if (!selectedEvaluation) {
                e.preventDefault();
                alert('يرجى اختيار نوع التقييم');
            }
        });
    }

    // Handle file upload preview for all file inputs
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function (fileInput) {
        fileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            const fileName = file?.name || 'لم يتم اختيار ملف';

            // Update .custom-file-label if present (legacy Bootstrap 4 support)
            const fileLabel = document.querySelector('.custom-file-label');
            if (fileLabel) {
                fileLabel.textContent = fileName;
            }

            // Update any associated preview container (by data-preview attribute)
            const previewId = fileInput.dataset.preview;
            if (previewId && file) {
                const previewEl = document.getElementById(previewId);
                if (previewEl) {
                    // Show filename
                    const nameEl = previewEl.querySelector('.file-name, .preview-file-name');
                    if (nameEl) nameEl.textContent = fileName;

                    // Show image thumbnail if the file is an image
                    const thumbEl = previewEl.querySelector('.preview-thumbnail');
                    if (thumbEl && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function (ev) {
                            thumbEl.src = ev.target.result;
                            thumbEl.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                    }

                    previewEl.classList.add('show');
                }
            }
        });
    });

    // Password toggle visibility
    const passwordToggles = document.querySelectorAll('.password-toggle');
    passwordToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const passwordInput = document.getElementById(this.dataset.target);
            const icon = this.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });

    // Auto-styling of active filter controls
    var filterControls = document.querySelectorAll('.admin-filter-bar .form-select, .admin-filter-bar .form-control, form[method="GET"] .form-select, form[method="GET"] .form-control, select[id*="Filter"], input[id*="Filter"]');
    function updateAllFilterControls() {
        filterControls.forEach(function(ctrl) {
            if (ctrl.classList.contains('no-active-filter') || ctrl.dataset.noActiveFilter === 'true' || ctrl.closest('.no-active-filter')) {
                ctrl.classList.remove('active-filter');
                return;
            }
            var val = ctrl.value;
            var declaredDefault = ctrl.getAttribute('data-default-value');
            var isActive = declaredDefault !== null
                ? val !== declaredDefault
                : (val !== '' && val !== 'all' && val !== 'default');
            ctrl.classList.toggle('active-filter', isActive);
        });
    }
    filterControls.forEach(function(ctrl) {
        ctrl.addEventListener('change', updateAllFilterControls);
        ctrl.addEventListener('input', updateAllFilterControls);
    });
    updateAllFilterControls();

    // DataTables initialization (only if jQuery and DataTables are available)
    if (typeof $ !== 'undefined' && typeof $.fn.DataTable !== 'undefined') {
        // Global search input placeholder override
        $(document).on('draw.dt', function (e, settings) {
            if (settings && settings.sTableId) {
                var searchInput = document.querySelector('#' + settings.sTableId + '_filter input');
                if (searchInput) {
                    searchInput.placeholder = 'الاسم أو الكود';
                }
            }
        });

        $('.datatable').each(function () {
            if ($.fn.dataTable.isDataTable(this)) {
                return;
            }

            // Remove placeholder "no data" rows (a single cell with colspan)
            // before initializing DataTables — they break column-count detection
            // (warning tn/18) because the row has 1 cell while the header has N.
            // DataTables renders its own localized "emptyTable" message instead.
            $(this).find('tbody tr td[colspan]').each(function () {
                if ($(this).siblings().length === 0) {
                    $(this).closest('tr').remove();
                }
            });

            $(this).DataTable({
                language: {
                    "search": "البحث:",
                    "lengthMenu": "عرض _MENU_ سجل",
                    "info": "عرض _START_ إلى _END_ من أصل _TOTAL_ سجل",
                    "infoEmpty": "عرض 0 إلى 0 من أصل 0 سجل",
                    "infoFiltered": "(منقح من _MAX_ سجل إجمالي)",
                    "loadingRecords": "جاري التحميل...",
                    "zeroRecords": "لم يتم العثور على أي سجلات مطابقة",
                    "emptyTable": "لا توجد بيانات متاحة في الجدول",
                    "paginate": {
                        "first": "الأول",
                        "last": "الأخير",
                        "next": "التالي",
                        "previous": "السابق"
                    },
                    "aria": {
                        "sortAscending": ": تفعيل لترتيب العمود تصاعدياً",
                        "sortDescending": ": تفعيل لترتيب العمود تنازلياً"
                    }
                },
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']],
                ordering: true,
                paging: true,
                searching: true,
                responsive: true,
                autoWidth: false,
                deferRender: true,
                processing: false,
                dom: '<"row dt-toolbar-top"<"col-sm-6"l><"col-sm-6"f>>' +
                    '<"row dt-table-row"<"col-sm-12"tr>>' +
                    '<"dt-footer-bar"ip>',
                columnDefs: [
                    { targets: '_all', className: 'text-right' }
                ],
                drawCallback: function () {
                    var tableId = $(this).attr('id');
                    if (tableId) {
                        var searchInput = document.querySelector('#' + tableId + '_filter input');
                        if (searchInput && !searchInput.placeholder) {
                            searchInput.placeholder = 'الاسم أو الكود';
                        }
                    }
                }
            });
        });
    }

    // Student selection in evaluation form
    const studentCards = document.querySelectorAll('.student-card');
    if (studentCards.length > 0) {
        studentCards.forEach(function (card) {
            card.addEventListener('click', function () {
                // Remove active class from all cards
                studentCards.forEach(c => c.classList.remove('border-primary'));

                // Add active class to clicked card
                this.classList.add('border-primary');

                // Check the radio button
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
            });
        });
    }

    // Evaluation type selection in evaluation form
    const evaluationTypeCards = document.querySelectorAll('.evaluation-type-card');
    if (evaluationTypeCards.length > 0) {
        evaluationTypeCards.forEach(function (card) {
            card.addEventListener('click', function () {
                // Remove active class from all cards
                evaluationTypeCards.forEach(c => c.classList.remove('border-primary'));

                // Add active class to clicked card
                this.classList.add('border-primary');

                // Check the radio button
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
            });
        });
    }

    // Confirm delete actions — يظهر confirm() فقط للأزرار التي تحمل data-confirm="true" صراحةً
    // الأزرار التي تفتح Bootstrap Modal (سواء عبر data-bs-toggle أو JS) لا تحتاج confirm()
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(function (button) {
        button.addEventListener('click', function (e) {
            if (button.getAttribute('data-confirm') !== 'true') {
                return;
            }
            if (!confirm('هل أنت متأكد من رغبتك في الحذف؟')) {
                e.preventDefault();
            }
        });
    });

    // Reset points confirmation
    const resetPointsButtons = document.querySelectorAll('.btn-reset-points');
    resetPointsButtons.forEach(function (button) {
        button.addEventListener('click', function (e) {
            if (!confirm('هل أنت متأكد من رغبتك في تصفير النقاط؟ لا يمكن التراجع عن هذا الإجراء.')) {
                e.preventDefault();
            }
        });
    });

    // Mobile navigation bar
    function setupMobileNavigation() {
        // Only run on mobile devices
        if (window.innerWidth > 768) return;

        // Create mobile bottom navigation
        const currentPath = window.location.pathname;
        const userRole = document.body.dataset.userRole || 'default';

        let navigationItems = [];

        // Define navigation items based on user role
        if (userRole === 'admin') {
            navigationItems = [
                { icon: 'fa-tachometer-alt', text: 'الرئيسية', url: '/admin/index.php' },
                { icon: 'fa-users-cog', text: 'العاملين', url: '/admin/staff.php' },
                { icon: 'fa-user-graduate', text: 'الطلاب', url: '/admin/students.php' },
                { icon: 'fa-chart-line', text: 'التقارير', url: '/admin/evaluation_reports.php' }
            ];
        } else if (userRole === 'teacher') {
            navigationItems = [
                { icon: 'fa-tachometer-alt', text: 'الرئيسية', url: '/teacher/index.php' },
                { icon: 'fa-star', text: 'التقييمات', url: '/teacher/evaluations.php' }
            ];
        } else if (userRole === 'student') {
            navigationItems = [
                { icon: 'fa-tachometer-alt', text: 'الرئيسية', url: '/student/index.php' },
                { icon: 'fa-award', text: 'التقييمات', url: '/student/evaluations.php' }
            ];
        }

        // Create the navigation bar HTML
        if (navigationItems.length > 0) {
            const mobileNav = document.createElement('div');
            mobileNav.className = 'mobile-nav d-md-none';
            mobileNav.innerHTML = `
                <div class="mobile-nav-container">
                    ${navigationItems.map(item => `
                        <a href="${item.url}" class="mobile-nav-item ${currentPath.includes(item.url) ? 'active' : ''}">
                            <i class="fas ${item.icon}"></i>
                            <span>${item.text}</span>
                        </a>
                    `).join('')}
                </div>
            `;

            document.body.appendChild(mobileNav);

            // Add padding to main content to prevent nav from overlapping content
            const mainContent = document.querySelector('main') || document.querySelector('.main-content');
            if (mainContent) {
                mainContent.style.paddingBottom = '70px';
            }
        }
    }

    // Pull to refresh implementation for mobile
    function setupPullToRefresh() {
        if (window.innerWidth > 768) return;

        let touchstartY = 0;
        let touchendY = 0;
        const minSwipeDistance = 100;

        // Create pull indicator
        const pullIndicator = document.createElement('div');
        pullIndicator.className = 'pull-indicator';
        pullIndicator.innerHTML = 'اسحب لأسفل للتحديث <i class="fas fa-sync-alt"></i>';
        document.body.insertBefore(pullIndicator, document.body.firstChild);

        document.addEventListener('touchstart', e => {
            touchstartY = e.changedTouches[0].screenY;

            // Only show pull indicator if at top of page
            if (window.scrollY <= 0) {
                pullIndicator.classList.add('active');
            }
        }, false);

        document.addEventListener('touchend', e => {
            touchendY = e.changedTouches[0].screenY;
            pullIndicator.classList.remove('active');

            // Check if we've pulled down enough and we're at the top of the page
            if (window.scrollY <= 0 && touchendY - touchstartY > minSwipeDistance) {
                // Refresh the page
                window.location.reload();
            }
        }, false);
    }

    // Dynamic navbar height -> adjust CSS variable & body padding
    function updateNavbarHeight() {
        const nav = document.querySelector('.navbar-admin.fixed-top');
        if (!nav) return;
        const h = nav.getBoundingClientRect().height;
        // Update CSS variable (root + body classes using it)
        document.documentElement.style.setProperty('--navbar-height', h + 'px');
        // Ensure pages with navbar get correct padding
        if (document.body.classList.contains('admin-page') || document.body.classList.contains('teacher-page') || document.body.classList.contains('specialist-page')) {
            document.body.style.paddingTop = h + 'px';
        }
    }
    updateNavbarHeight();
    window.addEventListener('resize', () => {
        // debounce
        clearTimeout(window.__navHeightTimer);
        window.__navHeightTimer = setTimeout(updateNavbarHeight, 150);
    });

    // Call setup functions when DOM is loaded
    window.addEventListener('DOMContentLoaded', function () {
        setupMobileNavigation();
        // setupPullToRefresh(); // Temporarily disabled for debugging

        // Add data-userRole to body
        const userRoleElement = document.querySelector('.navbar .dropdown-toggle');
        if (userRoleElement) {
            const userRoleText = userRoleElement.textContent.trim();
            if (userRoleText.includes('admin')) {
                document.body.dataset.userRole = 'admin';
            } else if (userRoleText.includes('teacher')) {
                document.body.dataset.userRole = 'teacher';
            } else if (userRoleText.includes('student')) {
                document.body.dataset.userRole = 'student';
            }
        }
    });
});

// Fix logo visibility issues - simplified version without console spam
function ensureLogoVisibility() {
    document.querySelectorAll('img[src*="logo"], img.logo-img, img.portal-school-logo, img.rewards-school-logo, img.timetable-school-logo').forEach(img => {
        img.style.display = 'inline-block';
        img.style.visibility = 'visible';
        img.style.opacity = '1';

        // Retry loading if image failed
        if (!img.complete || img.naturalHeight === 0) {
            img.src = img.src + '?t=' + new Date().getTime();
        }
    });
}

// Call only once on window load
window.addEventListener('load', function () {
    ensureLogoVisibility();
});

// Error handling function
function handleAjaxError(error, message) {
    console.error('Error:', error);

    // Log the error details
    if (error.stack) {
        console.error('Stack:', error.stack);
    }

    // Show a user-friendly message
    showAlert('danger', message || 'حدث خطأ في الاتصال بالخادم');

    // If there's a modal open, keep it open
    const openModal = document.querySelector('.modal.show');
    if (openModal) {
        // Keep the modal open
        const modal = bootstrap.Modal.getInstance(openModal);
        if (modal) {
            modal._config.backdrop = 'static';
            modal._config.keyboard = false;
        }
    }
}

// Function to show alert messages
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    // If a modal is open, place the alert at top of its body to ensure visibility
    const openModal = document.querySelector('.modal.show .modal-body');
    if (openModal) {
        openModal.insertBefore(alertDiv, openModal.firstChild);
    } else {
        const container = document.querySelector('.container-fluid') || document.body;
        container.insertBefore(alertDiv, container.firstChild);
    }
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 150);
    }, 5000);
}

// Function to filter students by class (only used on students.php as fallback)
function filterByClass(classId) {
    // Use current page URL to stay on the same page
    var currentPage = window.location.pathname.split('/').pop().split('?')[0] || 'students.php';
    if (classId) {
        window.location.href = currentPage + '?class_id=' + classId;
    } else {
        window.location.href = currentPage;
    }
}

// Class filter functionality (only if jQuery is available and on students.php page)
if (typeof $ !== 'undefined') {
    $(document).on('change', '#classFilter', function () {
        // Only redirect if we're on the students.php page
        const currentPage = window.location.pathname;
        if (currentPage.includes('students.php') && !currentPage.includes('student_')) {
            filterByClass($(this).val());
        }
    });
}

// إصلاح مشكلة navbar toggle
document.addEventListener('DOMContentLoaded', function () {
    // التأكد من عمل navbar toggler بشكل صحيح لكل من specialist و admin
    const navbarTogglers = document.querySelectorAll('.navbar-toggler');

    navbarTogglers.forEach(function (navbarToggler) {
        const targetId = navbarToggler.getAttribute('data-bs-target');
        const navbarCollapse = document.querySelector(targetId);

        if (navbarToggler && navbarCollapse) {
            // إزالة أي event listeners قديمة وإضافة معرف فريد
            if (!navbarToggler.hasAttribute('data-toggle-bound')) {
                navbarToggler.setAttribute('data-toggle-bound', 'true');

                navbarToggler.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // الحصول على الحالة الحالية
                    const isExpanded = navbarCollapse.classList.contains('show') ||
                        navbarCollapse.classList.contains('collapsing');

                    // إنشاء أو الحصول على bootstrap collapse instance
                    let bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
                    if (!bsCollapse) {
                        bsCollapse = new bootstrap.Collapse(navbarCollapse, {
                            toggle: false
                        });
                    }

                    // تبديل الحالة
                    if (isExpanded) {
                        bsCollapse.hide();
                    } else {
                        bsCollapse.show();
                    }
                });
            }

            // الاستماع لأحداث Bootstrap collapse
            navbarCollapse.addEventListener('shown.bs.collapse', function () {
                navbarToggler.setAttribute('aria-expanded', 'true');
                navbarToggler.classList.remove('collapsed');
            });

            navbarCollapse.addEventListener('hidden.bs.collapse', function () {
                navbarToggler.setAttribute('aria-expanded', 'false');
                navbarToggler.classList.add('collapsed');
            });

            // تحديد الحالة الأولية
            const isInitiallyExpanded = navbarCollapse.classList.contains('show');
            navbarToggler.setAttribute('aria-expanded', isInitiallyExpanded.toString());
            navbarToggler.classList.toggle('collapsed', !isInitiallyExpanded);
        }
    });

    // إغلاق القائمة عند الضغط على رابط في الشاشات الصغيرة
    const navLinks = document.querySelectorAll('.navbar-nav .nav-link:not(.dropdown-toggle)');
    navLinks.forEach(function (link) {
        if (!link.hasAttribute('data-close-bound')) {
            link.setAttribute('data-close-bound', 'true');

            link.addEventListener('click', function () {
                if (window.innerWidth < 992) {
                    const navbar = link.closest('.navbar');
                    const navbarCollapse = navbar ? navbar.querySelector('.navbar-collapse') : null;

                    if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                        const bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
                        if (bsCollapse) {
                            bsCollapse.hide();
                        }
                    }
                }
            });
        }
    });

    // إصلاح مشكلة dropdown menu للبروفايل
    const dropdownToggles = document.querySelectorAll('[data-bs-toggle="dropdown"]');
    dropdownToggles.forEach(function (toggle) {
        if (!toggle.hasAttribute('data-dropdown-bound')) {
            toggle.setAttribute('data-dropdown-bound', 'true');

            // إنشاء bootstrap dropdown instance
            let dropdown = bootstrap.Dropdown.getInstance(toggle);
            if (!dropdown) {
                dropdown = new bootstrap.Dropdown(toggle);
            }

            // إضافة event listener للتأكد من العمل في الشاشات الصغيرة
            toggle.addEventListener('click', function (e) {
                // في الشاشات الكبيرة، السماح لـ Bootstrap بالتعامل مع الحدث
                if (window.innerWidth >= 992) {
                    return;
                }

                // في الشاشات الصغيرة، التعامل اليدوي
                e.preventDefault();
                e.stopPropagation();

                const dropdownMenu = toggle.nextElementSibling;
                if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                    const isOpen = dropdownMenu.classList.contains('show');

                    // إغلاق جميع القوائم المفتوحة الأخرى
                    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                        if (menu !== dropdownMenu) {
                            menu.classList.remove('show');
                        }
                    });

                    // تبديل حالة القائمة الحالية
                    dropdownMenu.classList.toggle('show', !isOpen);
                    toggle.setAttribute('aria-expanded', (!isOpen).toString());
                }
            });
        }
    });

    // إغلاق dropdown عند الضغط خارجها
    document.addEventListener('click', function (e) {
        const clickedInsideDropdown = e.target.closest('.dropdown');
        if (!clickedInsideDropdown) {
            // إغلاق جميع القوائم المفتوحة
            document.querySelectorAll('.dropdown-menu.show').forEach(function (dropdown) {
                dropdown.classList.remove('show');
                const toggle = dropdown.previousElementSibling;
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });

    // إغلاق dropdown عند الضغط على Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown-menu.show').forEach(function (dropdown) {
                dropdown.classList.remove('show');
                const toggle = dropdown.previousElementSibling;
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });

    function ensureAdminConfirmationModal() {
        var existing = document.getElementById('adminGlobalConfirmationModal');
        if (existing) return existing;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = '<div class="modal fade" id="adminGlobalConfirmationModal" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning">' +
            '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-triangle-exclamation me-2"></i><span>تأكيد الإجراء</span></h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>' +
            '<div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg mb-3"></i>' +
            '<p class="mb-0" data-confirmation-message></p></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>' +
            '<button type="button" class="btn btn-warning" data-confirmation-accept><i class="fas fa-check me-1"></i>تأكيد</button></div>' +
            '</div></div></div>';
        var modalElement = wrapper.firstElementChild;
        document.body.appendChild(modalElement);
        return modalElement;
    }

    window.adminConfirm = function (message, options) {
        options = options || {};
        var modalElement = ensureAdminConfirmationModal();
        var content = modalElement.querySelector('.modal-content');
        var messageElement = modalElement.querySelector('[data-confirmation-message]');
        var acceptButton = modalElement.querySelector('[data-confirmation-accept]');
        var operation = options.operation === 'delete' ? 'admin-modal-delete' : 'admin-modal-warning';
        content.classList.remove('admin-modal-warning', 'admin-modal-delete');
        content.classList.add(operation);
        messageElement.textContent = message || 'هل تريد تنفيذ هذا الإجراء؟';
        acceptButton.className = options.operation === 'delete' ? 'btn btn-danger' : 'btn btn-warning';
        acceptButton.innerHTML = options.operation === 'delete'
            ? '<i class="fas fa-trash me-1"></i>تأكيد الحذف'
            : '<i class="fas fa-check me-1"></i>تأكيد';

        return new Promise(function (resolve) {
            var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            var parentModalElement = Array.from(document.querySelectorAll('.modal.show'))
                .filter(function (element) { return element !== modalElement; })
                .pop() || null;
            var parentModal = parentModalElement
                ? bootstrap.Modal.getOrCreateInstance(parentModalElement)
                : null;
            var settled = false;
            function restoreParentModal() {
                if (parentModalElement && document.body.contains(parentModalElement)) {
                    parentModal.show();
                }
            }
            function showConfirmationModal() {
                if (parentModalElement) {
                    modalElement.addEventListener('hidden.bs.modal', restoreParentModal, { once: true });
                }
                modal.show();
            }
            function finish(result) {
                if (settled) return;
                settled = true;
                acceptButton.removeEventListener('click', accept);
                modalElement.removeEventListener('hidden.bs.modal', dismiss);
                resolve(result);
            }
            function accept() { modal.hide(); finish(true); }
            function dismiss() { finish(false); }
            acceptButton.addEventListener('click', accept);
            modalElement.addEventListener('hidden.bs.modal', dismiss, { once: true });
            if (parentModalElement) {
                parentModalElement.addEventListener('hidden.bs.modal', showConfirmationModal, { once: true });
                parentModal.hide();
            } else {
                showConfirmationModal();
            }
        });
    };

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-confirm-message]');
        if (!form || form.dataset.confirmApproved === 'true') return;
        event.preventDefault();
        var submitter = event.submitter || null;
        window.adminConfirm(form.dataset.confirmMessage, { operation: form.dataset.confirmOperation || 'warning' }).then(function (approved) {
            if (!approved) return;
            form.dataset.confirmApproved = 'true';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter);
            else form.submit();
        });
    }, true);

    ensureAdminConfirmationModal();

    // Move all modals to document.body to prevent containment/overflow issues and allow unrestricted dragging
    // (Avoid moving modals nested inside forms to prevent breaking input name serialization on form submit)
    document.querySelectorAll('.modal').forEach(function (modalElement) {
        if (modalElement.closest('form')) return;
        if (modalElement.parentNode && modalElement.parentNode !== document.body) {
            document.body.appendChild(modalElement);
        }
    });

    // Global Draggable Modals (Pointer API)
    document.querySelectorAll('.modal').forEach(function (modalElement) {
        var dialog = modalElement.querySelector('.modal-dialog');
        var header = modalElement.querySelector('.modal-header');
        if (!dialog || !header) return;

        var dragging = false;
        var pointerOffsetX = 0;
        var pointerOffsetY = 0;

        header.style.cursor = 'grab';
        header.style.userSelect = 'none';

        header.addEventListener('pointerdown', function (event) {
            var isResponsiveFullscreen = window.innerWidth < 992 && dialog.classList.contains('modal-fullscreen-lg-down');
            if (isResponsiveFullscreen || event.button !== 0 || event.target.closest('button, input, select, textarea, a, .nav-link, .dropdown-toggle')) return;

            var rect = dialog.getBoundingClientRect();

            // Remove centering class temporarily to calculate height without flex layout's stretched pseudo-element
            if (dialog.classList.contains('modal-dialog-centered')) {
                dialog.classList.remove('modal-dialog-centered');
                dialog.dataset.hadCentered = 'true';
            }

            dialog.style.position = 'absolute';
            dialog.style.margin = '0';
            dialog.style.width = rect.width + 'px';
            dialog.style.boxSizing = 'border-box';
            dialog.style.left = rect.left + 'px';
            dialog.style.top = rect.top + 'px';
            dialog.style.right = 'auto';
            dialog.style.transform = 'none';

            dragging = true;
            pointerOffsetX = event.clientX - rect.left;
            pointerOffsetY = event.clientY - rect.top;
            header.style.cursor = 'grabbing';
            header.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        header.addEventListener('pointermove', function (event) {
            if (!dragging) return;

            var dialogWidth = dialog.offsetWidth;
            var dialogHeight = dialog.offsetHeight;
            var maxLeft = Math.max(0, window.innerWidth - dialogWidth);
            var maxTop = Math.max(0, window.innerHeight - dialogHeight);
            var left = Math.min(Math.max(0, event.clientX - pointerOffsetX), maxLeft);
            var top = Math.min(Math.max(0, event.clientY - pointerOffsetY), maxTop);

            dialog.style.left = left + 'px';
            dialog.style.top = top + 'px';
        });

        function stopDragging(event) {
            if (!dragging) return;
            dragging = false;
            header.style.cursor = 'grab';
            if (event && header.hasPointerCapture(event.pointerId)) {
                header.releasePointerCapture(event.pointerId);
            }
        }

        header.addEventListener('pointerup', stopDragging);
        header.addEventListener('pointercancel', stopDragging);

        modalElement.addEventListener('hidden.bs.modal', function () {
            // Restore centering class if it had one
            if (dialog.dataset.hadCentered === 'true') {
                dialog.classList.add('modal-dialog-centered');
                delete dialog.dataset.hadCentered;
            }
            dialog.style.removeProperty('position');
            dialog.style.removeProperty('margin');
            dialog.style.removeProperty('width');
            dialog.style.removeProperty('box-sizing');
            dialog.style.removeProperty('left');
            dialog.style.removeProperty('top');
            dialog.style.removeProperty('right');
            dialog.style.removeProperty('transform');
            dragging = false;
            header.style.cursor = 'grab';
        });
    });
});
