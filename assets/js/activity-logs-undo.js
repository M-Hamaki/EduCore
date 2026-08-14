(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-system-undo, .js-system-redo').forEach(function (button) {
            button.addEventListener('click', function () {
                var isRedo = button.classList.contains('js-system-redo');
                var prefix = isRedo ? 'systemRedo' : 'systemUndo';
                var modalElement = document.getElementById(prefix + 'Modal');
                var activityInput = document.getElementById(prefix + 'ActivityId');
                var undoInput = document.getElementById(prefix + 'Id');
                var operationName = document.getElementById(prefix + 'OperationName');
                if (!modalElement || !activityInput || !undoInput || !operationName) return;

                activityInput.value = button.dataset.activityId || '';
                undoInput.value = button.dataset.undoId || '';
                operationName.textContent = button.dataset.operationName || 'هذا السجل';
                bootstrap.Modal.getOrCreateInstance(modalElement).show();
            });
        });
    });
}());
