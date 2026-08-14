<?php
/**
 * محمّل ملف البيئة (.env)
 * Lightweight .env file loader for EduCore
 * 
 * يُحمّل تلقائياً من config/database.php
 * Loads environment variables from .env file into $_ENV and getenv()
 */

function loadEnvFile($path = null) {
    if ($path === null) {
        $path = dirname(__DIR__) . '/.env';
    }
    
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }
    
    foreach ($lines as $line) {
        // تجاهل التعليقات والأسطر الفارغة
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        
        // تقسيم على أول = فقط
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        
        // إزالة علامات الاقتباس إن وجدت
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        
        // تعيين في $_ENV و $_SERVER و putenv
        $processValue = function_exists('getenv') ? getenv($key) : false;
        if (!array_key_exists($key, $_ENV)
            && !array_key_exists($key, $_SERVER)
            && $processValue === false) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            if (function_exists('putenv')) {
                @putenv("$key=$value");
            }
        }
    }
    
    return true;
}

/**
 * الحصول على قيمة من البيئة مع قيمة افتراضية
 * Get environment variable with fallback default
 */
function env($key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? @getenv($key) : null);
    if ($value === false || $value === null) {
        return $default;
    }
    return $value;
}

// تحميل تلقائي عند include
loadEnvFile();
