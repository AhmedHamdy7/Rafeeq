# RAFEEQ — تسليم Phase 0 و Phase 1

> **الغرض:** مستند تسليم — إيه اللي اتبنى بالظبط في المرحلتين دول، وإيه اللي **مش** متضمّن، وإزاي تتأكد بنفسك إن كله شغال.
>
> **التاريخ:** 2026-09-19 · **الحالة:** ✅ مكتمل ومراجَع
> **للمتابعة اليومية:** `RAFEEQ_PROGRESS.md` (الملف الحيّ) · **للمواصفات:** `RAFEEQ_ENGINEERING_BIBLE.md` + `RAFEEQ_ERD.md`

---

## 1. الخلاصة في سطور

| البند | العدد |
|---|---:|
| Migrations بتاعتنا | **72** (+5 من Laravel/Sanctum/Spatie = 77 إجمالي) |
| جداول الدومين | **71** (+14 جدول إطار عمل/حزم = 85 إجمالي) |
| Eloquent Models | **71** |
| PHP Backed Enums | **77** |
| Factories | **71** |
| اختبارات (Pest) | **375** ✅ كلها خضرا |
| Assertions | **579** |

> **ملاحظة على العدد:** الـ ERD بيتكلم عن 57 جدول. العدد الفعلي 71 لأن الـ ERD نفسه بيذكر جداول جوّه الشرح مش في العد الرسمي (زي `user_places`, `user_stats`, `pickup_point_requests`, `corridor_stats`, `payment_webhooks`, `trip_wait_timers`, `rating_tags`, `review_reports`, `escort_windows`, `feature_flags`, `recommendation_cache`, `demand_heatmap_cells`)، وكلها متنفّذة.

**معيار الانتهاء المتفق عليه:** `migrate:fresh --seed` نظيف من الصفر ✅ · كل قيود ERD §20 متحققة ومختبَرة ✅ · اختبار DST ✅ · Pint نضيف ✅

---

## 2. Phase 0 — الأساسات

| # | التسليم | المسار |
|---|---|---|
| 1 | الحزم: Sanctum · Reverb · Spatie Permission · Larastan · Scramble · Laravel-Lang | `composer.json` |
| 2 | إعدادات Model الصارمة (`preventLazyLoading`, `shouldBeStrict`, `prohibitDestructiveCommands`) + `CarbonImmutable` في كل المشروع | `app/Providers/AppServiceProvider.php` |
| 3 | `routes/api.php` بـ prefix `/v1` + تسجيله في `bootstrap/app.php` | `routes/api.php` |
| 4 | Envelope موحّد للـ API + كتالوج أكواد الأخطاء + معالج استثناءات | `app/Http/Responses/ApiResponse.php`, `ApiExceptionHandler.php`, `app/Domains/Shared/Support/ErrorCode.php` |
| 5 | Value Objects مشتركة: `Money` (قروش integer) · `PhoneNumber` (E.164 + أرقام عربية) · `Coordinate` · `DaysMask` | `app/Domains/Shared/ValueObjects/` |
| 6 | دعم عربي/إنجليزي كامل: ملفات ترجمة للاتنين + middleware بيقرأ `Accept-Language` | `lang/ar/`, `lang/en/`, `app/Http/Middleware/SetLocaleFromHeader.php` |
| 7 | ملف متابعة حيّ بـ 24 معيار ملزم | `Rafeeq doc/RAFEEQ_PROGRESS.md` |

**مكوّنات مشتركة اتبنت في الطريق:**
- `SpatialPoint` cast — بيحوّل أعمدة `POINT` من/لـ `Coordinate` (Laravel مفيهوش cast جاهز للجغرافيا)
- `IsAppendOnly` trait — بيمنع UPDATE و DELETE على سجلات INSERT-only
- `PreventsDeletion` trait — بيمنع DELETE بس (للسجلات اللي بتتحدّث بس مابتتحذفش)
- `DepartureTimeCalculator` — حساب UTC من وقت محلي + timezone (قلب مشكلة التوقيت الصيفي)

---

## 3. Phase 1 — الداتابيز الكاملة (14 مجموعة)

| # | المجموعة | الجداول | الحالة |
|---|---|---|:---:|
| ① | الهوية والجلسات | organizations, users, otp_challenges, devices, auth_sessions, security_events, user_consents, user_places, user_stats | ✅ |
| ② | الأدمن + التوثيق | admin_users (+roles/permissions), user_verifications, identity_documents, trust_scores | ✅ |
| ③ | السائق والمركبة | driver_profiles, vehicles, vehicle_documents, verification_logs | ✅ |
| ④ | الجغرافيا | places, corridors, corridor_stats, route_cache | ✅ |
| ⑤ | عروض التنقّل | commute_offers, commute_locations, commute_schedules, commute_rules, scheduled_trips | ✅ |
| ⑥ | المجموعات (1) | commute_groups, group_members | ✅ |
| ⑦ | الطلب والمطابقة | commute_demands 🔒, saved_searches, match_scores, match_notifications | ✅ |
| ⑧ | الطلبات والحجوزات | seat_requests, pickup_point_requests, bookings, booking_events | ✅ |
| ⑨ | المجموعات (2) | group_attendance, group_absences | ✅ |
| ⑩ | الفلوس | payment_methods, payments, payment_webhooks, driver_fee_ledger, driver_balances, payouts, refunds | ✅ |
| ⑪ | الرحلة الحية | trip_sessions, attendance, trip_locations, trip_wait_timers | ✅ |
| ⑫ | التقييم والثقة | ratings, rating_tags, review_reports | ✅ |
| ⑬ | الأمان | emergency_contacts, live_shares, safety_events, sos_events, incidents, incident_evidence, blocked_users, escort_windows | ✅ |
| ⑭ | التواصل والأدمن/التحليلات | notifications, notification_preferences, conversations, messages, admin_actions, support_tickets, platform_settings, feature_flags, analytics_events, recommendation_cache, demand_heatmap_cells | ✅ |

### القيود الحرجة (ERD §20) — كلها متحققة ومختبَرة

| القيد | إزاي اتنفذ |
|---|---|
| رقم موبايل واحد نشط لكل حساب | **generated column** — القيد المكتوب في الـ ERD (`UNIQUE(phone, deleted_at)`) ماكانش هيشتغل فعليًا في MySQL |
| عربية نشطة واحدة لكل سائق | generated column (نفس الأسلوب) |
| طلب مقعد نشط واحد لكل راكب/عرض | generated column بيتحوّل NULL لأي status غير pending/approved |
| لوحة سيارة فريدة على مستوى المنصة | `UNIQUE(plate_normalized)` + تطبيع تلقائي عند الحفظ |
| رحلة واحدة لكل عرض لكل يوم | `UNIQUE(commute_offer_id, trip_date)` |
| حجز واحد لكل راكب لكل رحلة | `UNIQUE(scheduled_trip_id, passenger_user_id)` |
| `seats_taken <= seats_total` | `CHECK` constraint |
| `السعر = العمولة + نصيب السائقة` | `CHECK` على `bookings` و`payments` |
| النجوم بين 1 و 5 | `CHECK` |
| `end_date >= start_date` | `CHECK` |
| منع الخصم المزدوج | `UNIQUE(idempotency_key)` |
| منع إعادة تشغيل webhook | `UNIQUE(provider, event_id)` |

### السيناريو المرجعي في الـ Seeder

`php artisan migrate:fresh --seed` بيبني: **نور** (سائقة معتمدة، 4 مستويات توثيق، عربية، عرض منشور الرحاب←سمارت فيلدج نساء-فقط) · **مريم** (راكبة موثّقة، طلب مقعد تجريبي مقبول، حجز مؤكد، عضوة في المجموعة، محادثة مفتوحة) · كورريدور · **24 مستخدم في طابور التوثيق** · حالة أمان مفتوحة · 6 أدوار أدمن.

---

## 4. نتيجة الـ Code Review

اتعمل review آلي على الـ 71 موديل بعد ما المرحلة خلصت. **اتكشفت 3 مشاكل حقيقية واتصلحت:**

| # | المشكلة | الخطورة | الحل |
|---|---|---|---|
| 1 | `RecommendationCache` كان بيشاور على جدول `recommendation_caches` — **مش موجود أصلاً**. الموديل كان هيقع أول ما يتستخدم | 🔴 عالية | `protected $table` صريح + **اختبار smoke بيغطي الـ 71 موديل كلهم** |
| 2 | `UserVerification.status` و `Attendance.status/confirmed_by` كانوا mass-assignable — يعني لو أي controller مرّر request body مباشرة، المستخدم يقدر **يعتمد توثيق نفسه** أو **يأكد حضوره بنفسه** (والحضور هو اللي بيشغّل الفاتورة، قرار D18) | 🔴 عالية | اتشالوا من `fillable` — لازم الـ Action يحطهم صراحة |
| 3 | `phone_e164` و `full_name` كانوا بيتسربوا في أي serialization افتراضي — ده بالظبط فخ #21 ووعد المنتج "رقمك مخفي دايمًا" | 🟠 متوسطة | اتخفوا افتراضيًا + `makeVisible()` صريح لعرض الملف الشخصي للمستخدم نفسه + **اختبار ضمانات الخصوصية** |

**كمان اتصلح للاتساق:** 4 أعمدة enum ماكانش عليها cast (`fuel_type`, `Conversation.status`, `ReviewReport.status`, `IncidentEvidence.kind`).

**اتفحص وطلع سليم:** كل الـ FK عليها indexes · مفيش fillable/cast/hidden بيشاور على عمود مش موجود · مفيش موديل تاني باسم جدول غلط · مفيش علاقة تانية بمفتاح مخمّن غلط.

**قرارات واعية (مش أخطاء):** `BookingEvent.from_status/to_status` سايبينهم string مش enum عن قصد — ده سجل تاريخي، ولو غيّرنا اسم حالة في الـ enum بعدين، الـ cast هيكسر قراءة السجلات القديمة.

### الجولة التانية — مراجعة خارجية على طبقة الـ HTTP والـ casts

| # | المشكلة | الخطورة | الحل |
|---|---|---|---|
| 4 | **`SpatialPoint`**: لو بعت النقطة كـ array، القراءة بترجع `null` لحد ما تعمل `fresh()` (Eloquent بيكاش قيمة الـ cast بس لو المدخل object، والـ `Expression` بتفضل في الـ attributes) | 🟠 | اتشال `Expression` خالص — دلوقتي بنكتب **WKB blob** كـ bound parameter، فالقراءة مسار واحد في الحالتين |
| 5 | **ترويسات مفقودة**: 429 بيفقد `Retry-After`، 405 بيفقد `Allow`، 401 بيفقد `WWW-Authenticate` | 🟠 | `ApiResponse::error()` بقت بتمرّر `$e->getHeaders()` |
| 6 | **`abort(400)` كان بيرجع `SERVER_ERROR`** مع status 400 — العميل هيفتكرها مشكلة سيرفر قابلة لإعادة المحاولة | 🟠 | `fromHttpStatus()` بقت ترجع لفئة الـ status + اتضافت 6 أكواد جديدة (400/410/413/419/503…) |
| 7 | **`admin_users` من غير `remember_token`** — `logout()` على الـ admin guard كان هيرمي `MissingAttributeException` تحت `shouldBeStrict()` | 🟠 | اتضاف العمود + اختبار login/logout فعلي على الـ guard |
| 8 | **`PlatformSetting::value()`** كانت بتخزّن الـ `$default` بتاع أول مستدعي للأبد وتردّه على كل اللي بعده | 🟠 | الـ default بقى بيتطبّق **بعد** الكاش مش جوّاه |
| 9 | `email` و`date_of_birth` لسه بيتسربوا · callback ميت لـ `AuthorizationException` | 🟢 | اتخفوا + الكود الميت اتشال |

**ملاحظة اتحققت وطلعت غير دقيقة (مهمة):** المراجعة قالت إن `abort($response)`/Precognition بيتحولوا لـ 500 بسبب الـ `Throwable` catch-all. **ده مش صح** — `Route::run()` بيمسك `HttpResponseException` بنفسه، فهي عمرها ما بتوصل للـ handler من جوّه route action. اتأكدنا بـ probe فعلي + قراءة كود الإطار (`Route.php:214-220`). **لكن** لو اترمت من **middleware** (اللي بيشتغل بره الـ try/catch ده) فهي بتوصل فعلاً وكانت هتتحول لـ 500 — فالحماية اتضافت، والاختبار بيغطي المسار الصح ده وتم التأكد إنه **بيفشل من غير الحماية** (مش اختبار فاضي).

---

## 5. مؤجّل عن قصد — مش جزء من المرحلتين دول

| البند | ليه | هيتعمل فين |
|---|---|---|
| Controllers / Routes / Actions | Phase 1 هي طبقة الداتابيز بس | Phase 2 وبعدها |
| اختبار تزامن حقيقي (10 موافقات متوازية) | محتاج `ApproveSeatRequest` Action اللي لسه ماتكتبش. جرّبنا اختبار باتصالين PDO لكنه بيتعارض مع إن `RefreshDatabase` بيلف كل اختبار في transaction مش متعمول له commit | Phase 7 |
| PHPStan level 8 | **عطلان في البيئة دي** — بيقع من غير أي رسالة خطأ حتى مع ملف واحد و level 0 (PHP 8.5.7 + phpstan 2.2.14). Pint شغال وبيتنفذ كبديل | محتاج نجرّبه على بيئة تانية |
| Docker compose / CI pipeline | مش لازم لبدء Phase 2 | Phase 15 أو عند الطلب |
| `seats_total <= vehicle.seats` | قيد بين جدولين — MySQL CHECK مايقدرش يعمله | الـ Action في Phase 5 |
| `SUM(refunds) <= payment.amount` | نفس السبب (aggregate) | الـ Action في Phase 8 |
| منع إيقاف إشعارات `safety` | قاعدة عمل مش قاعدة schema | الـ Action في Phase 12 |

---

## 6. إزاي تتأكد بنفسك

```bash
composer install
php artisan migrate:fresh --seed    # لازم يعدّي من الصفر من غير أخطاء
php artisan test                    # 355 اختبار، كلهم لازم يعدّوا
php vendor/bin/pint --test          # لازم يطلع passed
```

**اختبارات تستاهل تبصّ عليها بالذات:**
- `tests/Feature/ModelFactorySmokeTest.php` — بيتأكد إن الـ 71 موديل كلهم بيشاوروا على جداول موجودة وبيقدروا يتخزنوا
- `tests/Feature/PrivacyGuaranteesTest.php` — وعود الخصوصية (رقم الموبايل، الاسم الكامل، الجندر، درجة الثقة)
- `tests/Feature/Domains/Commute/ScheduledTripTest.php` — اختبار التوقيت الصيفي المصري (أخطر فخ في المشروع)
- `tests/Feature/Domains/Payment/PaymentTest.php` — منع الخصم المزدوج + توازن التوزيع المالي

---

## 7. الخطوة الجاية

**Phase 2 — المصادقة والهوية** (الفصل 2 من الـ Bible): OTP (hash + غرض + حدود معدل) · تسجيل/دخول موحّد · PIN محلي بالكامل · تدوير refresh token مع كشف إعادة الاستخدام · إدارة الأجهزة · Consents versioning.

الداتابيز اللي محتاجاها Phase 2 **جاهزة بالكامل** (`users`, `otp_challenges`, `devices`, `auth_sessions`, `security_events`, `user_consents`) — الشغل الجاي كله في طبقة الـ Actions والـ Controllers.
