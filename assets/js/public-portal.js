(function () {
    'use strict';

    var body = document.body;
    var themeToggle = document.querySelector('.theme-toggle');
    var passwordToggle = document.querySelector('.portal-password-toggle');
    var passwordInput = document.getElementById('portal-password');

    var updateThemeIcon = function () {
        if (!themeToggle) return;
        var icon = themeToggle.querySelector('i');
        if (icon) {
            icon.className = body.classList.contains('dark-mode') ? 'fas fa-sun' : 'fas fa-moon';
        }
    };

    var savedTheme = localStorage.getItem('theme') || localStorage.getItem('educore-public-theme');
    if (savedTheme === 'dark') {
        body.classList.add('dark-mode');
        document.documentElement.classList.add('dark-mode');
    }
    updateThemeIcon();

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var isDark = body.classList.toggle('dark-mode');
            document.documentElement.classList.toggle('dark-mode', isDark);
            localStorage.setItem('educore-public-theme', isDark ? 'dark' : 'light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon();

            var color = isDark ? '#60a5fa' : '#667eea';
            if (window.pJSDom && window.pJSDom[0] && window.pJSDom[0].pJS) {
                window.pJSDom[0].pJS.particles.color.value = color;
                window.pJSDom[0].pJS.particles.line_linked.color = color;
                window.pJSDom[0].pJS.fn.particlesRefresh();
            }
        });
    }

    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener('click', function () {
            var showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            passwordToggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
            passwordToggle.setAttribute('aria-label', showing ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور');
            var icon = passwordToggle.querySelector('i');
            if (icon) {
                icon.className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
        });
    }

    if (window.particlesJS && document.getElementById('particles-js')) {
        window.particlesJS('particles-js', {
            "particles": {
                "number": {
                    "value": 60,
                    "density": {
                        "enable": true,
                        "value_area": 800
                    }
                },
                "color": {
                    "value": body.classList.contains('dark-mode') ? "#6366f1" : "#667eea"
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
                    "color": body.classList.contains('dark-mode') ? "#6366f1" : "#667eea",
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
    }
}());
