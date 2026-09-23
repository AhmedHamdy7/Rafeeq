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
| 35 | **تصفير الـ auth guards في الاختبارات** | Laravel مش بيصفّر الـ guards بين الطلبات جوّه نفس الاختبار، و`RequestGuard` (اللي هو `auth:sanctum`) بيحفظ اليوزر اللي حلّه في أول طلب. النتيجة: أي اختبار بيتأكد إن حاجة **بتقفل** الوصول (logout، إلغاء جهاز، سحب توكن) بيعدّي وهو غلط. `Tests\TestCase::call()` بيعمل `forgetGuards()` قبل كل طلب — ده بيرجّع سلوك الإنتاج (كل طلب بيبوّت app جديد) مش بيلتف حواليه |
| 36 | **قيد CHECK بدل القيمة الافتراضية الوهمية** | لو عمود لازم يفضل فاضي لحد خطوة لاحقة في الـ flow، خليه `nullable` + `CHECK` بيربط اكتمال الحالة بامتلائه — **مش** قيمة placeholder. مثال حي: `users.gender` لو اتحطت `prefer_not_to_say` مؤقتًا كانت هتستبعد ست صامتًا من مطابقة النساء، لأن دي إجابة حقيقية مش "مش متجاوبة" |
| 37 | **إلغاء الجلسة لازم يمسح التوكن كمان** | تعليم `auth_sessions.revoked_at` لوحده بيمنع الـ refresh بس — الـ access token اللي في إيد السارق بيفضل شغال لحد ما ينتهي لوحده. عشان كده اسم توكن Sanctum = `auth_session.id`، و`AuthSession::revoke()` بتمسح الصف والتوكن مع بعض |
| 38 | **التعليق فوق قاعدة التحقق = توثيق API** | Scramble بينشر التعليق اللي فوق كل سطر في `rules()` كوصف الحقل في المستند اللي الفريق الخارجي بيقراه. يعني التعليقات هناك **مكتوبة للعميل** مش للصيانة — أي شرح داخلي (فخاخ، أسباب اختيار قاعدة على تانية) مكانه docblock الكلاس. نفس الكلام على الـ Resources والـ Actions اللي شكل خرجها بيتستنتج |
| 39 | **`Rule::in` لوحدها مش بتحدد نوع** | `['nullable', Rule::in([...])]` بيحدد القيم المسموحة بس من غير أي نوع مُعلَن — لا في التحقق ولا في العقد. لازم `'string'` (أو `'integer'`) جنبها. ولو القيم جاية من enum، `Rule::enum(X::class)->only([...])` أفضل: بيحدد النوع والمجموعة مع بعض، وبيمنع إضافة case جديدة من إنها توسّع المسموح بالسكوت |
| 40 | **ممنوع object في ملف config** | Laravel بيسيريالايز الـ config بـ `var_export` وقت `config:cache`، وده ما بيعرفش يعبّر عن object. أي إعداد محتاج object لازم يبقى class string والـ object يتبني في الكلاس — حتى لو توثيق الحزمة نفسه موصّي بغير كده (حصل فعلاً مع `scramble.security_strategy`) |
| 24 | **`hasMany`/`hasOne` من موديل بـ PK غير قياسي** | لو الموديل الأب مفتاحه الأساسي مش `id` (زي `DriverProfile` اللي مفتاحه `user_id`)، Eloquent بيخمّن اسم الـ FK بصيغة `{model}_{primary_key}` (يعني `driver_profile_user_id`!) مش `driver_profile_id`. أي `hasMany`/`hasOne` من موديل زي ده **لازم** يحدد الـ FK والـ local key صراحة: `hasMany(Vehicle::class, 'driver_profile_id', 'user_id')`. الفخ ده اتكشف متأخر في الـ seeder لأن الاختبارات كانت بتستخدم اتجاه العلاقة العكسي (`belongsTo`) بس |

---

## الحالة الحالية

**آخر تحديث:** 2026-09-23
**الوضع:** ✅ **Phase 0 و Phase 1 و Phase 2 مكتملات + تسليم OpenAPI للفريق الخارجي.** 73 migration · 71 جدول دومين · 71 موديل · 80 enum · 71 factory · 11 endpoint · 32 رد خطأ موصوف · **473 اختبار كلهم خضرا** (+98 اختبار جديد في Phase 2).
**مستند التسليم:** `RAFEEQ_PHASE_0_1_DELIVERY.md` (المرحلتين 0 و1) — الخطوة الجاية Phase 3 (توثيق السائق والمركبة، الفصل 3).

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

### 🎯 الخطوة الجاية
**Phase 3 — توثيق السائق والمركبة** (الفصل 3 من الكتاب). الفصل 2 بيقفل بشرط صريح: "Chapter 3 can create a driver profile using the same `user_id`" — وده متحقق (`driver_profiles.user_id` هو الـ PK وبيشاور على `users.id`).

---

## سجل التقدّم (الأحدث فوق)

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
