(function () {
    'use strict';

    function initAssessmentSchemeBatchForm(root) {
        root = root || document;
        var form = root.querySelector('#assessmentSchemeBatchForm');
        if (!form || form.dataset.batchUiInitialized === '1') {
            return;
        }
        form.dataset.batchUiInitialized = '1';

        var selectAllTerms = form.querySelector('#batchSelectAllTerms');
        var selectAllGrades = form.querySelector('#batchSelectAllGrades');
        var annualEnabled = form.querySelector('#annualEnabled');
        var annualWeights = form.querySelector('#annualWeights');
        var annualControl = form.querySelector('[data-annual-control]');
        var annualEligibilityHelp = form.querySelector('#annualEligibilityHelp');
        var annualWeightSummary = form.querySelector('#annualWeightSummary');
        var annualWeightTotal = form.querySelector('#annualWeightTotal');
        var annualWeightStatus = form.querySelector('#annualWeightStatus');
        var termInputs = Array.from(form.querySelectorAll('.batch-term'));
        var annualWeightInputs = form.querySelectorAll('.annual-weight-input');
        var gradeInputs = Array.from(form.querySelectorAll('.batch-grade-all'));
        var classInputs = Array.from(form.querySelectorAll('.batch-grade-class'));

        function invalidatePreview() {
            var signature = form.querySelector('input[name="preview_signature"]');
            if (!signature) {
                return;
            }

            signature.remove();
            var preview = form.querySelector('[data-batch-preview]');
            if (preview) {
                preview.remove();
            }
            document.querySelectorAll('button[name="action"][value="create_batch"]').forEach(function (button) {
                if (button.form === form || button.closest('form') === form) {
                    button.remove();
                }
            });
        }

        function syncTerms() {
            form.querySelectorAll('.batch-term-card').forEach(function (card) {
                var termInput = card.querySelector('.batch-term');
                var isChecked = Boolean(termInput && termInput.checked);
                card.classList.toggle('border-primary', isChecked);
                card.classList.toggle('shadow', isChecked);
            });

            if (selectAllTerms) {
                var allTermsSelected = termInputs.length > 0 && termInputs.every(function (input) {
                    return input.checked;
                });
                selectAllTerms.classList.toggle('btn-primary', allTermsSelected);
                selectAllTerms.classList.toggle('btn-outline-primary', !allTermsSelected);
                selectAllTerms.innerHTML = allTermsSelected
                    ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                    : '<i class="fas fa-check-double me-1"></i>تحديد كل الترمات';
            }
        }

        function syncAcademicScope() {
            form.querySelectorAll('.assignment-grade-card').forEach(function (card) {
                var gradeInput = card.querySelector('.batch-grade-all');
                var gradeSelected = Boolean(gradeInput && gradeInput.checked);
                var cardClassInputs = Array.from(card.querySelectorAll('.batch-grade-class'));

                cardClassInputs.forEach(function (input) {
                    input.disabled = gradeSelected;
                    if (gradeSelected) {
                        input.checked = false;
                    }
                });

                var selectedClasses = cardClassInputs.filter(function (input) {
                    return input.checked;
                });
                var hasScope = gradeSelected || selectedClasses.length > 0;

                card.classList.toggle('border-primary', hasScope);
                card.classList.toggle('shadow', hasScope);

                var badge = card.querySelector('.assignment-grade-scope-badge');
                if (badge) {
                    badge.className = hasScope
                        ? 'badge bg-primary-subtle text-primary border border-primary assignment-grade-scope-badge'
                        : 'badge bg-light text-dark border assignment-grade-scope-badge';
                    badge.textContent = gradeSelected
                        ? 'الصف بالكامل'
                        : (selectedClasses.length > 0 ? selectedClasses.length + ' فصول' : 'غير محدد');
                }
            });

            form.querySelectorAll('.assignment-stage-group').forEach(function (stageGroup) {
                var stageGrades = Array.from(stageGroup.querySelectorAll('.batch-grade-all'));
                var allSelected = stageGrades.length > 0 && stageGrades.every(function (input) {
                    return input.checked;
                });
                var stageButton = stageGroup.querySelector('.select-assignment-stage-btn');
                if (stageButton) {
                    stageButton.classList.toggle('btn-primary', allSelected);
                    stageButton.classList.toggle('btn-outline-primary', !allSelected);
                    stageButton.innerHTML = allSelected
                        ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد المرحلة'
                        : '<i class="fas fa-check-double me-1"></i>تحديد المرحلة';
                }
            });

            if (selectAllGrades) {
                var allGradesSelected = gradeInputs.length > 0 && gradeInputs.every(function (input) {
                    return input.checked;
                });
                selectAllGrades.classList.toggle('btn-primary', allGradesSelected);
                selectAllGrades.classList.toggle('btn-outline-primary', !allGradesSelected);
                selectAllGrades.innerHTML = allGradesSelected
                    ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                    : '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف';
            }
        }

        function distributeAnnualWeights(inputs) {
            var remaining = 100;
            inputs.forEach(function (input, index) {
                var weight = index === inputs.length - 1
                    ? remaining
                    : Math.round((100 / inputs.length) * 1000) / 1000;
                input.value = weight;
                remaining = Math.round((remaining - weight) * 1000) / 1000;
            });
        }

        function updateAnnualWeightSummary(selectedInputs) {
            if (!annualWeightSummary || !annualWeightTotal || !annualWeightStatus) {
                return;
            }

            var total = selectedInputs.reduce(function (sum, input) {
                var value = Number(input.value);
                return sum + (Number.isFinite(value) ? value : 0);
            }, 0);
            total = Math.round(total * 1000) / 1000;

            var hasTwoPositiveWeights = selectedInputs.filter(function (input) {
                return Number(input.value) > 0;
            }).length >= 2;
            var allWeightsAreValid = selectedInputs.every(function (input) {
                var value = Number(input.value);
                return input.value.trim() !== '' && Number.isFinite(value) && value >= 0 && value <= 100;
            });
            var totalIsValid = Math.abs(total - 100) <= 0.001;
            var weightsAreValid = allWeightsAreValid && totalIsValid && hasTwoPositiveWeights;
            var validationMessage = weightsAreValid
                ? ''
                : 'يجب أن يساوي مجموع الأوزان 100% وأن يكون لترمين على الأقل وزن أكبر من صفر.';

            selectedInputs.forEach(function (input) {
                input.setCustomValidity(validationMessage);
            });
            annualWeightTotal.textContent = String(total);
            annualWeightStatus.textContent = weightsAreValid ? 'الأوزان صحيحة' : validationMessage;
            annualWeightSummary.classList.toggle('alert-success', weightsAreValid);
            annualWeightSummary.classList.toggle('alert-danger', !weightsAreValid);
        }

        function syncAnnualWeights(redistribute) {
            if (!annualEnabled || !annualWeights) {
                return;
            }
            var selectedIds = termInputs.map(function (input) {
                return input.checked ? input.value : null;
            }).filter(function (value) {
                return value !== null;
            });

            var annualIsEligible = selectedIds.length >= 2;
            if (!annualIsEligible && annualEnabled.checked) {
                annualEnabled.checked = false;
            }
            annualEnabled.disabled = !annualIsEligible;
            annualEnabled.setAttribute('aria-disabled', annualIsEligible ? 'false' : 'true');
            if (annualControl) {
                annualControl.classList.toggle('opacity-50', !annualIsEligible);
            }
            if (annualEligibilityHelp) {
                annualEligibilityHelp.textContent = annualIsEligible
                    ? 'فعّل الخيار لإدخال وزن كل ترم في النتيجة السنوية؛ يجب أن يكون المجموع 100%.'
                    : 'اختر ترمين على الأقل لإتاحة النتيجة السنوية وإدخال أوزان الترمات.';
            }

            var showWeights = annualIsEligible && annualEnabled.checked;
            annualWeights.classList.toggle('d-none', !showWeights);
            if (annualWeightSummary) {
                annualWeightSummary.classList.toggle('d-none', !showWeights);
            }
            annualWeightInputs.forEach(function (input) {
                var wrapper = input.closest('[data-annual-term-id]');
                var selected = wrapper && selectedIds.indexOf(wrapper.dataset.annualTermId) !== -1;
                if (wrapper) {
                    wrapper.classList.toggle('d-none', !showWeights || !selected);
                }
                input.disabled = !showWeights || !selected;
                input.required = showWeights && selected;
                if (input.disabled) {
                    input.setCustomValidity('');
                }
            });

            var selectedInputs = Array.prototype.filter.call(annualWeightInputs, function (input) {
                return !input.disabled;
            });
            if (showWeights && (redistribute || selectedInputs.every(function (input) {
                return input.value.trim() === '';
            }))) {
                distributeAnnualWeights(selectedInputs);
            }
            if (showWeights) {
                updateAnnualWeightSummary(selectedInputs);
            }
        }

        if (selectAllTerms) {
            selectAllTerms.addEventListener('click', function () {
                var allTermsSelected = termInputs.length > 0 && termInputs.every(function (input) {
                    return input.checked;
                });
                termInputs.forEach(function (input) {
                    input.checked = !allTermsSelected;
                });
                syncTerms();
                syncAnnualWeights(annualEnabled && annualEnabled.checked);
                invalidatePreview();
            });
        }

        termInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                syncTerms();
                syncAnnualWeights(annualEnabled && annualEnabled.checked);
                invalidatePreview();
            });
        });

        if (selectAllGrades) {
            selectAllGrades.addEventListener('click', function () {
                var allGradesSelected = gradeInputs.length > 0 && gradeInputs.every(function (input) {
                    return input.checked;
                });
                gradeInputs.forEach(function (input) {
                    input.checked = !allGradesSelected;
                    if (!allGradesSelected) {
                        form.querySelectorAll('.batch-grade-class[data-grade="' + input.dataset.grade + '"]').forEach(function (classInput) {
                            classInput.checked = false;
                        });
                    }
                });
                syncAcademicScope();
                invalidatePreview();
            });
        }

        form.querySelectorAll('.select-assignment-stage-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var stageGroup = this.closest('.assignment-stage-group');
                if (!stageGroup) {
                    return;
                }
                var stageGrades = Array.from(stageGroup.querySelectorAll('.batch-grade-all'));
                var allSelected = stageGrades.length > 0 && stageGrades.every(function (input) {
                    return input.checked;
                });
                stageGrades.forEach(function (input) {
                    input.checked = !allSelected;
                    if (!allSelected) {
                        form.querySelectorAll('.batch-grade-class[data-grade="' + input.dataset.grade + '"]').forEach(function (classInput) {
                            classInput.checked = false;
                        });
                    }
                });
                syncAcademicScope();
                invalidatePreview();
            });
        });

        gradeInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (input.checked) {
                    form.querySelectorAll('.batch-grade-class[data-grade="' + input.dataset.grade + '"]').forEach(function (classInput) {
                        classInput.checked = false;
                    });
                }
                syncAcademicScope();
                invalidatePreview();
            });
        });

        classInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (input.checked) {
                    var gradeInput = form.querySelector('.batch-grade-all[data-grade="' + input.dataset.grade + '"]');
                    if (gradeInput) {
                        gradeInput.checked = false;
                    }
                }
                syncAcademicScope();
                invalidatePreview();
            });
        });

        if (annualEnabled && annualWeights) {
            annualEnabled.addEventListener('change', function () {
                syncAnnualWeights(annualEnabled.checked);
                invalidatePreview();
            });
        }

        annualWeightInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                var selectedInputs = Array.prototype.filter.call(annualWeightInputs, function (weightInput) {
                    return !weightInput.disabled;
                });
                updateAnnualWeightSummary(selectedInputs);
            });
        });

        var subject = form.querySelector('#batchSubject');
        var template = form.querySelector('#batchTemplate');
        if (subject && template) {
            subject.addEventListener('change', function () {
                Array.prototype.forEach.call(template.options, function (option) {
                    if (!option.dataset.subject) {
                        return;
                    }
                    option.hidden = subject.value !== '' && option.dataset.subject !== subject.value;
                });
                if (template.selectedOptions[0] && template.selectedOptions[0].hidden) {
                    template.value = '';
                }
            });
            subject.dispatchEvent(new Event('change'));
        }

        form.addEventListener('input', invalidatePreview);
        form.addEventListener('change', invalidatePreview);

        syncTerms();
        syncAcademicScope();
        syncAnnualWeights(false);
    }

    window.initAssessmentSchemeBatchForm = initAssessmentSchemeBatchForm;
    document.addEventListener('DOMContentLoaded', function () {
        initAssessmentSchemeBatchForm(document);
    });
}());
