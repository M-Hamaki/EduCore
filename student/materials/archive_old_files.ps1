# PowerShell Script to Archive Old Materials Files
# هذا السكريبت ينقل الملفات القديمة إلى مجلد archive

# إنشاء مجلد archive
$archivePath = ".\archive"
if (-not (Test-Path $archivePath)) {
    New-Item -ItemType Directory -Path $archivePath
    Write-Host "✅ تم إنشاء مجلد archive" -ForegroundColor Green
}

# إنشاء مجلدات فرعية
$term1Archive = "$archivePath\term1_old_files"
$term2Archive = "$archivePath\term2_old_files"

if (-not (Test-Path $term1Archive)) {
    New-Item -ItemType Directory -Path $term1Archive
}
if (-not (Test-Path $term2Archive)) {
    New-Item -ItemType Directory -Path $term2Archive
}

# قائمة الملفات للنقل (Term 1)
$term1Files = @(
    "term1\kg1-t1.html",
    "term1\kg2-t1.html",
    "term1\prim1-t1.html",
    "term1\prim2-t1.html",
    "term1\prim3-t1.html",
    "term1\prim4-t1.html",
    "term1\prim5-t1.html",
    "term1\prim6-t1.html",
    "term1\prep1-t1.html",
    "term1\prep2-t1.html",
    "term1\prep3-t1.html",
    "term1\sec1-t1.html",
    "term1\sec2-t1.html"
)

# قائمة الملفات للنقل (Term 2)
$term2Files = @(
    "term2\kg1-t2.html",
    "term2\kg2-t2.html",
    "term2\prim1-t2.html",
    "term2\prim2-t2.html",
    "term2\prim3-t2.html",
    "term2\prim4-t2.html",
    "term2\prim5-t2.html",
    "term2\prim6-t2.html",
    "term2\prep1-t2.html",
    "term2\prep2-t2.html",
    "term2\prep3-t2.html",
    "term2\sec1-t2.html",
    "term2\sec2-t2.html"
)

Write-Host "`n🗂️  بدء عملية الأرشفة...`n" -ForegroundColor Cyan

# نقل ملفات Term 1
$count = 0
foreach ($file in $term1Files) {
    if (Test-Path $file) {
        $fileName = Split-Path $file -Leaf
        Move-Item -Path $file -Destination "$term1Archive\$fileName" -Force
        Write-Host "✅ تم نقل: $fileName" -ForegroundColor Green
        $count++
    }
    else {
        Write-Host "⚠️  الملف غير موجود: $file" -ForegroundColor Yellow
    }
}

# نقل ملفات Term 2
foreach ($file in $term2Files) {
    if (Test-Path $file) {
        $fileName = Split-Path $file -Leaf
        Move-Item -Path $file -Destination "$term2Archive\$fileName" -Force
        Write-Host "✅ تم نقل: $fileName" -ForegroundColor Green
        $count++
    }
    else {
        Write-Host "⚠️  الملف غير موجود: $file" -ForegroundColor Yellow
    }
}

Write-Host "`n📊 ملخص العملية:" -ForegroundColor Cyan
Write-Host "   - تم نقل $count ملف إلى مجلد archive" -ForegroundColor White
Write-Host "   - الملفات المحفوظة في: $archivePath" -ForegroundColor White
Write-Host "`n✅ اكتملت عملية الأرشفة بنجاح!`n" -ForegroundColor Green

# إنشاء ملف README في مجلد archive
$readmeContent = @"
# Archived Materials Files

هذه الملفات تم أرشفتها بعد تحويل النظام إلى نظام ديناميكي.

## التاريخ
تاريخ الأرشفة: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")

## السبب
تم استبدال هذه الملفات الـ 26 بنظام ديناميكي واحد يستخدم:
- view.php (ملف واحد فقط)
- materials_data.json (قاعدة بيانات مركزية)

## الملفات
### Term 1 (13 ملف):
- kg1-t1.html, kg2-t1.html
- prim1-t1.html → prim6-t1.html
- prep1-t1.html → prep3-t1.html
- sec1-t1.html, sec2-t1.html

### Term 2 (13 ملف):
- kg1-t2.html, kg2-t2.html
- prim1-t2.html → prim6-t2.html
- prep1-t2.html → prep3-t2.html
- sec1-t2.html, sec2-t2.html

## ملاحظة
يمكن حذف هذه الملفات نهائياً بعد التأكد من عمل النظام الجديد بشكل صحيح.

## النظام الجديد
راجع الدليل الكامل في: DYNAMIC_MATERIALS_GUIDE.md
"@

Set-Content -Path "$archivePath\README.txt" -Value $readmeContent -Encoding UTF8
Write-Host "📄 تم إنشاء ملف README.txt في مجلد archive`n" -ForegroundColor Cyan
