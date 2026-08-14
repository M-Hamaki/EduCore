<?php
/**
 * مُحدِّد المعدل البسيط (Simple Rate Limiter)
 *
 * تنفيذ خفيف يعتمد على الملفات لمحدودية معدل الطلبات لكل مفتاح (عادةً IP).
 * مناسب لنقاط النهاية العامة منخفضة الحجم مثل verify_certificate.php.
 *
 * ملاحظات:
 * - يُخزَّن سجل المحاولات تحت storage/framework/rate_limits/ الذي يحميه
 *   storage/.htaccess (Require all denied) من الوصول العام عبر الويب.
 * - التنفيذ atomic بقدر file_put_contents + LOCK_EX على الأنظمة المحلية.
 * - ليس بديلاً عن rate limiter حقيقي للحمل العالي (Redis/Memcached)؛ هذا حل
 *   عملي لتقليل خطر التعداد (enumeration) على نقطة التحقق العامة.
 */
class RateLimiter
{
    /** @var string المجلد الجذر لتخزين ملفات تحديد المعدل */
    private static $storageDir;

    /**
     * تسجيل محاولة لمفتاح معيّن ضمن نافذة زمنية.
     *
     * @param string $key            مفتاح فريد (مثل 'cert_verify:' . IP)
     * @param int    $max            الحد الأقصى للمحاولات في النافذة
     * @param int    $windowSeconds  طول النافذة بالثواني
     * @return bool                 true إذا سُمح بالمحاولة، false إذا تجاوز الحد
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . sha1($key) . '.json';

        $now = time();
        $cutoff = $now - $windowSeconds;

        // اقرأ المحاولات الحالية (آمن في حالة الملف غير موجود).
        $attempts = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $attempts = array_values(array_filter($decoded, static function ($t) use ($cutoff) {
                        return is_int($t) && $t > $cutoff;
                    }));
                }
            }
        }

        if (count($attempts) >= $max) {
            return false;
        }

        $attempts[] = $now;
        file_put_contents($file, json_encode($attempts), LOCK_EX);
        return true;
    }

    /**
     * مسح جميع ملفات تحديد المعدل المنتهية (تنظيف دوري اختياري).
     */
    public static function gc(int $windowSeconds): void
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - $windowSeconds;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                @unlink($file);
                continue;
            }
            $valid = array_values(array_filter($decoded, static function ($t) use ($cutoff) {
                return is_int($t) && $t > $cutoff;
            }));
            if (empty($valid)) {
                @unlink($file);
            } else {
                file_put_contents($file, json_encode($valid), LOCK_EX);
            }
        }
    }

    private static function getStorageDir(): string
    {
        if (self::$storageDir === null) {
            self::$storageDir = __DIR__ . '/../storage/framework/rate_limits';
        }
        return self::$storageDir;
    }
}
