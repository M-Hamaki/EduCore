# 🎬 أفضل الممارسات لعرض الفيديو الافتتاحي

## 📋 نظرة عامة

الفيديو الافتتاحي (Intro Video) هو أول ما يراه المستخدم عند دخوله للموقع. هناك عدة استراتيجيات لعرضه، ولكل منها مزايا وعيوب.

---

## 🎯 الطرق المختلفة لعرض الفيديو

### 1️⃣ **الطريقة الحالية: فيديو إجباري مرة واحدة** ⭐ (موصى بها)

**الوصف:**
- يُعرض الفيديو **مرة واحدة** عند أول زيارة
- يُحفظ في Session أن المستخدم شاهده
- لا يُعرض مرة أخرى إلا إذا أغلق المتصفح

**المزايا:**
- ✅ لا يزعج المستخدم بتكرار
- ✅ سريع في الزيارات اللاحقة
- ✅ مناسب للمدارس (رسالة ترحيبية)
- ✅ يحافظ على تجربة المستخدم

**العيوب:**
- ⚠️ قد يفوت على بعض المستخدمين
- ⚠️ يُعاد عرضه عند إغلاق المتصفح

**الكود الحالي:**
```php
// في index.php
if (!isset($_SESSION['intro_shown']) && !isset($_GET['skip_intro'])) {
    $_SESSION['intro_shown'] = true;
    header('Location: intro_youtube.php');
    exit;
}
```

**متى تستخدمها:**
- ✅ أنظمة تعليمية
- ✅ مواقع المدارس
- ✅ بوابات الطلاب
- ✅ عند وجود رسالة ترحيبية قصيرة

---

### 2️⃣ **فيديو إجباري في كل زيارة** ❌ (غير موصى به)

**الوصف:**
- يُعرض الفيديو **في كل مرة** يدخل المستخدم

**الكود:**
```php
// عدم استخدام Session - يُعرض دائماً
if (!isset($_GET['skip_intro'])) {
    header('Location: intro_youtube.php');
    exit;
}
```

**المزايا:**
- ✅ يضمن مشاهدة الفيديو
- ✅ مناسب للإعلانات

**العيوب:**
- ❌ مزعج جداً للمستخدمين
- ❌ يبطئ الوصول للمحتوى
- ❌ قد يدفع المستخدمين للمغادرة
- ❌ سيء لتجربة المستخدم

**متى تستخدمها:**
- ⚠️ فقط للإعلانات المهمة جداً
- ⚠️ إشعارات طوارئ
- ❌ لا تستخدمها بدون سبب قوي

---

### 3️⃣ **فيديو اختياري مع زر "تخطي"** ⭐⭐ (موصى بها بشدة)

**الوصف:**
- يُعرض الفيديو مرة واحدة
- **زر "تخطي" واضح** من البداية
- المستخدم يتحكم

**الكود المحسّن:**
```php
// في intro_youtube.php
<div class="skip-button-wrapper">
    <a href="index.php?skip_intro=1" class="skip-btn">
        <i class="fas fa-forward"></i> تخطي الفيديو
    </a>
    <span class="skip-timer">يمكنك التخطي بعد <span id="countdown">5</span> ثواني</span>
</div>

<style>
.skip-button-wrapper {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 9999;
}

.skip-btn {
    display: none; /* يظهر بعد 5 ثواني */
    background: rgba(255, 255, 255, 0.9);
    color: #333;
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.skip-btn:hover {
    background: white;
    transform: scale(1.05);
}
</style>

<script>
let countdown = 5;
const timerElement = document.getElementById('countdown');
const skipBtn = document.querySelector('.skip-btn');
const timerWrapper = document.querySelector('.skip-timer');

const interval = setInterval(() => {
    countdown--;
    timerElement.textContent = countdown;
    
    if (countdown <= 0) {
        clearInterval(interval);
        timerWrapper.style.display = 'none';
        skipBtn.style.display = 'inline-block';
    }
}, 1000);
</script>
```

**المزايا:**
- ✅✅ أفضل تجربة مستخدم
- ✅ يعطي الخيار للمستخدم
- ✅ لا يُزعج المستخدمين المتكررين
- ✅ احترافي ومحترم

**العيوب:**
- ⚠️ قد يتخطى البعض الفيديو
- ⚠️ يحتاج كود إضافي

**متى تستخدمها:**
- ✅✅ **الأفضل للمواقع العامة**
- ✅ مواقع الشركات
- ✅ المنصات التعليمية
- ✅ عند احترام وقت المستخدم

---

### 4️⃣ **فيديو في صفحة منفصلة اختيارية** ⭐

**الوصف:**
- رابط "شاهد الفيديو التعريفي" في الصفحة الرئيسية
- لا يُعرض تلقائياً
- المستخدم يختار

**الكود:**
```php
// في index.php
<div class="intro-video-link">
    <a href="intro_youtube.php" class="btn-watch-intro">
        <i class="fas fa-play-circle"></i> شاهد الفيديو التعريفي
    </a>
</div>
```

**المزايا:**
- ✅ لا يزعج أحد
- ✅ سريع للمستخدمين المتكررين
- ✅ الفيديو متاح دائماً

**العيوب:**
- ⚠️ معظم الناس لن يشاهدوه
- ⚠️ قد يفوت رسالة مهمة

**متى تستخدمها:**
- ✅ فيديو توضيحي إضافي
- ✅ دليل استخدام
- ✅ عند عدم أهمية مشاهدة الفيديو

---

### 5️⃣ **فيديو مع Cookie (تذكّر لمدة طويلة)** ⭐⭐⭐ (موصى بها جداً)

**الوصف:**
- يُعرض مرة واحدة
- يُحفظ في **Cookie** لمدة 30 يوم (مثلاً)
- حتى لو أغلق المتصفح، لن يُعرض مرة أخرى

**الكود:**
```php
// في index.php
<?php
// التحقق من Cookie
$intro_shown = isset($_COOKIE['intro_shown']);

if (!$intro_shown && !isset($_GET['skip_intro'])) {
    // تعيين Cookie لمدة 30 يوم
    setcookie('intro_shown', '1', time() + (30 * 24 * 60 * 60), '/');
    header('Location: intro_youtube.php');
    exit;
}
?>
```

**المزايا:**
- ✅✅ أفضل من Session (يبقى بعد إغلاق المتصفح)
- ✅ تجربة مستخدم ممتازة
- ✅ يمكن التحكم بالمدة (7 أيام، 30 يوم، سنة)
- ✅ لا يزعج المستخدمين المتكررين

**العيوب:**
- ⚠️ يعتمد على تفعيل Cookies
- ⚠️ يمكن مسحه من المتصفح

**متى تستخدمها:**
- ✅✅ **الأفضل للمواقع التعليمية**
- ✅ عند الرغبة بعدم تكرار الفيديو
- ✅ مواقع الشركات
- ✅ التطبيقات الويب

---

### 6️⃣ **فيديو في قاعدة البيانات (للمستخدمين المسجلين)** ⭐⭐

**الوصف:**
- يُحفظ في جدول المستخدمين (`intro_watched`)
- يُعرض مرة واحدة لكل مستخدم

**الكود:**
```php
// في index.php
<?php
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    // التحقق من قاعدة البيانات
    $stmt = $pdo->prepare("SELECT intro_watched FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user['intro_watched']) {
        // تحديث قاعدة البيانات
        $stmt = $pdo->prepare("UPDATE users SET intro_watched = 1 WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        header('Location: intro_youtube.php');
        exit;
    }
}
?>
```

**المزايا:**
- ✅ دائم (لا يُمسح)
- ✅ مرتبط بالمستخدم
- ✅ يعمل على أي جهاز

**العيوب:**
- ⚠️ فقط للمستخدمين المسجلين
- ⚠️ يحتاج قاعدة بيانات
- ⚠️ لا يعمل للزوار

**متى تستخدمها:**
- ✅ أنظمة تتطلب تسجيل دخول
- ✅ عند الحاجة لتتبع دقيق
- ✅ للمستخدمين الدائمين

---

## 🏆 التوصيات حسب الحالة

### للنظام الحالي (مدرسة دلتا):

**الأفضل: الطريقة 3 + 5 (فيديو اختياري + Cookie)**

```php
// index.php
<?php
$intro_shown = isset($_COOKIE['intro_shown']);

if (!$intro_shown && !isset($_GET['skip_intro'])) {
    setcookie('intro_shown', '1', time() + (30 * 24 * 60 * 60), '/');
    header('Location: intro_youtube.php');
    exit;
}
?>
```

```javascript
// في intro_youtube.php - زر تخطي بعد 5 ثواني
let countdown = 5;
const skipBtn = document.querySelector('.skip-btn');
const timerText = document.querySelector('.skip-timer');

const interval = setInterval(() => {
    countdown--;
    document.getElementById('countdown').textContent = countdown;
    
    if (countdown <= 0) {
        clearInterval(interval);
        timerText.style.display = 'none';
        skipBtn.style.display = 'inline-flex';
        skipBtn.style.animation = 'fadeIn 0.5s';
    }
}, 1000);
```

---

## 📊 مقارنة الطرق

| الطريقة | سهولة | تجربة المستخدم | الاحترافية | التوصية |
|---------|-------|----------------|------------|----------|
| **1. Session مرة واحدة** | ⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐ | ✅ جيد |
| **2. إجباري دائماً** | ⭐⭐⭐ | ❌ | ❌ | ❌ تجنبه |
| **3. تخطي بعد 5 ث** | ⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅✅ ممتاز |
| **4. اختياري بالكامل** | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ✅ جيد |
| **5. Cookie 30 يوم** | ⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅✅✅ الأفضل |
| **6. قاعدة البيانات** | ⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ✅ للأنظمة الكبيرة |

---

## 🎬 مواصفات الفيديو المثالية

### المدة:
- ✅ **15-30 ثانية**: مثالي
- ⚠️ **30-60 ثانية**: مقبول
- ❌ **أكثر من دقيقة**: طويل جداً

### المحتوى:
- ✅ رسالة ترحيبية
- ✅ شعار المدرسة
- ✅ موسيقى خفيفة
- ✅ عبارة "مرحباً بكم"

### التقنية:
- ✅ YouTube (استضافة مجانية)
- ✅ جودة 720p أو 1080p
- ✅ Autoplay (تشغيل تلقائي)
- ✅ Mute في البداية (حسب سياسة المتصفحات)

---

## 🔧 الكود الموصى به (النسخة المحسّنة)

### 1. تعديل index.php (استخدام Cookie):

```php
<?php
// بدلاً من Session، استخدم Cookie
$intro_shown = isset($_COOKIE['intro_shown']);

if (!$intro_shown && !isset($_GET['skip_intro'])) {
    // Cookie لمدة 30 يوم
    setcookie('intro_shown', '1', time() + (30 * 24 * 60 * 60), '/');
    header('Location: intro_youtube.php');
    exit;
}
?>
```

### 2. تعديل intro_youtube.php (إضافة زر تخطي):

```html
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <title>مرحباً بكم في مدرسة دلتا</title>
    <style>
        body {
            margin: 0;
            background: #000;
            overflow: hidden;
        }
        
        iframe {
            width: 100vw;
            height: 100vh;
            border: none;
        }
        
        .skip-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 9999;
        }
        
        .skip-timer {
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-family: 'Cairo', sans-serif;
            font-size: 14px;
        }
        
        .skip-btn {
            display: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 10px;
            text-decoration: none;
            font-family: 'Cairo', sans-serif;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s;
        }
        
        .skip-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="skip-container">
        <div class="skip-timer">
            يمكنك التخطي بعد <span id="countdown">5</span> ثواني
        </div>
        <a href="index.php?skip_intro=1" class="skip-btn">
            <i class="fas fa-forward"></i> تخطي الفيديو
        </a>
    </div>
    
    <iframe 
        src="https://www.youtube.com/embed/YOUR_VIDEO_ID?autoplay=1&mute=1&controls=0&modestbranding=1&rel=0"
        allow="autoplay; encrypted-media"
        allowfullscreen>
    </iframe>
    
    <script>
        let countdown = 5;
        const timerElement = document.getElementById('countdown');
        const skipBtn = document.querySelector('.skip-btn');
        const timerDiv = document.querySelector('.skip-timer');
        
        const interval = setInterval(() => {
            countdown--;
            timerElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(interval);
                timerDiv.style.display = 'none';
                skipBtn.style.display = 'inline-flex';
                skipBtn.style.animation = 'fadeIn 0.5s';
            }
        }, 1000);
        
        // تحويل تلقائي بعد 30 ثانية (مدة الفيديو)
        setTimeout(() => {
            window.location.href = 'index.php?skip_intro=1';
        }, 30000);
    </script>
</body>
</html>
```

---

## 📱 التصميم المتجاوب

```css
@media (max-width: 768px) {
    .skip-container {
        top: 10px;
        left: 10px;
    }
    
    .skip-btn {
        padding: 10px 20px;
        font-size: 12px;
    }
    
    .skip-timer {
        padding: 8px 16px;
        font-size: 12px;
    }
}
```

---

## ✅ الخلاصة والتوصية النهائية

### للنظام الحالي (مدرسة دلتا):

**استخدم: Cookie + زر تخطي بعد 5 ثواني**

**المزايا:**
1. ✅ يُعرض مرة واحدة لمدة 30 يوم
2. ✅ زر تخطي بعد 5 ثواني
3. ✅ تحويل تلقائي بعد انتهاء الفيديو
4. ✅ لا يزعج المستخدمين المتكررين
5. ✅ احترافي ومحترم

**الكود:**
- `index.php`: استخدام Cookie بدلاً من Session
- `intro_youtube.php`: إضافة زر تخطي + عداد تنازلي

---

## 🎯 ملخص سريع

| السؤال | الإجابة |
|---------|---------|
| **متى يُعرض؟** | مرة واحدة فقط (Cookie لمدة 30 يوم) |
| **هل يمكن تخطيه؟** | نعم، بعد 5 ثواني |
| **ماذا لو أُغلق المتصفح؟** | لن يُعرض مرة أخرى (Cookie) |
| **المدة المثالية؟** | 15-30 ثانية |
| **أين يُستضاف؟** | YouTube (مجاني وسريع) |

---

**📅 تاريخ التوثيق:** 18 أكتوبر 2025  
**✅ الحالة:** دليل شامل  
**🎬 الموضوع:** أفضل الممارسات لعرض الفيديو الافتتاحي
