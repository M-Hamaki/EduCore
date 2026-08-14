// إعدادات مكتبة Particles.js
particlesJS('particles-js', {
    "particles": {
        "number": {
            "value": 60,
            "density": {
                "enable": true,
                "value_area": 800
            }
        },
        "color": {
            "value": "#667eea"
        },
        "shape": {
            "type": "circle",
            "stroke": {
                "width": 0,
                "color": "#000000"
            }
        },
        "opacity": {
            "value": 0.3,
            "random": false,
            "anim": {
                "enable": false,
                "speed": 1,
                "opacity_min": 0.1,
                "sync": false
            }
        },
        "size": {
            "value": 3,
            "random": true,
            "anim": {
                "enable": false,
                "speed": 40,
                "size_min": 0.1,
                "sync": false
            }
        },
        "line_linked": {
            "enable": true,
            "distance": 150,
            "color": "#667eea",
            "opacity": 0.2,
            "width": 1
        },
        "move": {
            "enable": true,
            "speed": 2,
            "direction": "none",
            "random": false,
            "straight": false,
            "out_mode": "out",
            "bounce": false
        }
    },
    "interactivity": {
        "detect_on": "canvas",
        "events": {
            "onhover": {
                "enable": true,
                "mode": "repulse"
            },
            "onclick": {
                "enable": true,
                "mode": "push"
            },
            "resize": true
        },
        "modes": {
            "repulse": {
                "distance": 100,
                "duration": 0.4
            },
            "push": {
                "particles_nb": 2
            }
        }
    },
    "retina_detect": true
});

// دالة لتحديث لون الـ particles حسب الثيم
function updateParticlesTheme(theme) {
    const particlesContainer = document.getElementById('particles-js');
    if (particlesContainer) {
        if (theme === 'dark') {
            particlesContainer.style.background = '#0f1419';
            if (window.pJSDom && window.pJSDom[0] && window.pJSDom[0].pJS) {
                window.pJSDom[0].pJS.particles.color.value = '#6366f1';
                window.pJSDom[0].pJS.particles.line_linked.color = '#6366f1';
            }
        } else {
            particlesContainer.style.background = '#fafbfc';
            if (window.pJSDom && window.pJSDom[0] && window.pJSDom[0].pJS) {
                window.pJSDom[0].pJS.particles.color.value = '#4f46e5';
                window.pJSDom[0].pJS.particles.line_linked.color = '#4f46e5';
            }
        }
    }
}

// إضافة مستمع أحداث للتحميل الكامل للصفحة
document.addEventListener('DOMContentLoaded', function() {
    // تطبيق الثيم المحفوظ على الخلفية
    const currentTheme = localStorage.getItem('theme') || 'light';
    updateParticlesTheme(currentTheme);

    // إخفاء شاشة التحميل عند تحميل الصفحة
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
    }

    // إضافة تأثيرات الدخول
    const container = document.querySelector('.container');
    if (container) {
        container.classList.add('fade-in');
        
        // تأثير تدريجي للعناصر
        const elements = container.querySelectorAll('.nav-button');
        elements.forEach((element, index) => {
            element.style.opacity = '0';
            element.style.transform = 'translateY(20px)';
            setTimeout(() => {
                element.style.transition = 'all 0.5s ease';
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, 300 + (index * 100));
        });
    }

    // التعامل مع أزرار التنقل
    const navButtons = document.querySelectorAll('.nav-button');
    
    navButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            // إظهار شاشة التحميل
            if (loadingOverlay) {
                loadingOverlay.classList.add('active');
            }
            
            // الحصول على الرابط والانتقال بعد تأخير
            const href = this.getAttribute('href');
            
            setTimeout(() => {
                window.location.href = href;
            }, 1000);
        });
    });
});

// إضافة مستمع لأحداث التنقل للتعامل مع زر الرجوع
window.addEventListener('pageshow', function(event) {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
    }
});

// إضافة مستمع لحدث الرجوع في التاريخ
window.addEventListener('popstate', function(event) {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
    }
});

// التأكد من إخفاء شاشة التحميل عند مغادرة الصفحة
window.addEventListener('beforeunload', function() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
    }
});