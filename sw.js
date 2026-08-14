/**
 * Service Worker للإشعارات الفورية - EduCore
 */

// استقبال حدث Push
self.addEventListener('push', function (event) {
    let data = { title: 'إشعار جديد', body: 'لديك إشعار جديد من النظام', icon: '/assets/img/logo.png' };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body || '',
        icon: data.icon || '/assets/img/logo.png',
        badge: data.badge || '/assets/img/badge.png',
        dir: 'rtl',
        lang: 'ar',
        tag: data.tag || 'educore-notification-' + Date.now(),
        renotify: true,
        vibrate: [200, 100, 200],
        data: {
            url: data.url || '/',
            notification_id: data.notification_id || null
        },
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// النقر على الإشعار
self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const urlToOpen = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // إذا كان هناك نافذة مفتوحة، استخدمها
            for (let client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }
            // فتح نافذة جديدة
            return clients.openWindow(urlToOpen);
        })
    );
});

// تثبيت Service Worker
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

// تفعيل Service Worker
self.addEventListener('activate', function (event) {
    event.waitUntil(clients.claim());
});
