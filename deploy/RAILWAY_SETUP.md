# RAFEEQ — نشر على Railway

> للفريق اللي اختار Railway بدل VM. شغّال، بس فيه **تلات حاجات** في المنصّة دي تصطدم
> باللي المشروع بقى محتاجه — مكتوبة تحت قبل الخطوات، عشان تقرر قبل ما تصرف وقت.
>
> لو لسه بتختار: `deploy/ORACLE_SETUP.md` أرخص وأقل مفاجآت. الملف ده مش بيقول Railway
> غلط — بيقول إيه اللي لازم تعمله زيادة عليها.

---

## أولًا: الغلط اللي وصلك (exit 137)

```
process "npm prune --omit-dev --ignore-scripts" did not complete successfully: exit code: 137
```

**137 = 128 + 9 = SIGKILL.** يعني العملية اتقتلت من نقص الرام على ماكينة البناء، مش غلط في الكود.

Nixpacks بتشوف `package.json` فبتضيف مرحلة Node كاملة — install ثم build ثم prune — والـ prune بياخد رام أكتر من المتاح.

**واتصلح، وبطريقة إن الشغل ده ماينفّذش أصلاً مش بطريقة إننا ندوّر على رام أكتر** — لأنه مابيجيب حاجة:

| | |
|---|---|
| الـ API | JSON، مفيش assets خالص |
| لوحة التحكم | `public/css/admin.css` — ملف مكتوب بالإيد ومرفوع في الريبو، مش محتاج bundler |
| الحاجة الوحيدة اللي كانت محتاجة Vite | صفحة Laravel الافتراضية على `/` — ومش جزء من المنتج |

فاتضاف `nixpacks.toml` بـ `providers = ["php"]`، وصفحة `/` بقت من غير أي asset. **اعمل pull وجرّب تاني — البناء المفروض يعدّي.**

> وكمان: صفحة Laravel الافتراضية على سيرفر عام مش حاجة كويسة — بتعلن إن ده تركيب جديد وبتقول للمتطفّل الـ framework ونسخته.

---

## 🔴 تلات حاجات في Railway لازم تحسمها

### ١. الملفات بتضيع مع كل نشر — وده الأخطر

Railway بتستخدم filesystem مؤقت. المشروع دلوقتي `FILESYSTEM_DISK=local`، ويخزّن فيه:

- **صور البطاقات ورخص السواقة** (`user_verifications` · `identity_documents`)
- صور العربيات ومستنداتها
- أدلة البلاغات

**يعني كل نشر يمسح مستندات هوية ناس حقيقية**، والصفوف في قاعدة البيانات تفضل بمسارات بتشاور على لا حاجة. ومش هتلاحظ غير لما مراجع يفتح بطاقة ويلاقيها مش موجودة.

**الحل — واحد من اتنين قبل أي داتا حقيقية:**

| | |
|---|---|
| **Volume** | من Railway: Service → Settings → Volumes → mount على `/app/storage` |
| **S3** | `RAFEEQ_DOCUMENTS_DISK=s3` + مفاتيح AWS. وده اللي القرار D7 بيطلبه للإنتاج أصلاً |

للتجربة مع فريق الموبايل؟ الـ Volume كفاية. لأي مستخدم حقيقي؟ S3.

### ٢. محتاج **تلات خدمات** مش خدمة، وكمان cron

المشروع ما بقاش PHP + قاعدة بيانات:

| الخدمة | الأمر | لو مش موجودة |
|---|---|---|
| web | (الافتراضي) | — |
| **worker** | `php artisan queue:work --tries=3` | إشعارات عمرها ما توصل، **ومفيش أي رسالة خطأ** |
| **reverb** | `php artisan reverb:start --host=0.0.0.0 --port=$PORT` | الخريطة الحيّة مابتتحدّثش — والـ polling بيفضل شغّال فبيبان إن كله تمام |
| **cron** | `php artisan schedule:run` كل دقيقة | أيام الرحلات بتبطّل تتولد · **ومسارات GPS بتضيع كلها** |

⚠️ **والـ cron بالتحديد مشكلة:** أقل تكرار في Railway **كل ٥ دقايق**، والمشروع محتاج `trips:flush-locations` كل ٣٠ ثانية. يعني buffer مواقع بيقعد ٥ دقايق مكان ٣٠ ثانية — والنافذة دي هي نفسها الخطورة: اللي في الـ buffer وقت ما العملية تقع بيضيع، ومسار فيه فتحة ٣٠ ثانية لسه دليل، فيه فتحة ٥ دقايق مش دليل.

**البديل:** خدمة رابعة بتشغّل `php artisan schedule:work` (عملية مستمرة بتحترم الـ ٣٠ ثانية). وده معناه ٤ خدمات.

**وكل خدمة بتتحاسب لوحدها.** ٤ خدمات + MySQL على Railway مش مجانية — الرصيد التجريبي بيخلص وبعديها استهلاك. VM بـ ٥ دولار بيشيل الأربعة.

### ٣. `/docs/api` **مش** هتشتغل — وده مقصود

**تصحيح لحاجة قلتها غلط قبل كده:** قلتلك إن `/docs/api` مفتوحة للعالم. **مش صح.** `config/scramble.php` حاططها ورا `RestrictedDocsAccess`، اللي بيسمح بيها في `local` بس ما لم يبقى فيه gate اسمه `viewApiDocs`.

يعني على سيرفر بـ `APP_ENV=staging` هتبقى **مقفولة** — والفريق مش هيقدر يقراها من هناك.

**وده صح**، والحل هو اللي رشّحته من الأول: `composer openapi` على جهازك وتبعتلهم الـ JSON. بيشتغل واللاب مقفول، ومابيعرّضش سطح الـ API على الإنترنت.

لو **عاوز** تفتحها لهم على السيرفر، ده قرار متعمّد ومحتاج gate — قولّي أعمله.

---

## الخطوات

### ١. قاعدة البيانات

**New → Database → MySQL.** من تاب Variables بتاعها خد القيم، وحدّد على خدمة الـ web:

```
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
```

> الصيغة `${{MySQL.X}}` مراجع Railway نفسها — ماتنسخش القيم بإيدك، عشان تتحدّث لوحدها لو الخدمة اتغيّرت.

⚠️ **اتأكد من الـ collation:** المشروع محتاج `utf8mb4_unicode_ci`. الأسماء والعناوين عربي، و collation غلط بيخلّي البحث والفرز يطلعوا غلط بطرق مش واضحة.

### ٢. المتغيّرات

`.env.production.example` فيه القايمة كاملة بشرح كل سطر خطر. أهمهم:

```
APP_NAME=Rafeeq
APP_KEY=                      # php artisan key:generate --show   (من جهازك)
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<your-app>.up.railway.app
APP_LOCALE=ar
LOG_LEVEL=info
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
```

🔴 **`APP_DEBUG=false`** — لو `true`، صفحة الخطأ بتعرض الـ SQL والمتغيّرات وأجزاء من الكود.

🔴 **`LOG_LEVEL=info` مش `debug`** — على أي بيئة غير production، `debug` بتكتب **كود الـ OTP في اللوج**، وده على سيرفر مشترك تخطّي لتسجيل الدخول.

🔴 **`APP_ENV=staging` مش `production`** — عن قصد: `LogOtpSender` بيرفض يشتغل في production (عشان محدش ينشر بلا مزوّد SMS)، وبما إنه لسه مفيش مزوّد، `production` معناها محدش يقدر يسجّل دخول خالص.

### ٣. الترحيل

Railway → Service → Settings → **Deploy → Pre-deploy Command**:

```
php artisan migrate --force
```

بيشتغل قبل كل نشر. أول مرة بس، من الـ Console، لو عاوز داتا يشتغلوا عليها:

```
php artisan db:seed --force
```

بيعمل نور (سائقة معتمدة) ومريم (راكبة موثّقة) ومسار الرحاب ← القرية الذكية.

### ٤. الكاش

**Post-deploy Command:**

```
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

⚠️ **مش pre-deploy.** `config:cache` بيثبّت المتغيّرات وقت تشغيله — لو اشتغل قبل ما Railway تحقن المتغيّرات، هتلاقي التطبيق شغّال على إعدادات فاضية.

### ٥. الخدمات التانية

لكل واحدة: **New → Empty Service** على نفس الريبو، وحدّد **Custom Start Command**:

| الخدمة | الأمر |
|---|---|
| worker | `php artisan queue:work --tries=3 --max-time=3600` |
| reverb | `php artisan reverb:start --host=0.0.0.0 --port=$PORT` |
| scheduler | `php artisan schedule:work` |

وانسخ نفس المتغيّرات عليهم كلهم (Railway بتسمح بمشاركة متغيّرات على مستوى المشروع).

> **`--max-time=3600` على الـ worker مش اختيارية.** عملية PHP طويلة العمر بتمسك الكود اللي قامت بيه، فمن غيرها النشر بيسيب الكود القديم شغّال على الطابور لحد ما حد يفتكر.

### ٦. التأكيد

```
curl -s https://<your-app>.up.railway.app/up
curl -s https://<your-app>.up.railway.app/api/v1/places
```

التاني لازم يرجّع:

```json
{"success":false,"error":{"code":"UNAUTHENTICATED","message":"...","fields":{}}}
```

**٤٠١ دي نجاح** — معناها الـ routing والـ PHP وقاعدة البيانات والـ envelope والحماية كلهم ماشيين.

---

## 🔴 وفضلت حاجة هتوقّف الفريق عند شاشة ٦

مفيش مزوّد SMS. كود الـ OTP بيتكتب في اللوج والفريق مش شايفه.

| الاختيار | التفصيل |
|---|---|
| **`RAFEEQ_DEV_OTP_CODE`** | كود ثابت لأي رقم، محصّن على **البيئة** مش على الـ config. **لسه مش مبني** — قولّي أبنيه |
| اللوج | من Railway Console: `tail -f storage/logs/laravel.log` وقت ما يسجّلوا. شغّال دلوقتي، بس محتاج حد قاعد يبص |

والاختيار الثالث — نرجّع الكود في رد الـ API لما debug مفتوح — **مش هعمله**: ده بالظبط اللي بيوصل production بالغلط.

---

## الخلاصة بصراحة

Railway هتشتغل. بس عشان تشتغل صح محتاجة: **٤ خدمات + MySQL + volume**، والـ cron بتاعها مش بيوصل للـ ٣٠ ثانية اللي المشروع عايزها فمحتاج خدمة خامسة شغّالة باستمرار.

نفس الشغل على VM = عملية واحدة، سطر cron واحد، وقرص حقيقي مابيمسحش المستندات. والحساب المالي بيطلع لصالح الـ VM من تاني خدمة.

**لو ماشي على Railway:** اعمل الـ volume قبل أي داتا حقيقية. دي الحاجة اللي لو اتنسيت بتضيّع مستندات هوية ناس، ومحدش بيلاحظها غير بعد فوات الوقت.
