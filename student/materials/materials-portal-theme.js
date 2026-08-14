/**
 * Materials Portal - Theme System
 * EduCore
 * نظام Dark Mode موحد مع البوابة
 */

document.addEventListener('DOMContentLoaded', function () {

    // ====================================
    // DARK MODE SYSTEM
    // ====================================

    // تحميل الوضع المحفوظ
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.body.classList.toggle('dark-mode', savedTheme === 'dark');
    document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark');

    // زر تبديل الوضع
    const themeToggle = document.querySelector('.theme-toggle');

    // دالة تحديث أيقونة الوضع
    function updateThemeIcon(isDark) {
        const icon = themeToggle?.querySelector('i');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    if (themeToggle) {
        // تحديث الأيقونة عند التحميل
        updateThemeIcon(savedTheme === 'dark');

        themeToggle.addEventListener('click', function () {
            const isDark = document.body.classList.toggle('dark-mode');
            document.documentElement.classList.toggle('dark-mode');

            // حفظ الاختيار
            localStorage.setItem('theme', isDark ? 'dark' : 'light');

            // تحديث الأيقونة
            updateThemeIcon(isDark);

            // تأثير صوتي (اختياري)
            if (isDark) {
                console.log('🌙 Dark Mode Activated');
            } else {
                console.log('☀️ Light Mode Activated');
            }
        });
    }

    // ====================================
    // PARTICLES.JS CONFIGURATION - محسّن للأداء
    // ====================================

    if (typeof particlesJS !== 'undefined') {
        // الكشف عن نوع الجهاز
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        const isLowPowerMode = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // تحديد عدد الجزيئات حسب الجهاز
        const particleCount = isMobile ? 40 : (isLowPowerMode ? 30 : 50);
        const particleSpeed = isMobile ? 1.5 : 1.8;

        particlesJS('particles-js', {
            particles: {
                number: {
                    value: particleCount,
                    density: {
                        enable: true,
                        value_area: 1000  // زيادة المساحة = أداء أفضل
                    }
                },
                color: {
                    value: document.body.classList.contains('dark-mode') ? '#60a5fa' : '#667eea'
                },
                shape: {
                    type: 'circle',
                    stroke: {
                        width: 0,
                        color: '#000000'
                    }
                },
                opacity: {
                    value: 0.4,  // تقليل الشفافية قليلاً
                    random: false,
                    anim: {
                        enable: false
                    }
                },
                size: {
                    value: 3,
                    random: true,
                    anim: {
                        enable: false
                    }
                },
                line_linked: {
                    enable: true,
                    distance: 130,  // تقليل المسافة = حسابات أقل
                    color: document.body.classList.contains('dark-mode') ? '#60a5fa' : '#667eea',
                    opacity: 0.3,  // تقليل الشفافية
                    width: 1
                },
                move: {
                    enable: true,
                    speed: particleSpeed,
                    direction: 'none',
                    random: false,
                    straight: false,
                    out_mode: 'out',
                    bounce: false
                }
            },
            interactivity: {
                detect_on: 'canvas',
                events: {
                    onhover: {
                        enable: !isLowPowerMode,  // تعطيل على الأجهزة الضعيفة
                        mode: 'grab'
                    },
                    onclick: {
                        enable: !isLowPowerMode,  // تعطيل على الأجهزة الضعيفة
                        mode: 'push'
                    },
                    resize: true
                },
                modes: {
                    grab: {
                        distance: 120,  // تقليل المسافة
                        line_linked: {
                            opacity: 0.8
                        }
                    },
                    push: {
                        particles_nb: 2  // إضافة جزيئات أقل عند النقر
                    }
                }
            },
            retina_detect: false  // تعطيل لتحسين الأداء
        });

        // تحديث لون الجزيئات عند التبديل
        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                setTimeout(() => {
                    const isDark = document.body.classList.contains('dark-mode');
                    const color = isDark ? '#60a5fa' : '#667eea';

                    if (window.pJSDom && window.pJSDom[0]) {
                        window.pJSDom[0].pJS.particles.color.value = color;
                        window.pJSDom[0].pJS.particles.line_linked.color = color;
                        window.pJSDom[0].pJS.fn.particlesRefresh();
                    }
                }, 100);
            });
        }
    }

    // ====================================
    // TABLE ANIMATIONS
    // ====================================

    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach((row, index) => {
        row.style.animation = `fadeIn 0.5s ease-out ${index * 0.05}s both`;
    });

    // ====================================
    // DOWNLOAD ANALYTICS (اختياري)
    // ====================================

    const downloadLinks = document.querySelectorAll('.download-btn, a[download]');
    downloadLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            const filename = this.getAttribute('href').split('/').pop();
            console.log(`📥 Downloading: ${filename}`);

            // يمكن إضافة Google Analytics هنا
            if (typeof gtag !== 'undefined') {
                gtag('event', 'download', {
                    'event_category': 'materials',
                    'event_label': filename
                });
            }
        });
    });

    // ====================================
    // LOGO CLICK EVENT
    // ====================================

    const logo = document.querySelector('.materials-logo');
    if (logo) {
        logo.addEventListener('click', function () {
            // يمكن إضافة تأثير صوتي أو رسوم متحركة
            this.style.animation = 'none';
            setTimeout(() => {
                this.style.animation = 'fadeInDown 0.8s ease-out';
            }, 10);
        });
    }

    // ====================================
    // BACK BUTTON CONFIRMATION (اختياري)
    // ====================================

    const backButton = document.querySelector('.back-button');
    if (backButton && backButton.textContent.includes('تسجيل الخروج')) {
        backButton.addEventListener('click', function (e) {
            if (!confirm('هل أنت متأكد من تسجيل الخروج؟')) {
                e.preventDefault();
            }
        });
    }

    // ====================================
    // KEYBOARD SHORTCUTS
    // ====================================

    document.addEventListener('keydown', function (e) {
        // Ctrl/Cmd + D = Toggle Dark Mode
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            e.preventDefault();
            if (themeToggle) themeToggle.click();
        }

        // Escape = Back
        if (e.key === 'Escape' && backButton) {
            backButton.click();
        }
    });

    // ====================================
    // ACCESSIBILITY
    // ====================================

    // إضافة ARIA labels
    if (themeToggle) {
        themeToggle.setAttribute('aria-label', 'Toggle Dark Mode');
        themeToggle.setAttribute('role', 'button');
    }

    // ====================================
    // CONSOLE MESSAGE
    // ====================================

    console.log('%c🎓 EduCore', 'color: #667eea; font-size: 20px; font-weight: bold;');
    console.log('%c📚 Materials Download System v2.0', 'color: #764ba2; font-size: 14px;');
    console.log('%cKeyboard Shortcuts:', 'font-weight: bold; margin-top: 10px;');
    console.log('  Ctrl/Cmd + D = Toggle Dark Mode');
    console.log('  Escape = Back');
});

// ====================================
// SERVICE WORKER (PWA Support - اختياري)
// ====================================

if ('serviceWorker' in navigator) {
    // يمكن إضافة Service Worker لاحقاً للعمل Offline
    console.log('✅ Service Worker Support Available');
}

// ====================================
// EXPORT FOR EXTERNAL USE
// ====================================

window.MaterialsPortal = {
    version: '2.0.0',
    toggleDarkMode: function () {
        const toggle = document.querySelector('.theme-toggle');
        if (toggle) toggle.click();
    },
    getCurrentTheme: function () {
        return document.body.classList.contains('dark-mode') ? 'dark' : 'light';
    },
    setTheme: function (theme) {
        const isDark = theme === 'dark';
        document.body.classList.toggle('dark-mode', isDark);
        document.documentElement.classList.toggle('dark-mode', isDark);
        localStorage.setItem('theme', theme);
    }
};
