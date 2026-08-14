(function () {
    'use strict';

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value || '');
        return div.innerHTML;
    }

    function iconClass(icon) {
        return {
            success: 'fa-check-circle text-success',
            warning: 'fa-exclamation-triangle text-warning',
            error: 'fa-times-circle text-danger',
            info: 'fa-info-circle text-primary'
        }[icon] || 'fa-info-circle text-primary';
    }

    window.LessonDialog = {
        fire: function (options) {
            options = options || {};
            var modalElement = document.getElementById('lessonDialogModal');
            if (!modalElement) {
                alert(options.text || options.title || '');
                return Promise.resolve({ isConfirmed: true, isDenied: false, isDismissed: false });
            }

            var form = document.getElementById('lessonDialogForm');
            var title = document.getElementById('lessonDialogTitle');
            var body = document.getElementById('lessonDialogBody');
            var icon = document.getElementById('lessonDialogIcon');
            var confirmButton = document.getElementById('lessonDialogConfirm');
            var denyButton = document.getElementById('lessonDialogDeny');
            var cancelButton = document.getElementById('lessonDialogCancel');
            var modal = bootstrap.Modal.getOrCreateInstance(modalElement);

            title.innerHTML = options.title || '';
            body.innerHTML = options.html || '<p class="mb-0 fw-semibold text-dark fs-6">' + escapeHtml(options.text || '') + '</p>';
            
            var iconContainer = document.getElementById('lessonDialogIconContainer') || (icon ? icon.parentElement : null);

            if (options.iconHtml) {
                if (iconContainer) iconContainer.style.display = 'block';
                if (icon) {
                    icon.style.display = 'block';
                    icon.className = '';
                    icon.innerHTML = options.iconHtml;
                }
            } else if (options.icon) {
                if (iconContainer) iconContainer.style.display = 'block';
                if (icon) {
                    icon.style.display = 'block';
                    icon.className = 'fas ' + iconClass(options.icon);
                    icon.innerHTML = '';
                }
            } else {
                if (iconContainer) iconContainer.style.display = 'none';
                if (icon) {
                    icon.style.display = 'none';
                    icon.className = '';
                    icon.innerHTML = '';
                }
            }

            // Confirm Button (Green / Primary)
            confirmButton.innerHTML = options.confirmButtonText || '<i class="fas fa-check me-1"></i> موافق';
            confirmButton.className = 'btn ' + (options.confirmButtonClass || 'btn-success');
            confirmButton.classList.toggle('d-none', options.showConfirmButton === false);

            // Deny Button (Blue / Secondary Option)
            if (denyButton) {
                denyButton.innerHTML = options.denyButtonText || '<i class="fas fa-edit me-1"></i> تعديل';
                denyButton.className = 'btn ' + (options.denyButtonClass || 'btn-primary');
                denyButton.classList.toggle('d-none', options.showDenyButton !== true);
            }

            // Cancel Button (Gray / Secondary)
            if (cancelButton) {
                cancelButton.innerHTML = options.cancelButtonText || '<i class="fas fa-times me-1"></i> إلغاء';
                cancelButton.className = 'btn btn-secondary';
                cancelButton.classList.toggle('d-none', options.showCancelButton !== true);
            }

            return new Promise(function (resolve) {
                var settled = false;
                var settle = function (result) {
                    if (settled) return;
                    settled = true;
                    resolve(result);
                };

                if (form) {
                    form.onsubmit = function (event) {
                        event.preventDefault();
                        settle({ isConfirmed: true, isDenied: false, isDismissed: false });
                        modal.hide();
                    };
                }

                if (denyButton) {
                    denyButton.onclick = function () {
                        settle({ isConfirmed: false, isDenied: true, isDismissed: false });
                        modal.hide();
                    };
                }

                if (cancelButton) {
                    cancelButton.onclick = function () {
                        settle({ isConfirmed: false, isDenied: false, isDismissed: true });
                        modal.hide();
                    };
                }

                modalElement.addEventListener('hidden.bs.modal', function onHidden() {
                    modalElement.removeEventListener('hidden.bs.modal', onHidden);
                    settle({ isConfirmed: false, isDenied: false, isDismissed: true });
                });

                modal.show();

                if (Number(options.timer) > 0) {
                    window.setTimeout(function () {
                        settle({ isConfirmed: true, isDenied: false, isDismissed: false });
                        modal.hide();
                    }, Number(options.timer));
                }
            });
        }
    };
})();
