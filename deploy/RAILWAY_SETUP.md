# RAFEEQ — نشر على Railway

> للفريق اللي اختار Railway بدل VM. شغّال، بس فيه **تلات حاجات** في المنصّة دي تصطدم
> باللي المشروع بقى محتاجه — مكتوبة تحت قبل الخطوات، عشان تقرر قبل ما تصرف وقت.
>
> لو لسه بتختار: `deploy/ORACLE_SETUP.md` أرخص وأقل مفاجآت. الملف ده مش بيقول Railway
> غلط — بيقول إيه اللي لازم تعمله زيادة عليها.

---

## 🔵 أهم حاجة في الملف ده: فيه `Dockerfile` دلوقتي — استخدمه

تلات builds فشلوا ورا بعض، كل واحد لسبب مختلف **جوّه بناء إحنا مش متحكّمين فيه**. فبقى فيه `Dockerfile` في جذر المشروع، وRailway بتفضّله على Nixpacks لوحدها. **مش محتاج تعمل حاجة غير `git pull`** — بس لازم تعرف إيه اللي اتغيّر:

| | |
|---|---|
| `config:cache` | بقى بيشتغل **وقت التشغيل** مش وقت البناء. وده مش إصلاح لفشل بس — ده كان **غلط حتى لما بينجح**، لأن وقت البناء Railway ماحقنتش متغيّرات الخدمات بعد، فالكاش كان بيثبّت باسورد قاعدة بيانات فاضي و`APP_URL` غلط |
| المنفذ | الـ entrypoint بيحطّ `$PORT` في إعداد nginx. منفذ ثابت هو أشهر سبب لـ "Application failed to respond" |
| `APP_KEY` | الحاويّة **بترفض تقوم** من غيره برسالة واضحة، بدل ما تقوم وتفشل في فك تشفير أعمدة |
| نسخة PHP | 8.4 صريحة في الـ image، مش مستنبطة من constraint |
| الأخطاء | nginx و php-fpm بيكتبوا على stdout، يعني بتشوفهم في Deploy Logs |

وكمان `.dockerignore`، ودي **مسألة أمنية مش تنظيم**: من غيره `COPY . .` كان بياخد `.env` المحلي — الباسورد والـ `APP_KEY` — **جوّه طبقة في الـ image**، بتفضل موجودة ويقراها أي حد يقدر يسحب الـ image. وكمان كان بياخد `storage/app` اللي فيه مستندات ومسارات GPS من التطوير.

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

### ولو جالك "Application failed to respond"

معناها الـ edge مش قادر يوصل لمنفذ الحاويّة — يعني **التطبيق مش سامع**، مش إنه بيرجّع خطأ. والفرق مهم: الـ build نجح، والمشكلة في التشغيل.

**السبب اللي حصل فعلًا هنا:** `nixpacks.toml` كان فيه `[phases.build] cmds = []` بمعنى "مفيش حاجة تُبنى". وده بان مؤذي: **مرحلة الـ build بتاعة مزوّد PHP هي اللي بتولّد إعداد nginx وسكربت التشغيل اللي بيربط على `$PORT`.** تفريغها بيطلّع حاويّة بتقوم وماتسمعش على حاجة.

اتصلح — `providers = ["php"]` لوحدها كفاية لمنع Node. **الدرس:** ماتفرّغش مرحلة عند مزوّد بمعنى "ماتعملش حاجة" — إنت كده بترمي كل اللي المرحلة دي كانت بتعمله لك كمان.

**وباقي الأسباب المحتملة للرسالة دي، بالترتيب:**

| السبب | إزاي تتأكد |
|---|---|
| `APP_KEY` فاضي | التطبيق مايقومش خالص. `php artisan key:generate --show` من جهازك وحطّها في المتغيّرات |
| متغيّرات قاعدة البيانات ناقصة | الـ Deploy Logs هتقول `could not find driver` أو `Connection refused` |
| الخدمة بتسمع على منفذ ثابت | Railway بتحقن `$PORT` ولازم التطبيق يستخدمها — مزوّد PHP بيعمل ده لوحده لو مامنعتهوش |
| **`BROADCAST_CONNECTION=reverb` ومفيش مفاتيح Reverb** | الـ Deploy Logs بتقول `Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given` — **مكرّرة عشرات المرات**. ومابتذكرش broadcasting ولا إعدادات ولا اللي تعمله. **الحل: `BROADCAST_CONNECTION=log`** |

**والـ Deploy Logs هي المصدر الوحيد اللي بيقول الحقيقة.** لو اللي فوق ماحلّهاش، ابعت آخر ٣٠ سطر.

### الـ entrypoint بيفحص كل ده قبل ما يقوم

كان بيموت على **أول** متغيّر ناقص، فكل نشر يكشف واحد بس — وده اتصلّح. دلوقتي بيفحص الكل ويطبع تقرير واحد:

```
🔴 RAFEEQ — refusing to start. Fix these in the platform's variables:
  ✗ APP_KEY is not set. Generate one with:  php artisan key:generate --show
  ✗ DB_HOST is not set. On Railway use the service reference, e.g. DB_HOST=${{MySQL.MYSQLHOST}}
  ✗ BROADCAST_CONNECTION=reverb but missing: REVERB_APP_KEY
    If you do not have one yet, set BROADCAST_CONNECTION=log
  ✗ APP_URL has no scheme: 'rafeeq.up.railway.app'. It must start with https://
```

وفيه تحذيرات **مابتمنعش الإقلاع** بس مهمة: `APP_DEBUG=true` (صفحة الخطأ بتعرض SQL والمتغيّرات) و`LOG_LEVEL=debug` (بتكتب **أكواد الـ OTP** في اللوج) و`DB_PASSWORD` فاضي و`APP_URL` بـ `http://` (الكوكيز secure فالدخول مش هينفع).

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

### ٣. `/docs/api` — بقت تتفتح بمتغيّر واحد

**تصحيح لحاجة قلتها غلط قبل كده:** قلتلك إن `/docs/api` مفتوحة للعالم. **مش صح.** `config/scramble.php` حاططها ورا `RestrictedDocsAccess`، اللي بيسمح بيها في `local` بس ما لم يبقى فيه gate اسمه `viewApiDocs`.

يعني على سيرفر بـ `APP_ENV=staging` هتبقى **مقفولة** — والفريق مش هيقدر يقراها من هناك.

**وده صح**، والحل هو اللي رشّحته من الأول: `composer openapi` على جهازك وتبعتلهم الـ JSON. بيشتغل واللاب مقفول، ومابيعرّضش سطح الـ API على الإنترنت.

**واتعمل الـ gate.** عشان الفريق الخارجي يقرا العقد من السيرفر:

```
RAFEEQ_PUBLIC_API_DOCS=true
```

وبعدها `/docs/api` (الواجهة) و`/docs/api.json` (المستند نفسه) الاتنين يفتحوا لأي حد يعرف اللينك.

🔴 **وده اللي بيتكلّف، مكتوب صريح لأن إقفاله متغيّر واحد وحد هيفتحه تاني بعدين:** مستند الـ OpenAPI هو **سطح الـ API كامل** — كل endpoint وكل اسم حقل وكل كود خطأ. مابيسلّمش داتا ومابيتخطّاش أي فحص؛ بيسلّم **خريطة**، والخريطة هي أول حاجة حد بيستكشف المنصة بيجمّعها. على staging بداتا وهميّة مزروعة دي مقايضة مكسب. على نسخة فيها رحلات ناس حقيقية لأ.

فـ**الإنتاج مستثنى في الكود مش في المتغيّر**: لو `APP_ENV=production` الـ gate بيرفض مهما كانت قيمة المتغيّر — نفس منطق كود الـ OTP التطويري. مختبَر على الأربع حالات.

> **و`/request-docs` بقت شغّالة كمان** (بطلب منك). كانت بترجّع 404 لأن الباكدج (`rakutentech/laravel-request-docs`) كانت في `require-dev` فمش موجودة في الصورة أصلًا — اتنقلت لـ`require`.
>
> الحماية بتاعتها **مختلفة عن Scramble ومستقلة عن `RAFEEQ_PUBLIC_API_DOCS`**: الإعداد في `config/request-docs.php` حاطط عليها `NotFoundWhenProduction`، فهي **403 في الإنتاج** و200 في أي بيئة تانية — مختبَر بطلب حقيقي على الاتنين. ومحدودة بـ`api/v1` فمابتعرضش راوتات الويب، و`hide_sql_data` و`hide_logs_data` و`hide_models_data` كلهم مقفولين.
>
> الفرق في الاستخدام: **Scramble هي العقد** (بتتولّد من الكود وفيه اختبارات بتفشل البناء لو درفت)، و`request-docs` بتقرا جدول الراوتات وقت التشغيل — مفيدة للاستكشاف السريع، مش مرجع.

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
BROADCAST_CONNECTION=log
```

🔴 **`BROADCAST_CONNECTION=log` مش اختيارية لحد ما تعمل خدمة Reverb.** لو حطيتها `reverb` والمفاتيح فاضية، التطبيق **مابيتعطّلش جزئيًا — بيموت**: لارافيل بتبني الـ broadcaster من خلال عميل Pusher، وconstructor بتاع Pusher بيرمي type error على مفتاح `null`:

```
Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
```

والرمية دي بتحصل وقت تحميل `routes/channels.php`، اللي بيحصل جوّه `route:cache` — فالحاويّة بتموت وقت الإقلاع والمنصّة بتقول "Application failed to respond" وبس. `log` بتقوم نظيفة، والحاجة الوحيدة اللي مش هتشتغل هي إن **الخريطة تتحرّك لوحدها**؛ الـ polling (`GET /trips/{trip}/location`) شغّال عادي.

> **وده كان عيب في `.env.production.example` نفسه** — كان فيه `BROADCAST_CONNECTION=reverb` مع مفاتيح فاضية، فأي حد ينسخه يقع في نفس الحفرة. اتصلّح، والـ entrypoint بقى **بيرفض يقوم** لو لقى واحدة من غير التانية، برسالة بتقولك تعمل إيه.

🔴 **`APP_DEBUG=false`** — لو `true`، صفحة الخطأ بتعرض الـ SQL والمتغيّرات وأجزاء من الكود.

🔴 **`LOG_LEVEL=info` مش `debug`** — على أي بيئة غير production، `debug` بتكتب **كود الـ OTP في اللوج**، وده على سيرفر مشترك تخطّي لتسجيل الدخول.

🔴 **`APP_ENV=staging` مش `production`** — عن قصد: `LogOtpSender` بيرفض يشتغل في production (عشان محدش ينشر بلا مزوّد SMS)، وبما إنه لسه مفيش مزوّد، `production` معناها محدش يقدر يسجّل دخول خالص.

### ٣. الترحيل

Railway → Service → Settings → **Deploy → Pre-deploy Command**:

```
php artisan migrate --force
```

بيشتغل قبل كل نشر. 🔴 **لو مش متضبط**، الكود الجديد بيشتغل على سكيمة قديمة، وده شكله `500 Server Error` على أول صفحة بتقرا جدول جديد (حصل فعلًا على `/admin/dashboard` بعد إضافة `account_suspensions`). عشان كده الـ entrypoint دلوقتي **بيرفض يبدأ لو فيه migrations معلّقة** وبيكتب في الـ Deploy Logs اسمها والأمر المطلوب — و Railway بيفضل شغّال على النشر اللي قبله. ولو عايز تشغّلها يدوي مرة: من الـ Console بتاع الخدمة `php artisan migrate --force`، وتتأكد بـ `php artisan migrate:status --pending`.

أول مرة بس، من الـ Console، لو عاوز داتا يشتغلوا عليها:

```
php artisan db:seed --force
```

بيعمل نور (سائقة معتمدة) ومريم (راكبة موثّقة) ومسار الرحاب ← القرية الذكية.

### ٤. الكاش — **مابقاش محتاج حاجة**

الـ entrypoint بيعمله وقت تشغيل الحاويّة، لما المتغيّرات تبقى موجودة فعلًا. **ماتحطّهوش في pre-deploy ولا post-deploy** — هيتكرّر بلا داعي، ولو حصل في pre-deploy هيثبّت إعدادات فاضية.

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
| **`RAFEEQ_DEV_OTP_CODE`** ✅ | **مبني.** كود ثابت لأي رقم، محصّن على **البيئة** مش على الـ config. ٦ أرقام بالظبط، وفي production بيرفض يصدر كود خالص مش بيتجاهل الإعداد |
| اللوج | من Railway Console: `tail -f storage/logs/laravel.log` وقت ما يسجّلوا. شغّال دلوقتي، بس محتاج حد قاعد يبص |

والاختيار الثالث — نرجّع الكود في رد الـ API لما debug مفتوح — **مش هعمله**: ده بالظبط اللي بيوصل production بالغلط.

---

## الخلاصة بصراحة

Railway هتشتغل. بس عشان تشتغل صح محتاجة: **٤ خدمات + MySQL + volume**، والـ cron بتاعها مش بيوصل للـ ٣٠ ثانية اللي المشروع عايزها فمحتاج خدمة خامسة شغّالة باستمرار.

نفس الشغل على VM = عملية واحدة، سطر cron واحد، وقرص حقيقي مابيمسحش المستندات. والحساب المالي بيطلع لصالح الـ VM من تاني خدمة.

**لو ماشي على Railway:** اعمل الـ volume قبل أي داتا حقيقية. دي الحاجة اللي لو اتنسيت بتضيّع مستندات هوية ناس، ومحدش بيلاحظها غير بعد فوات الوقت.
