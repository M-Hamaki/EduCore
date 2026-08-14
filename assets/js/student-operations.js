(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.student-operation-undo, .student-operation-redo');
        if (!button) return;

        const isRedo = button.classList.contains('student-operation-redo');
        const prefix = isRedo ? 'redo' : 'undo';
        const activityInput = document.getElementById(prefix + 'StudentActivityId');
        const undoInput = document.getElementById(prefix + 'StudentOperationId');
        const target = document.getElementById(prefix + 'StudentOperationTarget');
        const modalElement = document.getElementById(prefix + 'StudentOperationModal');
        if (!activityInput || !undoInput || !target || !modalElement) return;

        activityInput.value = button.dataset.activityId || '';
        undoInput.value = button.dataset.undoId || '';
        target.textContent = button.dataset.targetName || 'العملية المحددة';
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    });
})();
