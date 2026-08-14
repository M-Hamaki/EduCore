/**
 * EduCore Push Notifications - Client Side
 * يدير تسجيل Service Worker والاشتراك في الإشعارات الفورية
 */
(function () {
    'use strict';

    // المفتاح العام VAPID (يتم تعيينه من PHP)
    const VAPID_PUBLIC_KEY = window.VAPID_PUBLIC_KEY || '';
    const BASE_URL = window.PUSH_BASE_URL || '';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

    if (!VAPID_PUBLIC_KEY || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        return; // المتصفح لا يدعم Push Notifications
    }

    /**
     * تحويل Base64 URL-safe إلى Uint8Array
     */
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * تسجيل Service Worker
     */
    async function registerServiceWorker() {
        try {
            const registration = await navigator.serviceWorker.register(BASE_URL + '/sw.js');
            return registration;
        } catch (error) {
            console.warn('Push: فشل تسجيل Service Worker:', error);
            return null;
        }
    }

    /**
     * التحقق من حالة الاشتراك الحالية
     */
    async function getSubscriptionState() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return {
            permission: Notification.permission,
            subscribed: !!subscription,
            subscription: subscription
        };
    }

    /**
     * الاشتراك في الإشعارات
     */
    async function subscribeToPush() {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return { success: false, message: 'تم رفض إذن الإشعارات' };
            }

            const registration = await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
            });

            // إرسال الاشتراك للسيرفر
            const response = await fetch(BASE_URL + '/api/push_subscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(subscription.toJSON())
            });

            const result = await response.json();
            return { success: result.success, message: result.message };
        } catch (error) {
            console.error('Push: فشل الاشتراك:', error);
            return { success: false, message: 'فشل في تفعيل الإشعارات' };
        }
    }

    /**
     * إلغاء الاشتراك
     */
    async function unsubscribeFromPush() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                // إخبار السيرفر
                await fetch(BASE_URL + '/api/push_unsubscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({ endpoint: subscription.endpoint })
                });

                await subscription.unsubscribe();
            }

            return { success: true, message: 'تم إلغاء الإشعارات الفورية' };
        } catch (error) {
            console.error('Push: فشل إلغاء الاشتراك:', error);
            return { success: false, message: 'فشل في إلغاء الإشعارات' };
        }
    }

    /**
     * تهيئة وتحديث واجهة زر الإشعارات
     */
    async function initPushButton() {
        const btn = document.getElementById('pushNotifBtn');
        if (!btn) return;

        const state = await getSubscriptionState();
        updateButtonUI(btn, state);

        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            btn.style.pointerEvents = 'none';
            const currentState = await getSubscriptionState();

            let result;
            if (currentState.subscribed) {
                result = await unsubscribeFromPush();
            } else {
                result = await subscribeToPush();
            }

            // تحديث الزر
            const newState = await getSubscriptionState();
            updateButtonUI(btn, newState);
            btn.style.pointerEvents = '';

            // عرض الرسالة عبر مكوّن Bootstrap المشترك بدل مكتبة تنبيه إضافية.
            if (typeof showAlert === 'function') {
                showAlert(result.success ? 'success' : 'warning', result.message);
            }
        });
    }

    /**
     * تحديث مظهر الزر
     */
    function updateButtonUI(btn, state) {
        const isDropdownItem = btn.classList.contains('dropdown-item');

        if (state.permission === 'denied') {
            btn.innerHTML = '<i class="fas fa-bell-slash me-2"></i> الإشعارات محظورة';
            btn.title = 'تم حظر الإشعارات من إعدادات المتصفح';
            if (!isDropdownItem) btn.style.opacity = '0.5';
        } else if (state.subscribed) {
            if (isDropdownItem) {
                btn.innerHTML = '<i class="fas fa-bell me-2 text-success"></i> الإشعارات مفعّلة ✓';
            } else {
                btn.innerHTML = '<i class="fas fa-bell"></i>';
                btn.style.background = 'rgba(25,135,84,0.3)';
            }
            btn.title = 'الإشعارات مفعّلة - اضغط لإلغائها';
        } else {
            if (isDropdownItem) {
                btn.innerHTML = '<i class="fas fa-bell me-2"></i> تفعيل الإشعارات';
            } else {
                btn.innerHTML = '<i class="fas fa-bell"></i>';
                btn.style.background = 'rgba(255,255,255,0.15)';
            }
            btn.title = 'اضغط لتفعيل الإشعارات الفورية';
        }
    }

    // التهيئة عند تحميل الصفحة
    document.addEventListener('DOMContentLoaded', async function () {
        await registerServiceWorker();
        await initPushButton();
    });

    // تصدير الدوال للاستخدام الخارجي
    window.EduCorePush = {
        subscribe: subscribeToPush,
        unsubscribe: unsubscribeFromPush,
        getState: getSubscriptionState
    };
})();
