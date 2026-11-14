# Task 14 — بررسی و آماده‌سازی بسته نصب (Stage 5)

**تاریخ:** 2026-11-14
**Branch:** `release/stage-5-final-preparation`
**خروجی:** `ani-navasan-lite-1.2.0.zip` (ZIP آزمایشی)

---

## ۱. هدف

ساخت یک ZIP استاندارد و تمیز که مستقیماً از پنل WordPress قابل نصب باشد؛ شامل بررسی فایل‌های بسته، بررسی اطلاعات حساس، ساخت ZIP و ثبت Hash.

## ۲. بررسی فایل‌های بسته (Task 14.1)

### فایل‌های واردشده در ZIP (۱۱ فایل runtime)

| فایل | نقش |
|---|---|
| `ani-navasan-lite.php` | فایل اصلی افزونه (در ریشهٔ پوشهٔ افزونه) |
| `includes/init.php` | بوت‌استرپ |
| `includes/functions.php` | توابع مشترک |
| `includes/hooks.php` | هوک `save_post_product` |
| `includes/ajax.php` | نقطهٔ پایانی AJAX |
| `includes/currencies-cpt.php` | ثبت CPT ارز |
| `includes/currencies-metabox.php` | متاباکس ارز |
| `admin/admin.php` | بارگذاری اسکریپت‌های مدیریتی |
| `admin/metabox.php` | فیلدهای متاباکس محصول/ورییشن |
| `assets/js/admin.js` | جاوااسکریپت مدیریتی |
| `assets/css/admin.css` | استایل مدیریتی |

### فایل‌های حذف‌شده از بسته (از مخزن Git حذف **نشده‌اند**، فقط از ZIP خارج شدند)

| مورد | دلیل حذف از بسته |
|---|---|
| `.git/` | دادهٔ مخزن |
| `.clinerules/` | تنظیمات محلی Cline/AI |
| `.vscode/` | تنظیمات IDE |
| `.gitignore` | تنظیمات محلی |
| `docs/` (reports, sources, نقشهٔ راه) | مستندات توسعه + ZIP افزونهٔ اصلی نوسان |
| `tests/` (compat, integration, unit, results) | زیرساخت و خروجی‌های تست |
| `README.md` | مستندات مخزن/GitHub (نه فایل runtime) |
| `CHANGELOG.md` | مستندات مخزن/GitHub (نه فایل runtime) |

> **تصمیم:** بستهٔ نصب فقط شامل فایل‌های runtime است تا ساختار استاندارد و تمیز WordPress داشته باشد. مستندات (README/CHANGELOG) در مخزن GitHub و بخش Releases در دسترس هستند.

## ۳. بررسی اطلاعات حساس (Task 14.2)

اسکن الگوهای حساس روی ۱۱ فایل runtime انجام شد:

| الگو | نتیجه |
|---|---|
| Password / Passwd | ❌ یافت نشد |
| API Key / Token / Secret | ❌ یافت نشد |
| Private Key (RSA/OPENSSH/EC) | ❌ یافت نشد |
| اطلاعات دیتابیس (mysql, db_pass, wp-config) | ❌ یافت نشد |
| مسیرهای اختصاصی سیستم توسعه (/home/ramin) | ❌ یافت نشد |
| اطلاعات سایت آزمایشی (anishop-package-test, localhost:port) | ❌ یافت نشد |
| IP داخلی (127.0.0.1, 192.168, 10.x) | ❌ یافت نشد |
| Bearer / AWS Key | ❌ یافت نشد |
| ایمیل/نام کاربر | ⚠️ فقط `Author: ramin4068@gmail.com` در هدر افزونه (تصویر عمومی و استاندارد، نه محرمانه) |

**نتیجه:** هیچ اطلاعات محرمانه‌ای در ZIP باقی نمانده است.

## ۴. ساخت ZIP آزمایشی (Task 14.3)

- **مسیر ZIP:** `/tmp/stage5/ani-navasan-lite-1.2.0.zip`
- **ساختار:** استاندارد WordPress — `ani-navasan-lite/ani-navasan-lite.php` (فایل اصلی در ریشهٔ پوشهٔ افزونه)
- **تعداد فایل:** ۱۱ فایل + ۶ پوشه

### نتایج بررسی

| بررسی | نتیجه |
|---|---|
| صحت ساختار ZIP (`unzip -t`) | ✅ No errors detected |
| وجود فایل اصلی افزونه | ✅ `ani-navasan-lite/ani-navasan-lite.php` |
| وجود تمام فایل‌های ضروری | ✅ ۱۱/۱۱ |
| نبود فایل‌های غیرضروری | ✅ |
| تطبیق نسخه داخل ZIP | ✅ `Version: 1.2.0` |
| قابل خواندن بودن PHP (lint از داخل ZIP) | ✅ ۹/۹ OK |
| قابل خواندن بودن JavaScript | ✅ `node --check` OK |

### Hash و اندازه

- **SHA256:** `0402c412e3161165b47b83b385b75f36131b46d60851146dd8ee81b676b03e56`
- **اندازه:** 19256 bytes

## ۵. پیامدهای امنیتی و سازگاری

- بستهٔ نصب تمیز است و هیچ فایل توسعه/تست/تنظیمات محلی در آن نیست.
- ساختار استاندارد WordPress است و مستقیماً از پنل قابل نصب است.

## ۶. وضعیت Git

- Branch: `release/stage-5-final-preparation`
- Commit: (در پایان Stage 5 ثبت می‌شود)
- Push: (در پایان Stage 5 انجام می‌شود)
