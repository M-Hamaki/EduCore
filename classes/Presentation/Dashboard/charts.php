<?php
$dashboardChartJsonFlags = JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_INVALID_UTF8_SUBSTITUTE;
$dashboardChartJson = static function ($value) use ($dashboardChartJsonFlags): string {
    $encoded = json_encode(is_array($value) ? $value : [], $dashboardChartJsonFlags);
    return is_string($encoded) ? $encoded : '[]';
};
?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // تهيئة ألوان الرسوم البيانية
        const chartColors = [
            '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
            '#06b6d4', '#ec4899', '#f97316', '#14b8a6', '#6366f1'
        ];
        const chartColorsBg = chartColors.map(c => c + '22');

        Chart.defaults.font.family = 'Tajawal, sans-serif';
        Chart.defaults.font.size = 13;
        Chart.defaults.plugins.legend.rtl = true;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;

        // 1. توزيع الطلاب حسب المرحلة (Doughnut)
        const stageData = <?php echo $dashboardChartJson($chart_students_by_stage ?? []); ?>;
        if (stageData.length > 0 && document.getElementById('chartStudentsStage')) {
            new Chart(document.getElementById('chartStudentsStage'), {
                type: 'doughnut',
                data: {
                    labels: stageData.map(r => r.stage_name),
                    datasets: [{
                        data: stageData.map(r => parseInt(r.cnt)),
                        backgroundColor: chartColors.slice(0, stageData.length),
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 15 } },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                    return ctx.label + ': ' + ctx.parsed.toLocaleString('ar-SA') + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // 2. توزيع الطلاب حسب الصف (Horizontal Bar)
        const gradeData = <?php echo $dashboardChartJson($chart_students_by_grade ?? []); ?>;
        if (gradeData.length > 0 && document.getElementById('chartStudentsGrade')) {
            new Chart(document.getElementById('chartStudentsGrade'), {
                type: 'bar',
                data: {
                    labels: gradeData.map(r => r.grade_name),
                    datasets: [{
                        label: 'عدد الطلاب',
                        data: gradeData.map(r => parseInt(r.cnt)),
                        backgroundColor: chartColors.slice(0, gradeData.length).map(c => c + 'cc'),
                        borderColor: chartColors.slice(0, gradeData.length),
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.x.toLocaleString('ar-SA') + ' طالب'
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // 3. التقييمات الشهرية (Line/Area)
        const evalData = <?php echo $dashboardChartJson($chart_monthly_evaluations ?? []); ?>;
        if (evalData.length > 0 && document.getElementById('chartMonthlyEvals')) {
            new Chart(document.getElementById('chartMonthlyEvals'), {
                type: 'line',
                data: {
                    labels: evalData.map(r => r.month_label),
                    datasets: [
                        {
                            label: 'تقييمات إيجابية',
                            data: evalData.map(r => parseInt(r.positive_cnt)),
                            borderColor: '#10b981',
                            backgroundColor: '#10b98122',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            borderWidth: 2.5
                        },
                        {
                            label: 'تقييمات سلبية',
                            data: evalData.map(r => parseInt(r.negative_cnt)),
                            borderColor: '#ef4444',
                            backgroundColor: '#ef444422',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            borderWidth: 2.5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { padding: 20 } },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('ar-SA')
                            }
                        }
                    },
                    scales: {
                        x: { grid: { color: '#f1f5f9' } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } }
                    }
                }
            });
        }

        // 4. توزيع العاملين حسب الدور (Pie)
        const staffData = <?php echo $dashboardChartJson($chart_staff_by_role ?? []); ?>;
        const roleLabels = { admin: 'إداريين', teacher: 'معلمين', specialist: 'أخصائيين' };
        if (staffData.length > 0 && document.getElementById('chartStaffRoles')) {
            new Chart(document.getElementById('chartStaffRoles'), {
                type: 'pie',
                data: {
                    labels: staffData.map(r => roleLabels[r.role] || r.role),
                    datasets: [{
                        data: staffData.map(r => parseInt(r.active_cnt) + parseInt(r.inactive_cnt)),
                        backgroundColor: ['#3b82f6', '#10b981', '#8b5cf6'],
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 15 } },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const role = staffData[ctx.dataIndex];
                                    return (roleLabels[role.role] || role.role) + ': ' + ctx.parsed.toLocaleString('ar-SA') + ' (نشط: ' + parseInt(role.active_cnt).toLocaleString('ar-SA') + ')';
                                }
                            }
                        }
                    }
                }
            });
        }

        // 5. كثافة الفصول (Bar)
        const classStudentsData = <?php echo $dashboardChartJson($chart_students_per_class ?? []); ?>;
        if (classStudentsData.length > 0 && document.getElementById('chartStudentsPerClass')) {
            new Chart(document.getElementById('chartStudentsPerClass'), {
                type: 'bar',
                data: {
                    labels: classStudentsData.map(r => r.class_name),
                    datasets: [{
                        label: 'عدد الطلاب بالفصل',
                        data: classStudentsData.map(r => parseInt(r.cnt)),
                        backgroundColor: '#3b82f6cc',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.y.toLocaleString('ar-SA') + ' طالب'
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } }
                    }
                }
            });
        }

        // 6. أعلى المعلمين نشاطاً في التقييمات (Horizontal Bar)
        const topTeachersData = <?php echo $dashboardChartJson($chart_top_teachers ?? []); ?>;
        if (topTeachersData.length > 0 && document.getElementById('chartTopTeachers')) {
            new Chart(document.getElementById('chartTopTeachers'), {
                type: 'bar',
                data: {
                    labels: topTeachersData.map(r => r.teacher_name),
                    datasets: [{
                        label: 'عدد التقييمات الممنوحة',
                        data: topTeachersData.map(r => parseInt(r.cnt)),
                        backgroundColor: '#10b981cc',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.x.toLocaleString('ar-SA') + ' تقييم'
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // 7. أكثر الفصول حصداً للتقييمات (Bar)
        const topClassesData = <?php echo $dashboardChartJson($chart_top_classes_evals ?? []); ?>;
        if (topClassesData.length > 0 && document.getElementById('chartTopClassesEvals')) {
            new Chart(document.getElementById('chartTopClassesEvals'), {
                type: 'bar',
                data: {
                    labels: topClassesData.map(r => r.class_name),
                    datasets: [{
                        label: 'عدد التقييمات المستلمة',
                        data: topClassesData.map(r => parseInt(r.cnt)),
                        backgroundColor: '#f59e0bcc',
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.y.toLocaleString('ar-SA') + ' تقييم'
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } }
                    }
                }
            });
        }

        // 8. أعلى المستخدمين نشاطاً بالعمليات (Horizontal Bar)
        const topUsersActionsData = <?php echo $dashboardChartJson($chart_top_users_actions ?? []); ?>;
        if (topUsersActionsData.length > 0 && document.getElementById('chartTopUsersActions')) {
            new Chart(document.getElementById('chartTopUsersActions'), {
                type: 'bar',
                data: {
                    labels: topUsersActionsData.map(r => r.user_name),
                    datasets: [{
                        label: 'عدد العمليات المنفذة',
                        data: topUsersActionsData.map(r => parseInt(r.cnt)),
                        backgroundColor: '#4b5563cc',
                        borderColor: '#4b5563',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.x.toLocaleString('ar-SA') + ' عملية'
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // 9. الدروس والمواد المرفوعة حسب الصف (Bar)
        const materialsGradeData = <?php echo $dashboardChartJson($chart_materials_per_grade ?? []); ?>;
        if (materialsGradeData.length > 0 && document.getElementById('chartMaterialsPerGrade')) {
            new Chart(document.getElementById('chartMaterialsPerGrade'), {
                type: 'bar',
                data: {
                    labels: materialsGradeData.map(r => r.grade_name),
                    datasets: [{
                        label: 'عدد المواد المرفوعة',
                        data: materialsGradeData.map(r => parseInt(r.cnt)),
                        backgroundColor: '#2563ebcc',
                        borderColor: '#2563eb',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed.y.toLocaleString('ar-SA') + ' مادة/ملف'
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } }
                    }
                }
            });
        }

        // 10. استهلاك الذكاء الاصطناعي اليومي (Line Chart)
        const aiApiUsageData = <?php echo $dashboardChartJson($chart_ai_api_usage ?? []); ?>;
        if (aiApiUsageData.length > 0 && document.getElementById('chartAiApiUsage')) {
            new Chart(document.getElementById('chartAiApiUsage'), {
                type: 'line',
                data: {
                    labels: aiApiUsageData.map(r => r.date_label),
                    datasets: [
                        {
                            label: 'عدد الطلبات (API Calls)',
                            data: aiApiUsageData.map(r => parseInt(r.cnt)),
                            borderColor: '#7c3aed',
                            backgroundColor: '#7c3aed22',
                            fill: true,
                            tension: 0.3,
                            yAxisID: 'y'
                        },
                        {
                            label: 'الرموز المستهلكة (Tokens)',
                            data: aiApiUsageData.map(r => parseInt(r.tokens || 0)),
                            borderColor: '#ec4899',
                            backgroundColor: '#ec489922',
                            fill: false,
                            tension: 0.3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: { grid: { color: '#f1f5f9' } },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            grid: { color: '#f1f5f9' },
                            title: { display: true, text: 'عدد طلبات API' }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'الرموز (Tokens)' }
                        }
                    }
                }
            });
        }
    });
</script>
