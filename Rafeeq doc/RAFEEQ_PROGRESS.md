# RAFEEQ — متابعة التنفيذ (Progress Tracker)

> **الغرض:** الملف ده هو مصدر الحقيقة لـ "وصلنا فين؟". بيتحدّث مع كل خطوة تنفيذ حقيقية
> (مش مع كل سطر كود). لو عايز تعرف الحالة دلوقتي، اقرا القسم "الحالة الحالية" بس.
>
> **المصادر المعتمدة (لا تتغيّر إلا بقرار صريح):**
> `RAFEEQ_MASTER_PLAN.md` (القرارات D1–D20) · `RAFEEQ_ENGINEERING_BIBLE.md` (كل عمود + 64 فخ) ·
> `RAFEEQ_ERD.md` (57 جدول / 71 migration / 14 مجموعة)

---

## المعايير الملزمة — راجعها قبل أي PR/تعديل

هذه خلاصة الاتفاقيات من الـ Engineering Bible. أي كود جديد لازم يتوافق معاها:

| # | المعيار | التفصيل |
|---|---|---|
| 1 | **IDs** | `ULID` لكل الجداول العامة (`HasUlids` trait) — إلا الجداول 1:1 الموثّقة اللي بتستخدم الـ FK نفسه كـ PK (مثل `driver_profiles.user_id`, `attendance.booking_id`, `trust_scores.user_id`) |
| 2 | **الفلوس** | `bigint` بالقروش دايمًا، عمود اسمه `..._piastres`. ممنوع `float`/`decimal` للفلوس |
| 3 | **الوقت** | تخزين UTC. الجداول اللي فيها تكرار (`commute_schedules`) تخزن الوقت المحلي + `timezone` منفصلين، والـ UTC بيتحسب وقت التوليد بس. `CarbonImmutable` في كل حتة |
| 4 | **Enums** | `varchar` + PHP 8.3 backed enum + `casts()`. ممنوع MySQL `ENUM` |
| 5 | **الترميز** | `utf8mb4` / `utf8mb4_unicode_ci` في كل الجداول |
| 6 | **الفهارس** | كل Foreign Key لازم index صريح (Laravel مش بيعمله لوحده) |
| 7 | **الجغرافيا** | `POINT SRID 4326` + `SPATIAL INDEX`، بالإضافة لأعمدة `lat`/`lng` decimal مكرّرة عمدًا للفلترة السريعة (bbox). كل استعلام جغرافي وراء `GeoQueryEngine` — ممنوع `ST_*` أو `DB::raw` جغرافي في أي مكان تاني |
| 8 | **التشفير** | `national_id`, `licence_number`, `push_token` → عمود `_encrypted` (Laravel `encrypted` cast) + عمود `_hash` (sha256) منفصل للبحث عن التكرار من غير فك تشفير |
| 9 | **سجلات غير قابلة للتعديل** | `booking_events`, `verification_logs`, `driver_fee_ledger`, `admin_actions`, `safety_events`, `incident_evidence` → INSERT فقط، مفيش UPDATE/DELETE |
| 10 | **التسمية** | جداول: جمع snake_case · أعمدة: مفرد snake_case · FK: `{singular}_id` · Boolean: `is_/has_/can_` · تواريخ: `_at` (لحظة) / `_date` (يوم) |
| 11 | **الحسابات الحساسة** | مفيش رقم سحري في الكود — كل رقم إعداد (رسوم، حدود، مهلات) في `platform_settings` |
| 12 | **الطبقات** | Controller رفيع (4 أسطر) → Form Request (تحقق) → Policy (تفويض) → Action (منطق) → Resource (شكل الخرج). الدومين النقي (`app/Domains/*/Enums`, `ValueObjects`) ممنوع يعرف Laravel/Illuminate |
| 13 | **الأمان الحرج** | استبعاد صارم (women-only, blocked_users) في الاستعلام نفسه مش post-filter · `commute_demands` سري تمامًا (مفيش endpoint للسائقة) · double-blind ratings بـ `whereNotNull('visible_at')` |
| 14 | **أعمدة الجغرافيا في Laravel 13** | مفيش `$table->point()` — استخدم `$table->geography('col', subtype: 'point', srid: 4326)` (بيتحول لـ `ref_system_id` تلقائيًا على MariaDB، و`srid` على MySQL 8) |
| 15 | **timestamp إجباري بدون default** | استخدم `$table->dateTime(...)` مش `$table->timestamp(...)` لأي عمود `NOT NULL` من غير default حقيقي — MariaDB/MySQL بيدّوا default ضمني للعمود التاني+ من نوع TIMESTAMP في نفس الجدول، وده بيتعارض مع `NO_ZERO_DATE`. الأعمدة الـ nullable مش متأثرة، و`timestamps()` الافتراضية في Laravel nullable أصلاً فمعندهاش المشكلة |
| 16 | **بيئة التطوير المحلية** | MariaDB 10.4.27 (مش MySQL 8 حرفيًا) — تم التحقق: POINT/SPATIAL INDEX، JSON، generated columns، CHECK constraints، و`ST_Distance_Sphere` كلها شغالة. اختبارات الـ Feature بتشتغل على قاعدة بيانات حقيقية `rafeeq_testing` مش SQLite (`phpunit.xml`) — لأن الشكل الجغرافي مش قابل للمحاكاة على SQLite |
| 17 | **تسمية جدول الجلسات** | جدول تتبّع refresh tokens اسمه `auth_sessions` **مش** `sessions` — عشان يتفادى تعارض الاسم مع جدول `sessions` الافتراضي في Laravel (تخزين جلسات الويب، لازم لداشبورد الأدمن Livewire). قرار موافَق عليه من المستخدم في 2026-09-19 |
| 18 | **الموديلات والـ Factories** | الموديلات في `app/Domains/{Domain}/Models/`، مش `app/Models/`. الـ Factories فضلت flat في `database/factories/{Model}Factory.php` (زي العادة الافتراضية)، وضفنا `Factory::guessFactoryNamesUsing()` في `AppServiceProvider::boot()` عشان الـ resolution يلاقيها — من غير كده Laravel بيدوّر على namespace متداخل مطابق لمسار الموديل ومش هيلاقيها |
| 19 | **`Attribute::set()` اللي بيرجّع أكتر من مفتاح** | لو virtual accessor (زي `national_id` في `DriverProfile`) بيرجّع array فيه أكتر من عمود حقيقي (مثلاً `national_id_encrypted` + `national_id_hash`)، Eloquent بيعمل `array_merge` مباشر جوّه `$attributes` — **من غير** ما يعدّي على الـ cast بتاع كل عمود. لو حطيت `'encrypted'` cast على العمود ده في `casts()`، هيتخزن **plaintext من غير تشفير** بصمت. الحل: شفّر يدويًا جوّه الـ accessor/mutator نفسه بـ `Crypt::encryptString()`/`decryptString()`، ومتحطش cast منفصل على العمود ده |
| 20 | **سجلات append-only** | استخدم `App\Domains\Shared\Concerns\IsAppendOnly` trait + `const UPDATED_AT = null;` للجداول المعلّمة 🔒 **INSERT-only صراحة** (`verification_logs`, `booking_events`, `driver_fee_ledger`, وهيتكرر مع `admin_actions` في مجموعة ⑭). لكن `safety_events` و`incident_evidence` موثّقين "مايتحذفوش أبدًا" بس **مش** INSERT-only — استخدم `PreventsDeletion` trait الأخف بدل كده (بيسمح بالـ update، بيمنع الـ delete بس) |
| 21 | **أسماء جداول مفردة** | بعض جداول الـ ERD اسمها مفرد عمدًا (`route_cache`, وهيتكرر مع `driver_fee_ledger` في مجموعة ⑩) لكن Eloquent بيخمّن الاسم بصيغة الجمع تلقائيًا (`route_caches`). لازم `protected $table = '...';` صريح في أي موديل زي كده — راجع الاسم من الـ ERD حرفيًا قبل كل migration جديدة |
| 22 | **طول اسم الـ index** | MySQL/MariaDB بيرفض أي اسم index/constraint أطول من 64 حرف. أي `unique([...])` على 3 أعمدة أو أكتر بأسماء طويلة (زي `group_attendance`) لازم اسم صريح مختصر كـ argument تاني — من غيره Laravel بيولّد اسم طويل أوي وبيفشل الـ migration |
| 23 | **اتجاه إشارة `diffInSeconds` في Carbon** | في Carbon الحديث، `$a->diffInSeconds($b)` ممكن ترجع بالسالب حسب اتجاه المقارنة (مش absolute value زي القديم). أي حساب "مدة استجابة" أو فرق وقت لازم يتلف بـ `abs()` صراحة، متعتمدش على الإشارة الافتراضية |
| 25 | **الكتابة في أعمدة الجغرافيا** | `SpatialPoint::set()` بيرجّع **WKB blob كـ string** (SRID 4 بايت + WKB) مش `Expression` بـ `ST_GeomFromText()`. السبب: Eloquent بيخزّن قيمة الـ cast في الكاش **بس لو المدخل كان object** — فلو بعت array، الـ Expression بتفضل في الـ attributes والقراءة بترجع `null` لحد ما تعمل `fresh()`. الـ blob بيخلّي القراءة مسار واحد في الحالتين |
| 26 | **ترويسات الأخطاء** | `ApiResponse::error()` لازم تمرّر `$e->getHeaders()` — من غيرها 429 بيفقد `Retry-After` و405 بيفقد `Allow` و401 بيفقد `WWW-Authenticate` |
| 27 | **كود الخطأ مقابل الـ status** | ممنوع أي 4xx يرجع `SERVER_ERROR` — العميل هيفتكرها قابلة لإعادة المحاولة. `ErrorCode::fromHttpStatus()` بترجع لفئة الـ status (4xx → `BAD_REQUEST`) مش لـ `ServerError` |
| 28 | **موديلات الـ session guard** | أي موديل مربوط بـ guard نوعه `session` (زي `AdminUser`) لازم عمود `remember_token` — `SessionGuard::logout()` بيقرا `getRememberToken()` دايمًا، وده بيرمي `MissingAttributeException` تحت `Model::shouldBeStrict()` |
| 29 | **كاش الإعدادات** | ممنوع تخزّن الـ `$default` بتاع المستدعي جوّه الكاش — خزّن قيمة الصف بس وطبّق الـ default بعد الكاش، وإلا أول fallback هيتجمّد للأبد ويترد على كل المستدعيين بعد كده |
| 35 | **تصفير حالة المصادقة في الاختبارات** | جوّه اختبار واحد، الـ app بيتعاد استخدامه بين الطلبات، فحاجتين بيتسربوا: (أ) `RequestGuard` (اللي هو `auth:sanctum`) بيحفظ اليوزر اللي حلّه، فاختبار بيتأكد إن حاجة **بتقفل** الوصول (logout، إلغاء جهاز) بيعدّي وهو غلط · (ب) `Authenticate` middleware بيستدعي `shouldUse($guard)` اللي بيغيّر الـ **guard الافتراضي** لباقي العملية، فطلب على مسار **مالوش auth middleware** بيحلّ التوكن برضه. `Tests\TestCase::call()` بيصفّر الاتنين قبل كل طلب — بيرجّع سلوك الإنتاج (كل طلب بيبوّت app جديد) مش بيلتف حواليه. **وكمان:** `withToken()` بيحط هيدر **افتراضي** على الـ test case، فأي طلب "مجهول" بعده لازم `withoutToken()` صريح |
| 36 | **قيد CHECK بدل القيمة الافتراضية الوهمية** | لو عمود لازم يفضل فاضي لحد خطوة لاحقة في الـ flow، خليه `nullable` + `CHECK` بيربط اكتمال الحالة بامتلائه — **مش** قيمة placeholder. مثال حي: `users.gender` لو اتحطت `prefer_not_to_say` مؤقتًا كانت هتستبعد ست صامتًا من مطابقة النساء، لأن دي إجابة حقيقية مش "مش متجاوبة" |
| 37 | **إلغاء الجلسة لازم يمسح التوكن كمان** | تعليم `auth_sessions.revoked_at` لوحده بيمنع الـ refresh بس — الـ access token اللي في إيد السارق بيفضل شغال لحد ما ينتهي لوحده. عشان كده اسم توكن Sanctum = `auth_session.id`، و`AuthSession::revoke()` بتمسح الصف والتوكن مع بعض |
| 38 | **التعليق فوق قاعدة التحقق = توثيق API** | Scramble بينشر التعليق اللي فوق كل سطر في `rules()` كوصف الحقل في المستند اللي الفريق الخارجي بيقراه. يعني التعليقات هناك **مكتوبة للعميل** مش للصيانة — أي شرح داخلي (فخاخ، أسباب اختيار قاعدة على تانية) مكانه docblock الكلاس. نفس الكلام على الـ Resources والـ Actions اللي شكل خرجها بيتستنتج |
| 39 | **`Rule::in` لوحدها مش بتحدد نوع** | `['nullable', Rule::in([...])]` بيحدد القيم المسموحة بس من غير أي نوع مُعلَن — لا في التحقق ولا في العقد. لازم `'string'` (أو `'integer'`) جنبها. ولو القيم جاية من enum، `Rule::enum(X::class)->only([...])` أفضل: بيحدد النوع والمجموعة مع بعض، وبيمنع إضافة case جديدة من إنها توسّع المسموح بالسكوت |
| 40 | **ممنوع object في ملف config** | Laravel بيسيريالايز الـ config بـ `var_export` وقت `config:cache`، وده ما بيعرفش يعبّر عن object. أي إعداد محتاج object لازم يبقى class string والـ object يتبني في الكلاس — حتى لو توثيق الحزمة نفسه موصّي بغير كده (حصل فعلاً مع `scramble.security_strategy`) |
| 41 | **`sometimes` بيقتل `required_with`** | `sometimes` معناه "تحقّق **لو موجود**" — فبيتخطّى مجموعة قواعد الحقل بالكامل لما الحقل يكون غايب. يعني `required_with` على النص الغايب من زوج (زي `lat`/`lng`) عمره ما بيشتغل: إرسال نص الزوج بيتقبل ويُتجاهل بالسكوت. لأي زوج مترابط استخدم `nullable` + `required_with`، مش `sometimes` |
| 42 | **أي قائمة لازم ترتيب ثابت** | `latest()` على `created_at` (دقة ثانية) بيتعادل لصفوف اتعملت في نفس الثانية، والداتابيز بترجّعهم بأي ترتيب. ده اللي بيخلّي القائمة المصفّحة تكرّر أو تفوّت صفوف بين الصفحات. لازم tiebreak على الـ id (أو أي عمود فريد) مع أي `orderBy` على عمود ممكن يتعادل |
| 43 | **الهيلبرز المشتركة في `tests/Pest.php` بس** | function عامة معرّفة في ملف اختبار موجودة **بس** لما الملف ده يتجمّع. أي هيلبر بيستخدمه أكتر من ملف لازم يبقى في `Pest.php`، وإلا تشغيل ملف لوحده بيفشل على هيلبر شايفه في المحرّر |
| 24 | **`hasMany`/`hasOne` من موديل بـ PK غير قياسي** | لو الموديل الأب مفتاحه الأساسي مش `id` (زي `DriverProfile` اللي مفتاحه `user_id`)، Eloquent بيخمّن اسم الـ FK بصيغة `{model}_{primary_key}` (يعني `driver_profile_user_id`!) مش `driver_profile_id`. أي `hasMany`/`hasOne` من موديل زي ده **لازم** يحدد الـ FK والـ local key صراحة: `hasMany(Vehicle::class, 'driver_profile_id', 'user_id')`. الفخ ده اتكشف متأخر في الـ seeder لأن الاختبارات كانت بتستخدم اتجاه العلاقة العكسي (`belongsTo`) بس |

---

## الحالة الحالية

**آخر تحديث:** 2026-10-02 · **1,373 اختبار خضرا** · 116 endpoint للموبايل (+ صفحة ويب عامة واحدة) + داشبورد Livewire (توثيق + سائقين)
**الوضع:** ✅ **Phase 0 · 1 · 2 · 3 · 4 · 5 · 6 · 7 · 11 مكتملات + Phase 10 شريحة أولى + تسليم OpenAPI.** الجاي: شريحة Phase 10 التانية (مراجعات البروفايل · الإبلاغ · درجة الثقة — الأخيرة محتاجة قرارك). Phase 8 مستنية اتجاه الرسوم، وآخر جزء في Phase 9 مستني قرار إلغاء اليوم الواحد.
75 migration · 71 جدول دومين · 71 موديل · 83 enum · 71 factory · **116 endpoint** · **٥ أوامر مجدولة** · job واحد · **1,373 اختبار كلهم خضرا**.
**المستندات:** **`RAFEEQ_MOBILE_API_GUIDE.md`** (عقد التسليم لفريق الموبايل — إنجليزي، شاشة ← API + شرح كل حقل، وملخص عربي) · `RAFEEQ_SCREEN_API_MAP.md` (داخلي: الناقص والتعارضات) · `DEPLOYMENT.md` (تشغيل ونشر) · `RAFEEQ_PHASE_0_1_DELIVERY.md` (المرحلتين 0 و1).

### 🔄 تعديل الخطة — 2026-09-25 (بقرار المستخدم)

الترتيب الأصلي كان مرحلة-بمرحلة لحد Phase 15. اتغيّر لأن **الدايزين خلص ومستني API**، وفتحنا الـ prototype فعلًا لأول مرة. التفاصيل الكاملة في **`RAFEEQ_SCREEN_API_MAP.md`** (ملف جديد). أهم ٣ نتايج:

1. **المعمار صح والحقول ناقصة.** الشاشات المبنية (٢٧ من ٤٧) شغالة على endpoints موجودة، بس حقول زي التقييم وشارات التوثيق وتجميعات الصفحة الرئيسية مش مطلّعة.
2. 🔴 **مفيش ولا endpoint للأدمن.** `ReviewVerificationAction` و`ReviewDriverApplicationAction` موجودين ومختبَرين، ومحدش بينادي عليهم غير الاختبارات. يعني **في الإنتاج محدش يقدر يوثّق راكب ولا يعتمد سائق** — الموبايل واقف عند أول خطوة مهمة، مش لأنه ناقص، لأن نصّه التاني مش موجود. تأجيلها لـ Phase 13 كان **قرار غلط في الترتيب**.
3. 🔴 **تعارض فلوس لازم يُحسم قبل Phase 8:** الـ Bible وقيد `CHECK` في `bookings` بيقولوا الرسم بيتخصم من سعر السائق (`price = fee + driver_amount`)، وشاشتين في الـ prototype بيقولوا السائق يقبض الكامل والرسم يتضاف على الراكب. والرقم كمان متعارض (10% في الشاشة مقابل 3% موثّقة). القسم 8.1 في الخريطة.

**الترتيب الجديد:**

| # | الخطوة | الحالة |
|---|---|---|
| 1 | خريطة شاشة ← API للـ٤٧ شاشة + الـ٩ أقسام | ✅ `RAFEEQ_SCREEN_API_MAP.md` |
| 2 | اختبار بيحمي الخريطة من التعفّن | ✅ `tests/Feature/ScreenApiMapTest.php` (58 اختبار) |
| 3 | أصغر شريحة أدمن: مراجعة التوثيق + اعتماد السائق | ✅ **اتعملت** — 33 اختبار |
| 4 | ترقيع حقول Phases 2–7 من الخريطة (القسم 10-ب) | ✅ **خلصت** — ٨ من ٨ |
| 5 | ~~Phase 8~~ → **Phase 9 (دورة حياة الرحلة)** | 🔨 **شغّالة** — Phase 8 محجوزة بتعارض الفلوس (8.1)، فبدأنا اللي مش محجوز |

**اللي خلص من الترقيع:** `GET /v1/account/stats` (شاشة 21) · `PersonSummary` بالتقييم وشارات التوثيق (12 · 20 · 28) · `pickup-preview` والانعطاف التراكمي على الرحلة كلها (13 · 29) · `waitlist` للسائق (28) · سبب الرفض ونسبة الإنجاز وزمن المراجعة (15) · `flexibility` و`wantsReturnTrip` وتصحيح الميزانية لشهرية (18) · **السعر المقترح العادل** `GET /v1/commutes/{commute}/price-suggestion` (24).

**وآخر بند:** تجميعتين الصفحة الرئيسية — **`GET /v1/home`** و**`GET /v1/driver/home`** (9 · 23). الشاشتين بيبدأوا بكارت واحد لازم يبقى صح **كوحدة**: "رحلتك بعد ٥١ دقيقة، ٢ من ٣ جايين، المقابلة عند بوابة ٢". لو الكارت ده مركّب من خمس ردود على موبايل، ده خمس فرص يظهر نصّه — ووقت القيام من نداء وعدد الحضور من نداء تاني بفارق ثواني يقدروا يتعارضوا، وأول شاشة في التطبيق أسوأ مكان تورّي فيه حد تناقض. ومش paginated بنيّة: كل جزء مقصوص على حجم الشاشة، فراكب عنده ٤٠٠ حجز رده نفس حجم رد اللي عنده تلاتة.

**والباقي بعد الترقيع** محتاج مرحلة جديدة مش ترقيع: `check-in` و`Start trip` والـ wait timer (Phase 9 — دورة حياة الرحلة) · التقييمات (Phase 10) · تفصيل السعر في شاشة 12 (محجوز بتعارض الفلوس).

### شريحة الأدمن — إيه اللي اتعمل (Livewire، قرار D4)

`/admin/verifications` و`/admin/drivers` + دخول بـ MFA إجباري. **المسار الأساسي بقى ماشي من الأول للآخر** لأول مرة: راكب يوثّق → مراجع يوافق → سائق يتعمد → رحلة تتنشر → حجز.

| البند | التفصيل |
|---|---|
| **MFA إجباري** | خطوتين (باسورد ثم TOTP). مفيش مسار بيدخل حد بالباسورد لوحده. `EnsureAdminMfaIsConfirmed` بيفحص على **كل طلب** مش وقت الدخول بس — عشان أي مسار مستقبلي (SSO، إعادة تعيين باسورد، أمر console) ينادي `Auth::login()` مايعديش من غير عامل تاني |
| **منع إعادة استخدام كود TOTP** | عمود `mfa_last_used_timestamp` جديد. من غيره الكود يفضل صالح طول خطوته (٣٠ ثانية) + النافذة على الجهتين — والوعد الأساسي للـ TOTP إن الكود يُستخدم مرة واحدة |
| **٦ أدوار بصلاحيات في الكود** | `AdminRole::permissions()` هي المرجع، و`SyncAdminRolesAction` بيكتبها في جداول Spatie. صلاحيات مش فحص أدوار، و**الشوف والقرار وفتح المستند تلات صلاحيات منفصلة**: الدعم يشوف الطابور ماشي ومايفتحش بطاقة ومايقررش |
| **سجل تدقيق على كل قرار** | `AdminActionLog` كاتب واحد، والأفعال الحساسة **بترفض تتسجل من غير سبب** — صف بيقول إن مراجع اتخذ قرار ومش بيقول ليه أسوأ من مفيش صف |
| **تفاصيل أمنية** | مقارنة الباسورد بتشتغل حتى لو الإيميل مش موجود (مايبقاش oracle لإيميلات الفريق) · نفس الرسالة للباسورد الغلط والكود الغلط · throttle بالإيميل **و**بالـ IP · تجديد الجلسة عند تغيير الصلاحية (session fixation) · مهلة خمول ٣٠ دقيقة |
| **أمر `admin:create`** | console بس — مفيش endpoint ولا صفحة تعمل أدمن. الباسورد بيُطلب مخفي مش كـ argument (الـ arguments بتدخل history و`ps`)، و`uncompromised()` بيرفض باسورد موجود في تسريبات معروفة |

**باجات حقيقية اتكشفت وأنا بأبني الشريحة دي:**

1. 🔴 **`sha256($ip)` مكان ما بيحمي حاجة.** IPv4 مساحته 2^32، فالمساحة كلها تتعدّ وتتطابق في ثواني — يعني "الهاش" كان ترميز عكسي بخطوات زيادة. بقى `hash_hmac` بمفتاح التطبيق، فجدول جاهز مايفيدش من غير سرقة `APP_KEY` كمان.
2. 🔴 **الـ factory كان بيولّد سر TOTP بـ hex مش base32** — يعني مفيش كود صحيح كان هيتحقق أبدًا، وأول أدمن حقيقي من الـ factory ده ماكانش هيقدر يدخل.
3. 🔴 **زائر مش مسجّل على أي صفحة داشبورد كان بياخد 500** مش تحويل لصفحة الدخول: تحويل Laravel الافتراضي بيروح لـ route اسمه `login` وإحنا اسمه `admin.login`. أول حاجة كان الزائر هيشوفها.
4. 🟠 **`DomainException` مالهاش عرض على الويب** — بتتحول لظرف الـ API بواسطة `ApiExceptionHandler` اللي عمره ما بيشتغل لطلب ويب، فرمْيها جوّه Livewire كان بيطلع 500 مكان 404.
5. 🟠 **`forgetGuards()` في `TestCase` كان بيرمي مستخدم `actingAs()`** — التوكن في الهيدر بيتقرا تاني كل طلب (وده المقصود)، بس مستخدم الجلسة في الإنتاج بيتنقل بالكوكي فالمفروض يعيش. الاختبار كان يبان كأنه فحص صلاحيات مكسور.
6. 🟠 **مهلة الجلسة كانت بتستخدم `time()`** — Carbon مابيأثرش عليها، فالمهلة كانت **مستحيلة الاختبار** ومختلفة عن باقي المشروع اللي بيقرا الوقت من `now()`.
7. 🟠 **`user_verifications` مالهاش `submitted_at`** — الطابور مرتّب بالأقدم أولًا وبيعرض "مستني ٣ ساعات"، والاتنين وعد مش زينة. `updated_at` كان هيبوّظهم بأول كتابة تلمس الصف، فالأقدم ينزل في الطابور — باج عدالة شكله مفيش حاجة.
8. 🟠 **`reject()` في `ReviewVerificationAction` كان بيحط `action_needed`** — اسم بيوحي بحاجة والكود بيعمل غيرها. بقى `requestMoreInfo()`، وده كمان اسم الزرار في الـ prototype ("Ask info").

**قرار: مفيش رفض نهائي للتوثيق.** الحالة النهائية بتتوصل بنفاد المحاولات (`max_submission_attempts`) — قاعدة الشخص يقدر يشوفها جاية — مش بمراجع بيقفل الباب بكليك. والتزوير الواضح بيتعامل معاه بـ**إيقاف الحساب**، وهي أداة تانية وأصدق والفصل 12 بيسردها فعلًا. عشان كده `VerificationStatus::Rejected` فاضلة من غير استخدام.

**تعريف "المرحلة خلصت" اتغيّر:** مش كفاية الاختبارات تبقى خضرا — لازم **شاشات المرحلة تُقرا من الـ prototype وحقولها تتغطى حقل بحقل**، وصفوفها في الخريطة تتحدّث. ده اللي بيمنع تكرار اللي حصل في §23 من الـ ERD: مراجعة صح على الجداول وبايتة على الـ endpoints.

**قرار اتحسم:** تعارض قائمة الانتظار — **الشاشة هي اللي تتغيّر**، السائقة تقرر مين يركب (الخريطة، القسم 8.2).

### ✅ خلصنا
- قراءة وفهم المخطط الكامل (Master Plan + Engineering Bible + ERD)
- اعتماد خطة التنفيذ: Phase 0 (الأساسات) ثم Phase 1 (الداتابيز الكاملة)
- Phase 0 كاملة (تفاصيل في القسم 1 تحت)
- Phase 1 / مجموعة ① الهوية والجلسات — كاملة: 9 migrations + 9 models + 14 enum + 9 factories + 70 اختبار
- Phase 1 / مجموعة ② الأدمن + التوثيق والثقة — كاملة: 4 migrations + 4 models + 8 enum + 4 factories + 13 اختبار إضافي (المجموع التراكمي 83 اختبار خضرا)
  - إضافة `admin` guard + `admin_users` provider في `config/auth.php` (لازمة لـ Spatie Permission تعرف الـ guard الصحيح لـ AdminUser)
- Phase 1 / مجموعة ③ السائق والمركبة — كاملة: 4 migrations + 4 models + 4 enum + 4 factories + 16 اختبار إضافي (المجموع 99 اختبار خضرا)
  - `IsAppendOnly` trait جديد في `app/Domains/Shared/Concerns/` — بيتحقق من الحماية بمنع UPDATE/DELETE على `verification_logs` (وهيتستخدم في جداول append-only تانية لاحقًا)
  - فخ حقيقي اتكتشف وانحل: `Attribute::set()` اللي بيرجّع أكتر من مفتاح بيتجاهل الـ cast بتاع كل عمود (تفاصيل في المعيار #19 فوق) — كان هيسبب تخزين الرقم القومي/الرخصة **من غير تشفير فعلي**
- Phase 1 / مجموعة ④ الجغرافيا والمسارات — كاملة: 5 migrations (places, user_places.place_id FK المؤجّلة, corridors, corridor_stats, route_cache) + 4 models + 2 enum + 4 factories + 8 اختبار إضافي (المجموع 107 اختبار خضرا)
  - فخ تاني اتكتشف: `RouteCache` بيتخمّن Eloquent اسم جدوله بصيغة الجمع (`route_caches`) لكن اسمه في الـ ERD مفرد (`route_cache`) — لازم `protected $table` صريح (تفاصيل في المعيار #21)
- Phase 1 / مجموعة ⑤ عروض التنقّل والرحلات المجدولة — كاملة: 5 migrations (commute_offers, commute_locations, commute_schedules, commute_rules, scheduled_trips) + 5 models + 8 enum + 5 factories + `DepartureTimeCalculator` + 10 اختبار إضافي (المجموع 117 اختبار خضرا)
  - أول اختبار DST كامل اتعمل وعدّى: التحويل الصيفي/الشتوي في مصر (24 أبريل / 30 أكتوبر 2026) بيدّي نفس الساعة المحلية 07:05 دايمًا، بأوفست UTC مختلف — تأكدنا كمان إن tzdata بتاعة PHP في البيئة دي محدّثة وعارفة قانون 2023 المصري
  - قيد `CHECK (seats_taken <= seats_total)` اتضاف بـ `DB::statement()` (Laravel من غير fluent helper للـ CHECK) واتأكدنا إنه شغال فعليًا
- Phase 1 / مجموعة ⑥ المجموعات (جزء 1) — كاملة: commute_groups, group_members + 2 model + 3 enum + 2 factory + 4 اختبار إضافي (المجموع 121 اختبار خضرا)
- Phase 1 / مجموعة ⑦ الطلب والمطابقة — كاملة: commute_demands (🔒 سري)، saved_searches، match_scores، match_notifications + 4 models + 1 enum + 4 factories + 6 اختبار إضافي (المجموع 126 اختبار خضرا)
  - `commute_demands` اتعمل بتعليق واضح إنه ممنوع أي endpoint (من Phase 6) يرجّعه للسائقين — مفيش endpoints لسه نختبرهم، الاختبار الفعلي هيتضاف مع أول search controller
- Phase 1 / مجموعة ⑧ الطلبات والحجوزات — كاملة: seat_requests (partial-unique للطلبات النشطة)، pickup_point_requests، bookings (+ CHECK للتوزيع المالي)، booking_events (append-only) + 4 models + 8 enum + 4 factories + 9 اختبار إضافي (المجموع 135 اختبار خضرا)
- Phase 1 / مجموعة ⑨ المجموعات (جزء 2) — كاملة: group_attendance, group_absences (+ CHECK لمدى التاريخ) + 2 model + 1 enum + 2 factory + 5 اختبار إضافي (المجموع 140 اختبار خضرا)
  - رجعنا لـ migration مجموعة ⑤ (`commute_schedules`) وضفنا قيد `CHECK(end_date >= start_date)` كان ناقص من ERD §20 #17
  - فخ الجمع الشاذ تكرر: `GroupAttendance` -> Eloquent خمّن `group_attendances` بس الاسم في الـ ERD مفرد `group_attendance` — هيتكرر مع `Attendance` (بدون group_) في مجموعة ⑪ الجاية، لازم أتذكره
- Phase 1 / مجموعة ⑩ الفلوس — كاملة (أكبر مجموعة): payment_methods، payments (+ CHECK للتوزيع + idempotency_key)، payment_webhooks (replay guard)، driver_fee_ledger (append-only + نفس فخ الجمع الشاذ)، driver_balances، payouts، refunds + 7 models + 7 enum + 7 factories + 12 اختبار إضافي (المجموع 152 اختبار خضرا)

- Phase 1 / مجموعة ⑪ الرحلة الحية — كاملة: trip_sessions، attendance (مفرد، الفخ المتوقع فعلاً حصل)، trip_locations (append-only, من غير updated_at)، trip_wait_timers + 4 models + 4 enum + 4 factories + 10 اختبار إضافي (المجموع 162 اختبار خضرا)
  - وضّحنا الفرق بين `group_attendance` (حضور مُعلَن مسبقًا) و`attendance` (حضور فعلي بيحدد الفاتورة) في الاختبارات كمان مش بس التعليقات

- Phase 1 / مجموعة ⑫ التقييم والثقة — كاملة: ratings (double-blind + CHECK للنجوم 1-5)، rating_tags، review_reports + 3 models + 3 enum + 3 factories + 7 اختبار إضافي (المجموع 169 اختبار خضرا)
  - `scopeVisible()` على `Rating` — عمدًا مش global scope، لأن المستخدم لازم يفضل يشوف تقييمه هو نفسه اللي بعته — التوثيق في الموديل نفسه يوضح الفرق
  - سمّينا الـ enum `RatingTagValue` مش `RatingTag` عشان يتفادى تعارض الاسم مع موديل `RatingTag` (جدول rating_tags)

- Phase 1 / مجموعة ⑬ الأمان — كاملة (8 جداول): emergency_contacts، live_shares (توكن غير قابل للتخمين)، safety_events (`PreventsDeletion` جديد)، sos_events، incidents، incident_evidence، blocked_users (فحص باتجاهين)، escort_windows + 9 models + 5 enum + 8 factories + 17 اختبار إضافي (المجموع 186 اختبار خضرا)
  - trait جديد `PreventsDeletion` — أخف من `IsAppendOnly`، للجداول اللي "مايتحذفوش" بس بتتحدّث عاديًا
  - فخ اتصلح: `SosEvent::responseSeconds()` كان بيرجّع سالب بسبب اتجاه `diffInSeconds` في Carbon الحديث (معيار #23 فوق)

- Phase 1 / مجموعة ⑭ التواصل + الأدمن + التحليلات — كاملة (11 جدول، آخر مجموعة): notifications (بيغطّي/يستبدل `notifications()` الافتراضية بتاعة Notifiable عمدًا — شرح في الموديل)، notification_preferences، conversations، messages، admin_actions (append-only)، support_tickets، platform_settings (مع كاش + invalidation)، feature_flags (rollout % deterministic)، analytics_events، recommendation_cache، demand_heatmap_cells + 11 model + 4 enum + 11 factory + 17 اختبار إضافي (المجموع 207 اختبار خضرا)
- **DatabaseSeeder الكامل** — سيناريو نور (سائقة معتمدة + عربية + عرض منشور + مجموعة) ومريم (راكبة موثّقة + حجز trial مؤكّد) + كورريدور الرحاب-سمارت فيلدج + 24 مستخدم في طابور التوثيق + حالة أمان مفتوحة. `migrate:fresh --seed` بيشتغل نضيف من الصفر.
  - فخ أخير اتكتشف من الـ seeder نفسه: `DriverProfile::vehicles()` و`activeVehicle()` كانوا من غير FK صريح، فـ Eloquent كان بيخمّن `driver_profile_user_id` (غلط) بدل `driver_profile_id` لأن الـ PK بتاع DriverProfile مش `id` — راجع المعيار #24 فوق. الاختبارات القديمة ماكانتش لاقفاه لأنها كانت بتستخدم اتجاه العلاقة العكسي بس (`belongsTo`)
  - جرّبنا اختبار concurrency حقيقي (اتصالين PDO منفصلين يتسابقوا على نفس القفل) لكنه اتعارض مع إن `RefreshDatabase` بيلف كل اختبار في transaction مش متعمول له commit — التفاصيل والقرار موثّقين، والاختبار الفعلي هيتأجل لـ Phase 7 لما `ApproveSeatRequest` Action الحقيقي يتكتب

### ⬜ مؤجّلة عمدًا (مش جزء من Phase 0/1)
- Docker compose / GitHub Actions CI — هتتضاف لو اتطلبت أو في Phase 15
- PHPStan level 8 — عطلان في بيئة التطوير الحالية (PHP 8.5.7 + phpstan 2.2.14 بيقع من غير أي رسالة خطأ حتى مع ملف واحد وlevel 0). Pint شغال وسليم كبديل مؤقت لمراجعة الأسلوب
- اختبار concurrency حقيقي على مستوى قاعدة البيانات (10 موافقات متزامنة → واحدة بس تنجح) — محتاج `ApproveSeatRequest` Action من Phase 7

### 🔍 Code Review بعد اكتمال Phase 1 (2026-09-19)
اتعمل فحص آلي على الـ 71 موديل. **3 مشاكل حقيقية اتكشفت واتصلحت:**
1. 🔴 `RecommendationCache` كان بيشاور على جدول مش موجود (`recommendation_caches`) — الموديل كان هيقع أول استخدام. اتصلح + **اتضاف `ModelFactorySmokeTest` بيغطي الـ 71 موديل كلهم** (الـ 8 موديلات اللي ماكانش عليها أي اختبار هي السبب إن الباج عاش)
2. 🔴 `UserVerification.status` و`Attendance.status/confirmed_by/gps_corroborated` كانوا mass-assignable — ثغرة "المستخدم يعتمد توثيق نفسه" و"يأكد حضوره بنفسه" (والحضور بيشغّل الفاتورة، D18). اتشالوا من `fillable`
3. 🟠 `phone_e164` و`full_name` كانوا بيتسربوا في أي serialization افتراضي (فخ #21 + وعد المنتج). بقوا مخفيين افتراضيًا مع `makeVisible()` صريح للملف الشخصي + **`PrivacyGuaranteesTest` جديد**

**كمان:** 4 أعمدة enum ماكانش عليها cast اتصلحت. **اتفحص وطلع سليم:** كل الـ FK عليها indexes · مفيش fillable/cast/hidden على عمود مش موجود · مفيش اسم جدول غلط تاني · مفيش علاقة تانية بمفتاح مخمّن غلط.

### 🔍 Code Review تاني (مراجعة خارجية على طبقة الـ HTTP والـ casts)
**5 مشاكل حقيقية اتصلحت** (تفاصيلها في المعايير #25–#29):
1. 🟠 **`SpatialPoint`** — المدخل كـ array كان بيرجّع `null` لحد ما تعمل `fresh()`. اتصلح جذريًا بكتابة WKB blob بدل `Expression`
2. 🟠 **ترويسات مفقودة** — 429 كان بيفقد `Retry-After`، و405 بيفقد `Allow`
3. 🟠 **`abort(400)` كان بيرجع `SERVER_ERROR`** مع status 400 — العميل هيفتكرها قابلة لإعادة المحاولة
4. 🟠 **`admin_users` من غير `remember_token`** — `logout()` على الـ admin guard كان هيرمي استثناء
5. 🟠 **`PlatformSetting::value()`** كانت بتخزّن الـ default بتاع أول مستدعي للأبد
6. 🟢 **`email` و`date_of_birth`** اتضافوا للمخفيين + **`AuthorizationException` callback ميت** اتشال (Laravel بيحوّله لـ 403 قبل الـ callbacks)

**نتيجة مهمة — ملاحظة اتحققت وطلعت غير دقيقة:** المراجعة قالت إن `abort($response)`/Precognition بيتحولوا لـ 500. **مش صح** — `Route::run()` بيمسك `HttpResponseException` بنفسه، فهي عمرها ما بتوصل للـ handler من داخل route action (اتأكدنا بـ probe فعلي + كود الإطار). **بس** لو اترمت من **middleware** فهي بتوصل فعلاً وبتتحول لـ 500 — فالحماية اتضافت والاختبار بيغطي المسار الصح ده (اتأكدنا إنه بيفشل من غير الحماية).

---

## Phase 2 — المصادقة والهوية ✅ (2026-09-20)

**المصدر:** `book/RAFEEQ_Chapter_02_Detailed_Authentication_Story.md` (1697 سطر، اتقرا كامل قبل أي كود).

### نقاط الـ API (11)

| الطريقة | المسار | الوصول | الفصل |
|---|---|---|---|
| POST | `/v1/auth/otp/request` | عام | §23.1 |
| POST | `/v1/auth/otp/verify` | عام | §23.2 |
| POST | `/v1/auth/session/refresh` | عام (الـ refresh token هو الاعتماد) | §23.3 |
| GET | `/v1/auth/me` | مسجّل دخول (حتى لو موقوف) | §3 |
| POST | `/v1/auth/logout` | مسجّل دخول (حتى لو موقوف) | §23.4 |
| GET | `/v1/account/devices` | مسجّل دخول (حتى لو موقوف) | §21 |
| DELETE | `/v1/account/devices/{id}/session` | مسجّل دخول (حتى لو موقوف) | §23.5 |
| PATCH | `/v1/account/devices/current/security` | مسجّل دخول | §15/§16 |
| GET · POST | `/v1/account/consents` | مسجّل دخول | §27 |
| PUT | `/v1/account/profile/basic` | نشِط (غير موقوف) | §17 |

**ثلاث طبقات وصول** موثّقة في `routes/api.php`: عام · مسجّل دخول · نشِط. المسارات اللي بيحتاجها الموقوف (يشوف حالته، يخرج، يلغي جهاز مسروق) عمدًا **برّه** طبقة `account.active` — عشان ما نحبسش حد من غير طريق للاستئناف (سيناريو H).

### القرارات الحاسمة

| # | القرار | السبب |
|---|---|---|
| 30 | **طول الـ PIN = 4** (الكتاب بيقول 6) | `RAFEEQ_MASTER_PLAN.md` §13 حسم التعارض لصالح الـ prototype. وهامشي هندسيًا: الـ PIN محلي بالكامل، السيرفر بيخزن `devices.has_local_pin` (boolean) بس. الـ **OTP** 6 أرقام (رقم تاني متسق بين المصدرين) |
| 31 | **`OtpSender` interface + `LogOtpSender`** | مزوّد الـ SMS لسه سؤال مفتوح (§19 سؤال 2). التبديل = كلاس واحد + سطر config. `LogOtpSender` **بيرمي exception في production** عشان النشر من غير مزوّد حقيقي يفشل بصوت عالي مش بصمت |
| 32 | **`users.gender` و`registered_role` بقوا nullable + CHECK constraint** | الـ flow الموثّق بينشئ الصف عند تحقق الـ OTP، والحقلين بيتجمّعوا بعدين (§17 + Bible §1.3/§1.5 + الفصل §20.1 بيعلّم gender بـ "Policy-based"). **رفضنا القيمة الافتراضية الوهمية**: `prefer_not_to_say` إجابة حقيقية، فست نصّ مسجّلة كانت هتتستبعد صامتًا من مطابقة النساء. الـ CHECK بيمنع `profile_status='basic_complete'` طول ما أي حقل هوية فاضي |
| 33 | **الـ access token اسمه = `auth_session.id`** | عشان `revoke()` تقدر تمسح التوكن الفعلي كمان مش بس تعلّم الصف — من غير كده التوكن المسروق بيفضل شغال 15 دقيقة كاملة بعد الإلغاء (سيناريو G) |
| 34 | **`Rotated` و`Superseded`** اتضافوا لـ `SessionRevocationReason` | لازم نفرّق بين توكن **متدوّر** (استخدامه تاني = سرقة → إلغاء العيلة كلها) وتوكن **مسجّل خروجه** (عميل قديم بس → إلغاء فردي). من غير التفرقة دي إما نكذب في سجل التدقيق أو نعمل تسجيل خروج جماعي على سباق عادي |
| 35 | **`Model::forgetGuards()` في `TestCase::call()`** | Laravel مش بيصفّر الـ guards بين الطلبات جوّه نفس الاختبار، و`RequestGuard` (وهو `auth:sanctum`) بيحفظ اليوزر اللي حلّه. يعني اختبار "الخروج بيقفل الوصول" كان بيعدّي لسبب غلط. ده بيرجّع سلوك الإنتاج (كل طلب بيبوّت app جديد) مش بيلتف حواليه |

### الضمانات الأمنية المُختبَرة

- **منع الاستعلام عن الحسابات (سيناريو D):** `otp/request` **عمره ما بيلمس جدول `users`** — مفيش branch أصلاً يقدر يفرّق. اختبار بيقارن الرد على رقم له حساب ورقم مالوش: نفس الـ status، نفس المفاتيح، نفس القيم كلها ما عدا اللي مشتقة من الرقم اللي المستخدم كتبه بنفسه
- **الـ OTP:** `random_int` (CSPRNG) → `Hash::make` → مفيش plaintext في الرد ولا الداتابيز ولا أي لوج. استعمال واحد فقط (بـ update شرطي ذرّي عشان السباق) · مربوط بالغرض · الإصدار الجديد بيقتل القديم فورًا (سيناريو E)
- **الـ rate limiting على 3 محاور:** الرقم (5/ساعة) · الـ IP (20/ساعة) · التحقق من نفس الـ challenge (10/دقيقة) — كلها بترجّع `Retry-After`
- **`refresh` rotation + كشف إعادة الاستخدام:** sha256 بس في الداتابيز · أي استخدام تاني لتوكن متدوّر → **إلغاء الـ `token_family_id` كله** + `security_event` بـ `risk_level: high` (RFC 9700 §4.14.2)
- **`SecurityLog` بيغسل الـ metadata:** أي مفتاح اسمه `code`/`otp`/`pin`/`token`/... بيتحوّل لـ `[redacted]`، والأرقام بتتخزن مقنّعة (`*********5678`)، والـ IP بـ sha256
- **IDOR:** جهاز حد تاني بيرجّع **404 مش 403** — 403 كان هيأكد إن الـ id موجود
- **`gender`** غير موجود في أي خرج، واختبار بيفحص الـ payload كله كـ string مش بس المفاتيح

### الملفات

```
app/Domains/Identity/
  Actions/       RequestOtp · VerifyOtp · AuthenticateWithOtp · RegisterDevice ·
                 IssueSession · RefreshSession · RevokeSession · RecordConsent ·
                 CompleteBasicProfile
  Contracts/     OtpSender
  Enums/         +AccountState · NextStep · SecurityEventType (المجموع 80)
  Support/       AuthSettings · LaunchRouter · SecurityLog · ConsentRegistry ·
                 CurrentSession · LogOtpSender · DeviceIdentity ·
                 IssuedSession · AuthenticationResult
app/Domains/Shared/Exceptions/DomainException.php
app/Http/  Controllers/Api/V1/{Auth,Account} · Requests/{Auth,Account} ·
           Resources/{User,Device,Session,Authentication} ·
           Middleware/{EnsureAccountIsActive,EnsureProfileIsComplete}
config/rafeeq.php          كل رقم إعداد (OTP · session · profile · legal)
database/migrations/2026_09_20_090000_relax_users_profile_columns_*.php
tests/Feature/{Auth,Account}/   71 اختبار جديد
tests/Support/FakeOtpSender.php
```

### باجات حقيقية اتكشفت أثناء التنفيذ

1. 🔴 **سلسلة الـ resend ماكانتش بترجع تتصفّر** — `activeChallengeFor` كان بيدوّر على `status = pending` بس، ومفيش حاجة بتقلب الصف لـ `expired` لما وقته يخلص. يعني اللي يطلب 4 أكواد ومايتحققش **كان هيتقفل عليه للأبد**، والحد الساعي ماكانش هيوصل له أصلاً. اتصلح بشرط `expires_at > now()`
2. 🔴 **`User::create()` مش بيقرا الـ defaults بتاعة الداتابيز** — `account_status` رجع `null` وكسر أول رد تسجيل بـ 500. اتصلح بـ `refresh()` بعد الإنشاء
3. 🟠 **`*/` جوّه docblock** (في `lang/*/errors`) كانت بتقفل التعليق بدري وتكسر الملف — اتلقطت من الـ linter
4. 🟠 **التحدي المقفول (`Blocked`) كان بيرجّع `AUTH_OTP_INVALID`** بدل `AUTH_OTP_MAX_ATTEMPTS` — العميل ما كانش هيعرف إنه لازم يطلب كود جديد
5. 🟠 **`getPreferredLanguage()` مش بيفرّق بين "طابق" و"مطابقش"** — بيرجّع أول لغة في القايمة اللي بعتها له لما مفيش تطابق، فـ `Accept-Language: fr` كان بيتقري كأنه طلب عربي. اتصلح بمسح يدوي لـ `getLanguages()` بترتيب أفضلية العميل نفسه (لازم عشان الرجوع للغة المخزّنة يبقى قابل للوصول أصلاً)

### التوطين (ar/en)
`SetLocaleFromHeader` بقى بيكمّل الوعد اللي كان مكتوب في تعليقه: `Accept-Language` بيكسب لو بيطلب لغة إحنا بنتكلمها، وإلا `users.preferred_language` للمسجّل دخوله، وإلا العربي. كل أكواد الأخطاء الجديدة (15 كود) ورسايل التحقق مترجمة في `lang/ar` و`lang/en`.
**ملاحظة للاختبارات:** `Request::create()` بتاعة Symfony بتحقن `Accept-Language: en-us` افتراضيًا، فمفيش اختبار يقدر يبعت "من غير هيدر" — بنستخدم `fr` كبديل لنفس نقطة القرار.

### ⬜ مؤجّل من Phase 2 (موثّق مش مسكوت عنه)

- **مزوّد SMS حقيقي** — السؤال المفتوح رقم 2. الـ seam جاهز (`OtpSender`)
- **تغيير رقم الموبايل (سيناريو F)** — الفصل نفسه بيقول إنها ميزة أمان حساب مش تعديل بروفايل، ومحتاجة purpose `phone_change` بـ endpoint خاص لمستخدم مسجّل دخوله. مؤجّلة للمرحلة اللي فيها إعدادات الحساب
- **رفع صورة البروفايل + تجريد الـ metadata** — محتاج طبقة التخزين S3 (قرار D7)، مؤجّل للمرحلة اللي فيها الملفات
- **إشعار أمان عند إلغاء جهاز** — `security_event` بيتكتب، لكن الإشعار الفعلي محتاج طبقة الإشعارات
- **الحد الأدنى للسن = 18 — افتراض مش قرار موثّق.** الفصل بيطلب حد أدنى من غير ما يحدد رقم، ولا الخطة ولا الـ Bible فيهم رقم. اخترنا 18 (سن الرشد + الحد الأدنى لرخصة القيادة في مصر). **محتاج تأكيد من المنتج/القانوني** — بس مش بيوقف حاجة، لأنه قابل للتعديل من `platform_settings` (تحت)

### الأرقام القابلة للتعديل وقت التشغيل (معيار #11)

بقرار المستخدم (2026-09-23): أي رقم سياسة زي الحد الأدنى للسن لازم يتعدّل من الـ **settings** مش من الكود، والداشبورد هتعدّله لما نوصل لمرحلتها.

كل رقم بيمرّ من `platform_settings` → لو مفيش صف، الافتراضي من `config/rafeeq.php`. **ممنوع أي Action أو Request أو Resource يقرا `config('rafeeq...')` مباشرة** — لازم عبر accessor:

| الـ accessor | بيغطّي |
|---|---|
| `Identity\Support\AuthSettings` | طول الـ OTP · عمره · مهلة إعادة الإرسال · حد المحاولات وإعادة الإرسال · عمر الـ access/refresh token · طول الـ PIN · حدود الـ rate limiting الثلاثة |
| `Identity\Support\ProfileSettings` | الحد الأدنى للسن · أدنى وأقصى طول للاسم |
| `Identity\Support\ConsentRegistry` | إصدارات الشروط وسياسة الخصوصية |

**استثناء واحد معلّم `DEPLOY-ONLY`:** `auth.otp.driver` (مزوّد الـ SMS). ده توصيل بنية تحتية مش سياسة — خطأ كتابة فيه من داشبورد كان هيوقّف المصادقة على الكل، ومش زي رقم السياسة مش ممكن تتحقق منه بالنظر. `RuntimeTunableSettingsTest` فيه اختبار بيثبت إن صف `platform_settings` عليه **مالوش أي تأثير**.

**سقوف مهمة:** أي إعداد بيحدّد طول عمود، عرض العمود هو السقف الحقيقي (`users.full_name` = varchar(100)، `users.public_first_name` = varchar(50)). موثّقة inline في `config/rafeeq.php`. عشان كده `public_first_name` بيتقص على 50 كـ literal مش كإعداد — لو بقى قابل للتعديل كان ممكن حد يظبّطه على قيمة بتقطع الكتابة.

**اختبار:** `tests/Feature/Account/RuntimeTunableSettingsTest.php` (11 اختبار) — بيثبت إن تغيير صف بيغيّر السلوك فعلاً على الـ endpoints، مش بس قيمة الـ accessor. الوعد ده مش حقيقي إلا لو مُختبَر: accessor بيفضل يقرا `config` بالسر كان هيبان مطابق تمامًا من برّه.

**تبعية على مرحلة الداشبورد (⑭):** الداشبورد محتاجة كتالوج بالمفاتيح القابلة للتعديل (المفتاح · النوع · الافتراضي · الوصف · السقف). مش اتعمل دلوقتي عمدًا — بيتعمل مع الشاشة نفسها عشان ما نبنيش تجريد لواجهة لسه ما اتحددتش.

### تسليم OpenAPI للفريق الخارجي ✅ (2026-09-23)

الخطة §15.4/§18 بتحسب الـ OpenAPI + staging تسليم مستحق **من نهاية Phase 2** مش بعدين. Scramble كان مثبّت من Phase 0 بس من غير ضبط.

| | |
|---|---|
| `config/scramble.php` | عنوان · إصدار · servers (Local + Staging) · وصف بيشرح الـ envelope والتوطين وقاعدة الـ refresh · شاشة الـ docs محمية بـ `RestrictedDocsAccess` (local بس) |
| `BearerTokenSecurity` | بيشتق متطلّب المصادقة **من الـ middleware بتاع المسار** — المصدر الوحيد اللي ما يقدرش يبعد عن الحقيقة. المسارات العامة بتتعلّم `security: []` صراحة |
| `DescribeErrorResponses` | operation transformer: بيصلّح `meta` + بيضيف ردود الأخطاء |
| `#[ApiErrors(...)]` | attribute على كل controller method بيعلن أخطاء الـ endpoint. **32 رد خطأ موصوف** على 11 endpoint |
| `composer openapi` | أمر واحد يولّد المستند (`storage/app/openapi.json`، مش في git — مُشتَق) |
| `OpenApiDocumentTest` | **10 اختبارات** بتحمي العقد |

**ليه الأخطاء معلَنة (attribute) مش مُستنتَجة:** كل رفض في النظام ده `DomainException` بيترمي جوّه طبقة الـ Action — عمره ما يظهر في return type. أي تخمين ساكن هيبقى غلط أو واسع لدرجة ما يفيدش. الـ attribute يخلّي العقد قرار مقصود، و`OpenApiDocumentTest` بيمنعه من التعفّن: لو Action رمى كود جديد ومحدش وثّقه، الاختبار بيفشل ويسمّي الكود.

**الأخطاء العامة مُشتَقة مش معلَنة:** 422 لو فيه FormRequest · 401 لو `auth:sanctum` · 403 لو `account.active` · 500 دايمًا. دي حقايق عن المسار، مش تخمين.

#### باجات حقيقية اتكشفت وهي بتتعمل

1. 🔴 **`purpose` و`preferredLanguage` ماكانش عليهم قاعدة `string` أصلاً** — `Rule::in` لوحدها بتحدد القيم المسموحة بس مش النوع، فالحقلين كانوا **بلا نوع مُعلَن** في التحقق نفسه مش بس في التوثيق. و`purpose` بقى `Rule::enum(OtpPurpose::class)->only([...])` — كده إضافة case جديدة للـ enum ما تقدرش توسّع اللي الطلب المجهول يقدر يطلبه بالسكوت
2. 🔴 **Scramble بينشر التعليقات الداخلية كوصف الحقول في العقد** — الفريق الخارجي كان هيقرا "pitfall #31" و"filter-then-map". التعليق فوق أي قاعدة تحقق **هو توثيق API**، فاتكتب للجمهور ده، وملاحظات الصيانة اتنقلت لـ docblock الكلاس. القاعدة دي معيار #38 تحت
3. 🟠 **`--fail-on-unknown` بيدّي false positive** — بيفحص شجرة النوع الداخلية قبل التطبيع وبيبلّغ عن حقول بتخرج موصوفة صح. مش مستخدم في `composer openapi`؛ بدالُه اختبار بيمشي على **المستند المنشور** نفسه ويفشل على أي عقدة من غير نوع (اتأكدنا إنه بيعضّ فعلاً)
4. ⚠️ **خطأ مني اتصحّح:** وأنا بحاول أرضي الـ flag الكداب، حوّلت `outstandingFor()` من `foreach` لـ `array_map` — وده **كسر** نوع عناصر المصفوفة في العقد المنشور (كان سليم من الأصل). اترجع، والسبب موثّق في الميثود عشان محدش يعيدها كـ"تحسين"

**اختبار `config:cache`:** الحزمة بنفسها بتوصّي تحط `SecurityScheme` **object** في الـ config. ده بيشتغل لحد أول deploy بيعمل `config:cache` وبعدها بيقع، لأن Laravel بيسيريالايز الـ config بـ `var_export` اللي ما بيعرفش يعبّر عن object. عشان كده الـ scheme في كلاس والـ config فيه class string بس — واتأكدنا إن `config:cache` بيعدّي.

---

## Phase 3 — البروفايل والتوثيق ✅ (2026-09-23)

**تصحيح ترقيم:** الفصل 3 من الكتاب (توثيق السائق) هو **Phase 4** مش 3. المرحلة 3 حسب الخطة §869 هي **Verification Centre بالمستويات الأربعة + رفع مستندات آمن + بوابة التوثيق**. الـ Basic profile كان خلص في المرحلة 2.

### المستويات الأربعة

| المستوى | الطريقة | مستندات | مراجعة بشرية |
|---|---|---|---|
| `phone` | OTP | — | ❌ (تلقائي عند أول دخول) |
| `government_id` | مراجعة | البطاقة وجه + ظهر | ✅ |
| `selfie` | مراجعة | سيلفي | ✅ |
| `organization` | نطاق الإيميل | (أو badge لو مفيش نطاق) | ❌ لو النطاق طابق |

`users.trust_level` = عدد المعتمَد (0–4)، بيتحسب من الصفوف نفسها كل مرة مش بـ increment — عدّاد بيتزوّد مرتين أو بيفوت مرة بيسيب رقم غلط للأبد. **مختلف تمامًا** عن `trust_scores.score` (0–100 داخلي، Phase 10 — التقييمات).

### نقاط API (5 جديدة، المجموع 16)

`GET verifications` (المركز) · `POST verifications/{type}/documents` · `POST verifications/{type}/submit` · `POST verifications/organization` · `GET verifications/documents/{document}` (رابط موقّع قصير العمر)

### القرارات الحاسمة

| # | القرار | السبب |
|---|---|---|
| 44 | **ترتيب الرفع: افحص → جرّد الميتاداتا → خزّن** | الفحص بعد إعادة الترميز بيفحص ملف إحنا عملناه — مابيثبتش حاجة عن اللي اترفع. والتخزين قبل الفحص بيحط بايتات مفحوصة في الـ bucket، و"مسحناها بعدين" مش زي "عمرها ما اتخزنت" |
| 45 | **إعادة ترميز الصورة عبر GD** | مش تجميل: صورة البطاقة من موبايل بتحمل إحداثيات GPS في الـ EXIF — غالبًا بيت الشخص، لأن ده المكان اللي بيصوّروا فيه مستنداتهم. تخزينها جنب الرقم القومي بيدّي للمهاجم أكتر من المستند نفسه، والشخص عمره ما وافق على ده. فك الترميز لـ bitmap وإعادة الترميز بتسيب البيكسلات بس |
| 46 | **مسار التخزين مالوش معنى** | اسم عشوائي تحت id المالك — مش نوع المستند ولا اسم الشخص ولا اسم الملف الأصلي. سرد الـ bucket مابيكشفش حاجة، والمسار المسرّب مايتعدّلش لتخمين الجار |
| 47 | **العمود المولّد الفريد والكود اللي بيفحصه = بيان واحد لقاعدة واحدة** | لو `assertNoDuplicate…()` بتحسب حالات معيّنة، لازم العمود المولّد يشوف **نفس القائمة بالحرف**. أول ما يختلفوا، الـ index بيرفض حاجة الكود لسه موافق عليها، والمستخدم بياخد **500 مش رفض مفهوم** — لأن القيد بيضرب بعد ما الكود قال "تمام". حصل فعلاً في `bookings` (Phase 7، باج #2). أي تضييق للقيد لازم يعدّل الاتنين في نفس الـ commit، والتعليق في كل واحد يشاور على التاني |
| 48 | **اللي المايجريشن بتقول "إحنا بنحسبه" مايبقاش `#[Fillable]`** | أي عمود موصوف إنه "محسوب عندنا، مش من المستخدم" (زي `added_minutes`, `added_km`) أو إنه رد الطرف التاني (زي `alternative_place_id`, `status`) لازم يتحدّد بإسناد صريح في الـ Action، مش عبر `fill()`. التعليق في الـ migration نيّة؛ استبعاده من `#[Fillable]` هو اللي بينفّذها. حصل فعلاً في `pickup_point_requests` (Phase 7، باج #3): راكب كان يقدر يعلن انعطاف 0.1 دقيقة ويعدّي فحص `max_detour_minutes` بتاع السائق |
| 44 | **presigned أو route موقّع = **إعداد صريح** مش استنتاج قدرة الـ disk** | `Storage::fake()` بتخلّي **أي** disk يدّعي إنه بيدعم presigned URLs — يعني فحص القدرة بياخد مسار مختلف تحت الاختبار عن الإنتاج، وده أسوأ مكان يحصل فيه الاختلاف لأن للمسارين خصائص أمان مختلفة |
| 45 | **404 مش 403 لمستند حد تاني** | 403 بيأكد إن الـ id موجود |
| 46 | **رفض = `action_needed` مش `rejected`** | الشخص يقدر يصلّح ويعيد، والمركز بيوريها صف فيه إجراء مش طريق مسدود. والسبب **إجباري** ومكتوب للشخص (الفصل 3 §10) |
| 47 | **`OrgType::School` اتضاف** | الـ ERD بيسمح `organizations.type = school` لكن `users.org_type` كان work/university بس — يعني عضو مدرسة مش ممكن يتسجّل نوعه. البدائل أسوأ: تحويله لـ university بيسجّل حاجة غير صحيحة عن شخص حقيقي، وسيبه null بيخلط "مش متجاوب" بـ"متجاوب ومالقيناش كلمة" |

### باجات حقيقية اتكشفت

1. 🔴 **المركز كان بيبلّغ الطريقة الافتراضية للمستوى مش اللي حصلت فعلًا** — مستوى اتوثّق بنطاق الإيميل كان بيظهر `badge_review`. المركز بيوصف **الحساب ده** مش الحالة العامة
2. 🔴 **3 حقول جديدة كانت خارجة للفريق التاني بدون نوع** (`requiredDocuments` · `missingDocuments` · `attemptsRemaining`) — اتلقطوا بـ `OpenApiDocumentTest` اللي اتكتب في الخطوة اللي فاتت. نفس الدرس المتكرر: `array_map` بيدّي `array<unknown>` و`foreach` بيدّي `array<string>`
3. 🟠 **تسريب حالة بين الطلبات جوّه الاختبار:** `Authenticate` middleware بيستدعي `shouldUse($guard)` اللي بيغيّر الـ **guard الافتراضي** لباقي العملية. في الإنتاج ده بيموت مع الطلب، جوّه اختبار بيفضل — فبعد أي طلب مُصادَق، `$request->user()` كان بيحلّ توكن على مسار **مالوش auth middleware أصلاً**. اتصلح في `TestCase::call()` (معيار #35 اتوسّع)
4. 🟠 **`withToken()` بيحط هيدر افتراضي على الـ test case** — فأي اختبار "بدون مصادقة" بعد helper بيستخدمه كان بيعدّي وهو غلط. لازم `withoutToken()` صراحة

### ⬜ مؤجّل من Phase 3

- **محرك فحص فيروسات حقيقي** (ClamAV أو خدمة) — الـ seam جاهز (`VirusScanner`)، والـ scanner الحالي بيرفض أي حاجة مش صورة ويرمي exception في production
- **OCR** — الفصل 3 §5/§6 بيطلبه لمطابقة الرقم والاسم. عمود `ocr_payload` موجود ومشفّر. مؤجّل لـ Phase 4 مع بيانات الرخصة
- **شاشة المراجعة للأدمن** — الـ Action جاهز ومختبَر (`ReviewVerificationAction`)، الواجهة Livewire في Phase 13
- **job تنظيف المستندات المنتهية** (`purge_after` بيتكتب عند الرفع) — محتاج طبقة الـ jobs
- **إشعار عند قرار المراجعة** — `security_event` بيتكتب، الإشعار الفعلي محتاج Phase 12

---

## Phase 4 — السائق والمركبات ✅ (2026-09-23)

**المصدر:** `book/RAFEEQ_Chapter_03_Detailed_Driver_Verification_Story.md` (كامل) + الخطة §874.

### 11 endpoint جديد (المجموع 27)

`GET driver/eligibility` · `GET/POST/DELETE driver/application` · `PUT driver/application/licence` · `POST driver/application/submit` · `GET/POST driver/vehicles` · `PATCH driver/vehicles/{v}` · `POST driver/vehicles/{v}/activate` · `POST driver/vehicles/{v}/documents`

**بوابة التوثيق بقى لها أول مستهلك حقيقي:** كل مسارات `/v1/driver` وراء `verified:government_id` — رفيق مايسمحش لهوية غير موثّقة تقدّم لتوصيل ناس. ما عدا `eligibility` نفسها، لأن غرضها يقول للشخص الناقص إيه، فتقييدها كان هيسيبه برفض من غير تفسير.

### القرارات الحاسمة

| # | القرار | السبب |
|---|---|---|
| 48 | **`VerificationType::DrivingLicence` اتضاف — بس مش مستوى ثقة خامس** | صور الرخصة محتاجة بيت: `identity_documents.user_verification_id` مش nullable، و`DocumentKind` في الـ ERD أصلاً فيها `licence_front/back` — يعني السكيمة اتصمّمت متوقّعة ده. وفي نفس الوقت الرخصة **مابتقولش** للراكب يثق بحد، بتقول إن الشخص مسموح له يسوق. فهي برّه `levels()` ومابتحركش `trust_level` |
| 49 | **نفس مسار الرفع الآمن لمستندات العربية** | استمارة العربية فيها اسم المالك وعنوانه — مش أقل حساسية من البطاقة. اتعمل `DocumentIntake` مشترك عشان ترتيب (فحص → تجريد → تخزين) يبقى في **مكان واحد**؛ نسخة تانية من ترتيب من 3 خطوات هي نسخة ممكن تفقد خطوة بالسكوت |
| 50 | **كود تكرار **واحد** لكل الحقول** | لو قلنا للمتقدّم **أي** بيان اتكرر (رقم قومي / رخصة / لوحة)، الـ endpoint يبقى أداة استعلام: "هل الشخص ده أو العربية دي على المنصة؟". المتقدّم الصادق مايحتاجش يعرف — بالنسبة له ده خطأ كتابة. الـ **reviewer** بيشوف التفصيل في `security_events` بـ `risk_level: high` |
| 51 | **صلاحية الرخصة لازم تزيد عن المراجعة نفسها** | المراجعة 24–48 ساعة (§4). رخصة بتخلص بكرة هتتعتمد وتبقى بلا قيمة فورًا — والسائق هيكون نشر رحلات. الحد (30 يوم) إعداد قابل للتعديل، و**بيتفحص تاني عند التقديم** مش وقت الكتابة بس: الطلب ممكن يقعد أسابيع في draft |
| 52 | **الموافقة على السائق بتعتمد عربيته، والإيقاف بيوقّفها** | سائق معتمَد وعربيته pending = سائق مايقدرش ينشر حاجة ومفيش حاجة على الشاشة تفسّر. وسائق موقوف وعربيته معتمَدة = ممكن يفضل يشيل ناس من أي مسار كود بيفحص العربية بس |
| 53 | **الأرقام مش بتترجّع للشخص اللي كتبها** | الرقم القومي والرخصة مخزّنين مشفّرين عشان محدش يحتاج يشيلهم واضحين. ترجيعهم يلغي ده كله مقابل حقل الشخص عارفه أصلاً. بيترجع بس **إن** الرقم موجود (`hasNationalId`) والتاريخ |

### حمايات آلية جديدة (`tests/Feature/ConventionsTest.php`)
بطلب المستخدم "خلي بالك من الأخطاء اللي حصلت قبل كده". الوعد بالانتباه اتكسر مرتين، فالأغلاط المتكررة بقت **تتلقط آليًا**:

| الفحص | المعيار |
|---|---|
| كل قيد قيم (`Rule::in`) لازم جنبه نوع معلَن | #39 |
| ممنوع مصطلحات داخلية في تعليقات `rules()` (بتتنشر للفريق الخارجي) | #38 |
| ممنوع `Illuminate`/`Laravel` في أي `Domains/*/Enums` | #12 |
| كل كود خطأ له status منطقي | #27 |

الفحص التاني لقط بقية فورًا في `RequestOtpRequest` — وكشف كمان إن `Str::between` بيستخدم `beforeLast`، فكان بيفحص الميثودز اللي بعدها كمان.

### باجات حقيقية اتكشفت
1. 🔴 **`firstOrNew(['user_id' => ...])` بيعمل mass-assignment للمفتاح** — و`user_id` مش في `#[Fillable]` **عن قصد** (مفتاح عمره مايجيش من طلب). كان بيرمي 500
2. 🟠 **lazy load في `$vehicle->driverProfile`** — `preventLazyLoading` بيرميه. الـ profile بقى بيتمرّر صراحة من الـ controller اللي عنده أصلاً
3. 🟠 **`assertApplicationIsEditable` كانت في كلاس غلط** — انتقلت لـ `DriverApplicationState` مع باقي الـ state machine

### أخطاء في الاختبارات (مش في الكود) — للتسجيل
- **الـ dataset في Pest بيتقيّم قبل ما الـ app يبوّت** — `config()` مش متاحة هناك، ولا جوّه closure. القيم بقت literals + تأكيد جوّه الاختبار إنها فعلاً برّه المدى المضبوط
- **`->or->` في Pest مش OR منطقي** — الطرفين بيتنفّذوا. الشرط بقى boolean واحد
- **`sole()` مش مقيّدة بالمستخدم** — اختبار بحسابين فيه صفّين من نفس النوع. الهيلبرز بقت تقيّد بـ `status = pending`
- **رقم الموبايل هو الهوية** — هيلبر بيستخدم الرقم الافتراضي بيرجّع **نفس** الحساب

### ⬜ مؤجّل من Phase 4
- **OCR** (§5/§6: مطابقة الرقم والاسم مع المستند) — عمود `ocr_payload` موجود ومشفّر، محتاج مزوّد OCR. الفحص البشري شغال بدونه
- **شاشة طابور المراجعة للأدمن** — `ReviewDriverApplicationAction` جاهز ومختبَر (10 اختبارات)، الواجهة Livewire في Phase 13
- **`expired_documents` تلقائيًا** — الحالة موجودة في الـ enum والـ state machine، محتاجة job يومي يفحص `licence_expiry` (Phase 15)
- **التأمين والفحص الفني** — الفصل نفسه بيعلّمهم "future"، بيتقبلوا دلوقتي بس مش مطلوبين
- **إشعار بقرار المراجعة** — `security_event` بيتكتب، الإشعار محتاج Phase 12

---

## Phase 5 — الأماكن والـ Corridors ونشر الرحلات ✅ (2026-09-23)

**المصدر:** `book/RAFEEQ_Chapter_04_Commute_Management_Story.md` (كامل) + الخطة §878 + Bible §3.9 و§Part 2 المشهد 3.

### 12 endpoint جديد (المجموع 38)

`GET/POST commutes` · `GET/PATCH/DELETE commutes/{id}` · `PUT commutes/{id}/route` · `PUT commutes/{id}/schedule` · `POST commutes/{id}/publish|pause|resume` · `GET places`

`PUT` مش `PATCH` للمسار والجدول: كل واحد فيهم **بيُستبدل بالكامل**، لأن نص مسار أو نص جدول يقدر يوصف رحلة مالهاش معنى.

### طبقة الجغرافيا (معيار #7 — أول تنفيذ فعلي)

كل سؤال جغرافي وراء `GeoQueryEngine` (توقيع Bible §3.9 حرفيًا). ممنوع أي `ST_*` أو استدعاء مزوّد برّه `app/Domains/Geo`.

| الملف | الغرض |
|---|---|
| `Contracts/GeoQueryEngine` | الباب الواحد |
| `Support/StraightLineGeoEngine` | خطوط مستقيمة + سرعة مفترضة، بدون مزوّد |
| `Support/CachingGeoEngine` | decorator على أي engine، بيكتب في `route_cache` |
| `Support/Haversine` · `Polyline` | المسافة، وترميز/فك polyline جوجل |
| `ValueObjects/Route` · `BoundingBox` | المسار والصندوق |
| `Shared/ValueObjects/Distance` · `WalkTime` | **نوعين مختلفين** عمدًا (فخ #19) |

### القرارات الحاسمة

| # | القرار | السبب |
|---|---|---|
| 54 | **`StraightLineGeoEngine` مابيرفضش يشتغل في production** | عكس `LogOtpSender` و`SignatureVirusScanner`. تقدير سفر تقريبي = منتج **أقل جودة**؛ رفع مستند غير مفحوص أو كود دخول مش واصل = منتج **مكسور**. ولو رفض، انقطاع المزوّد بيوقّف النشر كله، وده أسوأ من تقدير أخشن |
| 55 | **الصندوق بيتحسب من **كل** نقاط المسار + هامش** (فخ #18) | صندوق من الطرفين بس بيفوّت أي مسار بيتقوّس — وعرض بره صندوقه هو عرض **مش موجود** لراكبة واقفة جنبه، ومفيش مرحلة بعدين تقدر تصلّح ده. وكمان الهامش بيتقسم على `cos(lat)` للطول، لأن درجة الطول أقصر بعيد عن خط الاستواء (~14% عند القاهرة) |
| 56 | **المسافة والمدة عمرهم مختلف في الكاش** (فخ #20) | المسافة بين مكانين مابتتغيرش؛ المدة بتتغير. عمر واحد = إما دفع متكرر لرقم ثابت، أو تقديم مدة سفر من الشهر اللي فات |
| 57 | **إحداثيات الكاش بتتقرّب لـ 4 خانات (~11م)** | سائقتين بيحطّوا نفس بوابة الكومبوند مش هيطلّعوا نفس الـ float. كاش بمطابقة تامة = صفر hits |
| 58 | **المسار بيتحسب مرة واحدة، عند النشر** | المسودة بتتعدّل كتير، والسؤال للمزوّد على كل تعديل = فاتورة لرحلات محدش نشرها. فخ #16 الحقيقي: حلقة بتنادي المزوّد لكل مرشّح — بحث واحد ≈ 4 دولار |
| 59 | **التحقق من نقاط الالتقاء بالـ `max_detour_minutes` بتاع السائق نفسه** | رقم ثابت كان يرفض وقفات السائق موافق عليها، أو يقبل وقفات مش موافق عليها |
| 60 | **الإيقاف المؤقت مابيلمسش الأيام المولّدة** | الفصل 4: "paused commutes keep history". سائقة بتوقف أسبوع وترجع تلاقي مجموعتها سليمة |
| 61 | **الأرشفة بتلغي الأيام الفاضية بس** | يوم فيه ركّاب هو **التزام**. إلغاؤه بالسكوت بيسيب ناس مستنية توصيلة مش جاية — محتاج إلغاء مقصود بالتعويضات والإشعارات (مراحل لاحقة) |
| 62 | **المقاعد والسعر snapshot على كل يوم** | الفصل §6: "price changes affect only future scheduled trips". الراكبة اللي حجزت التلات بتفضل على شروط التلات |
| 63 | **رحلة المرة الواحدة بنفس شكل المتكررة** | يوم واحد في الـ mask، والبداية = النهاية. كده مفيش مستهلك واحد (بحث، حجز، توليد) محتاج حالة خاصة |

### أفق متدحرج + أخطر فخ في المشروع

التوليد **بأفق 30 يوم** مش لنهاية الجدول: 5000 عرض × ~110 يوم عمل = نص مليون صف يوم الإطلاق في أسرع جدول نموًا في النظام. أمر يومي (`commutes:generate-trips`، 3 صباحًا القاهرة) بيدحرج الأفق ويأرشف الجداول اللي خلصت.

**و`departure_at` بيتحسب لكل يوم على حدة** — مش مخزّن مقدّمًا. ده فخ #6: مصر بتطبّق التوقيت الصيفي بقانون من 2023. "07:05 القاهرة" وقت حيطة؛ تحويله لـ offset ثابت وقت الحفظ معناه إن كل رحلة بعد التحويل بتقوم بساعة غلط — وللراكب اليومي ده معناه يتأخر على الشغل. **اختباران** بيثبتوا نفس الساعة المحلية على جهتي التحويل (أبريل وأكتوبر) بـ offsets UTC مختلفة.

### باجات حقيقية اتكشفت
1. 🔴 **`sometimes` + `required_with` = ثقب في التحقق** — `sometimes` بيتخطّى مجموعة قواعد الحقل بالكامل لو الحقل **غايب**، فقاعدة `required_with` على النص الغايب من زوج عمرها ما بتشتغل. إرسال `lat` لوحده كان بيتقبل ويُتجاهل بالسكوت. الحل `nullable` بدل `sometimes` (معيار #41)
2. 🟠 **ترتيب غير محدَّد في قائمة الرحلات** — `latest()` على `created_at` (دقة ثانية) بيتعادل، ومفيش ترتيب معرّف. ده اللي بيخلّي القائمة المصفّحة تكرّر أو تفوّت صفوف. اتضاف tiebreak على الـ id
3. 🟠 **`seatsAvailable` خارج بدون نوع في العقد** — `max()` بيرجّع أوسع نوع في وسائطه. نفس درس `attemptsRemaining` في المرحلة 3، ولقطه نفس الاختبار

### أخطاء في الاختبارات — للتسجيل
- **الهيلبرز العامة في ملف اختبار مابتتحمّلش** لما تشغّل ملف تاني لوحده. المشتركة كلها في `tests/Pest.php`
- **الرقم القومي والرخصة واللوحة فريدين على مستوى المنصة** — أي اختبار بسائقين لازم يمرّر `seed` مختلف، وإلا التكرار بيترفض (والنظام صح والإعداد غلط)
- **اختبار انعطاف كان بيعدّي لسبب غلط** — النقطة اللي اخترتها كانت على الخط تقريبًا (أقل من دقيقة انعطاف)، فكانت هتعدّي أي حد. اتغيّرت لنقطة 10 كم شمال الخط (~7 دقايق)

### ⬜ مؤجّل من Phase 5
- **مزوّد مسارات حقيقي** (Google Directions أو غيره) — الـ seam جاهز بالكامل، والتبديل كلاس واحد + سطر config
- **`duration_in_traffic_seconds`** — العمود موجود بـ TTL ساعة موثّق، بس محتاج مزوّد بيرجّعه
- **إحصاءات الـ corridors** (`corridor_stats`، حالات الصحة) — الربط شغال، الحساب محتاج بيانات طلب حقيقية من Phase 6
- **`user_places` CRUD** (بيت/شغل محفوظين) — الجدول موجود من Phase 1، الشاشة محتاجة Phase 6
- **إنشاء الأماكن من الأدمن** — الكتالوج منسّق مش مفتوح للإضافة، والشاشة في Phase 13
- **إشعار الركّاب عند تغيير الميعاد** — الفصل بيطلبه، محتاج Phase 12
- **إيقاف الرحلات تلقائيًا عند انتهاء الرخصة** — `pauseUnpublishableOffers` جاهز ومختبَر، محتاج ينضم للأمر اليومي في Phase 15

---

## Phase 6 — محرك البحث والمطابقة ★ ✅ (2026-09-25)

**المصدر:** `book/RAFEEQ_Chapter_05_Search_and_Matching.md` + Bible §7.2 (استراتيجية البحث) و§7.3 (الكاش) والمشهد 4 (سكور نور 96/100).

### 8 endpoints جديدة (المجموع 46)

`GET search/commutes` · `GET/POST commute-demands` · `DELETE commute-demands/{id}` · `GET matches` · `GET/POST saved-searches` · `DELETE saved-searches/{id}`

### خط الأنابيب ذو الأربع مراحل

| المرحلة | من → إلى | الأداة |
|---|---|---|
| 1 — فلترة صارمة | ~50,000 → ~800 | SQL على أعمدة مفهرسة، **صفر جغرافيا** |
| 2 — مسافة المشي | ~800 → ~40 | حساب في PHP على صفوف في الذاكرة |
| 3 — الانعطاف | ~40 صف | المسار المخزّن وقت النشر، مفيش استدعاء جديد |
| 4 — التنقيط والترتيب | ~40 صف | نموذج الـ 100 نقطة |
| 5 — الكاش | ساعة | `match_scores` |

**الترتيب هو التصميم.** فخ #16 هو حلقة بتسأل المزوّد عن كل مرشّح — بحث واحد ≈ 4 دولار، فخمس بحثات لشخص واحد = 20 دولار. المرحلة 1 هي اللي بتخلّي المرحلة 3 ممكنة اقتصاديًا، وهي **مفيهاش جغرافيا خالص**.

### 🔴 أهم ضمانة في المشروع

فخ #15: قيد صارم يتحوّل لخصم نقاط. `$score = women_only && !woman ? 6 : 10` معناه **راجل بيظهر في نتائج مجموعة نساء فقط** — خرق أمان مش ترتيب.

الاستبعاد في **الاستعلام نفسه** (`HardFilters`)، قبل أي تنقيط. فمفيش تعديل في المعادلة بعدين يقدر يرجّعه، ومفيش caller جديد يقدر ينساه. و`gender` بيتقرا من **الحساب** مش من الطلب — محاولة إرسال `audiencePreference: women_only` مابتغيّرش الأهلية.

**اختبارات الاستبعاد (مش الترتيب الأقل):** الراجل · اللي اختار "أفضّل ما أقول" · محاولة التحايل بالطلب · الحظر في الاتجاهين (فخ #27) · سائق موقوف · رخصة منتهية · مقاعد خلصت · اليوم مش مطابق · برّه الصندوق · مشي أطول من المسموح · trust level أقل · موعد الحجز فات.

### نموذج الـ 100 نقطة

| المكوّن | الوزن | يكافئ |
|---|---:|---|
| التقاطع | 30 | كم من رحلة الراكبة مشترك |
| الجدول | 25 | قرب الميعاد من اللي طلبته |
| الانعطاف | 15 | قِلّة ما السائقة تلفه |
| الجمهور | 10 | **تفضيل** مش أهلية |
| الراحة | 10 | القواعد اللي طلبتها |
| السعر | 5 | ضمن الميزانية |
| الموثوقية | 5 | سجل الالتزام |

الأوزان دي **بتقول رفيق إيه**: التقاطع والجدول مع بعض أكتر من النص، لأن رحلة مش رايحة في طريقها في الوقت اللي محتاجاه مش مطابقة مهما كانت مريحة. والسعر 5 بس، لأن ده **مشاركة تكلفة مش سوق**.

### القرارات الحاسمة

| # | القرار | السبب |
|---|---|---|
| 64 | **الاستبعاد في الاستعلام، التفضيل في التنقيط** | الفرق بين الاتنين هو الفرق بين خرق أمان وترتيب أقل |
| 65 | **سائق بدون تاريخ = درجة كاملة في الموثوقية** | البدء من الصفر معناه إنه عمره ما يتطابق، فعمره ما يبني التاريخ اللي هيخليه يتطابق. الثقة **تُكتسب بدليل الفشل**، مش تُحجب لغياب دليل النجاح |
| 66 | **كارت واحد لكل رحلة، مش لكل يوم** | بحث متكرر على شهر كان هيرجّع نفس الرحلة 20 مرة ويزقّ كل السائقين التانيين برّه أول شاشة |
| 67 | **المسار المنشور بيُعاد بناؤه من الـ polyline المخزّن** | الطريق ماتغيّرش، والهدف من حسابه مرة واحدة عند النشر هو ما ندفعش فيه تاني |
| 68 | **الـ signature فيه الـ user id** | اتنين بنفس المعايير بيشوفوا نتائج مختلفة لو واحد محظور من سائقة والتاني لأ — مشاركة صف كاش بينهم بتسرّب ده |
| 69 | **الإحداثيات بتتقرّب لـ 3 خانات (~110م) قبل الـ hash** | تحريك الدبوس متر واحد نفس السؤال؛ كاش بمطابقة تامة = صفر hits |
| 70 | **الإشعار من العرض ناحية الطلبات، مش العكس** | السائقة عمرها ما تشوف مين كان مستني — بس إن حد انضم |
| 71 | **الإشعار بيعيد تشغيل محرك البحث الحقيقي** | مقارنة "قريبة كفاية" أرخص كانت هتبلّغ ناس عن رحلات البحث نفسه مش هيوريهالهم. جوابين لنفس السؤال = جواب زيادة |
| 72 | **الـ job بياخد id مش موديل** | موديل مُسلسَل ممكن يوصف عرض اتوقّف في الثواني اللي بين الإرسال والتنفيذ |
| 73 | **البحث محتاج بروفايل كامل بس مش توثيق** | البحث هو إزاي حد يقرر إن رفيق يستاهل التوثيق. تقييده بيخلّي المنتج **غير قابل للتقييم** |

### باجات حقيقية اتكشفت
1. 🔴 **N+1 حقيقي لقطه `preventLazyLoading`** — الـ Resource بيقرا `driverProfile->user` (الاسم العام ومستوى الثقة) وأنا ما حمّلتهوش. صفحة نتائج كانت هتعمل استعلام لكل سائق
2. 🟠 **`filters` خارج بدون نوع في العقد** — العمود JSON فالمولّد مايعرفش يوصف عناصره. اتوصف صريحًا، وده كمان بيوثّق إن البحث المحفوظ بيشيل بالظبط اللي البحث بيقبله

### أخطاء في الاختبارات — للتسجيل
- **الاسم العربي بيطلع JSON-escaped** (`م...`) — تأكيد `toContain` على النص الخام بيفشل لسبب ترميز مش لسبب حقيقي. التأكيد الإيجابي على الـ payload المفكوك، والسلبي على النص الخام (أسماء المفاتيح ASCII)
- **اختبار الجدول كان premise غلط** — طلبت نافذة بعد الميعاد بـ55 دقيقة وتوقعت نقاط > 0، والتلاشي بيوصل صفر عند 30 دقيقة

### ⬜ مؤجّل من Phase 6
- **إحصاءات الـ corridors** (`corridor_stats`، حالات الصحة) — فيه بيانات طلب حقيقية دلوقتي، الحساب محتاج Phase 14
- **`delivered_at` / `clicked_at`** — الأعمدة موجودة، التسليم الفعلي محتاج Phase 12
- **job يومي لإنهاء الطلبات المنتهية** — `expireOverdue()` جاهز ومختبَر، محتاج ينضم للجدولة
- **`wants_return_trip`** (رجلة العودة) — العمود موجود من Phase 1، الواجهة محتاجة قرار منتج
- **الترتيب بالصفحات (pagination)** — النتائج محدودة بـ20، والصفحات محتاجة قرار UX

---

## Phase 7 — طلبات المقاعد والحجوزات والمجموعات ✅ (2026-09-25)

**المصدر:** الفصل 6 (`RAFEEQ_Chapter_06_Booking_and_Confirmation.md`) + الخطة §889 + مشهد 10 في الـ Bible (مريم بتنضم للمجموعة دايم).

### 27 endpoint جديد (المجموع 73)

| المجموعة | Endpoints |
|---|---|
| طلب المقعد | `POST commutes/{id}/seat-requests` · `GET seat-requests` · `DELETE seat-requests/{id}` |
| الحجوزات | `GET my-bookings` · `GET bookings/{id}` · `PATCH bookings/{id}/cancel` |
| ناحية السائق | `GET driver/seat-requests` · `POST .../{id}/approve` · `POST .../{id}/reject` · `GET driver/bookings` |
| نقطة الالتقاء المخصصة | `POST seat-requests/{id}/pickup-request` · `POST groups/{id}/pickup-request` · `GET pickup-requests` · `POST pickup-requests/{id}/accept-alternative` · `GET driver/pickup-requests` · `POST driver/pickup-requests/{id}/approve` · `.../suggest-alternative` · `.../reject` |
| المجموعة | `GET groups` · `GET groups/{id}` · `GET groups/{id}/members` · `POST groups/{id}/leave` |
| الحضور والغياب | `GET/POST groups/{id}/attendance` · `GET/POST groups/{id}/absences` · `DELETE groups/{id}/absences/{id}` |

### 🔴 أخطر اختبار في المشروع — اتقفل أخيرًا

كان مؤجّلًا من Phase 1. `ApproveSeatRequestAction` بيقفل صف الرحلة بـ `lockForUpdate()` **قبل** ما يقراه، وكل الفحوص جوّه القفل، والزيادة `increment()` بجملة SQL واحدة.

الاختبارات في `SeatConcurrencyTest` (13 اختبار) بتثبّت الضمانة من **تلات جهات**، وكل واحدة صريحة بإنها بتغطي أنهي طبقة:

1. **تسلسليًا:** 10 موافقات على 3 مقاعد → 3 بالظبط، والرفض جاي من الفحص **جوّه القفل**
2. **الداتابيز نفسها:** `CHECK (seats_taken <= seats_total)` بترفض `seats_taken = 4` مباشرة، وبترفض `increment` في SQL خام فوق السعة
3. **إن القفل فعلاً مأخوذ:** `DB::listen` بيتأكد إن الـ SQL المُنفَّذ فيه `for update` على `scheduled_trips` وإن الزيادة `seats_taken = seats_taken + …`

**اللي الاختبارات دي مش بتقدر تثبته، ومكتوب بصراحة في رأس الملف:** 10 اتصالات متوازية حقيقية مستحيلة تحت `RefreshDatabase` (كل اختبار جوّه transaction ما بتتعملهاش commit، فاتصال تاني مش شايف البيانات أصلاً وهيستنى على القفل لحد timeout). الاختبار الحقيقي بتوازي فعلي مكانه بيئة تكامل بداتابيز مكتوبة — مسجّل تحت في المؤجّل، مش متظاهر بيه.

### القرارات الحاسمة

| القرار | السبب |
|---|---|
| **الموافقة = عملية واحدة للتجربة والالتزام** | المشهد 10 صريح: الموافقة على `recurring` بتولّد حجوزات لكل الرحلات الجاية وبتحوّل `trial` → `member`. التجربة طلبت يوم، الملتزم طلب نمط أيام — نفس الحلقة. الرد شكل واحد (`SeatApprovalResource`) بدل endpoint لكل نوع |
| **يوم مليان في الالتزام = يتخطى ويتبلّغ، مش يفشل الكل** | رفض عضوية شهر كامل بسبب أربعاء واحد مليان بيخلّي الالتزام المتكرر شبه مستحيل. الأيام المتخطية بترجع في `skippedDays` بالسبب — والسائقة تشوفها فورًا مش بعد أسبوعين |
| **لو ولا يوم واحد ينفع → ترجع 409 والـ transaction بتترجع** | بديلها عضوية في مجموعة من غير أي رحلة، والطلب بيتقفل على الفاضي. كده الطلب يفضل مفتوح لما مقعد يفضى |
| **القفل على الرحلات بترتيب `trip_date` واحد** | موافقتين متكررتين على نفس الرحلة ممكن يمسكوا نفس القفلين بترتيبين معاكسين = deadlock. الترتيب الثابت هو كل الدفاع |
| **ترقية قائمة الانتظار = `waitlisted → pending`، مش حجز** | في رفيق السائق هي اللي بتقرر مين يركب معاها. حجز تلقائي لمجرد إن حد تاني لغى معناه حط غريب في عربيتها من غير ما تقول أبدًا "أيوه" |
| **ترقية واحدة لكل مقعد فضي** | ترقية الصف كله بتحوّل إلغاء واحد لخمس أشخاص متأملين على مقعد واحد، أربعة منهم بيعرفوا بالرفض |
| **`added_minutes`/`added_km` بتتحسب عندنا** | هي نفسها اللي `max_detour_minutes` بتتقاس عليها. راكب يقدر يبعتها = راكب بيقرر السائقة هتلف قد إيه عن طريقها. اتشالوا من `#[Fillable]` كمان (باج حقيقي، تحت) |
| **القياس مقابل المسار **كما هو منشور** (بنقاط الالتقاء الموجودة)** | مقارنة بخط بداية→نهاية مجرّد أسوأ من مفيدة: رحلة بتلف أصلاً لراكبين موجودين هتخلّي الوقفة التالتة تبان إنها **بتوفّر** وقت |
| **اقتراح بديل = عرض مقابل، مش قرار** | تطبيقه فورًا معناه نقل صبح حد من غير سؤاله — عكس الحاجة اللي الـ flow كله موجود يمنعها. وضفنا `accept-alternative` للراكب، لأن اقتراح محدش يقدر يقبله طريق مسدود |
| **الغياب: `releases_seat` هو البند الوحيد اللي له أثر** | مضبوط = الحجوزات بتتلغي فعلاً والمقعد يتعرض على قائمة الانتظار. مش مضبوط = المقعد يفضل للعضو والعربية تمشي وهو فاضي. والافتراضي `false` لأن تحرير مقعد بيديه لحد تاني وممكن ياخده |
| **إلغاء الغياب مابيرجّعش الحجوزات** | المقاعد اترجعت وممكن بقت لحد تاني. إلغاء الغياب بيرجّع خطط العضو، مش خطط الناس التانية |
| **المغادرة = إخطار، والمقاعد بعد المهلة تتحرر فورًا** | المقاعد جوّه المهلة تفضل للعضو (هو لسه بيسافرها)، واللي بعدها ترجع للعرض وهي لسه فيها وقت تتملي |
| **السائق مايسيبش مجموعته** | المجموعة موجودة لأن رحلته موجودة. "الكل بيسافر إلا اللي بيسوق" مش حالة تتمثّل. الإيقاف/الأرشفة هي العملية المقصودة |
| **`commute_groups.seats_open` مش بيتقرا** | عدّاد مفكوك من غير كاتب واحد بيعطب أول ما واحد ينسى يحدّثه، و"كام مقعد فاضي" سؤال **لكل يوم** مش عمود واحد. الـ API بيحسبه من أقرب رحلة جاية (استعلام واحد مفهرس) |

### تعارضات مع المصادر — موضّحة مش مسكوت عنها

1. **`effective_from = specific_date`** — الـ ERD بيذكرها كقيمة لـ `pickup_point_requests.effective_from`، بس **مفيش عمود يحمل أي تاريخ**. الاختيارات كانت: نخزّن تاريخ في عمود موثّق إنه كلمة مفتاحية، أو نقبل قيمة بتعمل نفس اللي `next_trip` بتعمله. الاتنين غير صادقين. **منفَّذ `next_trip` بس**، والقرار السكيمي مسجّل في `DEPLOYMENT.md`.
2. **`SeatRequestStatus::Ended` (جديدة، مش في الـ ERD)** — لازمة، والسبب باج حقيقي تحت (#1). الـ ERD بيسرد 6 حالات ومفيش واحدة منهم بتقول "الموافقة عاشت أطول من العضوية اللي عملتها". `expired` كذب (حد **رد** فعلًا) وهي اللي جرد الطلبات المنتهية بيدور عليها؛ `withdrawn` بتقرا إنه سحب قبل الرد. العمود `varchar(20)` من غير CHECK، والعمود المولّد بيشوف `('pending','approved')` بس — فالإضافة **كود بس، من غير migration**، ووجودها بره القائمة دي هو بالظبط اللي بيفضّي المكان.
3. **قيد ERD §20 #7** ("حجز واحد للراكب في الرحلة") — نيّته محفوظة، بس **حجز ملغي مش حجز**. تفاصيل في الباج #2.

### باجات حقيقية اتكشفت

1. 🔴 **اللي ساب مجموعة عمره ما كان يقدر يرجع.** القاعدة "طلب مفتوح واحد لكل راكب لكل رحلة" بتحسب `approved` كمفتوح — صح وهو راكب — والقاعدة منفَّذة بعمود مولّد فريد مش بكود بس. فالموافقة المستهلكة بتفضل ماسكة المكان للأبد: 409 على كل محاولة رجوع، محدش (لا هو ولا السائقة) يقدر يفكّها. الحل: إكمال مهلة المغادرة بيقفل الطلب بـ `Ended`.
2. 🔴 **راكب لغى حجزه في يوم عمره ما كان يقدر يتحجّز فيه تاني — و500 مش رفض.** `assertNoDuplicateBooking()` بتسمح بده صراحة ("اللي لغى وغيّر رأيه المفروض يقدر يحجز")، بس الـ unique index كان على `(scheduled_trip_id, passenger_user_id)` **من غير أي اعتبار للحالة**. فالكود والسكيمة بيتعارضوا، والسكيمة بتكسب بأبشع طريقة متاحة: `PDOException` → 500. الحل: migration جديدة بتضيّق القيد على الحجوزات القائمة بس (`live_booking_key` مولّد، نفس تقنية `users.phone_e164_active`). **الطريق اللي بيوصل له:** حد يسيب مجموعة ويرجع — يعني الباج الأول هو اللي كشف التاني.
3. 🟠 **`added_minutes`/`added_km` كانوا في `#[Fillable]`** رغم إن الـ migration نفسها مكتوب فيها "computed by us, never claimed by the requester". أي مسار بيمرّر input لـ `fill()` كان بيخلّي راكب يعلن انعطاف 0.1 دقيقة ويعدّي فحص `max_detour_minutes`. اتشالوا هم و`alternative_place_id` (ده رد السائق، مش الطلب).
4. 🟠 **مكان في قائمة الانتظار بيتكرر.** `nextWaitlistPosition` كانت `count + 1` — مع تلاتة في 1، 2، 3، أول ما رقم 2 يسحب الـ count يبقى 2 والجاي ياخد 3، نفس المكان اللي حد واقف فيه. بقت `max(position) + 1`، والـ count بيقرر لو الصف مليان بس (ده فعلاً سؤال عن عدد المستنيين).
5. 🟠 **فراغ في ترقيم الصف.** حد يسيب وسط الصف كان يخلّي اللي وراه يقرا مكان أبعد من مكانه الحقيقي ("تالت" وهو تاني). `renumber()` بقت تتنادى من الترقية والسحب والرفض.
6. 🟠 **N+1 لقطه `preventLazyLoading`** — `GroupMemberResource` بيقرا `notice_period_days` من المجموعة عشان يقول امتى آخر يوم لعضو مغادر، وأنا ما حمّلتهاش في `members()`. استعلام لكل عضو.

### الشغل الخفي الجديد — `memberships:roll-forward`

أمر يومي 3:30 ص القاهرة (بعد `commutes:generate-trips` عن قصد). تلات حاجات بالترتيب ده:

1. الطلبات اللي محدش ردّ عليها بتنتهي
2. مهل المغادرة اللي خلصت بتتقفل (+ الطلب بياخد `Ended`)
3. الأعضاء الملتزمين بيتحجزلهم على الأيام اللي المولّد عملها بعد ما انضموا

**البند التالت هو نفس الفشل الصامت بتاع `commutes:generate-trips`، طبقة أعلى:** الموافقة بتحجز على الـ 30 يوم الموجودين **دلوقتي**. بعد أسبوعين المولّد عمل 14 يوم جديد ومحدش محجوز عليهم. العضوة فاكرة إنها ملتزمة، شاشة المجموعة بتقول كده، وفي صباح بعد خمس أسابيع **العربية مابتوقفش لها**. الترتيب (2 قبل 3) مختبَر في `BackgroundWorkTest`، ومراقبته بـ SQL في `DEPLOYMENT.md`.

### ملاحظة عقد للفريق الخارجي

`json_encode` في PHP بتكتب الـ float `0.0` كـ `0`. يعني أي حقل `number` قيمته عدد صحيح بيوصل من غير علامة عشرية (`onTimePct`, `addedMinutes`, `addedKm`, والإحداثيات). في Dart لازم `num` بعدين `.toDouble()` — القراءة المباشرة كـ `double` بترمي exception على القيمة دي بالظبط. اتوثّق في وصف الـ OpenAPI (قسم Numbers) بعد ما اختبار وقع عليها.

### أخطاء في الاختبارات — للتسجيل

- **الـ helper كان بيغيّر جنس الراكب** — `submitGovernmentId()` كانت بتنادي `completeBasicProfile()` من غير شرط، واللي بتحدّد `gender => woman`. فاختبار الأمان "راجل يطلب مقعد في رحلة نساء فقط" كان بيحوّل الراجل لست قبل الطلب، فالاختبار كان بيعدّي وهو مش بيختبر حاجة. اتصلح بفحص `profileStatus` الأول. **ده بالظبط نوع الفشل اللي الـ suite موجود يمنعه.**
- **السفر في الزمن بيموّت التوكن** — `travel(47)->hours()` خلّى اختبار عن نافذة الطلب يرجع 401: كان بيأكّد على الجلسة مش على الصف. الصح: نخلّي الطلب قديم في الداتابيز. ونفس الحاجة مع سفر 8 أيام — **توكن السائق** كمان بينتهي، مش الراكب بس.
- **`assertJsonPath('...', 0.0)` بيفشل** — الـ JSON بيرجّع `0`. مقارنة رقمية `(float)` بدل تأكيد متطابق (وطلعت ملاحظة عقد حقيقية، فوق).
- **الاسم العام للراكب `سارة` مش `مريم`** — `passenger()` و`completeBasicProfile()` بيستخدموا اسمين مختلفين. توقّع غلط مني، مش باج.

### ⬜ مؤجّل من Phase 7
- **اختبار توازي حقيقي** (10 اتصالات متوازية على آخر مقعد) — مستحيل تحت `RefreshDatabase`؛ مكانه بيئة تكامل بداتابيز مكتوبة
- **رسوم الإلغاء** — سؤال مفتوح 7، مؤجّل لـ Phase 8. الرسم = صفر دايمًا، وده **قرار واعي** مش نسيان
- **إزالة عضو بواسطة السائق** — الأعمدة موجودة، مش في نطاق §889، والحظر في Phase 11 (الأمان)
- **`effective_from = specific_date`** — محتاج قرار سكيمة (فوق)
- **`on_time_pct` / `rides_together_count`** — بيتحسبوا من رحلات مكتملة، والحساب في Phase 9
- **الإشعارات** — الترقية والموافقة والرفض كلهم لازمهم إشعار. مفيش حاجة بتتبعت من جوّه transaction (فخ #3)، ومختبَر إنها مش بتتبعت. Phase 12
- **`group_attendance` من غير رد** — `no_response` حالة موجودة، والجرد اللي يحوّل اللي ما ردّوش محتاج قرار منتج

---

## سجل التقدّم (الأحدث فوق)

- **2026-10-02** — **Phase 10 — الشريحة التانية: المراجعات العامة والإبلاغ وفلتر `minRating`.** 3 endpoint + فلتر صارم + 35 اختبار.

  **القرار الكبير هنا، والمصادر ساكتة عنه تمامًا: المراجعة مفيهاش اسم اللي كتبها ولا تاريخ بالظبط — الشهر بس.**

  الرحلة فيها من واحد لتلاتة. يعني مراجعة بتاريخ بيوم **بتحدّد الرحلة**، والرحلة **بتحدّد الشخص** — فالتاريخ بيسمّي اللي كتب المراجعة حتى لو الرد مافيهوش اسم. وده أخطر هنا من أي سوق عادي لسبب واحد محدد: **السائقة معاها نقطة التقاء الراكبة، يعني عارفة باب بيتها**. راكبة بتكتب بصراحة عن سائقة تقدر تلاقي بيتها، وتقدر تتعرف عليها من التاريخ، معرّضة بشكل مفيش مشتري مجهول على الإنترنت معرّض له — **والنتيجة مش مراجعات أعدل، دي مراجعات أهدى**. والقارئ مش بيخسر حاجة تقريبًا: "الشهر" هو اللي البروفايل بيوصّله فعلًا.
  **ومجهولة لصاحب البروفايل كمان**، وده مقصود مش سهو: إنك تعرف مين دّاك نجمتين **هي** وسيلة الانتقام، مش مجاملة. ومفيش `bookingId` في الرد كمان، لأنه كان بيخلّي صاحب البروفايل يفتح الرحلة ويقرا اسم اللي كتب من قايمة ركابها.

  **قرارات تانية:**
  - **الـ endpoint مربوطة بالـ COMMUTE مش بـ user id.** ومفيش payload واحد في الـ API بيرجّع معرّف مستخدم (`PersonSummary` فيه اسم أول عام ومفيش حاجة قابلة للحل) — فـ`/users/{id}/reviews` كانت هتضطرنا نبدأ نوزّع ids، يعني **نقض قرار من Phase 6 عشان شاشة واحدة**. الكلاينت معاه الـ commute id من البحث أصلًا.
  - **الإبلاغ بيعلّم ومابيشيلش.** `flagged` صف في طابور مش حكم: المراجعة تفضل على البروفايل **وفي المتوسط** لحد ما موظف يقرر. العكس كان هيخلّي "أبلغ عن كل مراجعة تحت ٤ نجوم" طريقة ميكانيكية لتبييض السجل — واللي أكتر الناس حمسًا لكده هم بالظبط اللي نظام التقييم موجود عشان يبيّنهم. `hidden` (قرار موظف) هو الوحيد اللي بيشيل.
  - **الإبلاغ للطرف اللي المراجعة عنه بس** — مش اللي كتبها (هو كتبها) ومش غريب (ده تسخير طابور بشري ضد حد)، و**مش على مراجعة لسه مخفية** (حد ماشافهاش، فإبلاغ عنها معناه إنه اتبلّغ بيها من حد). التلاتة **404** عشان مايتفرّقوش عن id غلط.
  - **`minRating` فلتر صارم** (قاعدة الـ Bible: "hard conflicts never receive a soft score") — بس **سائقة محدش قيّمها بتفضل ظاهرة**. `null` معناها "مفيش تقييم" مش صفر، وإخفاء السائقات الجديدات = بداية باردة بتجوّع المنصة من العرض + جواب غير حقيقي، لأن محدش قال عنها حاجة وحشة.

  **وباج في أول كتابة للفلتر، اتكشف وأنا بكتبه:** استخدمت `whereHas` على صف `user_stats` — وده **بيستبعد السائقة اللي مالهاش صف أصلًا**، وهي كل سائقة ماكملتش رحلة (الصف بيتكتب لما رحلة تكمل). يعني الفلتر كان هيعمل بالظبط العكس من اللي كاتبه عشانه. اتعاد كتابته بصيغة النفي — **"مفيش دليل إنها تحت الحد بتاعك"** — وفيه اختبارين على الحالتين (صف برقم `null`، ومفيش صف خالص).

  **وفخ تكرر للمرة التالتة:** `UserStat` مالهاش `$fillable` بقرار (بيتكتب بـ jobs بس)، فـ`updateOrCreate` بترمي "Add [user_id] to fillable". ده وقع في تجميعة الصفحة الرئيسية، وفي `CompleteTripAction`، ودلوقتي في اختبار. **اتعمل helper واحد** (`setUserStat`) في `Pest.php` بيكتب بالـ query builder والسبب مكتوب فيه، فالفخ ماينفعش يتكرر في اختبار تاني.

  **الناقص من Phase 10:** **درجة الثقة** بس — ومحتاجة قرارك. المصادر بتحدّد الأعمدة بس (`score` 0–100 **داخلي ومابيتعرضش للمستخدم**، `public_tier` = `new`/`trusted`/`highly_trusted`، و`components` json) و**مفيش معادلة ولا أوزان ولا حدود مستويات في أي مستند من التلاتة**.

- **2026-10-02** — **Phase 10 (التقييمات) — الشريحة الأولى: التقييم المزدوج الأعمى.** 4 endpoint + أمر مجدول + 28 اختبار.

  فخ #26 بيسمّي الفشل بالنص: "لو الـ API رجّع التقييم قبل ما الطرفين يقيّموا، الحماية اتكسرت حتى لو الشاشة مبتعرضهوش". فمعظم الشغل هنا عن **اللي الـ API بيرفض يقوله**، وتلات مسارات كانت هتهزم التصميم من غير ما تقرا ولا صف مخفي واحد — كل واحد منهم اتقفل بقرار واضح:

  1. **نافذة التقييم بتتقفل مع لحظة الكشف.** رقم واحد مش اتنين. الـ Bible بيقول التقييم يبان لما الاتنين يقيّموا "**أو** تعدّي ٧ أيام" — ولو التسليم عاش بعد النافذة دي، حد يقدر يستنى اليوم السابع، يقرا اللي هي كتبته عنه، وبعدين يردّ عليه. ده double-blind بيُهزَم **من الباب الأمامي** مش بيتكسر.
  2. **التعديل بيترفض أول ما التقييم يبقى مرئي**، مهما قالت ساعة التعديل. نافذة التعديل موجودة عشان تصلّح خطأ كتابة في الدقيقة اللي بعد الكتابة وخلاص. من غير القاعدة دي، "قيّم ٥ نجوم → استنى الكشف → اقرا بتاعها → عدّل لـ١" بيبقى **نداءين API عاديين تمامًا**.
  3. **🔴 المتوسط — وده أخفى واحد، والسبب اللي خلّى الحساب يشتغل على الكشف مش على التسليم.** تصفية استعلام التقييمات هي النص الواضح. النص اللي بيتسرّب هو **الحساب**: سائقة قاعدة على ٥.٠٠ من ٤ رحلات وبتشوف ٤.٦٠ تظهر، عرفت **بالنجمة** اللي الراكبة قالته عنها — وعرفته قبل ما تكتب بتاعها. تصفية الاستعلام مالهاش قيمة لو المتوسط بيسرّب من حواليها.

  **قرارات تانية:**
  - **`direction` و`reviewed_user_id` بيتقروا من الحجز، عمرهم ما بيتقبلوا من الطلب** — نفس قاعدة `reported_user_id` في البلاغ ولنفس السبب: حقل بيسمّي اللي التقييم عنه هو طريقة تحطّ نجوم (أو تعليق) على حد غريب.
  - **`visible_at` له كاتب واحد** (`RevealRatingsAction`) بنفس منطق `SafetyEventLog`: العمود ده **هو** الحماية، ومكان تاني بيملاه هو مكان تاني يقدر يملاه بدري. والكشف **في أزواج وبنفس الـ timestamp** — كشف واحد قبل التاني ولو بطول request بيسيب نافذة حد يقرا فيها بتاع التاني وبتاعه لسه مخفي.
  - **أمر مجدول كل ساعة** (`ratings:reveal-due`) هو النص اللي بيخلّي الفيتشر يشتغل أصلًا: لو الكشف محتاج الطرفين، راكبة مابتقيّمش عمرها بتخلّي تقييم سائقتها مخفي للأبد — و**أهدى طريقة تمنع مراجعة سيئة تبقى إنك ماتكتبش واحدة**. كل ساعة مش كل يوم، لأن تمرير يومي بيخلّي الكشف يتأخر لحد ٢٤ ساعة — مدة كفاية إن حد بيراقب **توقيت** الظهور يستنتج منه، وده نسخة أضعف من نفس التسريب.
  - **البلاغ المقفول/الرحلة الملغية مش قابلين للتقييم** — "احجز وبعدين ألغي" ماينفعش يبقى طريقة تحطّ نجوم على حد ماركبتش معاه.
  - **النافذة بتتقاس من يوم الرحلة مش من صف الحجز.** الحجز بيتعمل لما المقعد يتوافق عليه، وده ينفع يبقى شهر قبل الرحلة — القياس من هناك كان بيقفل النافذة قبل الرحلة ما تحصل.

  **الجانب المجاني:** `PersonSummary` بيقرا `avg_rating_as_driver` / `avg_rating_as_passenger` من Phase 6 وبيرجّع `null` لعدم وجود تقييمات. البحث وتفاصيل المطابقة وأعضاء المجموعة ومراجعة طلبات السائقة **كلهم بقوا بيحملوا أرقام** من غير أي تغيير في الكود بتاعهم.

  **ولاقيت نقص في `DEPLOYMENT.md`:** جدول الأوامر المجدولة كان فيه **٢ من ٤** بس — `trips:flush-locations` و`trips:purge-locations` ماكانوش مذكورين، والتاني هو اللي بيحقّق وعد الاحتفاظ بـ٩٠ يوم. الجدول بقى كامل بالخمسة وكل واحد مكتوب جنبه "لو ما اشتغلش إيه بيحصل" — جدول ناقص في ملف تشغيل هو بالظبط نوع الحاجة اللي بتتنسى.

  **الناقص من Phase 10 (الشريحة التانية):** قايمة مراجعات الآخرين على البروفايل · الإبلاغ عن مراجعة + الـ moderation · **درجة الثقة** — ودي محتاجة قرارك: المصادر بتحدّد الأعمدة بس (`score` 0–100 **داخلي ومابيتعرضش**، `public_tier` = `new`/`trusted`/`highly_trusted`، و`components` json) و**مفيش معادلة ولا أوزان ولا حدود للمستويات في أي مستند من التلاتة**. هحسب `components` من حقايق موجودة فعلًا وأحطّ الأوزان والحدود في `platform_settings` بقيم افتراضية موثّقة (معيار #11) وأرفعها لك كقرار — مش كأنها مواصفة.

- **2026-10-02** — **✅ Phase 11 (الأمان) خلصت — والحاجتين الفاضلين مش بتاعتها.**

  اللي اتعمل في المرحلة: SOS والتنبيه الصامت · جهات الطوارئ · البلاغات · الحظر باتجاهين · **مشاركة الرحلة المباشرة** · **رفع الأدلة**. اللي كان مكتوب إنه فاضل، وطلع بعد قراية المصادر إنه **مش Phase 11**:

  | البند | كان مكتوب | الصح | ليه |
  |---|---|---|---|
  | Night escort mode | Phase 11 | **Phase 13** | تحكّم أدمن على كوريدور (`armed_by` → `admin_users`)، مراقبة من فريق العمليات، زرار في nav الداشبورد. مفيش endpoint جاي (D4) |
  | `auto_share_trips` | ضمنيًا "شغّال" | **Phase 12** | الرابط لازم يتسلّم لحظة إنشائه (التوكن بيرجع مرة واحدة)، فمحتاج قناة تسليم |
  | رقم القضية (شاشة 35) | Phase 11 | **Phase 13** | **مفيش حاجة في المنصة بتوقف حساب أصلًا** — دوّرت: `account_status` مابيتحوّل لـ`suspended` إلا في الاختبارات بالقوة. الإيقاف فعل أدمن، وهو نفسه المكان اللي رقم القضية ومهلة الـ24 ساعة بيتكتبوا فيه |

  **تلات بنود كانوا مكتوبين في المرحلة الغلط، وكلهم في نفس الاتجاه** (حاجة بتحتاج الأدمن أو الإشعارات مكتوبة على الأمان). ده نفس الانحراف اللي اتصلّح في أرقام المراحل 2026-09-26، بس على مستوى البنود مش الأرقام — وكل واحد منهم **لو اتعمل دلوقتي كان هيكتب كود محدش بيقراه**.

- **2026-10-02** — **🔒 قنبلة فك ضغط (decompression bomb) في مسار الصور — ثغرة حقيقية في كود كان موجود، اتكشفت بالغلط.**

  اختبارات الأدلة الجديدة نجحت لوحدها وفشلت مع الـ suite كله بـ `Allowed memory size exhausted` جوّه `ImageSanitiser`. الرسالة بتشاور على السانيتايزر وكأنه هو الباج — وهو مش هو. بس التفتيش طلّع اللي أهم:

  **`ImageSanitiser::sanitise()` كان بينادي `imagecreatefromstring()` على طول من غير ما يبصّ على الأبعاد.** وصورة مضغوطة **مابتقولش حاجة** عن تكلفة فتحها: ٢٠٠ كيلوبايت JPEG ينفع تعلن ٢٠٠٠٠ × ١٥٠٠٠ بكسل، وGD بياخد ٤ بايت لكل بكسل = **١.٢ جيجابايت** قبل ما حد يتكلّم. و**حد حجم الملف في الـ FormRequest مابيمنعهاش** — الملف فعلًا صغير، البيتماب هو اللي مش صغير. يعني أي **حساب مسجّل دخول بس** يقدر يوقّع الـ worker من تلات endpoints (توثيق الهوية · مستندات العربية · أدلة البلاغ).

  **الإصلاح:** `getimagesizefromstring()` بتقرا الهيدر بس (ببلاش) **قبل** أي فك ضغط، والأبعاد بتترفض فوق السقف بـ `DOCUMENT_DIMENSIONS_TOO_LARGE` (422، مش 413 — البايتات تمام، الصورة هي اللي بكسل كتير) والسقف في `error.fields` عشان الكلاينت يقدر يقوله. السقف **١٦ ميجابكسل** في الكونفيج: بيغطّي كل كاميرات الموبايل الشائعة (١٢ عادي) وبيحدّ البيتماب عند ~64MB اللي بيدخل في عملية 128MB.

  **تلات حاجات تانية طلعت من نفس الخيط:**
  1. **`thrownErrorCodes()` كان بيفحص `Actions/` و`Controllers/` بس.** و`ImageSanitiser` و`DocumentIntake` الاتنين بيرموا — دول اللي بيرفضوا ملف مش مقروء وفحص فيروسات فاشل وصورة كبيرة — ومكانهم `Support/`، يعني **كود خطأ من Support كان يقدر يوصل لكلاينت من غير ما يدخل العقد خالص**. الفحص اتوسّع لـ`Support/` (واستثناء الأدمن اتوسّع معاه، قرار D4). الـ suite خضرا بعده، يعني مافيش أكواد تانية كانت ناقصة.
  2. **`memory_limit` للـ suite** بقى 512M في `phpunit.xml` بتعليق بيقول إن ده عن عملية الاختبار مش عن التطبيق: PHPUnit بيمسك نتيجة كل اختبار في عملية واحدة، فبعد ١٣٠٠ اختبار مش فاضل من الـ128M حاجة، وأي اختبار صور جديد بيكسر الرن ويشاور على مكان غلط.
  3. **`DEPLOYMENT.md`** بقى فيه قسم إعدادات PHP اللي الأمان متوقّف عليها (`memory_limit` ≥ 256M · `upload_max_filesize`/`post_max_size` ≥ 10M · `max_execution_time` ≥ 30) + شرح الفخ، لأن سقف الميجابكسل والـ`memory_limit` **لازم يتحركوا مع بعض**.

- **2026-10-02** — **🔴 تصحيحين في دليل الموبايل، الاتنين غلط كتبته أنا، والاتنين اتكشفوا لما قريت المصادر بدل ما أفترض.**

  1. **`auto_share_trips` علم أمان المنتج بيوعد بيه ومفيش كود بينفّذه.** الدايزين (شاشة 26) و`MASTER_PLAN` §167 بيقولوا "مشاركة تلقائية لكل رحلة"، والميجريشن مكتوب جنبه `// sees every trip automatically` — والعمود بيتخزّن وبيترجع في الـ API و**مفيش سطر واحد في المنصة بيقراه**. والدليل كان بيقول "On, this contact sees EVERY trip automatically... a contact with this enabled has a standing feed" — يعني كنت بوصّف فيتشر مش موجود، واللي بتشغّله بتفتكر إن أختها هتشوف كل رحلة.
     **وليه ماينفعش يتعمل دلوقتي، وده الجزء المهم:** الرابط التلقائي **لازم يتسلّم في نفس لحظة إنشائه**، لأن التوكن بيترجع مرة واحدة والمخزّن هاش بس. فإنشاء المشاركة دلوقتي وتسليمها بعدين **مش خيار** — ده بيكتب روابط محدش على وجه الأرض يقدر يفتحها. الفيتشر محتاج قناة تسليم = **Phase 12**. العمود بيفضل بيتخزّن عشان نيّة الشخص ماتضيعش، والدليل بقى بيقول الحقيقة ويقول للموبايل مايكتبش نص بصيغة المضارع. **وفيه سؤال مفتوح للمستخدم** (القسم 8.0 في الخريطة): نسيب السويتش ظاهر بنص مستقبلي ولا نخفيه؟
  2. **Night escort mode مش فيتشر موبايل أصلًا.** كنت كاتب في الدليل إنه "نافذة جهة اتصال موثوقة بتتابع فيها" — وده اختراع مني. المصادر بتقول: `escort_windows` فيه `corridor_id` و`armed_by` → **`admin_users`** و`is_auto`، و`MASTER_PLAN` §170 "بيتفعّل تلقائيًا ٩م–٥ص **مع مراقبة من فريق العمليات**"، و§322 بيحطّه **في nav الداشبورد**، و§341 toggle في Settings. يعني **تحكّم أدمن على كوريدور، ومش هيبقى له endpoint** (D4) — مكانه **Phase 13 مش 11**.
     **وماتعملش دلوقتي بقرار**، مش نسيان: مفيش حاجة تقرا حالة التسليح لحد ما الداشبورد يوجد، وأمر مجدول بيكتب صفوف محدش بيقراها مش فيتشر. **واتسجّل تعارضين في السكيمة** لازم يتحلّوا وقتها (القسم 8.0.1 في الخريطة): `safety_events.user_id` مطلوب والـ Bible حاطط `escort_armed` كنوع فيه (وتسليح نافذة مش حدث شخص)، و`corridors.status` بتخلط `escort_armed` مع حالات الصحة فكوريدور ينفع يكون `driver_short` ومُسلَّح في نفس الوقت والعمود مابيسعش الاتنين.

- **2026-10-02** — **Phase 11 — رفع الأدلة على البلاغ.** 2 endpoint + 22 اختبار.

  1. **الملف بيمشي في نفس الـ `DocumentIntake`** اللي بطاقات الهوية بتمشي فيه، وبنفس الترتيب: **افحص البايتات الأصلية** → **أعِد الترميز** (ده اللي بيشيل الميتاداتا فعلًا) → **وبعد كده بس اكتب على الديسك الخاص**. مسار رفع تاني كان هيبقى مكان تاني يقدر يفقد خطوة بهدوء، وده المسار الوحيد اللي اللي بيرفع فيه غالبًا غريب عننا تمامًا — البلاغ ينفع يتبعت من حساب ماعملش غير إنه سجّل دخول.
  2. **إعادة الترميز هنا أهم من أي مكان تاني في المنتج، مش أقل.** صورة متصوّرة في مكان الحادثة شايلة في الـ EXIF إحداثيات **المكان اللي الشخص كان واقف فيه وهو خايف** — والبلاغ بيقراه موظفين وممكن يسافر أبعد من كده. هي صوّرت عربية، ماوافقتش تسلّم موقعها. فخ #24، وده أحد أمثلته.
  3. **صور بس** (`photo` / `screenshot`). الـ `EvidenceKind` فيه `video` و`audio` و`document` والسكيمة فيها مكانهم من Phase 1 — بس تنضيف الميتاداتا بيتعمل بإعادة ترميز صورة نقطية، ومفيش مكافئ لحاوية فيديو أو صوت. قبول واحد منهم معناه **يا تخزين غير منضَّف** (وتسليم الـ GPS ومعرّفات الجهاز اللي جوّاه) **يا ادّعاء حماية مش موجودة**. الرفض بصراحة أأمن من الاتنين، والسبب مكتوب لفريق الموبايل بالنص.
  4. **مفيش path ولا URL ولا hash بيرجع في أي رد** (فخ #23). اللي رفع عارف هو رفع إيه — هو اختاره قبل ثواني — والمراجع بيقراه من الداشبورد اللي بيصادق على جدول مستخدمين تاني خالص. النتيجة اللي لازم الموبايل يصمّم عليها: **الكلاينت مايقدرش يعرض thumbnail** لللي اتضاف، فيحتفظ بنسخة محليّة لو الشاشة محتاجة.
  5. **مفيش حاجة تتشال بعد ما تتضاف** — `incident_evidence` سلسلة حفظ ومابيتمسحش، والموديل بيرفض الحذف أصلًا. ده مكتوب كاختبار مش كتعليق، لأن كلاينت بيعرض زرار "شيل" مش قادر ينفّذه أسوأ من كلاينت بيأكّد قبل الرفع.
  6. **سقف ٥ ملفات للبلاغ**، لأن الجدول مابيتمسحش: أي حاجة تتكتب فيه بتتكتب لمدة الاحتفاظ كلها، فمسار رفع غير محدود = التزام غير محدود. والسقف بيرفض **الملف السادس**، عمره ما يرفض البلاغ.
  7. **البلاغ المقفول بيرفض الإضافة** (`INCIDENT_CLOSED`, 409) — ملف بينزل على حالة مقفولة ملف محدش هيقراه، والرد عليه بـ"اتقبل" أسوأ من الرفض لأن الشخص بيفتكر إن حد معاه. الرفض بيوجّهه يبعت بلاغ جديد، وده اللي بيحطّ بني آدم قصاده تاني. و**حالة البلاغ نفسها مش بترجع في الخطأ**.
  8. **مفيش `safety_event` لكل ملف.** إنشاء البلاغ نفسه مكتوب خلاص في السجل اللي مابيتمسحش، وصف زيادة لكل صورة بيزوّد حجم جدول المشغّلين بيقروه من غير ما يزوّد معلومة — صف الدليل **هو** السجل إن ملف وصل، وهو نفسه مش قابل للمسح.

  **واتكشف فخ قديم في نفس الشغل:** `LIVE_SHARE_ALREADY_ENDED` كان له status من غير ترجمة، و`__('errors.X')` بترجّع المفتاح نفسه لما ماتلاقيش سطر — يعني الـ API كانت بترد على بني آدم بالنص `errors.LIVE_SHARE_ALREADY_ENDED` ومحدش فشل، لأن الترجمة الناقصة مش خطأ في Laravel. وده أسوأ شكل للباج في الملف ده بالتحديد: `message` هو اللي الكلاينت بيعرضه لما مايكونش عنده حالة للـ `code` — وهي بالظبط حالة أي **code جديد**، فأول واحد يشوف خطأ جديد هو الأكثر احتمالًا إنه يتعرض له باسم تقني. ودي أكواد الأمان. **اتضاف اختبار** (`ConventionsTest`) بيتأكد إن كل كود له نص حقيقي بالعربي والإنجليزي، واتأكدت إنه مش اختبار فاضي.

- **2026-10-02** — **Phase 11 — Share Live Trip: الرابط المؤقت اللي حد موثوق بيفتحه.** 3 endpoint مصادَق عليها + **صفحة واحدة بدون مصادقة خالص** (`GET /s/{token}`، على راوتر الويب مش `/v1`) + 31 اختبار.

  الـ feature كله عبارة عن URL بيعدّي المصادقة، فكل قرار فيه عن **تحديده** مش عن تشغيله:

  1. **الـ token هو الصلاحية كلها**، فهو 43 حرف من `random_bytes` و**متخزّن هاش بس** (فخ #29): تسريب الداتابيز مايطلّعش ولا رابط شغّال، و id متسلسل كان هيخلّي أي حد يعدّد رحلات الناس. والنص الصريح **بيترجع مرة واحدة** وقت الإنشاء وخلاص — ولا لصاحبه. قراية تانية للمشاركة بتديك `viewCount` مش الرابط، لأن access token مسروق كان هيحصد كل رابط الشخص عمله.
  2. **الرابط بيموت مع الرحلة.** `expires_at` = نهاية الجلسة + سماح قصير، وسقف أقصى (180د) للجلسة اللي السائقة ما قفلتهاش — رابط بيعيش بعد رحلته هو **شباك دائم على أي حاجة الشخص ده بيروحها بعد كده**. ومفيش `expiresAt` في الـ request: المدة سياسة مش قرار كلاينت.
  3. **صفحة واحدة للمنتهي والملغي واللي عمره ما كان موجود.** التفريق بينهم بيأكّد إن token متخمّن كان حقيقي مرة — وده بيحوّل 404 لـ oracle على رحلات الناس. نفس الـ status ونفس البايت بالظبط، ومختبَر كده.
  4. **اللي الصفحة بتعرضه ضيّق بقرار.** الاسم الأول العام · العربية (باللوحة) · الموقع الحالي · الوجهة والميعاد. **مش بتعرض:** أي رقم تليفون ولا اسم كامل ولا عنوان · **ولا بقية الركّاب** · ولا تاريخ الـ GPS · ولا أي id. السبب اللي بيضيع بسهولة: **اللي بيفتح الصفحة غريب عن السائقة** — هي ماوافقتش تشارك أي حاجة مع الشخص ده، والراكب ماينفعش يوافق بدالها. فالصفحة بتشيل اللي حد محتاجه يتصرّف في طارئ، ومفيش حاجة تفضل مفيدة له بكرة.
     اللوحة **موجودة**، وده الحكم الوحيد اللي محتاج يتقال: هي بتعرّف عربية السائقة لحد مالهوش علاقة بيها، وده تكلفة حقيقية. موجودة لأن من غيرها الـ feature مابيعملش شغله — حد مش قادر يقول العربية إيه مامعاهوش حاجة يديها لحد.
  5. **الصفحة asset-free بالكامل** — مفيش `@vite` ولا خط خارجي ولا map tiles. كل request خارجي من الصفحة بيبعت الـ referrer، والـ referrer هو الـ URL، والـ URL **هو** الصلاحية. `Referrer-Policy: no-referrer` بيغطّيها، بس صفحة مابتعملش أي request لطرف تالت **مايقدرش** يتسرّب منها الـ token مهما الهيدر اتعامل معاه إزاي بعدين.
  6. **`no-store` + `noindex`** مش تزويق: كاش ماسك الصفحة = رابط عايش بعد انتهاء صلاحيته، وده الخاصية الوحيدة اللي التصميم كله قايم عليها. ورابط متفورورد وصل لـ index بحث = مشاركة خاصة بقت منشورة للأبد.
  7. **`view_count` مش analytics.** اللي شارك الرابط لأنه مرتاح مش قوي، سؤاله لما يفتح الشاشة هو "هو بص ولا لأ" — فالعدّاد بيتحرك على كل عرض لرابط **شغّال** بس (رابط ميّت مابيتعدّش، وإلا الشاشة كانت هتقول إن حد شاف حاجة وهو ماشافش).

  **ملاحظتين من الاختبارات:** `view_count` كان بيترجع `null` في رد الإنشاء لأن الصفر جاي من default العمود واللي الموديل مابيعرفوهوش — بقى بيتحدد صراحةً. و`contactId` مش موجود بقى **404** مش مشاركة بتتعمل بهدوء من غير جهة.

  **الناقص من الفصل 10:** رفع الأدلة على البلاغ و escort mode.

- **2026-09-29** — **Phase 11 (الأمان) — الشريحة الحرجة: SOS وجهات الطوارئ والبلاغات والحظر.** 12 endpoint + 41 اختبار. **كلهم في طبقة "مسجّل الدخول" بس — مفيش بوابة توثيق ولا بروفايل كامل**، وده أهم قرار في الشريحة: حد في مشكلة على جنب الطريق مش هيقعد يكمّل رفع بطاقة، و403 في اللحظة دي أسوأ رد المنصة تقدر تديه. وفيه اختبارات موجودة **بس عشان تثبت إن البوابة مش موجودة**.

  **القرارات:**
  1. **الـ SOS بيتسجّل قبل ما العداد يخلص.** العداد على الموبايل وبيحمي من الضغط بالغلط — مش بوابة للتسجيل. لو الموبايل اتاخد أو البطارية خلصت في العشر ثواني، تصميم بيستنى التأكيد ماكانش هيسيب أثر إن حاجة حصلت أصلًا. إنذار كاذب بيتلغي بعد لحظة = صف واحد وسطر في الداشبورد؛ العكس = حد حصلّه حاجة ومحدش سمع.
  2. **الإلغاء مابيمسحش.** نمط ضغطات بتتلغي بعد ثواني، نفس المسار ونفس السائق، هو بالظبط الإشارة اللي فريق الأمان محتاجها — وبتبقى مش موجودة لو كل واحدة بتمسح نفسها.
  3. **الخطورة بتتحدد من التصنيف مش من الطلب.** اللي لسه متعرّض لمضايقة ماينفعش يتسأل يقيّم حالته بين "متوسط" و"عالي"، وأي اختيار هيبقى غلط: يقلّلها فالبلاغ يستنى ورا شنطة مفقودة، أو يكبّرها فالحقل يفقد معناه. وكلاينت يقدر يحددها = كلاينت بيملك ترتيب طابور الأمان.
  4. **الحظر في الاتجاهين** (الفلتر موجود من Phase 6، ده الصف اللي بيغذّيه)، **ومفيش حاجة تقول للمحظور**، والقائمة اتجاه واحد. واللي بتحظر **مابيتكتبلهاش safety_event**: الجدول ده بيقراه المشغّلين، وصف بيقول "هي حظرته" في جدول الموظفين بيبصّوا فيه بيحوّل فعل حماية خاص لحاجة قابلة للمناقشة.
  5. **جهات الطوارئ أخطر feature صغير في المنتج**، والخطر بالعكس من اللي باين: القائمة دي مش "مين أكلّم" — دي **مين بيستقبل موقع الشخص**. شريك مسيء بيحطّ نفسه فيها بـ `auto_share_trips` عنده بثّ شكله قانوني لكل صبح هي بتروح فيه فين. فالقائمة **محدودة بـ5**، والمسح **فوري ومن غير أي تأكيد** (حد بيمسح جهة ممكن يكون بيعملها بسرعة وفي الخفاء، وكل خطوة زيادة خطوة بتتعمل وهو ممكن يكون متراقب)، وتغيير الرقم **بيلغي التأكيد** لأن شارة "مؤكَّد" على رقم محدش وصله هي اللي كانت هتمنع أي حد يبص تاني.
  6. **`SafetyEventLog` بيشيل المفاتيح الحساسة** من `metadata` بالكود مش بالاتفاق — الجدول ده ماينفعش يتمسح، فأي حاجة حساسة تتحط فيه بتبقى فيه للأبد، والضغط إن "نضيف حقل مفيد واحد كمان" في عمود JSON بيمشي في اتجاه واحد بس.

  **الناقص من الفصل 10 (وقتها):** Share Live Trip (اتعمل 2026-10-02، فوق) ورفع الأدلة على البلاغ و escort mode.
- **2026-09-29** — **Phase 9 — كشف الخروج عن المسار.** مفيش endpoint جديد: `deviation_detected_at` و`deviation_distance_meters` بيتحسبوا على كل موقع جديد وبيترجعوا في `GET /trips/{trip}` (شاشة 39). و`distanceFromRoute` اتضافت للـ `GeoQueryEngine` (معيار #7 — مفيش حساب جغرافي بره دومين Geo). **القياس لخط المسار مش لنقاطه:** polyline من مزوّد فيه نقطة كل بضع مئات متر، فقياس لأقرب نقطة كان هيقول إن عربية ماشية في نص الطريق بالظبط بره بمئات الأمتار — وده الفرق بين تنبيه حد بيتصرف عليه وتنبيه بيتعلّم يتجاهله. وكل segment بيُحسب على مستوي محلي مش على الكرة: على طول بضع مئات أمتار الانحناء أقل بكتير من خطأ الموبايل نفسه، والنسخة المسطّحة ضربات معدودة مكان كذا استدعاء trigonometry — وده بيفرق لأنها بتشتغل على كل ping من كل رحلة حيّة. **وأهم قرار: الفحص وقت `in_progress` بس.** وهي بتلم الناس، الخروج عن الخط المباشر **هو الشغل** — سائقة بتعدّي على نقطة التقاء مخصّصة اتوافق عليها بعد النشر بتعمل بالظبط اللي اتفقت عليه، وتنبيه عليها معناه إن التنبيه هيولع أكتر ما يكون على أكثر السائقات تعاونًا. والوقت الأول بيتحفظ وماينكتبش فوقه، والمسافة بتحفظ **الأسوأ**: يعني إمتاح بدأ وقد إيه وصل. التنبيه للـ ops نفسه Phase 12/13 و`safety_events` Phase 11 — اللي موجود دلوقتي هو **الدليل** اللي التلاتة دول هيقروه، وهو الجزء اللي ماينفعش يتعمل بأثر رجعي.
- **2026-09-28** — **عقد التسليم لفريق الموبايل: `RAFEEQ_MOBILE_API_GUIDE.md`** (بطلب المستخدم). إنجليزي بملخص عربي: كل شاشة من الـ٤٧ بـ endpoints بتاعتها وحالتها · فهرس كامل بالـ92 endpoint · شرح كل حقل ترجع ومعناه وإمتاح بيبقى `null` · الأشكال المشتركة (`person`، الحجز، الرحلة، السشن) مشروحة مرة واحدة · القواعد اللي مش باينة في أي schema (الفلوس قروش · الأيام bitmask · التوكن مرة واحدة · الخصوصية مبنية في الـ API مش في الشاشة) · ترتيب مقترح للبناء · واللي مش جاهز بمرحلته · والقرارات المفتوحة اللي تقدر تغيّر العقد. و`MobileApiGuideTest` (52 اختبار) بيمنعه يتعفّن **في الاتجاهين**: مفيش endpoint مذكور وهو مش موجود، ومفيش endpoint شغّال وهو مش مذكور — والتاني ده الأهم، لأن endpoint عملناه وما اتوثّقش هو شغل الفريق مش شايفه، وده أصلًا سبب وجود الملف. **الاختبار لقط ١٠ endpoints كنت كاتبهم بأسماء parameters مختصرة (`{c}` مكان `{commute}`) ومش موجودين بالشكل ده** — يعني كان هيكتب كلاينت على مسار مش موجود.
- **2026-09-26** — **Phase 9 — عدّاد الانتظار.** 3 endpoints (`GET`/`POST /v1/trips/{trip}/wait-timers` · `.../extend`) + 25 اختبار. **مفيش endpoint بيوقف العدّاد بنيّة:** `She's here` هو الـ check-in و`Mark no-show & depart` هو الـ no-show، والاتنين بيقفلوه في نفس النداء — لأن endpoint منفصل معناه السجلّين يقدروا يتعارضوا، والتعارض بيقع دايمًا في نفس الاتجاه: عدّاد سايب شغّال على راكبة اتسجّلت حاضرة بيتقرا بعد شهور كإنها اتسيبت عند البوابة. و`late` بقى له مصدر أخيرًا: العربية استنّتها = `late`. وأهم تفصيلة: **`no_show` و`driver_left` قيمتين مختلفتين** — السائقة من حقها تمشي في أي وقت، بس إنها تمشي بعد تسعين ثانية من مهلة خمس دقايق حكاية تانية خالص عن إنها مشيت بعد ما المهلة خلصت، والراكبة اللي اتسجّلت غايبة من حقها إن الفرق ده يتسجّل مش يتلمّ في قيمة واحدة. والنتيجة بتتحدد **بالساعة مش بكلام السائقة** — مفيش حقل في أي request بيحددها. والمهلة **بتتجمّد على الصف** وقت البداية، لأن نزاع عن صباح النهاردة لازم يُحسم على الوعد اللي كان ساري النهاردة مش على الرقم بعد ما الداشبورد تغيّره.
- **2026-09-26** — **Phase 9 — الحضور الفعلي وقرار D18 بضماناته.** 4 endpoints (`GET /v1/trips/{trip}/attendance` · `check-in` · `no-show` · `POST /v1/bookings/{booking}/dispute`) + state machine على `AttendanceStatus` + 28 اختبار (المجموع 1,098). **الاعتراض نزل في نفس الشريحة مع التأكيد مش بعده بمرحلة**، لأن D18 من غيره مش ضمانة ناقصة — هو **خصم مش متشكَّك فيه**. و`present`/`late`/`no_show` **نهائيين**: السائقة ماتقدرش ترجع في قرارها، وإلا راكب اتخصم منه الاتنين يتسجّل غايب الجمعة والنافذة ماتحميش حاجة. النافذة بتتحسب **من لحظة القرار مش من نهاية الرحلة** — لو من النهاية، سائقة بتسجّل غياب بعد يوم كانت هتدي الراكب نافذة مقفولة أصلًا. والـ GPS **دليل مساعد بدرجة ثقة (0–1) مش شرط**: تأكيد بيطلب إشارة كويسة بيفشل في جراج تحت الأرض، والسائقة هتتعلّم تسجّل الناس من الشارع عشان يشتغل. **باج لقطته الاختبارات:** `gps_corroborated` كان بيتحط `true` مجرد ما إحداثيات توصل — يعني سائقة بتسجّل حد من كيلومترين كانت بتاخد أقوى كلمة في الجدول على أضعف دليل فيه، وأي مراجع بيقرا العمود كان هيقراه بالمقلوب.
- **2026-09-26** — **Phase 9 (دورة حياة الرحلة) — العمود الفقري.** 4 endpoints (`GET /v1/trips/{trip}` · `start` · `status` · `complete`) + state machine على `TripSessionStatus` + 32 اختبار (المجموع 1,070). فحوص البداية كلها **بتتعاد لحظة القيام** مش موروثة من وقت النشر: رخصة انتهت الأسبوع اللي فات معناها الرحلة دي ماتحصلش. **أهم قرار: سكوت السائقة مش غياب** — صف حضور لسه `pending` وقت الإتمام يفضل `pending`، لأن D18 بيدي السائقة قرار فاتورة الراكب، وتحويل نسيانها لـ no-show كان هيمدّ السلطة دي لحاجة هي أصلاً ماعملتهاش. عمود جديد `trip_sessions.departed_at` (انحراف عن الـ ERD، موثّق): `started_at` هي لحظة فتح التطبيق مش لحظة تحرّك العربية، ومفيش مصدر أمين تاني لـ `on_time_rate` اللي تلات شاشات بيعرضوه لغريب بياخد قرار يركب مع حد.
- **2026-09-26** — **باج في الـ suite نفسه: الاختبارات كانت بتفشل كل يوم بعد ٩ مساءً.** الحجز بيقفل ٩ بالليل لليوم اللي بعده (`booking_deadline_hour`)، فحوالي مية اختبار بيحجزوا مقعد كانوا شغّالين طول النهار وبيرجعوا `BOOKING_DEADLINE_PASSED` أول ما الساعة تعدّي التسعة. والرسالة بتشاور على قاعدة حجز مش على الساعة، فأول ليلة تحصل كانت هتتصرف في تفتيش قاعدة الحجز. الساعة بقت مثبّتة لكل اختبار (سبت ٨ صباحًا القاهرة) في `Pest.php`.
- **2026-09-26** — **تصحيح أرقام المراحل في الخريطة.** الخريطة كانت بتحسب التقييمات Phase 9 والأمان Phase 10، والاتنين متأخرين مرحلة كاملة عن الـ MASTER_PLAN (9 = دورة حياة الرحلة · 10 = التقييمات · 11 = الأمان) — وصفّين كتبتهم أنا حطّوا الـ check-in والـ wait timer في 11. رقم غلط في ملف فريق تاني بيخطّط عليه معناه إنهم مستنيين شاشة مرحلة كاملة قبل ميعادها. اتضاف **جدول مراحل بالاسم** + اختبار بيطابقه على عناوين الـ MASTER_PLAN.
- **2026-09-26** — **التجميعتين (شاشة 9 و23) + السعر المقترح العادل (شاشة 24).** الترقيع من الخريطة خلص ٨ من ٨. وحدود السعر اتصححت لـ 5,000–12,000 قرش (تلات مصادر ضد الـ config). و**قراية جديدة لتعارض الفلوس:** المثال المحسوب في الـ Bible نفسه بيخلّي الراكبة تدفع 88 على مقعد بـ 80 — يعني **الـ Bible بيناقض نصّه ومع الشاشات**. القسم 8.1 في الخريطة فيه القراءتين المتماسكتين والقرار لسه للمستخدم.
- **2026-09-25** — **شريحة الأدمن (Livewire، قرار D4) — المسار الأساسي بقى ماشي.** لأول مرة راكب يقدر يتوثّق وسائق يقدر يتعمد في الإنتاج. دخول بـ MFA إجباري + منع إعادة استخدام كود TOTP + ٦ أدوار بصلاحيات في الكود + سجل تدقيق بيرفض قرار حساس من غير سبب. 33 اختبار جديد (المجموع 944). **٨ باجات حقيقية** أثناء البناء، أخطرهم: `sha256($ip)` ماكان بيحمي حاجة (مساحة IPv4 تتعدّ في ثواني)، وسر TOTP في الـ factory كان hex مش base32 فمفيش كود صحيح كان هيتحقق، وزائر مش مسجّل على أي صفحة داشبورد كان بياخد 500. وكمان أمّنت `laravel-request-docs` اللي المستخدم نزّله: صفحة الـ docs كانت هتبقى مكشوفة في الإنتاج وبتعرض SQL ولوجز.
- **2026-09-25** — **تعديل الخطة: الشغل بقى مربوط بالدايزين.** فتحنا الـ prototype فعلًا لأول مرة (فكّينا الـ bundle وقرينا كود الـ٤٧ شاشة + الـ٩ أقسام). طلع **`RAFEEQ_SCREEN_API_MAP.md`** + **`ScreenApiMapTest`** (58 اختبار بيمنعوا الخريطة تتعفّن زي مراجعة §23). **أهم اكتشاف: مفيش ولا endpoint للأدمن، يعني في الإنتاج محدش يقدر يعتمد سائق** — الترتيب اتعدّل وشريحة الأدمن بقت الجاية مش Phase 13. وتعارض فلوس اتكشف لازم يُحسم قبل Phase 8 (اتجاه رسوم المنصة ونسبتها). وقرار: تعارض قائمة الانتظار يتحل بتغيير الشاشة مش الكود.
- **2026-09-25** — **Phase 7 (طلبات المقاعد والحجوزات والمجموعات) كاملة.** 27 endpoint · **أخطر اختبار في المشروع اتقفل** (القفل على صف الرحلة، مثبَّت من تلات جهات) · الموافقة عملية واحدة للتجربة والالتزام (مشهد 10) · قائمة انتظار بترقية للـ inbox مش لحجز · نقطة التقاء مخصصة بانعطاف **محسوب عندنا** · المجموعة بخصوصية كاملة · حضور وغياب ومهلة مغادرة · أمر يومي تاني (`memberships:roll-forward`). 113 اختبار جديد (المجموع 849). **باجان حقيقيان خطيرين:** اللي ساب مجموعة عمره ما كان يقدر يرجع (موافقة مستهلكة ماسكة المكان للأبد)، وراكب لغى حجز عمره ما كان يقدر يتحجّز في نفس اليوم تاني — و**500 مش رفض**، لأن الكود والـ unique index كانوا بيتعارضوا. والباج الأول هو اللي كشف التاني.
- **2026-09-25** — **Phase 6 (محرك البحث والمطابقة ★) كاملة.** خط أنابيب بأربع مراحل (50,000 → 800 → 40 → ترتيب) · نموذج الـ 100 نقطة بسبعة مكوّنات · **الاستبعاد الصارم في الاستعلام نفسه** (فخ #15) · الطلب السرّي والإشعار من العرض ناحية الطلبات · كاش ساعة. 8 endpoints + 63 اختبار جديد (المجموع 736). باج حقيقي: N+1 لقطه `preventLazyLoading` — صفحة النتائج كانت هتعمل استعلام لكل سائق. **ملاحظة بيئة:** الـ suite وقف في منتصفه مرة لأن قرص C: امتلى (بيانات MySQL عليه) — مش مشكلة كود.
- **2026-09-23** — **Phase 5 (الأماكن والـ Corridors ونشر الرحلات) كاملة.** طبقة الجغرافيا كلها وراء `GeoQueryEngine` (معيار #7، أول تنفيذ فعلي) · 12 endpoint · 7 Actions · أفق متدحرج 30 يوم بأمر يومي · state machine كامل بحالات الحافة · ربط الـ corridors · بحث الأماكن. 73 اختبار جديد (المجموع 673)، منهم اختباران للـ DST على التوليد الحقيقي. باج حقيقي: `sometimes` + `required_with` ثقب في التحقق — إرسال نص إحداثي كان بيتقبل ويُتجاهل (معيار #41).
- **2026-09-23** — **Phase 4 (السائق والمركبات) كاملة.** 11 endpoint · 7 Actions · 3 Support · state machine · كشف تكرار على الـ hash · سجل تدقيق append-only · 63 اختبار جديد (المجموع 600). وبطلب المستخدم، الأخطاء المتكررة بقت **تتلقط آليًا** في `ConventionsTest` (4 فحوص) — ولقطت بقية فورًا. باج حقيقي: `firstOrNew` بيعمل mass-assignment للمفتاح اللي مستبعد عن قصد.
- **2026-09-23** — **Phase 3 (البروفايل والتوثيق) كاملة.** Verification Centre بالمستويات الأربعة · رفع مستندات آمن (فحص → تجريد ميتاداتا → تخزين خاص) · توثيق الجهة بنطاق الإيميل · بوابة التوثيق (`verified:` middleware) · `ReviewVerificationAction`. 5 endpoints + 64 اختبار جديد (المجموع 537). تصحيح ترقيم: الفصل 3 من الكتاب هو Phase **4** مش 3. باجان حقيقيان: المركز كان بيبلّغ الطريقة الافتراضية مش الفعلية، و3 حقول كانت خارجة بدون نوع في العقد (لقطهم `OpenApiDocumentTest`). ومعيار #35 اتوسّع بعد اكتشاف إن `shouldUse()` بيسرّب الـ guard الافتراضي بين الطلبات جوّه الاختبار.
- **2026-09-23** — **تسليم OpenAPI اتقفل** (§15.4/§18 بيحسبه مستحق من نهاية Phase 2): `config/scramble.php` + `BearerTokenSecurity` (الأمان مشتق من الـ middleware) + `DescribeErrorResponses` + `#[ApiErrors]` على كل endpoint + `composer openapi` + `OpenApiDocumentTest` (10 اختبارات). كشف باجين حقيقيين: `purpose`/`preferredLanguage` ماكانش عليهم قاعدة `string` أصلاً، وScramble كان بينشر التعليقات الداخلية كوصف الحقول للفريق الخارجي. معايير #38–#40 اتضافوا. (المجموع 473 خضرا)
- **2026-09-23** — قرار من المستخدم: أي رقم سياسة (زي الحد الأدنى للسن) يتعدّل من الـ settings مش من الكود. الحد الأدنى للسن وأطوال الاسم كانوا بيتقروا من `config` مباشرة في الـ FormRequest — مخالفة لمعيار #11 من ناحيتي. اتصلح بـ `ProfileSettings` accessor جديد + 11 اختبار بيثبتوا إن تغيير صف `platform_settings` بيغيّر السلوك فعلاً على الـ endpoints. و`auth.otp.driver` اتعلّم `DEPLOY-ONLY` صراحة مع اختبار بيثبت إنه مش قابل للتعديل من الـ settings. (المجموع 463 خضرا)
- **2026-09-20** — **Phase 2 (المصادقة والهوية) كاملة.** 11 endpoint · 9 Actions · 9 Support classes · 3 enums جديدة · 4 Resources · 2 middleware · migration واحدة · 71 اختبار جديد (المجموع 446 خضرا). اتقرا الفصل 2 كامل قبل الكود. تعارضان اتوضّحوا (طول الـ PIN 4 مش 6 حسب §13 · مزوّد الـ SMS لسه مفتوح فاتعمل له seam). باج حقيقي في السكيمة اتكشف واتصلح: `gender`/`registered_role` كانوا NOT NULL رغم إن الـ flow الموثّق ينشئ الصف من غيرهم.
- **2026-09-19** — مجموعة ① (الهوية والجلسات) كاملة: users/organizations/otp_challenges/devices/auth_sessions/security_events/user_consents/user_places/user_stats. قرارات مهمة اتاخدت: `auth_sessions` بدل `sessions` (تعارض اسم مع Laravel) · `SpatialPoint` cast مشترك للأعمدة الجغرافية · `dateTime()` بدل `timestamp()` للأعمدة الإجبارية من غير default · generated column لحل مشكلة `UNIQUE(phone, deleted_at)` · تأكيد MariaDB 10.4 متوافقة مع كل احتياجات الـ ERD.
- **2026-09-19** — بداية التنفيذ: قراءة الكتاب كامل، اعتماد الخطة، إنشاء ملف المتابعة.

---

## القسم 1 — Phase 0: الأساسات

| # | المهمة | الحالة | الملفات |
|---|---|---|---|
| 1 | تثبيت الحزم (Sanctum, Reverb, Spatie Permission, Larastan, Scramble) | ✅ | `composer.json` |
| 2 | إعدادات Model الصارمة + CarbonImmutable | ✅ | `app/Providers/AppServiceProvider.php` |
| 3 | `routes/api.php` + تسجيله | ✅ | `routes/api.php`, `bootstrap/app.php` |
| 4 | ApiResponse envelope + كتالوج أكواد الأخطاء + SetLocaleFromHeader | ✅ | `app/Http/Responses/`, `app/Http/Middleware/SetLocaleFromHeader.php` |
| 5 | Value Objects مشتركة (Money, PhoneNumber, Coordinate, DaysMask) | ✅ | `app/Domains/Shared/ValueObjects/` |
| 6 | هيكل الترجمة ar/en (validation/auth/passwords/pagination + errors) | ✅ | `lang/ar/`, `lang/en/` |
| 7 | ملف المتابعة ده | ✅ | `Rafeeq doc/RAFEEQ_PROGRESS.md` |

**معيار الانتهاء:** `composer install` ناجح · `php artisan test` أخضر · إعدادات الصرامة شغالة · الـ envelope عليه اختبار.

---

## القسم 2 — Phase 1: الداتابيز (14 مجموعة)

| # | المجموعة | الجداول | الحالة |
|---|---|---|---|
| 1 | الهوية والجلسات | users, organizations, otp_challenges, devices, auth_sessions, security_events, user_consents, user_places, user_stats | ✅ |
| 2 | الأدمن + التوثيق والثقة | admin_users(+roles/permissions), user_verifications, identity_documents, trust_scores | ✅ |
| 3 | السائق والمركبة | driver_profiles, vehicles, vehicle_documents, verification_logs | ✅ |
| 4 | الجغرافيا | places, corridors, corridor_stats, route_cache | ✅ |
| 5 | عروض التنقّل | commute_offers, commute_locations, commute_schedules, commute_rules, scheduled_trips | ✅ |
| 6 | المجموعات (جزء 1) | commute_groups, group_members | ✅ |
| 7 | الطلب والمطابقة | commute_demands, saved_searches, match_scores, match_notifications | ✅ |
| 8 | الطلبات والحجوزات | seat_requests, pickup_point_requests, bookings, booking_events | ✅ |
| 9 | المجموعات (جزء 2) | group_attendance, group_absences | ✅ |
| 10 | الفلوس | payment_methods, payments, payment_webhooks, driver_fee_ledger, driver_balances, payouts, refunds | ✅ |
| 11 | الرحلة الحية | trip_sessions, attendance, trip_locations, trip_wait_timers | ✅ |
| 12 | التقييم والثقة | ratings, rating_tags, review_reports | ✅ |
| 13 | الأمان | emergency_contacts, live_shares, safety_events, sos_events, incidents, incident_evidence, blocked_users, escort_windows | ✅ |
| 14 | التواصل والأدمن/التحليلات | notifications, notification_preferences, conversations, messages, admin_actions, support_tickets, platform_settings, feature_flags, analytics_events, recommendation_cache, demand_heatmap_cells | ✅ |
| — | Seeder السيناريو الكامل + اختبارات القيود الحرجة | | ✅ |

**معيار الانتهاء:** ✅ `migrate:fresh --seed` نظيف · ✅ كل اختبارات ERD §20 (207 اختبار) · ✅ اختبار DST · ⚠️ اختبار التزامن الكامل مؤجّل لـ Phase 7 (راجع "مؤجّلة عمدًا" فوق).

---

*آخر تحديث بواسطة: جلسة التنفيذ الحالية.*
