// Unified particles + theme toggle (reports style compatible)
(function () {
    function initParticles() {
        // تحقق من وجود مكتبة particles
        if (typeof particlesJS === 'undefined') {
            console.log('Particles.js library not loaded');
            return;
        }

        // تحقق من وجود عنصر particles
        const particlesContainer = document.getElementById('particles-js');
        if (!particlesContainer) {
            console.log('Particles container not found');
            return;
        }

        console.log('Initializing particles...');

        // التحقق من أن الصفحة هي صفحة تسجيل الدخول
        const isLoginPage = document.body.classList.contains('login-page');

        // إضافة ستايل مباشر للعنصر للتأكد من ظهوره
        particlesContainer.style.position = 'fixed';
        particlesContainer.style.top = '0';
        particlesContainer.style.left = '0';
        particlesContainer.style.width = '100%';
        particlesContainer.style.height = '100%';
        particlesContainer.style.pointerEvents = 'none';

        // جعل الجسيمات خلف المحتوى دائماً
        if (isLoginPage) {
            particlesContainer.style.zIndex = '1';
        } else {
            particlesContainer.style.zIndex = '-1';
        }

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

        console.log('Particles initialized successfully');
    }

    // حفظ وتطبيق الثيم المحفوظ
    function applySavedTheme() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.body.classList.add('dark-mode');
            document.body.classList.remove('light-mode');
        } else {
            document.body.classList.add('light-mode');
            document.body.classList.remove('dark-mode');
        }
    }

    // إضافة زر تبديل الوضع المظلم
    function addThemeToggle() {
        // التحقق من عدم وجود زر مسبقاً
        if (document.querySelector('.theme-toggle')) return;
        
        const toggleButton = document.createElement('button');
        toggleButton.classList.add('theme-toggle');
        toggleButton.setAttribute('aria-label', 'تبديل الوضع المظلم');
        toggleButton.setAttribute('title', 'تبديل الوضع المظلم/الفاتح');
        
        toggleButton.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                document.body.classList.remove('light-mode');
                localStorage.setItem('theme', 'dark');
                toggleButton.setAttribute('title', 'تبديل إلى الوضع الفاتح');
            } else {
                document.body.classList.add('light-mode');
                localStorage.setItem('theme', 'light');
                toggleButton.setAttribute('title', 'تبديل إلى الوضع المظلم');
            }
        });
        
        document.body.appendChild(toggleButton);
    }

    // التأكد من تحميل كل شيء
    document.addEventListener('DOMContentLoaded', () => {
        applySavedTheme();
        addThemeToggle();
        // تأخير بسيط للتأكد من تحميل كل شيء
        setTimeout(initParticles, 100);
    });

    // تجربة تحميل particles عند تحميل النافذة أيضاً
    window.addEventListener('load', () => {
        setTimeout(initParticles, 200);
    });
})();
// Unified particles + theme toggle (reports style compatible)
(function () {
    function initParticles() {
        // تحقق من وجود مكتبة particles
        if (typeof particlesJS === 'undefined') {
            console.log('Particles.js library not loaded');
            return;
        }

        // تحقق من وجود عنصر particles
        const particlesContainer = document.getElementById('particles-js');
        if (!particlesContainer) {
            console.log('Particles container not found');
            return;
        }

        console.log('Initializing particles...');

        // التحقق من أن الصفحة هي صفحة تسجيل الدخول
        const isLoginPage = document.body.classList.contains('login-page');

        // إضافة ستايل مباشر للعنصر للتأكد من ظهوره
        particlesContainer.style.position = 'fixed';
        particlesContainer.style.top = '0';
        particlesContainer.style.left = '0';
        particlesContainer.style.width = '100%';
        particlesContainer.style.height = '100%';
        particlesContainer.style.pointerEvents = 'none';

        // جعل الجسيمات خلف المحتوى دائماً
        if (isLoginPage) {
            particlesContainer.style.zIndex = '1';
        } else {
            particlesContainer.style.zIndex = '-1';
        }

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

        console.log('Particles initialized successfully');
    }

    // حفظ وتطبيق الثيم المحفوظ
    function applySavedTheme() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.body.classList.add('dark-mode');
            document.body.classList.remove('light-mode');
        } else {
            document.body.classList.add('light-mode');
            document.body.classList.remove('dark-mode');
        }
    }

    // ملاحظة: تم حذف زر تبديل الوضع القديم
    // لم يعد هناك حاجة لزر التبديل في صفحة تسجيل الدخول

    // التأكد من تحميل كل شيء
    document.addEventListener('DOMContentLoaded', () => {
        applySavedTheme();
        // تم حذف: addToggle();
        // تأخير بسيط للتأكد من تحميل كل شيء
        setTimeout(initParticles, 100);
    });

    // تجربة تحميل particles عند تحميل النافذة أيضاً
    window.addEventListener('load', () => {
        setTimeout(initParticles, 200);
    });
})();
