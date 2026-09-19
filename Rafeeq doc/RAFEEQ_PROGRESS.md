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
| 24 | **`hasMany`/`hasOne` من موديل بـ PK غير قياسي** | لو الموديل الأب مفتاحه الأساسي مش `id` (زي `DriverProfile` اللي مفتاحه `user_id`)، Eloquent بيخمّن اسم الـ FK بصيغة `{model}_{primary_key}` (يعني `driver_profile_user_id`!) مش `driver_profile_id`. أي `hasMany`/`hasOne` من موديل زي ده **لازم** يحدد الـ FK والـ local key صراحة: `hasMany(Vehicle::class, 'driver_profile_id', 'user_id')`. الفخ ده اتكشف متأخر في الـ seeder لأن الاختبارات كانت بتستخدم اتجاه العلاقة العكسي (`belongsTo`) بس |

---

## الحالة الحالية

**آخر تحديث:** 2026-09-19
**الوضع:** ✅ **Phase 0 و Phase 1 مكتملتين ومراجَعتين (جولتين code review).** 72 migration · 71 جدول دومين · 71 موديل · 77 enum · 71 factory · seeder كامل · **375 اختبار كلهم خضرا**.
**مستند التسليم الكامل:** `RAFEEQ_PHASE_0_1_DELIVERY.md` — جاهزين نبدأ Phase 2 (المصادقة والهوية).

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

### 🎯 الخطوة الجاية
**Phase 2 — المصادقة والهوية** (الفصل 2 من الكتاب): OTP request/verify · تسجيل/دخول موحّد · PIN 4 أرقام محلي · Session refresh rotation · Logout · Device management · Forgot PIN · Consents versioning. راجع `RAFEEQ_ENGINEERING_BIBLE.md` المشهد 1 و2 قبل البدء.

---

## سجل التقدّم (الأحدث فوق)

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
