# RAFEEQ — المرجع الهندسي الكامل

> **الملف ده هو المصدر الوحيد للحقيقة.** لو أي حاجة في الكود اختلفت عنه، الملف ده هو الصح
> (أو الملف يتحدّث أولاً وبعدين الكود). اقراه بالترتيب من فوق لتحت وهتفهم المشروع كامل
> من الصفر لحد الإطلاق.
>
> **الإصدار:** 1.0 · **التاريخ:** 3 أغسطس 2026
> **بيكمّل:** `RAFEEQ_MASTER_PLAN.md` (القرارات والمراحل) — والملف ده بيشرح **التنفيذ**

---

# الفهرس

| # | القسم | بيجاوب على |
|---|---|---|
| **1** | [المنتج من الصفر](#part1) | إيه هو رفيق؟ ليه موجود؟ المصطلحات |
| **2** | [الدورة الكاملة بمثال حقيقي](#part2) | إيه اللي بيحصل بالظبط، خطوة بخطوة، في الداتابيز |
| **3** | [المعمار — Clean Architecture](#part3) | الكود بيتكتب فين وإزاي |
| **4** | [الداتابيز — كل جدول وكل عمود](#part4) | كل عمود يعني إيه ومثاله |
| **5** | [الفخاخ والأخطاء](#part5) | 64 غلطة هنقع فيها لو مخدناش بالنا |
| **6** | [الأمان](#part6) | إزاي نحمي المستخدم والبيانات |
| **7** | [الأداء](#part7) | إزاي يفضل سريع مع النمو |
| **8** | [الاختبارات](#part8) | إزاي نتأكد إنه شغال |
| **9** | [خطة التنفيذ خطوة بخطوة](#part9) | نبدأ منين وبأي ترتيب |

---
---

<a name="part1"></a>
# الجزء 1 — المنتج من الصفر

## 1.1 المشكلة

كل يوم الصبح، آلاف العربيات بتتحرك من نفس المناطق لنفس أماكن الشغل في نفس الميعاد،
وفي كل عربية **مقعد فاضي أو تلاتة**. وفي نفس الوقت، ناس تانية بتاخد مواصلات مزدحمة
أو تاكسي غالي على **نفس الطريق بالظبط**.

رفيق بيوصّل الاتنين.

## 1.2 اللي رفيق **مش** بيعمله

ده أهم قسم في الملف. لو فهمته غلط، هتبني منتج تاني خالص.

| ❌ رفيق مش كده | ✅ رفيق كده |
|---|---|
| تطلب عربية دلوقتي وتيجي | تدوّر على حد ماشي طريقك **بكرة الصبح** وتطلب مقعد |
| النظام بيوزّع السائق عليك | **السائقة** بتقرأ طلبك وتوافق أو ترفض |
| كل مرة سائق مختلف | **نفس المجموعة** كل يوم، بتعرفهم |
| بتدفع أجرة + surge | بتساهم في **تكلفة البنزين مقسومة** |
| رحلة لمرة واحدة | **التزام أسبوعي** (3 أيام على الأقل) |

**الجملة اللي لازم تفضل في دماغك:**
> السائقة **مش شغالة عندنا**. هي رايحة شغلها على أي حال. إحنا بنملى المقاعد الفاضية.

## 1.3 المصطلحات — احفظها

| المصطلح | يعني إيه | مثال |
|---|---|---|
| **Corridor** (مسار) | طريق شائع بين منطقتين في وقت معين، إحنا اللي بنعرّفه | `الرحاب ← القرية الذكية · أحد-خميس · 6:45–8:00 ص` |
| **Commute Offer** (عرض) | رحلة نشرتها سائقة | `نور بتنشر: الرحاب ← سمارت فيلدج، 7:05، 3 مقاعد، 80 ج.م` |
| **Schedule** (جدول) | قاعدة التكرار بتاعة العرض | `أحد + إثنين + تلات + أربع + خميس، لحد 31 ديسمبر` |
| **Scheduled Trip** (رحلة مجدولة) | **يوم واحد** متولّد من الجدول | `رحلة نور يوم الأحد 12 يناير 2026 الساعة 7:05` |
| **Demand** (طلب محفوظ) | راكب مالقاش حاجة، فحفظ طلبه عشان ننبهه بعدين | `مريم عايزة الرحاب ← سمارت فيلدج 7 ص` |
| **Seat Request** (طلب مقعد) | طلب محتاج موافقة السائقة | `مريم بتطلب مقعد تجريبي يوم الأحد` |
| **Booking** (حجز) | مقعد مؤكد في **رحلة مجدولة واحدة** | `مريم محجوزة في رحلة الأحد 12 يناير` |
| **Commute Group** (مجموعة) | السائقة + الركاب الدائمين، بتتكرر أسبوعيًا | `مجموعة الرحاب-سمارت فيلدج الصباحية: نور + مريم + هنا` |
| **Trip Session** (جلسة رحلة) | التنفيذ الفعلي: بدأت، GPS، خلصت | `رحلة الأحد بدأت 7:07، خلصت 7:58، 32 كم` |

### 🔑 القاعدة الذهبية
```
الراكب بيحجز scheduled_trip  ←  رحلة يوم واحد
الراكب مش بيحجز commute_offer  ولا  schedule
```
لأن: لو حجز الـ offer، هيبقى محجوز للأبد ومش هنعرف نتتبع يوم بيوم.

## 1.4 مين الشخصيات

**حساب واحد للشخص الواحد.** مفيش حساب سائقة وحساب راكبة.

```
users  (شخص، رقم موبايل واحد موثّق)
  ├─ قدرة الركوب      ← متاحة من أول يوم
  └─ قدرة القيادة     ← مقفولة، بتتفتح لما يعمل driver_profile ويتوثّق
```

في التطبيق زرار **"تبديل لسائقة / راكبة"** — نفس الحساب، نفس التقييم، نفس التاريخ.

## 1.5 الثقة — 4 مستويات

| المستوى | الاسم | إزاي يتوثّق | بيفتح إيه |
|---|---|---|---|
| 1 | رقم الموبايل | OTP | إنشاء الحساب والتصفح |
| 2 | الهوية الحكومية | صورة + OCR + مراجعة | **طلب مقعد** |
| 3 | سيلفي / Liveness | مقارنة بصورة الهوية | ثقة أعلى في النتائج |
| 4 | جهة العمل/الجامعة | إيميل الدومين، أو بادج بمراجعة | فلتر "نفس الجامعة" + أولوية |

**وللقيادة إضافيًا:** رخصة قيادة سارية + رخصة سيارة + صور السيارة.

> ⚠️ رسالة لازم تظهر للمستخدم: *"الشارات بتقول إيه اللي اتفحص. مش بتضمن سلوك الشخص."*

## 1.6 الفلوس في سطرين

```
تكلفة الطريق 240 ج.م ÷ 3 ركاب = 80 ج.م للراكب
+ عمولة المنصة 3%
= 88 ج.م
لو رابع انضم → حصة كل واحد تنزل لـ ~68 ج.م
```

الدفع **بعد إتمام الرحلة**، بطريقتين:
- **أونلاين** → خصم تلقائي عبر Paymob، بيتقسّم بين السائقة وعمولتنا
- **كاش** → الراكب بيدي السائقة، وعمولتنا بتتسجل **دين على السائقة** يتخصم من أول تحويل أونلاين

---
---

<a name="part2"></a>
# الجزء 2 — الدورة الكاملة بمثال حقيقي

هنمشي مع **نور** (سائقة) و**مريم** (راكبة) من أول ثانية لآخر ثانية،
وهنشوف **إيه اللي بيتكتب في الداتابيز** في كل خطوة.

---

## المشهد 1 — نور بتنزّل التطبيق وتسجّل

### 1.1 فتح التطبيق أول مرة
مفيش جلسة → شاشات التعريف الثلاثة → **"ابدأ مع رفيق"**

### 1.2 إدخال الموبايل
تكتب `01012345678`

**السيرفر بيعمل إيه:**
```
1. يوحّد الرقم:   01012345678  →  +201012345678     (E.164)
2. يتأكد من الحدود:  الرقم ده طلب كام OTP آخر ساعة؟ الجهاز ده؟ الـ IP ده؟
3. يولّد كود عشوائي:  482931
4. يخزّن hash الكود  (مش الكود نفسه أبدًا)
5. يبعت SMS
6. يرجّع challengeId فقط  ← الكود عمره ما بيرجع للتطبيق
```

**في الداتابيز:**
```
otp_challenges
  id                = 01JQ...
  phone_e164        = "+201012345678"
  purpose           = "authentication"
  code_hash         = "$2y$12$..."          ← hash مش الكود
  expires_at        = الآن + دقيقتين
  attempt_count     = 0
  max_attempts      = 5
  status            = "pending"
```

> ⚠️ **الرد لازم يبقى نفسه** سواء الرقم موجود عندنا أو لأ — عشان محدش يقدر
> يعرف مين مسجّل عندنا (account enumeration).

### 1.3 إدخال الكود
تكتب `482931`

**السيرفر:**
```
1. يجيب الـ challenge بالـ id
2. لسه صالح؟ (مش منتهي، مش مستخدم، المحاولات < 5)
3. يقارن hash  ← مقارنة ثابتة الزمن (timing-safe)
4. يعلّم status = "verified"  ← مايتستخدمش تاني أبدًا
5. الرقم ده موجود في users؟
      لأ  → ينشئ user جديد
      أيوه → يسجّل دخول للموجود
6. يسجّل الجهاز
7. ينشئ جلسة
```

**في الداتابيز:**
```
users
  id                 = 01JQ_NOUR
  phone_e164         = "+201012345678"
  phone_verified_at  = 2026-08-03 09:14:22
  account_status     = "active"
  profile_status     = "not_started"
  preferred_language = "ar"

devices
  user_id           = 01JQ_NOUR
  device_public_id  = "a7f3..."          ← التطبيق بيولّده ويخزّنه محليًا
  platform          = "android"
  is_trusted        = true

sessions
  user_id             = 01JQ_NOUR
  device_id           = ...
  refresh_token_hash  = hash(refresh_token)   ← الأصل عند التطبيق بس
  access_expires_at   = الآن + 15 دقيقة
  refresh_expires_at  = الآن + 60 يوم

security_events
  event_type = "login_success"
  risk_level = "low"
```

### 1.4 إنشاء PIN
تختار `4471`

```
❌ الـ PIN عمره ما بيروح للسيرفر
✅ التطبيق بيولّد verifier محليًا ويخزّنه في Android Keystore / iOS Keychain
```
**في الداتابيز على السيرفر: لا شيء.** جدول `device_pins` (لو استخدمناه) بيسجّل بس
*إن* الجهاز عليه PIN، مش قيمته.

### 1.5 البيانات الأساسية
```
users (تحديث)
  full_name         = "نور حسن"
  public_first_name = "نور"          ← ده اللي الناس بتشوفه بس
  gender            = "woman"
  date_of_birth     = 1996-04-12
  org_type          = "work"
  organization_id   = 01JQ_SMARTVILLAGE
  registered_role   = "driver"
  profile_status    = "basic_complete"
```

---

## المشهد 2 — نور بتتوثّق كسائقة

### 2.1 الهوية
ترفع صورة الرقم القومي (وش + ضهر) + سيلفي

```
user_verifications
  user_id     = 01JQ_NOUR
  type        = "government_id"
  status      = "pending"

identity_documents
  kind        = "national_id_front"
  file_path   = "private/id/01JQ.../front.jpg"   ← disk خاص، مش public أبدًا
  file_hash   = "sha256:..."
  ocr_payload = {"name":"نور حسن ...","id":"296041..."}
```

**فحص تلقائي:** الاسم في الـ OCR = الاسم في البروفايل؟ الرقم القومي مكرر عند حد تاني؟

### 2.2 الرخصة والسيارة
```
driver_profiles
  user_id          = 01JQ_NOUR
  status           = "pending_review"
  national_id      = 🔒 مشفّر
  licence_number   = 🔒 مشفّر
  licence_expiry   = 2029-06-30

vehicles
  driver_profile_id   = ...
  make = "Kia" · model = "Sportage" · year = 2021 · colour = "فضي"
  plate_number        = "ق ط ٤٢١"       ← unique على مستوى المنصة
  seats               = 4
  is_active           = true             ← واحدة بس تبقى true
  verification_status = "pending"
```

### 2.3 مراجعة الأدمن
موظف التوثيق بيفتح الطلب من الداشبورد → يشوف المستندات → **موافقة**

```
driver_profiles.status      = "approved"
driver_profiles.verified_at = الآن
driver_profiles.reviewer_id = 01JQ_ADMIN_OMAR
vehicles.verification_status = "approved"

verification_logs           ← سجل دائم، مايتحذفش
  entity_type = "driver_profile"
  admin_id    = 01JQ_ADMIN_OMAR
  action      = "approve"
  old_value   = {"status":"pending_review"}
  new_value   = {"status":"approved"}

admin_actions               ← سجل التدقيق العام
  action      = "approve_driver"
```
📱 إشعار لنور: *"تم اعتماد حسابك كسائقة"*

---

## المشهد 3 — نور بتنشر رحلتها

### 3.1 المسار
`الرحاب، القاهرة الجديدة` ← `القرية الذكية مبنى B6`

**السيرفر بينادي Google مرة واحدة بس:**
```
Directions API → polyline + 32 كم + 48 دقيقة
يتخزّن في route_cache (30 يوم)  ← لأن الطريق مش هيتغير
```

### 3.2 الجدول والمقاعد والسعر
```
الأيام: أحد إثنين تلات أربع خميس    الميعاد: 7:05 ص
البداية: 2026-08-09    النهاية: 2026-12-31
المقاعد: 3    السعر: 80 ج.م    أقصى انعطاف: 10 دقايق
القواعد: ممنوع تدخين · هادئة · تكييف
الجمهور: نساء فقط
```

**في الداتابيز:**
```
commute_offers
  id                    = 01JQ_OFFER
  driver_profile_id     = ...
  vehicle_id            = ...
  corridor_id           = 01JQ_CORRIDOR_REHAB_SV
  commute_type          = "recurring"
  direction             = "to_work"
  status                = "published"
  seats_total           = 3
  price_per_seat        = 8000                 ← بالقروش! مش 80.00
  max_detour_minutes    = 10
  audience              = "women_only"
  route_polyline        = "}_p~iF~ps|U_ulL..."
  bbox_min_lat = 30.02  bbox_max_lat = 30.08   ← للفلترة السريعة
  bbox_min_lng = 31.20  bbox_max_lng = 31.49
  published_at          = 2026-08-03 11:02

commute_locations
  (type=origin,      sequence=0, point=..., address="الرحاب بوابة 2")
  (type=destination, sequence=99, point=..., address="القرية الذكية B6")

commute_schedules
  days_mask       = 0b0111110    ← أحد..خميس (bitmask)
  departure_time  = "07:05:00"   ← ⚠️ وقت محلي، مش UTC
  timezone        = "Africa/Cairo"
  start_date      = 2026-08-09
  end_date        = 2026-12-31

commute_rules
  (key="nonsmoking", value=true)
  (key="quiet",      value=true)
  (key="ac",         value=true)

commute_groups                   ← المجموعة بتتولد مع النشر
  commute_offer_id = 01JQ_OFFER
  name             = "الرحاب ← سمارت فيلدج · صباحًا"
  min_commitment_days_per_week = 3

group_members
  user_id = 01JQ_NOUR   role = "driver"   joined_at = الآن
```

### 3.3 توليد الرحلات المجدولة
Job بيولّد **أفق 30 يوم بس** (مش لحد ديسمبر — ده هيعمل ملايين الصفوف):

```
scheduled_trips
  (trip_date=2026-08-09, departure_at=2026-08-09 04:05 UTC, seats_total=3, seats_taken=0)
  (trip_date=2026-08-10, departure_at=2026-08-10 04:05 UTC, ...)
  ... 22 رحلة (أيام العمل في الـ 30 يوم)
```

> ⚠️ **لاحظ 04:05 UTC** = 07:05 بتوقيت القاهرة الصيفي (UTC+3).
> في نوفمبر بعد انتهاء التوقيت الصيفي، نفس الـ 07:05 المحلية = **05:05 UTC**.
> **عشان كده بنخزن الوقت المحلي + التايم زون، وبنحسب الـ UTC وقت التوليد.**
> تفاصيل الفخ ده في [الجزء 5، فخ #12](#part5).

---

## المشهد 4 — مريم بتدوّر

### 4.1 البحث
```
من: الرحاب    إلى: القرية الذكية
أيام: أحد–خميس    الوصول: 7:00–7:20 ص
فلاتر: نساء فقط ✓ · أقصى مشي 15 د · أقصى انعطاف 15 د
```

### 4.2 المحرك بيشتغل على 4 خطوات

```
الخطوة 1 — فلترة صارمة (SQL عادي، بدون جغرافيا)     ~50,000 → ~800 صف
  status = 'published'  AND  seats_taken < seats_total
  AND  الجمهور مسموح  AND  اليوم مطابق
  AND  bbox متقاطع    ← أعمدة عادية مفهرسة
  AND  driver_profile.status = 'approved'
  AND  السائقة مش محظورة من مريم والعكس

الخطوة 2 — مسافة المشي (MySQL native)              ~800 → ~40 صف
  ST_Distance_Sphere(نقطة مريم, نقطة الالتقاء) <= 15 دقيقة مشي

الخطوة 3 — Google للناجين بس                        ~40 استدعاء (أغلبها كاش)
  حساب الانعطاف: كام دقيقة زيادة على نور لو مرّت على مريم؟

الخطوة 4 — التنقيط من 100
```

### 4.3 نتيجة نور
| المعيار | الوزن | القيمة | النقاط |
|---|---:|---|---:|
| تطابق الطريق | 30 | 94% | 28 |
| الجدول | 25 | ±5 دقايق | 24 |
| الانعطاف | 15 | 6 دقايق | 15 |
| الجمهور | 10 | نساء ✓ | 10 |
| الراحة | 10 | هادئة ✓ | 9 |
| السعر | 5 | ضمن الميزانية | 5 |
| الموثوقية | 5 | 98% | 5 |
| **الإجمالي** | **100** | | **96** |

```
match_scores  (كاش، صالح ساعة)
  demand_signature = hash(بارامترات البحث)
  commute_offer_id = 01JQ_OFFER
  total = 96 · overlap = 28 · schedule = 24 · detour = 15 ...
  expires_at = الآن + 60 دقيقة
```

> 🔒 **التعارض الصارم:** لو نور حطّت `women_only` ومريم مش `woman` →
> **بتتشال من الخطوة 1 خالص**. مش بتاخد 6 من 10. الفرق ده أمان مش تفضيل.

### 4.4 لو مفيش نتائج
```
commute_demands
  passenger_user_id = 01JQ_MARIAM
  origin_point / destination_point / days_mask / preferred_time_window
  max_walk_minutes = 15 · audience_preference = "women_only"
  status = "active"
```
وبعدين job بيشتغل مع كل عرض جديد يتنشر → لو طابق → إشعار.

> 🔒 **الطلبات دي خاصة تمامًا.** السائقة **عمرها ما بتشوف** طلبات الركاب.
> ده الفرق بين رفيق وبين تطبيقات التوصيل.

---

## المشهد 5 — مريم بتطلب مقعد

### 5.1 بوابة التوثيق
مريم موثّقة بالموبايل بس (مستوى 1). تضغط "اطلبي مقعد" →

```
هل مريم عندها government_id = approved؟
   لأ  → نحفظ نيتها (pendingIntent = 'seat_request')
          نوديها للتوثيق
          بعد ما تخلّص → ترجع لنفس الشاشة تلقائيًا
```

### 5.2 الطلب
```
الالتزام: تجريبي (رحلة واحدة)
اليوم: الأحد 9 أغسطس
نقطة الالتقاء: بوابة الرحاب 2
رسالة: "أهلاً نور! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك."
☑ موافقة على قواعد المجموعة       ← إجبارية
طريقة الدفع: كاش
```

```
seat_requests
  passenger_user_id   = 01JQ_MARIAM
  commute_offer_id    = 01JQ_OFFER
  commitment          = "trial"
  requested_days_mask = 0b0000010          ← الأحد بس
  meeting_preference  = "gate"
  intro_message       = "أهلاً نور! ..."
  agreed_to_rules_at  = 2026-08-03 14:22
  payment_type        = "cash"
  status              = "pending"
```
📱 إشعار لنور: *"مريم طلبت مقعد تجريبي يوم الأحد"*

### 5.3 لو مريم طلبت نقطة التقاء مخصصة
```
pickup_point_requests
  seat_request_id  = ...
  proposed_point   = POINT(31.4102 30.0361)
  added_minutes    = 4          ← محسوبة من Google
  added_km         = 1.8
  status           = "pending"
```
نور بتشوف: *"منطقة المستثمرين الجنوبية · +4 دقايق / 1.8 كم"*
وتختار: **وافق** / **اقترح بديل** / **ارفض**

---

## المشهد 6 — نور بتوافق ⚠️ أخطر عملية في النظام

ده المكان اللي **لازم يبقى داخل transaction مع قفل**، وإلا هيحصل حجز مزدوج.

```php
DB::transaction(function () {
    // 1. اقفل الرحلة المجدولة — أي طلب تاني هيستنى هنا
    $trip = ScheduledTrip::lockForUpdate()->find($tripId);

    // 2. اتأكد تاني جوّه القفل (مش قبله!)
    if ($trip->seats_taken >= $trip->seats_total) {
        throw new NoSeatsAvailableException();   // → قائمة انتظار
    }

    // 3. اتأكد إن السائقة لسه معتمدة والرخصة سارية
    // 4. اتأكد إن مفيش حجز مكرر لنفس الراكب
    // 5. اعمل الحجز
    // 6. زوّد المقاعد
    $trip->increment('seats_taken');
    // 7. ضيف العضو للمجموعة
    // 8. علّم الطلب مقبول
});
// 9. الإشعارات والـ side effects → بره الـ transaction، في queue
```

**النتيجة في الداتابيز:**
```
seat_requests
  status       = "approved"
  responded_by = 01JQ_NOUR
  responded_at = 2026-08-03 18:40

bookings
  id                  = 01JQ_BOOKING
  scheduled_trip_id   = رحلة الأحد 9 أغسطس
  passenger_user_id   = 01JQ_MARIAM
  driver_profile_id   = ...
  commute_group_id    = ...
  seats_reserved      = 1
  price_snapshot      = 8000      ← 🔒 متجمّد. لو نور غيّرت السعر بكرة، مريم مش بتتأثر
  platform_fee_snapshot = 240     ← 3%
  payment_type        = "cash"
  status              = "confirmed"

scheduled_trips
  seats_taken  = 0 → 1

group_members
  user_id = 01JQ_MARIAM   role = "trial"   joined_at = الآن

booking_events
  event_type = "confirmed"   actor_type = "driver"   actor_id = 01JQ_NOUR

conversations                    ← المحادثة بتفتح دلوقتي بس
  booking_id = 01JQ_BOOKING   opened_at = الآن
```

### 6.1 لو المقاعد خلصت → قائمة انتظار
```
seat_requests
  status            = "waitlisted"
  waitlist_position = 1
```

---

## المشهد 7 — يوم الرحلة (الأحد 7:05 ص)

| الوقت | الحدث | الداتابيز |
|---|---|---|
| **6:35** | تذكير للطرفين | `notifications` |
| **6:58** | نور: "ابدأ الرحلة" | التحقق: حساب معتمد؟ رخصة سارية؟ سيارة معتمدة؟ GPS شغال؟<br>`trip_sessions` (started_at, status='preparing') |
| **7:01** | نور بتتحرك | `status = 'en_route'` · GPS كل 5 ثواني |
| | | **الموقع بيروح Redis + بث WebSocket. مش MySQL كل مرة.**<br>Job كل 30 ثانية بيكتب دفعة واحدة في `trip_locations` |
| **7:06** | نور وصلت البوابة | إشعار لمريم · `trip_wait_timers` (grace 300 ثانية) |
| **7:07** | مريم ركبت | نور بتضغط "مريم وصلت"<br>`attendance` (status='present', confirmed_by='driver', gps_corroborated=true) |
| **7:08** | تحرّك | `status = 'in_progress'` |
| **7:56** | وصلوا | نور: "إنهاء الرحلة" |

### إنهاء الرحلة — خط أنابيب مش عملية واحدة
```
TripCompleted  (event)
    ↓  كل واحد job منفصل، idempotent، بيتعاد لو فشل
    ├─ إغلاق trip_sessions (completed_at, distance, duration)
    ├─ تثبيت attendance
    ├─ bookings.status = 'completed'
    ├─ إنشاء طلبات التقييم للطرفين
    ├─ تحديث إحصاءات المجموعة (on_time_pct, rides_together)
    ├─ ⏱ بعد ساعتين: بدء التحصيل
    └─ analytics_events
```

> ⚠️ **ليه مش كله في request واحد؟** لو السيرفر وقع في النص، هتلاقي رحلة خلصت
> بس الفلوس متخصمتش أو التقييم مطلعش. الـ jobs بتتعاد لوحدها.

---

## المشهد 8 — الفلوس

### 8.1 كاش (حالة مريم)
```
7:58  الرحلة خلصت
9:58  (بعد ساعتين — نافذة الاعتراض بتبدأ)
      نور بتأكد: "استلمت 88 ج.م كاش"

payments
  booking_id            = 01JQ_BOOKING
  amount                = 8800
  platform_fee          = 240
  driver_amount         = 8560
  payment_type          = "cash"
  status                = "settled_offline"

driver_fee_ledger                       ← 🔒 غير قابل للتعديل
  driver_profile_id = ...
  booking_id        = 01JQ_BOOKING
  type              = "fee_due"
  amount            = 240
  balance_after     = 240

driver_balances
  outstanding_fee_piastres = 240
```
📱 لمريم: *"تم تسجيل رحلتك · 88 ج.م كاش"* + زرار **"مش صح"** (24 ساعة)

### 8.2 أونلاين (لو كانت اختارت كارت)
```
payments.status = "captured"
Paymob بيقسّم:  السائقة 85.60 ج.م  ·  إحنا 2.40 ج.م
لو عليها دين كاش سابق → بيتخصم من نصيبها
```

### 8.3 لما الدين يعدّي الحد
```
driver_balances.outstanding_fee_piastres > 20000   (200 ج.م)
  → is_blocked_from_publishing = true
  → مايقدرش ينشر رحلات جديدة لحد ما يسدد
  → الرحلات المحجوزة بالفعل بتكمل عادي
```

### 8.4 لو مريم اعترضت
```
attendance.disputed_at = ...   disputed_reason = "أنا ملحقتش أركب"
  → يوقف التحصيل
  → support_tickets جديدة
  → الدعم بيشوف trip_locations: موبايل مريم كان جوه المسار؟
  → قرار: تأكيد أو إلغاء + رد الفلوس
```

---

## المشهد 9 — التقييم (Double-blind)

```
ratings  (مريم → نور)
  stars = 5 · comment = "..." · visible_at = NULL   ← مخفي
ratings  (نور → مريم)
  stars = 5 · visible_at = NULL

لما الاتنين يقيّموا  (أو تعدّي 7 أيام):
  visible_at = الآن   للاتنين مع بعض
```

> 🔒 التصفية دي **في السيرفر**، مش في التطبيق. لو الـ API رجّع تقييم مخفي،
> الـ double-blind اتكسر حتى لو الشاشة مبتعرضهوش.

بعد كده job بيعيد حساب `trust_scores` للطرفين.

---

## المشهد 10 — مريم بتنضم للمجموعة دايم

```
بعد الرحلة التجريبية الناجحة:
مريم تضغط "انضمي للمجموعة"  →  seat_requests جديد (commitment='recurring')
نور توافق                    →  group_members.role: trial → member
                             →  bookings بتتولد لكل الرحلات المجدولة الجاية
```

---

## المشهد 11 — لما حاجة تبوظ

| السيناريو | إيه اللي بيحصل |
|---|---|
| **نور لغت الرحلة** | كل الحجوزات `cancelled_by_driver` · رد كامل · بحث تلقائي عن بديل · إشعار للكل |
| **مريم متأخرة** | عدّاد 5 دقايق · نور تمدد أو تمشي · `passenger_no_show` · رسوم إلغاء متأخر |
| **رخصة نور انتهت** | job يومي بيوقف نشر رحلات جديدة · الرحلات القريبة بتتلغى بإشعار |
| **مريم ضغطت SOS** | `sos_events` + `safety_events` · إشعار للأوصياء · حالة حرجة في الداشبورد · عداد الاستجابة بيبدأ |
| **السيارة اتوقفت** | `commute_offers` بتتحول `paused` تلقائيًا |
| **الدفع فشل** | إعادة محاولة × 3 · بعدها تعليق الحجوزات الجاية |

---
---

<a name="part3"></a>
# الجزء 3 — المعمار (Clean Architecture)

## 3.1 المبدأ الأساسي

> **منطق العمل مايعرفش إنه في Laravel.**

يعني: قاعدة "مايصحش تحجز مقعد لو المقاعد خلصت" مالهاش دعوة بـ HTTP ولا Eloquent
ولا Blade. هي قاعدة عمل. لازم تبقى في مكان تقدر تختبره من غير ما تعمل request.

### الطبقات الأربعة

```
┌──────────────────────────────────────────────────────────────┐
│  Presentation   Controllers · Livewire · Resources · Requests│
│                 مايحتوي منطق عمل خالص. بيستقبل ويرد بس.       │
├──────────────────────────────────────────────────────────────┤
│  Application    Actions (حالات الاستخدام) · DTOs · Events    │
│                 "احجز مقعد" — الخطوات بالترتيب                │
├──────────────────────────────────────────────────────────────┤
│  Domain         Entities · Value Objects · Enums · Rules     │
│                 القواعد النقية. مفيش Eloquent هنا.            │
├──────────────────────────────────────────────────────────────┤
│  Infrastructure Eloquent Models · External Services · Cache  │
│                 التنفيذ الفعلي: DB, Google, Paymob, SMS      │
└──────────────────────────────────────────────────────────────┘
```

**قاعدة الاعتماد:** السهم دايمًا لتحت. الـ Domain مايعرفش أي حاجة فوقه.

## 3.2 هيكل المجلدات

```
app/
├── Domains/
│   ├── Identity/
│   │   ├── Models/          User.php  Device.php  Session.php  OtpChallenge.php
│   │   ├── Actions/         RequestOtp.php  VerifyOtp.php  RefreshSession.php
│   │   ├── DTOs/            PhoneNumber.php  OtpRequestData.php
│   │   ├── Enums/           AccountStatus.php  ProfileStatus.php
│   │   ├── Events/          UserRegistered.php  SessionRevoked.php
│   │   ├── Listeners/       WriteSecurityEvent.php
│   │   ├── Policies/        UserPolicy.php
│   │   ├── Rules/           EgyptianPhone.php  StrongPin.php
│   │   ├── Exceptions/      OtpExpiredException.php
│   │   └── Support/         PhoneNormalizer.php
│   │
│   ├── Verification/    ├── Driver/      ├── Geo/         ├── Commute/
│   ├── Matching/        ├── Booking/     ├── Group/       ├── Payment/
│   ├── Trip/            ├── Safety/      ├── Rating/      ├── Notification/
│   ├── Admin/           └── Analytics/
│
├── Http/
│   ├── Controllers/Api/V1/     AuthController.php  SearchController.php ...
│   ├── Requests/Api/V1/        RequestOtpRequest.php ...
│   ├── Resources/Api/V1/       UserResource.php  MatchResource.php ...
│   ├── Middleware/             EnsureVerifiedIdentity.php  ForceJsonResponse.php
│   └── Responses/              ApiResponse.php        ← الـ envelope الموحّد
│
├── Livewire/Admin/             Dashboard.php  LiveTrips.php  VerificationQueue.php ...
├── Jobs/                       GenerateScheduledTrips.php  CaptureBookingPayment.php ...
├── Console/Commands/
└── Providers/
```

### ليه Domains مش MVC عادي؟
لما تيجي تدوّر على "منطق الحجز"، هتلاقيه كله في `app/Domains/Booking/` —
مش موزّع على `Controllers` و `Models` و `Services` في تلات مجلدات مختلفة.

## 3.3 نمط الـ Action — قلب الكود

**كل حالة استخدام = كلاس واحد، ميثود واحدة، مسؤولية واحدة.**

```php
namespace App\Domains\Booking\Actions;

final readonly class ApproveSeatRequest
{
    public function __construct(
        private SeatAvailabilityChecker $availability,
        private CreateBookingAction     $createBooking,
        private AddMemberToGroupAction  $addMember,
    ) {}

    /** @throws NoSeatsAvailableException|DriverNotEligibleException */
    public function execute(SeatRequest $request, User $approver): Booking
    {
        return DB::transaction(function () use ($request, $approver) {
            $trip = ScheduledTrip::lockForUpdate()->findOrFail($request->scheduled_trip_id);

            $this->availability->assertHasSeats($trip, $request->seats);
            $this->availability->assertDriverStillEligible($trip);
            $this->availability->assertNoDuplicateBooking($trip, $request->passenger_user_id);

            $booking = $this->createBooking->execute($request, $trip);
            $trip->increment('seats_taken', $request->seats);
            $this->addMember->execute($request);

            $request->update([
                'status'       => SeatRequestStatus::Approved,
                'responded_by' => $approver->id,
                'responded_at' => now(),
            ]);

            return $booking;
        });
        // الإشعارات في listener على BookingConfirmed — مش هنا
    }
}
```

### قواعد الـ Actions
| ✅ افعل | ❌ لا تفعل |
|---|---|
| ميثود واحدة عامة اسمها `execute` أو `handle` | أكتر من حالة استخدام في كلاس |
| `final readonly` + constructor injection | `new` جوّه الكلاس |
| ترمي exceptions من الدومين | ترجّع `null` أو `false` عند الفشل |
| ترجّع كائن أو DTO | ترجّع `Response` أو `redirect()` |
| تعرف عن الـ transaction | تبعت إشعارات جوّه الـ transaction |

## 3.4 الـ Controller — رفيع جدًا

```php
final class SeatRequestController extends Controller
{
    public function approve(
        ApproveSeatRequestRequest $request,     // ← التحقق
        SeatRequest $seatRequest,               // ← route model binding
        ApproveSeatRequest $action,             // ← المنطق
    ): JsonResponse {
        $this->authorize('approve', $seatRequest);          // ← الصلاحية

        $booking = $action->execute($seatRequest, $request->user());

        return ApiResponse::success(new BookingResource($booking), 201);
    }
}
```
**4 سطور.** التحقق في Form Request، الصلاحية في Policy، المنطق في Action،
شكل الخرج في Resource.

> 🚫 **ممنوع في الـ Controller:** استعلامات، حسابات، `if` على منطق عمل، إشعارات، transactions.

## 3.5 الـ Value Objects — امنع الأخطاء بالأنواع

```php
final readonly class Money
{
    private function __construct(public int $piastres) {}   // ← int دايمًا

    public static function fromPiastres(int $p): self
    {
        if ($p < 0) throw new InvalidArgumentException('Money cannot be negative');
        return new self($p);
    }
    public static function fromPounds(float $pounds): self
    {
        return new self((int) round($pounds * 100));
    }

    public function plus(self $o): self  { return new self($this->piastres + $o->piastres); }
    public function percentage(float $p): self { return new self((int) round($this->piastres * $p / 100)); }
    public function format(): string     { return number_format($this->piastres / 100, 2) . ' ج.م'; }
}
```

**كده مستحيل** حد يجمع 80.00 على 8000 بالغلط. النوع نفسه بيمنع الغلطة.

نفس الفكرة لـ: `PhoneNumber` · `Coordinate` · `TimeWindow` · `DaysMask` · `MatchScore`

## 3.6 الـ Enums — مفيش strings سايبة

```php
enum BookingStatus: string
{
    case Pending             = 'pending';
    case Confirmed           = 'confirmed';
    case Completed           = 'completed';
    case CancelledByPassenger = 'cancelled_by_passenger';
    case CancelledByDriver   = 'cancelled_by_driver';
    case Expired             = 'expired';
    case NoShow              = 'no_show';

    /** الانتقالات المسموحة — آلة الحالة في مكان واحد */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending   => [self::Confirmed, self::Expired, self::CancelledByPassenger],
            self::Confirmed => [self::Completed, self::NoShow,
                                self::CancelledByPassenger, self::CancelledByDriver],
            default         => [],   // نهائية
        }, true);
    }

    public function isTerminal(): bool { return $this->canTransitionTo(...) === []; }
    public function label(): string    { return __("booking.status.{$this->value}"); }
}
```

> 🔒 **كل تغيير حالة لازم يعدّي على `canTransitionTo`.** كده مستحيل رحلة ملغية
> ترجع "مكتملة" بالغلط.

## 3.7 الأحداث والـ Listeners — الآثار الجانبية

```
BookingConfirmed  (event)
    ├─→ NotifyDriver            (queued)
    ├─→ NotifyPassenger         (queued)
    ├─→ OpenConversation        (queued)
    ├─→ CloseMatchingDemand     (queued)
    └─→ RecordAnalyticsEvent    (queued)
```

**ليه؟** لو ضفنا بكرة "ابعت رسالة واتساب"، مش هنلمس `ApproveSeatRequest` خالص.
ولو الإشعار فشل، الحجز مش بيتلغى.

## 3.8 الاتفاقيات

### شكل الرد الموحّد
```json
// نجاح
{ "success": true, "data": { ... }, "meta": { "page": 1, "total": 42 } }

// فشل
{ "success": false,
  "error": {
    "code": "SEAT_UNAVAILABLE",
    "message": "المقاعد خلصت في الرحلة دي",
    "fields": { "seats": ["المتاح 0 من 3"] }
  } }
```

### التسمية
| العنصر | القاعدة | مثال |
|---|---|---|
| الجداول | جمع · snake_case | `commute_offers` |
| الأعمدة | مفرد · snake_case | `driver_profile_id` |
| المفاتيح الأجنبية | `{singular}_id` | `scheduled_trip_id` |
| Boolean | `is_` / `has_` / `can_` | `is_active` |
| التواريخ | `_at` (لحظة) · `_date` (يوم) | `verified_at` · `trip_date` |
| الفلوس | `_piastres` **دايمًا** | `price_piastres` |
| Actions | فعل + مفعول | `ApproveSeatRequest` |
| Events | ماضي | `BookingConfirmed` |
| Jobs | فعل أمر | `GenerateScheduledTrips` |

### أدوات إلزامية
```
Pint          تنسيق الكود      (يشتغل في CI)
PHPStan       level 8          (يشتغل في CI)
Pest          الاختبارات       (تغطية ≥ 80% للدومين)
Scramble      OpenAPI          (يتولّد تلقائيًا)
Rector        ترقية آمنة
```

### قواعد صارمة في الكود
```php
// bootstrap/app.php
Model::preventLazyLoading();          // ← يفجّر N+1 وقت التطوير
Model::preventSilentlyDiscardingAttributes();
Model::shouldBeStrict();              // في local و staging
DB::prohibitDestructiveCommands($app->isProduction());
```

## 3.9 قاعدة الـ Geo — كل الجغرافيا وراء باب واحد

```php
interface GeoQueryEngine
{
    public function distanceMeters(Coordinate $a, Coordinate $b): int;
    public function routeBetween(Coordinate $from, Coordinate $to, array $via = []): Route;
    public function detourMinutes(Route $original, Coordinate $pickup): float;
    public function overlapPercent(Route $a, Route $b): float;
}
```
`GoogleGeoEngine` النهارده · `PostGisGeoEngine` بعدين · `FakeGeoEngine` في الاختبارات.

> 🚫 **ممنوع منعًا باتًا** أي `ST_*` أو استدعاء Google خارج `app/Domains/Geo/`.
> ده الشرط الوحيد اللي بيخلّي الهجرة لـ PostGIS ممكنة.

---
---

<a name="part4"></a>
# الجزء 4 — الداتابيز: كل جدول وكل عمود

## 4.0 القواعد العامة لكل الجداول

### الأعمدة القياسية
كل جدول عنده — إلا لو مذكور غير كده:
| العمود | النوع | الشرح |
|---|---|---|
| `id` | `ULID (char 26)` | معرّف. **ULID مش auto-increment** — عشوائي بس مرتب زمنيًا، بيمنع تخمين الأرقام |
| `created_at` | `timestamp` | UTC |
| `updated_at` | `timestamp` | UTC |

### قواعد ملزمة
```
1. كل الأوقات UTC في الداتابيز.  العرض بس بتوقيت Africa/Cairo
2. كل الفلوس  BIGINT بالقروش.    ممنوع DECIMAL أو FLOAT نهائيًا
3. كل الـ enums  VARCHAR + PHP enum cast.  مش ENUM من MySQL (تعديله مؤلم)
4. الترميز  utf8mb4 · الترتيب utf8mb4_unicode_ci   ← عشان العربي والإيموجي
5. كل foreign key ليها index.  Laravel مش بيعمله لوحده
6. الحذف الناعم (deleted_at) للكيانات اللي ليها تاريخ مالي أو قانوني بس
7. الجداول المعلّمة 🔒 IMMUTABLE: مفيش UPDATE ولا DELETE — بس INSERT
```

### دليل الرموز
| الرمز | المعنى |
|---|---|
| 🔑 | مفتاح أساسي أو فريد |
| 🔗 | مفتاح أجنبي |
| 🔒 | مشفّر أو غير قابل للتعديل |
| ⚡ | لازم يبقى عليه index (استعلام متكرر) |
| ⚠️ | فيه فخ — راجع الجزء 5 |

---

## المجموعة 1 — الهوية والجلسات

### `users` — الشخص

| العمود | النوع | Null | الشرح | مثال |
|---|---|:---:|---|---|
| 🔑 `id` | ULID | ✗ | معرّف الشخص الدائم. بيتربط بيه كل حاجة | `01JQ8F3K...` |
| 🔑⚡ `phone_e164` | varchar(20) | ✗ | الرقم بصيغة E.164 موحّدة. **هوية الدخول** | `+201012345678` |
| `phone_verified_at` | timestamp | ✓ | إمتى اتأكد الرقم بالـ OTP | `2026-08-03 09:14:22` |
| `full_name` | varchar(100) | ✓ | الاسم الكامل — **داخلي**، للمطابقة مع الهوية | `نور حسن محمد` |
| `public_first_name` | varchar(50) | ✓ | ⚠️ **ده اللي الأعضاء بيشوفوه بس** | `نور` |
| `profile_photo_path` | varchar(255) | ✓ | مسار على disk خاص. مش URL عام | `private/avatars/01JQ.../a.webp` |
| ⚡ `gender` | varchar(20) | ✗ | `woman` `man` `prefer_not_to_say`. ⚠️ **فلتر صارم** — مايتعرضش في الـ API | `woman` |
| `date_of_birth` | date | ✓ | التاريخ نفسه مش السن (السن بيتغير) | `1996-04-12` |
| `email` | varchar(150) | ✓ | اختياري. للإيصالات والاسترداد | `nour@example.com` |
| `email_verified_at` | timestamp | ✓ | | `NULL` |
| ⚡ `account_status` | varchar(30) | ✗ | `active` `suspended` `pending_deletion` `deleted` | `active` |
| `suspension_reason` | varchar(255) | ✓ | فئة عامة، **من غير تفاصيل أمنية داخلية** | `تحقيق في بلاغ سلامة` |
| `profile_status` | varchar(30) | ✗ | `not_started` `basic_complete` | `basic_complete` |
| `org_type` | varchar(20) | ✓ | `work` `university` | `work` |
| 🔗 `organization_id` | ULID | ✓ | جهة العمل/الجامعة | `01JQ_SMARTVILLAGE` |
| `registered_role` | varchar(20) | ✗ | نيّته وقت التسجيل: `driver` `passenger` `both` | `driver` |
| `preferred_language` | varchar(5) | ✗ | `ar` `en` — بيحدد لغة الإشعارات | `ar` |
| `trust_level` | tinyint | ✗ | 0–4، محسوب. مخزّن للسرعة | `2` |
| `deleted_at` | timestamp | ✓ | حذف ناعم | `NULL` |

**فهارس:**
```sql
UNIQUE (phone_e164)                       -- ⚠️ راجع فخ #7 (الحذف الناعم)
INDEX (account_status, created_at)
INDEX (organization_id)
INDEX (gender, account_status)            -- للفلترة الصارمة
```

**ليه `full_name` و `public_first_name` منفصلين؟**
لأن الاسم الكامل لازم يطابق الهوية للتوثيق، بس **الأعضاء مايشوفوش اسمك الكامل**.
ده وعد صريح للمستخدم في الـ prototype: *"Full names and numbers stay private"*.

---

### `otp_challenges` — تحديات التحقق

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | ده اللي بيرجع للتطبيق كـ `challengeId` | `01JQ...` |
| ⚡ `phone_e164` | varchar(20) | الرقم المستهدف | `+201012345678` |
| `purpose` | varchar(30) | ⚠️ **مهم:** `authentication` `pin_reset` `phone_change` `high_risk_action` | `authentication` |
| 🔒 `code_hash` | varchar(255) | hash للكود. **الكود الخام عمره ما يتخزن** | `$2y$12$...` |
| ⚡ `expires_at` | timestamp | 2–5 دقايق | `2026-08-03 09:16:22` |
| `attempt_count` | tinyint | محاولات خاطئة | `0` |
| `max_attempts` | tinyint | من الإعدادات مش hard-coded | `5` |
| `resend_count` | tinyint | لمنع الإساءة | `0` |
| `status` | varchar(20) | `pending` `verified` `expired` `blocked` | `pending` |
| `device_fingerprint_hash` | varchar(64) | ربط بالجهاز | `sha256:...` |
| `ip_hash` | varchar(64) | ⚠️ **hash مش IP خام** (خصوصية) | `sha256:...` |
| `verified_at` | timestamp | لحظة الاستخدام الناجح | `NULL` |

> 🔒 **قاعدة أمنية:** الـ OTP اللي اتعمل لـ `authentication` **مايشتغلش** لـ `phone_change`.
> لازم تتأكد من `purpose` وقت التحقق، مش من الكود بس.

---

### `devices` — الأجهزة الموثوقة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | | |
| 🔗⚡ `user_id` | ULID | صاحب الجهاز | |
| ⚡ `device_public_id` | varchar(64) | **التطبيق** بيولّده ويخزّنه. ⚠️ مش معرّف إعلاني | `a7f3b2...` |
| `platform` | varchar(20) | `android` `ios` | `android` |
| `device_model` | varchar(80) | للدعم الفني | `Samsung SM-A546E` |
| `os_version` | varchar(30) | | `Android 14` |
| `app_version` | varchar(20) | لفرض التحديث الإجباري | `1.2.0` |
| 🔒 `push_token` | text | مشفّر. FCM/APNs | `🔒...` |
| `is_trusted` | boolean | | `true` |
| `has_local_pin` | boolean | ⚠️ **إن الجهاز عليه PIN — مش قيمته** | `true` |
| `biometric_enabled` | boolean | | `true` |
| `last_seen_at` | timestamp | | |
| `revoked_at` | timestamp | لما المستخدم يسحب الثقة (سرقة) | `NULL` |

---

### `sessions` — الجلسات

| العمود | النوع | الشرح |
|---|---|---|
| 🔑 `id` | ULID | |
| 🔗⚡ `user_id` | ULID | |
| 🔗 `device_id` | ULID | جلسة لكل جهاز |
| 🔒⚡ `refresh_token_hash` | varchar(64) | ⚠️ **hash فقط.** الأصل عند التطبيق |
| `token_family_id` | ULID | ⚠️ **مهم جدًا** — كل سلسلة تجديد ليها عائلة |
| `previous_session_id` | ULID | السلسلة، لكشف إعادة الاستخدام |
| `access_expires_at` | timestamp | 15 دقيقة |
| `refresh_expires_at` | timestamp | 60 يوم |
| `last_refreshed_at` | timestamp | |
| `revoked_at` | timestamp | |
| `revocation_reason` | varchar(50) | `user_logout` `token_reuse` `admin` `stolen_device` |

**آلية كشف السرقة (Refresh Token Rotation):**
```
كل تجديد → توكن جديد + القديم يتبطّل فورًا
لو جه توكن قديم مبطّل تاني → يعني حد نسخه
   → إلغاء كل الجلسات في نفس token_family_id
   → security_event بخطورة عالية + إشعار للمستخدم
```

---

### `security_events` 🔒 — سجل الأمان

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | | |
| 🔗 `user_id` | ULID | ✓ Null — لسه ما عرفناش مين | |
| 🔗 `device_id` | ULID | ✓ Null | |
| ⚡ `event_type` | varchar(50) | `otp_requested` `login_success` `login_failed` `pin_lockout` `token_reuse` `pin_reset` `device_revoked` | `token_reuse` |
| `risk_level` | varchar(10) | `low` `medium` `high` | `high` |
| `metadata` | json | ⚠️ **ممنوع أي سر هنا** | `{"attempts":5}` |
| `reviewed_at` | timestamp | مراجعة الأمان | `NULL` |

---

### `user_consents` — الموافقات القانونية

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗 `user_id` | ULID | | |
| `document_type` | varchar(30) | `terms` `privacy` `marketing` | `terms` |
| `document_version` | varchar(20) | ⚠️ **النسخة بالظبط** | `2026-05-01` |
| `accepted_at` | timestamp | دليل | |
| `withdrawn_at` | timestamp | للسحب | `NULL` |
| `source` | varchar(30) | `registration` `settings` `forced_reaccept` | `registration` |

> ❌ **ليه مش `accepted_terms = true` في `users`؟**
> لأن الشروط بتتغير. لازم تعرف **مين وافق على أنهي نسخة وإمتى** — ده مطلب قانوني.

---

## المجموعة 2 — التوثيق والثقة

### `organizations` — جهات العمل والجامعات

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| `name` / `name_ar` | varchar(150) | | `Smart Village` / `القرية الذكية` |
| `type` | varchar(20) | `work` `university` `school` | `work` |
| ⚡ `email_domain` | varchar(100) | ⚠️ لو موجود → توثيق تلقائي بإيميل | `smartvillage.com.eg` |
| `city` / `district` | varchar(80) | | `الجيزة` / `6 أكتوبر` |
| `location_point` | POINT | إحداثيات المقر | |
| `is_verified` | boolean | إحنا أكدنا الجهة نفسها | `true` |

---

### `user_verifications` — حالة كل نوع توثيق

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `user_id` | ULID | | |
| ⚡ `type` | varchar(30) | `phone` `government_id` `selfie` `organization` | `government_id` |
| ⚡ `status` | varchar(30) | `not_started` `pending` `approved` `rejected` `action_needed` `expired` | `pending` |
| `method` | varchar(30) | `otp` `ocr_review` `email_domain` `badge_review` | `ocr_review` |
| 🔗 `reviewed_by` | ULID | الأدمن | |
| `reviewed_at` | timestamp | | |
| `rejection_reason` | varchar(255) | ⚠️ **لازم تبقى قابلة للتنفيذ** | `صورة البادج مش واضحة — صوّريها في ضوء كويس` |
| `expires_at` | timestamp | التوثيق بينتهي (مثلاً الرخصة) | `2029-06-30` |
| `attempt_count` | tinyint | لمنع إغراق الطابور | `1` |

**فهرس فريد:** `UNIQUE (user_id, type)` — نوع واحد لكل مستخدم.

---

### `identity_documents` 🔒 — المستندات

| العمود | النوع | الشرح |
|---|---|---|
| 🔗 `user_verification_id` | ULID | |
| `kind` | varchar(40) | `national_id_front` `national_id_back` `selfie` `licence_front` `badge` |
| 🔒 `file_path` | varchar(255) | ⚠️ **disk خاص.** مفيش URL عام أبدًا |
| `file_hash` | varchar(64) | sha256 — لكشف التكرار وسلامة الملف |
| `mime_type` / `size_bytes` | varchar / int | للتحقق |
| `ocr_payload` | json 🔒 | مخرجات الـ OCR |
| `virus_scan_status` | varchar(20) | `pending` `clean` `infected` |
| `expires_at` | timestamp | انتهاء المستند نفسه |
| `purge_after` | date | ⚠️ **الحذف الإلزامي** (90 يوم بعد المغادرة) |

> 🔒 **3 قواعد لازم تتنفذ:**
> 1. الوصول عبر presigned URL عمره **5 دقايق** بس، وبعد `authorize()`
> 2. `purge_after` بيتنفذ بـ job يومي — مش وعد على ورق
> 3. مسار الملف **عمره ما يتسجل في اللوجز**

---

### `driver_profiles` — ملف السائقة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑🔗 `user_id` | ULID | ⚠️ **unique** — ملف واحد للشخص | |
| ⚡ `status` | varchar(30) | `draft` `pending_review` `approved` `rejected` `suspended` `expired_documents` | `approved` |
| 🔒⚡ `national_id_hash` | varchar(64) | ⚠️ hash للبحث عن التكرار | `sha256:...` |
| 🔒 `national_id_encrypted` | text | القيمة نفسها مشفّرة | `🔒...` |
| 🔒⚡ `licence_number_hash` | varchar(64) | نفس الفكرة | `sha256:...` |
| 🔒 `licence_number_encrypted` | text | | `🔒...` |
| ⚡ `licence_expiry` | date | ⚠️ **بيتفحص يوميًا + عند بدء كل رحلة** | `2029-06-30` |
| `verified_at` | timestamp | | |
| 🔗 `reviewer_id` | ULID | | |
| `rejection_reason` | varchar(255) | | |
| `completed_trips_count` | int | مخزّن للسرعة | `318` |
| `cancellation_rate` | decimal(5,2) | نسبة، محسوبة دوريًا | `2.10` |
| `on_time_rate` | decimal(5,2) | | `98.00` |

**ليه hash **و** encrypted للرقم القومي؟**
- `encrypted` → لعرضه للأدمن عند الحاجة (قابل للفك)
- `hash` → للبحث `WHERE national_id_hash = ?` عن التكرار **من غير فك التشفير**
لو خزّنت المشفّر بس، مش هتقدر تدوّر على التكرار غير بفك كل الصفوف.

---

### `vehicles` — المركبات

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `driver_profile_id` | ULID | | |
| `make` / `model` | varchar(50) | | `Kia` / `Sportage` |
| `year` | smallint | ⚠️ الحد الأدنى من الإعدادات | `2021` |
| `colour` | varchar(30) | مهم للتعرّف على العربية | `فضي` |
| 🔑⚡ `plate_number` | varchar(20) | ⚠️ **unique على المنصة كلها** | `ق ط ٤٢١` |
| `plate_normalized` | varchar(20) | ⚠️ بدون مسافات، أرقام موحّدة — للمقارنة | `قط421` |
| `seats` | tinyint | إجمالي مقاعد الركاب (2–8) | `4` |
| `transmission` / `fuel_type` | varchar(20) | | `automatic` / `petrol` |
| `photo_path` | varchar(255) | | |
| ⚡ `is_active` | boolean | ⚠️ **واحدة بس `true` لكل سائقة** | `true` |
| `verification_status` | varchar(20) | `pending` `approved` `rejected` `suspended` | `approved` |

```sql
UNIQUE (plate_normalized)
UNIQUE (driver_profile_id, is_active) WHERE is_active = 1   -- فهرس جزئي
```
> ⚠️ MySQL مبيدعمش الفهرس الجزئي. الحل: عمود مولّد
> `active_flag = CASE WHEN is_active THEN driver_profile_id ELSE NULL END` + `UNIQUE(active_flag)`

---

### `vehicle_documents` 🔒
نفس بنية `identity_documents` بس مربوطة بـ `vehicle_id`، والأنواع:
`registration` `insurance` `inspection`.

### `verification_logs` 🔒 — سجل كل قرار توثيق
| العمود | الشرح |
|---|---|
| `entity_type` / `entity_id` | إيه اللي اتغير |
| 🔗 `admin_id` | مين غيّره |
| `action` | `approve` `reject` `request_info` `suspend` |
| `old_value` / `new_value` | json — قبل وبعد |
| `reason` | إجباري عند الرفض |

> 🔒 **مايتحذفش أبدًا.** ده دفاعك القانوني لو حصلت مشكلة.

---

## المجموعة 3 — الجغرافيا والمسارات

### `places` — الأماكن

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| `name` / `name_ar` | varchar(150) | | `Rehab Gate 2` / `بوابة الرحاب ٢` |
| `type` | varchar(30) | `compound_gate` `street` `landmark` `station` `campus` `office` | `compound_gate` |
| ⚡ `point` | POINT SRID 4326 | ⚠️ SPATIAL INDEX | |
| `lat` / `lng` | decimal(10,7) | ⚠️ **مكرّرة عمدًا** — للفلترة السريعة بدون دوال | `30.0602` / `31.4915` |
| `city` / `district` | varchar(80) | | `القاهرة` / `القاهرة الجديدة` |
| `google_place_id` | varchar(120) | للربط والتحديث | `ChIJ...` |
| `is_public` | boolean | ⚠️ نقطة عامة ولا نقطة بيت خاصة | `true` |
| `usage_count` | int | للترتيب في البحث | `1420` |

---

### `corridors` — المسارات المتكررة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| `name` / `name_ar` | varchar(150) | | `الرحاب ← القرية الذكية` |
| 🔗 `origin_place_id` / `destination_place_id` | ULID | | |
| `window_start` / `window_end` | time | نافذة المغادرة | `06:45` / `08:00` |
| `days_mask` | tinyint | ⚠️ bitmask (سبت=1 … جمعة=64) | `62` (أحد–خميس) |
| `status` | varchar(20) | `healthy` `driver_short` `critical_gap` `escort_armed` | `healthy` |
| `drivers_count` / `seekers_count` | int | محسوبة دوريًا | `46` / `128` |
| `seat_fill_pct` | decimal(5,2) | | `92.00` |

**ليه bitmask للأيام؟** لأن `days_mask & 2 > 0` أسرع بكتير من `JSON_CONTAINS`
وبيسمح بفهرسة. `2` = الأحد.

---

### `route_cache` ⚡ — كاش المسارات (حرج للتكلفة)

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑⚡ `cache_key` | varchar(64) | ⚠️ `sha256(origin_4dp, dest_4dp, waypoints, mode)` | `sha256:...` |
| `origin_lat/lng` `dest_lat/lng` | decimal(10,4) | ⚠️ **مقرّبة لـ 4 خانات (~11 م)** | `30.0602` |
| `polyline` | text | المسار المشفّر من Google | `}_p~iF~ps|U...` |
| `distance_meters` | int | | `32400` |
| `duration_seconds` | int | بدون زحمة | `2880` |
| `duration_in_traffic_seconds` | int | ⚠️ **TTL قصير** (ساعة) | `3600` |
| `provider` | varchar(20) | | `google` |
| `fetched_at` / `expires_at` | timestamp | ⚠️ المسار 30 يوم، الزحمة ساعة | |
| `hit_count` | int | لمراقبة كفاءة الكاش | `847` |

> 💰 **التقريب لـ 4 خانات عشرية هو اللي بيوفّر الفلوس.** كل ساكني كمبوند الرحاب
> اللي بيطلعوا من نفس البوابة بيشاركوا **نفس صف الكاش**. من غير التقريب،
> كل مستخدم = استدعاء جديد.

---

## المجموعة 4 — عروض التنقّل

### `commute_offers` — العرض

| العمود | النوع | Null | الشرح | مثال |
|---|---|:---:|---|---|
| 🔑 `id` | ULID | ✗ | | `01JQ_OFFER` |
| 🔗⚡ `driver_profile_id` | ULID | ✗ | | |
| 🔗 `vehicle_id` | ULID | ✗ | ⚠️ لازم تكون معتمدة ونشطة | |
| 🔗⚡ `corridor_id` | ULID | ✓ | ينتمي لمسار معروف — للأولوية | |
| `commute_type` | varchar(20) | ✗ | `recurring` `one_time` | `recurring` |
| ⚡ `status` | varchar(20) | ✗ | `draft` `published` `paused` `archived` | `published` |
| `direction` | varchar(20) | ✗ | `to_work` `to_home` | `to_work` |
| `seats_total` | tinyint | ✗ | ⚠️ **لا يتجاوز `vehicles.seats`** | `3` |
| `price_per_seat_piastres` | int | ✗ | ⚠️ بالقروش. 80 ج.م = 8000 | `8000` |
| `currency` | char(3) | ✗ | | `EGP` |
| `max_detour_minutes` | tinyint | ✗ | أقصى انعطاف تقبله السائقة | `10` |
| `max_walk_minutes` | tinyint | ✗ | أقصى مشي تطلبه من الراكب | `10` |
| ⚡ `audience` | varchar(20) | ✗ | ⚠️ `women_only` `any_verified` — **فلتر صارم** | `women_only` |
| `min_trust_level` | tinyint | ✗ | أقل مستوى ثقة للانضمام | `2` |
| `allows_custom_pickup` | boolean | ✗ | تقبل طلبات نقاط التقاء مخصصة؟ | `true` |
| `route_polyline` | text | ✓ | ⚠️ **بيتحسب مرة واحدة عند النشر** | `}_p~iF...` |
| `route_distance_meters` | int | ✓ | | `32400` |
| `route_duration_seconds` | int | ✓ | | `2880` |
| ⚡ `bbox_min_lat` | decimal(10,7) | ✓ | ⚠️ **الأربعة دول أهم فهارس في النظام** | `30.0201` |
| ⚡ `bbox_max_lat` | decimal(10,7) | ✓ | الفلترة الأولى قبل أي جغرافيا | `30.0812` |
| ⚡ `bbox_min_lng` | decimal(10,7) | ✓ | | `31.2011` |
| ⚡ `bbox_max_lng` | decimal(10,7) | ✓ | | `31.4922` |
| `published_at` | timestamp | ✓ | | |
| `paused_at` / `paused_reason` | timestamp/varchar | ✓ | `vehicle_suspended` `licence_expired` `by_driver` | |
| `archived_at` | timestamp | ✓ | | |

```sql
INDEX idx_search (status, audience, bbox_min_lat, bbox_max_lat, bbox_min_lng, bbox_max_lng)
INDEX (driver_profile_id, status)
INDEX (corridor_id, status)
```

> ⚡ **الـ bounding box هو سر السرعة.** الاستعلام ده بيشيل 95% من الصفوف
> **من غير أي دالة جغرافية**، فبيقدر يستخدم الـ B-tree index:
> ```sql
> WHERE bbox_min_lat <= :user_max_lat AND bbox_max_lat >= :user_min_lat
>   AND bbox_min_lng <= :user_max_lng AND bbox_max_lng >= :user_min_lng
> ```

---

### `commute_locations` — نقاط المسار

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `commute_offer_id` | ULID | | |
| `type` | varchar(20) | `origin` `pickup` `dropoff` `destination` | `origin` |
| 🔗 `place_id` | ULID | لو مكان معروف | |
| `point` | POINT | ⚠️ SPATIAL INDEX | |
| `lat` / `lng` | decimal(10,7) | مكرّرة للفلترة | |
| `address` | varchar(255) | نص للعرض | `الرحاب، بوابة ٢` |
| `sequence` | smallint | ⚠️ الترتيب: origin=0، destination=999 | `0` |
| `is_exact` | boolean | ⚠️ `false` = منطقة تقريبية بس | `true` |

> 🔒 **إخفاء الموقع الدقيق:** لو الراكب لسه ما اتقبلش، الـ API بيرجّع نقطة
> **مزاحة عشوائيًا في دائرة 200 متر** — والإزاحة بتحصل **في السيرفر**.
> مش بنبعت الإحداثيات الحقيقية ونخفيها في الشاشة. راجع فخ #23.

---

### `commute_schedules` — قاعدة التكرار

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗 `commute_offer_id` | ULID | | |
| `days_mask` | tinyint | ⚠️ bitmask: سبت=1، أحد=2، إثنين=4، تلات=8، أربع=16، خميس=32، جمعة=64 | `62` |
| ⚠️ `departure_time` | time | **وقت محلي بالحائط.** مش UTC! | `07:05:00` |
| ⚠️ `timezone` | varchar(40) | لازم يتخزّن مع الوقت | `Africa/Cairo` |
| `start_date` | date | ⚠️ مايبقاش في الماضي | `2026-08-09` |
| `end_date` | date | ⚠️ **إجباري.** مفيش رحلات لا نهائية | `2026-12-31` |
| `generated_until` | date | ⚠️ لحد فين ولّدنا رحلات | `2026-09-08` |

> 🔴 **أخطر فخ في المشروع كله.** لو خزّنت `departure_time` كـ UTC، الرحلة اللي
> الساعة 7:05 الصبح هتبقى 6:05 أو 8:05 بعد تغيير التوقيت الصيفي في مصر
> (24 أبريل و 30 أكتوبر 2026). خزّن **الوقت المحلي + التايم زون**، واحسب الـ UTC
> **وقت توليد كل رحلة**. راجع فخ #12.

---

### `scheduled_trips` — رحلة يوم واحد ⚡

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | | |
| 🔗⚡ `commute_offer_id` | ULID | | |
| 🔗 `commute_schedule_id` | ULID | | |
| ⚡ `trip_date` | date | ⚠️ **اليوم المحلي** | `2026-08-09` |
| ⚡ `departure_at` | timestamp | ⚠️ **UTC محسوبة** من المحلي + التايم زون | `2026-08-09 04:05:00` |
| `departure_local` | datetime | مخزّنة للعرض والتصحيح | `2026-08-09 07:05:00` |
| `seats_total` | tinyint | ⚠️ **لقطة** — لو غيّرت العرض، القديم مايتأثرش | `3` |
| ⚠️ `seats_taken` | tinyint | **العمود اللي بيتقفل** عند الحجز | `1` |
| `price_snapshot_piastres` | int | ⚠️ لقطة السعر وقت التوليد | `8000` |
| ⚡ `status` | varchar(25) | `scheduled` `preparing` `en_route` `in_progress` `completed` `cancelled` | `scheduled` |
| `cancelled_reason` | varchar(255) | | |
| `booking_deadline_at` | timestamp | آخر ميعاد للحجز (9 م الليلة السابقة) | `2026-08-08 21:00` |

```sql
UNIQUE (commute_offer_id, trip_date)   -- 🔴 يمنع التوليد المكرر
INDEX (departure_at, status)
INDEX (status, trip_date)
```

> 🔴 **الفهرس الفريد ده مش رفاهية.** الـ job اللي بيولّد الرحلات ممكن يشتغل مرتين
> (إعادة محاولة، أو تشغيل يدوي). من غيره هتلاقي رحلتين لنفس اليوم. راجع فخ #13.

---

## المجموعة 5 — الطلب والمطابقة

### `commute_demands` 🔒 — طلبات الركاب (خاصة)

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `passenger_user_id` | ULID | | |
| `origin_point` / `destination_point` | POINT | | |
| `origin_lat/lng` `dest_lat/lng` | decimal(10,7) | للفلترة | |
| `origin_label` / `destination_label` | varchar(150) | | `الرحاب` |
| `commute_type` | varchar(20) | `recurring` `one_time` | `recurring` |
| `days_mask` | tinyint | | `62` |
| `preferred_arrival_start` / `_end` | time | نافذة الوصول | `07:00` / `07:20` |
| `flexibility_minutes` | tinyint | ±15 دقيقة | `15` |
| `max_walk_minutes` | tinyint | | `15` |
| `max_detour_minutes` | tinyint | | `15` |
| `budget_monthly_piastres` | int | | `180000` |
| `audience_preference` | varchar(20) | | `women_only` |
| ⚡ `status` | varchar(20) | `active` `matched` `expired` `cancelled` | `active` |
| `expires_at` | timestamp | ⚠️ الطلبات القديمة بتموت | |
| `last_notified_at` | timestamp | ⚠️ لمنع إغراق المستخدم بالإشعارات | |

> 🔒 **الجدول ده سري.** **مفيش أي endpoint** بيرجّع طلبات الركاب لأي سائقة.
> ده الفرق الجوهري بين رفيق و"مزاد على الركاب". لازم اختبار بيتأكد من ده.

---

### `saved_searches` — البحوث المحفوظة
| العمود | الشرح |
|---|---|
| 🔗 `user_id` | |
| `title` | `الرحاب ← سمارت فيلدج` |
| `filters` | json — كل الفلاتر |
| ⚡ `signature` | ⚠️ hash للفلاتر — `UNIQUE(user_id, signature)` يمنع التكرار |

---

### `match_scores` — كاش نتائج المطابقة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| ⚡ `demand_signature` | varchar(64) | hash لبارامترات البحث | |
| 🔗 `scheduled_trip_id` | ULID | | |
| `total` | tinyint | 0–100 | `96` |
| `overlap_score` | tinyint | من 30 | `28` |
| `schedule_score` | tinyint | من 25 | `24` |
| `detour_score` | tinyint | من 15 | `15` |
| `audience_score` | tinyint | من 10 | `10` |
| `comfort_score` | tinyint | من 10 | `9` |
| `price_score` | tinyint | من 5 | `5` |
| `reliability_score` | tinyint | من 5 | `5` |
| `walk_minutes` / `detour_minutes` | decimal(4,1) | للعرض | `4.0` / `6.0` |
| `expires_at` | timestamp | ⚠️ ساعة واحدة | |

**ليه بنخزّن كل مكوّن؟** لأن شاشة "تفاصيل المطابقة" بتعرض التفكيك للمستخدم.
وكمان لما نعدّل المعادلة، نقدر نقارن قبل وبعد.

---

### `match_notifications` — إشعارات المطابقة اللاحقة
| العمود | الشرح |
|---|---|
| 🔗 `commute_demand_id` / `commute_offer_id` | |
| `score` | الدرجة وقت الإشعار |
| `delivered_at` / `clicked_at` | لقياس الفعالية |

`UNIQUE (commute_demand_id, commute_offer_id)` — ⚠️ **منع إشعار نفس العرض مرتين**.

---

## المجموعة 6 — الطلبات والحجوزات

### `seat_requests` — طلب المقعد

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `passenger_user_id` | ULID | | |
| 🔗⚡ `commute_offer_id` | ULID | | |
| 🔗 `scheduled_trip_id` | ULID | ✓ للتجريبي (يوم محدد) | |
| `commitment` | varchar(20) | `trial` `recurring` | `trial` |
| `requested_days_mask` | tinyint | للمنتظم | `62` |
| `seats` | tinyint | عادة 1 | `1` |
| `meeting_preference` | varchar(20) | `gate` `street` `landmark` `custom` | `gate` |
| 🔗 `custom_pickup_place_id` | ULID | ✓ | |
| `intro_message` | text | ⚠️ **بيتفلتر من الإساءة وأرقام التليفون** | `أهلاً نور!...` |
| ⚠️ `agreed_to_rules_at` | timestamp | **إجباري.** بدونه الطلب مايتقبلش | |
| `payment_type` | varchar(10) | `cash` `online` | `cash` |
| ⚡ `status` | varchar(20) | `pending` `approved` `rejected` `waitlisted` `withdrawn` `expired` | `pending` |
| `waitlist_position` | smallint | ✓ | `1` |
| 🔗 `responded_by` | ULID | ✓ | |
| `responded_at` | timestamp | ✓ | |
| `response_note` | varchar(255) | ✓ سبب الرفض | |
| `expires_at` | timestamp | ⚠️ يموت لو السائقة مردتش (48 ساعة) | |

```sql
UNIQUE (passenger_user_id, commute_offer_id, status) WHERE status IN ('pending','approved')
-- ⚠️ يمنع نفس الشخص يبعت طلبين لنفس العرض
```

---

### `pickup_point_requests` — طلب نقطة التقاء مخصصة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗 `seat_request_id` | ULID | | |
| `proposed_point` | POINT | | |
| `proposed_label` | varchar(150) | | `منطقة المستثمرين الجنوبية` |
| `added_minutes` | decimal(4,1) | ⚠️ محسوبة من Google، مش من الراكب | `4.0` |
| `added_km` | decimal(5,2) | | `1.80` |
| `status` | varchar(30) | `pending` `approved` `suggested_alternative` `rejected` | `pending` |
| 🔗 `alternative_place_id` | ULID | ✓ لو اقترحت بديل | |

---

### `bookings` — الحجز

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | | `01JQ_BOOKING` |
| 🔗⚡ `scheduled_trip_id` | ULID | ⚠️ **رحلة يوم واحد** | |
| 🔗⚡ `passenger_user_id` | ULID | | |
| 🔗 `driver_profile_id` | ULID | مكرّر عمدًا للاستعلامات | |
| 🔗 `commute_group_id` | ULID | | |
| 🔗 `seat_request_id` | ULID | من أنهي طلب | |
| `seats_reserved` | tinyint | | `1` |
| 🔒 `price_snapshot_piastres` | int | ⚠️ **متجمّد للأبد** | `8800` |
| 🔒 `platform_fee_snapshot_piastres` | int | ⚠️ متجمّد | `240` |
| 🔒 `driver_amount_snapshot_piastres` | int | ⚠️ متجمّد | `8560` |
| `payment_type` | varchar(10) | `cash` `online` | `cash` |
| ⚡ `payment_status` | varchar(20) | `not_due` `pending` `paid` `failed` `refunded` `disputed` | `not_due` |
| ⚡ `status` | varchar(30) | `pending` `confirmed` `completed` `cancelled_by_passenger` `cancelled_by_driver` `expired` `no_show` | `confirmed` |
| `pickup_place_id` / `pickup_point` | ULID/POINT | نقطة الالتقاء المتفق عليها | |
| `cancelled_at` / `cancelled_reason` | timestamp/varchar | | |
| `cancellation_fee_piastres` | int | لو إلغاء متأخر | `0` |

```sql
UNIQUE (scheduled_trip_id, passenger_user_id)   -- 🔴 يمنع الحجز المزدوج
INDEX (passenger_user_id, status, created_at)
INDEX (driver_profile_id, status)
INDEX (commute_group_id, status)
```

> 🔒 **ليه 3 لقطات للسعر؟** لأن السائقة ممكن تغيّر السعر بكرة، والعمولة ممكن
> تتغير الشهر الجاي. الحجز لازم يفضل بالقيم اللي اتفقوا عليها **يوم الحجز**.
> ده مش تحسين — ده صحة مالية وقانونية.

---

### `booking_events` 🔒 — سجل كل تغيير حالة

| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `booking_id` | | |
| `event_type` | `created` `confirmed` `cancelled` `completed` `no_show` `disputed` | `confirmed` |
| `actor_type` | `passenger` `driver` `system` `admin` | `driver` |
| 🔗 `actor_id` | ✓ Null لو النظام | |
| `from_status` / `to_status` | | `pending` → `confirmed` |
| `metadata` | json | | |

> 🔒 **INSERT فقط.** ده اللي بيخليك تعرف "إيه اللي حصل بالظبط" لما يبقى في خلاف.

---

## المجموعة 7 — المجموعات

### `commute_groups` — المجموعة الثابتة

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗 `commute_offer_id` | ULID | ⚠️ unique — مجموعة لكل عرض | |
| `name` | varchar(150) | | `الرحاب ← سمارت فيلدج · صباحًا` |
| `status` | varchar(20) | `active` `paused` `disbanded` | `active` |
| `min_commitment_days_per_week` | tinyint | | `3` |
| `on_time_pct` | decimal(5,2) | محسوبة دوريًا | `98.00` |
| `rides_together_count` | int | | `42` |
| `seats_open` | tinyint | مخزّنة للسرعة | `1` |
| `notice_period_days` | tinyint | مهلة إخطار المغادرة | `7` |

### `group_members` — الأعضاء

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `commute_group_id` | ULID | | |
| 🔗⚡ `user_id` | ULID | | |
| `role` | varchar(20) | `driver` `member` `trial` | `trial` |
| ⚡ `status` | varchar(20) | `active` `notice_given` `left` `removed` | `active` |
| `committed_days_mask` | tinyint | الأيام اللي التزم بيها | `62` |
| `joined_at` / `left_at` | timestamp | | |
| `notice_given_at` | timestamp | بداية مهلة المغادرة | |
| `removal_reason` | varchar(255) | | |

`UNIQUE (commute_group_id, user_id)` — ⚠️ عضوية واحدة للشخص في المجموعة.

### `group_attendance` — الحضور المخطط

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗 `commute_group_id` / `scheduled_trip_id` / `user_id` | ULID | | |
| `status` | varchar(20) | `coming` `away` `no_response` | `coming` |
| `marked_at` | timestamp | | |

> ده **الحضور المُعلَن مسبقًا** ("أنا جاية بكرة") — مختلف عن `attendance`
> اللي في مجموعة الرحلة الحية (الحضور الفعلي). متخلطهمش.

### `group_absences` — الغياب المخطط
| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `commute_group_id` / `user_id` | | |
| `from_date` / `to_date` | | `2026-08-20` → `2026-08-27` |
| `reason` | | `إجازة` |
| `releases_seat` | boolean | ⚠️ يفتح المقعد لقائمة الانتظار؟ | `true` |

---

## المجموعة 8 — الفلوس

### `payment_methods` — وسائل الدفع

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `user_id` | ULID | | |
| `provider` | varchar(20) | | `paymob` |
| 🔒 `provider_token` | varchar(255) | ⚠️ **توكن المزوّد.** ممنوع أي رقم كارت | `tok_a7f3...` |
| `type` | varchar(20) | `card` `wallet` `instapay` | `card` |
| `last4` | char(4) | للعرض بس | `4471` |
| `brand` | varchar(20) | | `visa` |
| `wallet_phone_masked` | varchar(20) | لفودافون كاش | `010****8817` |
| `is_default` | boolean | | `true` |
| `expires_at` | date | للكروت | `2029-06-30` |
| `deleted_at` | timestamp | حذف ناعم | |

> 🔒 **PCI:** رقم الكارت **عمره ما يلمس السيرفر بتاعنا**. الـ tokenization
> بتحصل عند Paymob مباشرة من التطبيق. إحنا بنستقبل التوكن بس.

---

### `payments` — عمليات الدفع

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑 `id` | ULID | | |
| 🔗⚡ `booking_id` | ULID | | |
| 🔗 `user_id` | ULID | الدافع | |
| 🔗 `payment_method_id` | ULID | ✓ Null للكاش | |
| `payment_type` | varchar(10) | `cash` `online` | `cash` |
| `provider` | varchar(20) | ✓ | `paymob` |
| ⚡ `provider_ref` | varchar(120) | ✓ مرجع العملية عندهم | `pmb_8471...` |
| `amount_piastres` | int | الإجمالي | `8800` |
| `platform_fee_piastres` | int | عمولتنا | `240` |
| `driver_amount_piastres` | int | نصيب السائقة | `8560` |
| `type` | varchar(20) | `charge` `refund` `partial_refund` | `charge` |
| ⚡ `status` | varchar(25) | `pending` `authorized` `captured` `failed` `refunded` `settled_offline` | `settled_offline` |
| 🔑 `idempotency_key` | varchar(64) | ⚠️ **UNIQUE إجباري** | `bk_01JQ_charge` |
| `authorized_at` / `captured_at` | timestamp | | |
| `failure_code` / `failure_reason` | varchar | | |
| `confirmed_by_driver_at` | timestamp | ⚠️ للكاش بس | `2026-08-09 09:58` |
| `retry_count` | tinyint | | `0` |

```sql
UNIQUE (idempotency_key)     -- 🔴 خط الدفاع ضد الخصم المزدوج
INDEX (booking_id, status)
INDEX (status, created_at)
```

---

### `payment_webhooks` 🔒 — رسائل المزوّد

| العمود | الشرح |
|---|---|
| `provider` | `paymob` |
| 🔑 `event_id` | ⚠️ **UNIQUE** — Paymob بيبعت نفس الحدث أكتر من مرة |
| `event_type` | `transaction.processed` |
| `payload` | json — الرسالة كاملة كما وصلت |
| `signature_valid` | boolean | ⚠️ التحقق من التوقيع **قبل** أي معالجة |
| `processed_at` | ✓ Null = لسه ما اتعالجش |
| `processing_error` | لو فشلت |

> 🔴 **قاعدة الـ webhook:**
> ```
> 1. سجّل الرسالة فورًا (خام)
> 2. تحقق من التوقيع
> 3. موجودة قبل كده بنفس event_id؟  →  رجّع 200 وامشي
> 4. حوّلها لـ queue job
> 5. رجّع 200 بسرعة  ← Paymob بيعيد المحاولة لو اتأخرت
> ```
> **ممنوع** تعالج الدفع جوّه الـ webhook request نفسه.

---

### `driver_fee_ledger` 🔒 — دفتر عمولات الكاش

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `driver_profile_id` | ULID | | |
| 🔗 `booking_id` | ULID | ✓ | |
| `type` | varchar(20) | `fee_due` `fee_settled` `adjustment` `write_off` | `fee_due` |
| `amount_piastres` | int | ⚠️ موجب = زيادة الدين، سالب = سداد | `240` |
| `balance_after_piastres` | int | ⚠️ الرصيد بعد القيد — للتدقيق | `240` |
| 🔗 `settled_from_payment_id` | ULID | ✓ من أنهي دفعة اتخصم | |
| `note` | varchar(255) | | |

> 🔒 **INSERT فقط. ممنوع UPDATE أو DELETE.**
> الرصيد الحقيقي = `SUM(amount_piastres)`. جدول `driver_balances` **نسخة محسوبة**
> للسرعة، ولازم job يومي يقارنها بالمجموع ويبلّغ لو اختلفت.

### `driver_balances` — الرصيد المحسوب

| العمود | الشرح | مثال |
|---|---|---|
| 🔑🔗 `driver_profile_id` | unique | |
| `outstanding_fee_piastres` | الدين الحالي | `240` |
| `lifetime_earnings_piastres` | إجمالي الأرباح | `412000` |
| `last_settled_at` | آخر سداد | |
| ⚠️ `is_blocked_from_publishing` | لما الدين يعدّي الحد | `false` |
| `block_reason` | | |
| `reconciled_at` | ⚠️ آخر مطابقة مع الدفتر | |

### `payouts` — تحويلات المزوّد (تتبّع، مش احتجاز)
| العمود | الشرح |
|---|---|
| 🔗 `driver_profile_id` · `period_start/end` | |
| `amount_piastres` · `method` | `instapay` `wallet` `bank` |
| `status` | `pending` `cleared` `on_hold` `blocked` `released` |
| `provider_transfer_ref` | مرجع التحويل عند Paymob |
| 🔗 `released_by` | الأدمن |

### `refunds`
| العمود | الشرح |
|---|---|
| 🔗 `payment_id` · `amount_piastres` · `reason` |
| `status` | `pending` `approved` `processed` `rejected` |
| 🔗 `approved_by` · `provider_ref` |

---

## المجموعة 9 — الرحلة الحية

### `trip_sessions` — جلسة التنفيذ

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑🔗 `scheduled_trip_id` | ULID | unique — جلسة واحدة للرحلة | |
| `started_at` / `completed_at` | timestamp | | |
| ⚡ `current_status` | varchar(25) | `preparing` `en_route` `at_pickup` `in_progress` `completed` `cancelled` `emergency` | `in_progress` |
| `distance_travelled_meters` | int | | `32100` |
| `duration_seconds` | int | | `2940` |
| `deviation_detected_at` | timestamp | ⚠️ خروج عن المسار | `NULL` |
| `deviation_distance_meters` | int | | |
| `last_location_at` | timestamp | ⚠️ لكشف انقطاع الـ GPS | |

### `attendance` — الحضور الفعلي

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔑🔗 `booking_id` | ULID | unique | |
| ⚡ `status` | varchar(30) | `pending` `present` `late` `passenger_no_show` `driver_no_show` `cancelled` | `present` |
| `checked_in_at` / `checked_out_at` | timestamp | | `07:07` |
| `confirmed_by` | varchar(20) | ⚠️ `driver` (قرارنا D18) | `driver` |
| 🔗 `confirmed_by_user_id` | ULID | | |
| `confirmed_at` | timestamp | | |
| `gps_corroborated` | boolean | ⚠️ دليل مساعد، مش شرط | `true` |
| `gps_confidence` | decimal(4,2) | | `0.94` |
| `disputed_at` | timestamp | ✓ نافذة 24 ساعة | `NULL` |
| `dispute_reason` | varchar(255) | | |
| `dispute_resolution` | varchar(30) | `upheld` `overturned` `refunded` | |
| 🔗 `dispute_resolved_by` | ULID | | |

### `trip_locations` ⚠️ — مسار الـ GPS

| العمود | النوع | الشرح |
|---|---|---|
| 🔗⚡ `trip_session_id` | ULID | |
| `lat` / `lng` | decimal(10,7) | |
| `accuracy_meters` | smallint | لتصفية النقاط الرديئة |
| `speed_kmh` | smallint | |
| `recorded_at` | timestamp | ⚠️ **وقت الجهاز**، مش وقت الاستقبال |
| `purge_after` | date | ⚠️ الحذف الإلزامي |

> 🔴 **الجدول ده أخطر جدول من ناحية الأداء والخصوصية:**
> - سائقة واحدة × كل 5 ثواني × 50 دقيقة = **600 صف للرحلة**
> - 500 رحلة في الصبح = **300,000 صف يوميًا**
>
> **الحل:** الموقع بيروح **Redis** أول، وbroadcast للركاب فورًا.
> Job كل 30 ثانية بيكتب دفعة واحدة (`bulk insert`). راجع فخ #35.
>
> **الخصوصية:** مدة احتفاظ محددة + job حذف يومي + التقسيم بالشهر (partitioning).

### `trip_wait_timers` — عدّاد الانتظار
| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `trip_session_id` / `booking_id` | | |
| `started_at` | | `07:06:00` |
| `grace_seconds` | من الإعدادات | `300` |
| `extended_seconds` | تمديد السائقة | `120` |
| `outcome` | `arrived` `no_show` `driver_left` | `arrived` |

---

## المجموعة 10 — التقييم والثقة

### `ratings`

| العمود | النوع | الشرح | مثال |
|---|---|---|---|
| 🔗⚡ `booking_id` | ULID | | |
| 🔗 `reviewer_user_id` / `reviewed_user_id` | ULID | | |
| `direction` | varchar(30) | `passenger_to_driver` `driver_to_passenger` | |
| `stars` | tinyint | 1–5 | `5` |
| `comment` | text | ⚠️ يمر على فلتر الإساءة | |
| ⚠️ `visible_at` | timestamp | **Null = مخفي.** بيتملى لما الاتنين يقيّموا أو تنتهي المهلة | `NULL` |
| `edit_deadline_at` | timestamp | نافذة التعديل القصيرة | |
| `edited_at` / `deleted_at` | timestamp | ⚠️ حذف ناعم — للتدقيق | |
| `moderation_status` | varchar(20) | `clean` `flagged` `hidden` | `clean` |

```sql
UNIQUE (booking_id, reviewer_user_id)   -- تقييم واحد لكل طرف لكل حجز
```

> 🔴 **الـ double-blind لازم يتنفذ في الاستعلام نفسه:**
> ```php
> Rating::where('reviewed_user_id', $id)->whereNotNull('visible_at')->get();
> ```
> **ممنوع** ترجّع الصف كامل وتخفيه في الواجهة. راجع فخ #26.

### `rating_tags`
`rating_id` + `tag` — `safe_driving` `on_time` `clean_car` `great_company` `comfortable` `would_ride_again`

### `trust_scores`
| العمود | الشرح |
|---|---|
| 🔑🔗 `user_id` | |
| `score` | 0–100 **داخلي** ⚠️ مايتعرضش للمستخدم |
| `public_tier` | `new` `trusted` `highly_trusted` ← ده اللي بيتعرض |
| `components` | json — تفكيك الحساب |
| `computed_at` | |

### `review_reports`
`rating_id` · `reporter_id` · `reason` · `status` · `resolved_by`

---

## المجموعة 11 — الأمان

### `emergency_contacts` — جهات الاتصال الموثوقة
| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `user_id` · `name` · `phone_e164` | | `ليلى` |
| `relationship` | | `أخت` |
| `auto_share_trips` | ⚠️ يشوف كل رحلة تلقائيًا | `true` |
| `is_guardian` | صلاحيات أعلى وقت الطوارئ | `true` |
| `verified_at` | ⚠️ الرقم اتأكد إنه بيستقبل | |

### `live_shares` — روابط المشاركة المؤقتة
| العمود | الشرح |
|---|---|
| 🔗 `trip_session_id` / `user_id` |
| 🔒⚡ `token_hash` | ⚠️ **hash للتوكن.** ≥32 بايت عشوائي |
| `shared_with_contact_id` | ✓ |
| ⚠️ `expires_at` | **بينتهي مع نهاية الرحلة + مهلة قصيرة** |
| `revoked_at` · `view_count` · `last_viewed_at` |

> 🔒 صفحة المشاركة لازم يكون عليها `X-Robots-Tag: noindex` و `Cache-Control: no-store`،
> وتعرض **الاسم الأول والسيارة والموقع بس** — مش رقم تليفون ولا عنوان بيت.

### `safety_events` 🔒 — مايتحذفش أبدًا
| العمود | الشرح |
|---|---|
| `type` | `sos` `discreet_alert` `live_share_started` `incident_created` `escort_armed` `admin_intervention` |
| 🔗 `user_id` / `trip_session_id` / `booking_id` | |
| `severity` | `low` `medium` `high` `critical` |
| `metadata` | json |
| `occurred_at` | ⚠️ وقت الحدث، مش وقت التسجيل |

### `sos_events`
| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `safety_event_id` | |
| `countdown_seconds` | مهلة إلغاء الضغط بالخطأ | `10` |
| `cancelled_at` | ✓ | |
| `is_discreet` | ⚠️ إنذار صامت — **مفيش صوت ولا اهتزاز** | `true` |
| ⚠️ `first_touch_at` | **مؤشر الأداء الأهم** — عداد الاستجابة | |
| 🔗 `responder_admin_id` | | |
| `resolution` | `false_alarm` `resolved` `escalated_police` | |
| `location_at_trigger` | POINT | |

### `incidents` — البلاغات
| العمود | الشرح | مثال |
|---|---|---|
| 🔗 `booking_id` / `trip_session_id` | ✓ | |
| 🔗 `reporter_user_id` / `reported_user_id` | | |
| `category` | `harassment` `unsafe_driving` `identity_mismatch` `payment` `no_show` `lost_item` `other` | `harassment` |
| `severity` | `low` `medium` `high` `critical` | `high` |
| `description` | text | |
| ⚡ `status` | `open` `under_review` `escalated` `resolved` `closed` | `open` |
| 🔗 `assigned_admin_id` | ✓ | |
| `sla_due_at` | ⚠️ مهلة الاستجابة | |
| `resolution` / `resolved_at` | | |

### `incident_evidence` 🔒
`incident_id` · `file_path` 🔒 · `kind` · `file_hash` ⚠️ (سلامة الدليل) · `purge_after`

### `blocked_users`
| العمود | الشرح |
|---|---|
| 🔗 `blocker_user_id` / `blocked_user_id` | `UNIQUE` معًا |
| `reason` · `created_at` |

> 🔴 **الحظر لازم يتفحص في الاتجاهين** في المطابقة:
> ```sql
> AND NOT EXISTS (SELECT 1 FROM blocked_users
>   WHERE (blocker_user_id = :me AND blocked_user_id = offer_driver)
>      OR (blocker_user_id = offer_driver AND blocked_user_id = :me))
> ```
> غلطة شائعة: فحص اتجاه واحد بس. راجع فخ #27.

### `escort_windows` — وضع المرافقة الليلية
`corridor_id` · `starts_at` / `ends_at` · `trips_covered` · `armed_by` · `is_auto`

---

## المجموعة 12 — التواصل

### `notifications`
| العمود | الشرح |
|---|---|
| 🔗⚡ `user_id` · `type` · `title` · `body` |
| `data` | json — للتوجيه داخل التطبيق |
| `channel` | `push` `sms` `in_app` `email` |
| `category` | ⚠️ `booking` `payment` `trip` `safety` `marketing` |
| `read_at` · `sent_at` · `failed_reason` |

### `notification_preferences`
| العمود | الشرح |
|---|---|
| 🔗 `user_id` · `category` · `channel` · `enabled` |

> 🔒 **`category = 'safety'` مايتقفلش.** لازم يكون مفروض في الكود، مش في الشاشة بس.

### `conversations`
`booking_id` / `commute_group_id` · `opened_at` · ⚠️ `closed_at` (بعد الرحلة + مهلة) · `status`

### `messages`
| العمود | الشرح |
|---|---|
| 🔗 `conversation_id` / `sender_user_id` · `body` |
| `read_at` · `flagged_reason` · `deleted_at` |
| ⚠️ `contains_contact_info` | boolean — كشف محاولة تبادل أرقام |

---

## المجموعة 13 — الأدمن

### `admin_users`
| العمود | الشرح |
|---|---|
| `name` · 🔑 `email` · `password_hash` |
| ⚠️ 🔒 `mfa_secret` | **إلزامي** |
| `mfa_confirmed_at` · `status` · `last_login_at` · `last_login_ip_hash` |

`roles` / `permissions` / `model_has_roles` ← Spatie Permission
**الأدوار:** `super_admin` · `operations` · `verification` · `finance` · `support` · `safety_lead`

### `admin_actions` 🔒 — سجل التدقيق
| العمود | الشرح |
|---|---|
| 🔗 `admin_id` · `action` · `entity_type` · `entity_id` |
| `old_value` / `new_value` | json |
| `reason` | ⚠️ إجباري للإجراءات الحساسة |
| `ip_hash` · `user_agent_hash` · `created_at` |

> 🔒 **غير قابل للتعديل على مستوى الداتابيز:** حساب التطبيق لازم يكون
> `GRANT INSERT, SELECT` بس على الجدول ده — **بدون UPDATE ولا DELETE**.
> الاحتفاظ: 24 شهر.

### `support_tickets`
`user_id` · `category` · `priority` · `status` · `assigned_admin_id` · `sla_due_at` · `related_entity_type/id`

### `platform_settings` — الإعدادات الحية
| العمود | الشرح | مثال |
|---|---|---|
| 🔑 `key` | | `safety.night_escort_enabled` |
| `value` | json | `true` |
| `type` · `description` · 🔗 `updated_by` | | |

**مفاتيح أساسية:**
```
otp.expiry_seconds = 120          otp.max_attempts = 5
booking.deadline_hour = 21        trip.grace_period_seconds = 300
payment.platform_fee_pct = 3.0    payment.max_driver_debt_piastres = 20000
matching.max_results = 50         trips.generation_horizon_days = 30
privacy.pickup_blur_radius_m = 200
```

> ⚠️ **كل رقم في القائمة دي ممنوع يبقى hard-coded في الكود.**

### `feature_flags`
`key` · `enabled` · `rollout_percentage` · `audience_filter` (json)

---

## المجموعة 14 — التحليلات

### `analytics_events`
`user_id` (✓) · `session_id` · `event_name` · `entity_type/id` · `metadata` (json) · `occurred_at`
> ⚠️ **ممنوع أي بيانات شخصية هنا** — لا أسماء ولا تليفونات ولا إحداثيات دقيقة.

### `recommendation_cache`
`user_id` · `commute_offer_id` · `score` · `reason_codes` (json) · `expires_at`

### `demand_heatmap_cells`
`cell_geohash` (دقة 6 ≈ 1.2 كم) · `date` · `hour_bucket` · `demand_count` · `supply_count`
> ⚠️ **لازم حد أدنى للتجميع** (مثلاً ≥5) قبل العرض، وإلا الخريطة بتفضح أفراد.

---
---

<a name="part5"></a>
# الجزء 5 — الفخاخ: 64 غلطة هنقع فيها لو مخدناش بالنا

> كل فخ فيه: **الغلطة** → **إيه اللي بيحصل** → **الحل**
> الفخاخ المعلّمة 🔴 بتسبب فقد فلوس أو خرق أمني. الباقي بيسبب بق أو بطء.

---

## أ. الحجز والتزامن (Concurrency)

### 🔴 فخ #1 — الحجز المزدوج على آخر مقعد
```php
// ❌ غلط
if ($trip->seats_taken < $trip->seats_total) {
    Booking::create([...]);
    $trip->increment('seats_taken');
}
```
**بيحصل إيه:** طلبين في نفس المللي ثانية. الاتنين قروا `seats_taken = 2` من `3`.
الاتنين عدّوا الشرط. بقى عندك **4 ركاب في عربية 3 مقاعد**.

```php
// ✅ صح
DB::transaction(function () use ($tripId) {
    $trip = ScheduledTrip::lockForUpdate()->findOrFail($tripId);   // ← القفل
    if ($trip->seats_taken >= $trip->seats_total) {                // ← الفحص جوّه القفل
        throw new NoSeatsAvailableException();
    }
    $trip->increment('seats_taken');
    // ...
});
```
**اختبار إجباري:** شغّل 10 موافقات متوازية على مقعد واحد وتأكد إن **واحدة بس** نجحت.

---

### 🔴 فخ #2 — تحديث الرصيد بـ `$model->balance + $x`
```php
$balance->outstanding_fee = $balance->outstanding_fee + 240;   // ❌ Lost update
$balance->increment('outstanding_fee', 240);                    // ✅ ذري في SQL
```

### فخ #3 — الآثار الجانبية جوّه الـ transaction
```php
DB::transaction(function () {
    $booking = Booking::create([...]);
    Mail::send(...);              // ❌ لو الـ transaction اترجع، الإيميل اتبعت خلاص
    Http::post('webhook', ...);   // ❌ نفس المشكلة، وبيطوّل القفل
});
```
**الحل:** حدث + listener بـ `ShouldQueue`. أو `DB::afterCommit()`.

### فخ #4 — قفل صف كبير بيوقف كل حاجة
`lockForUpdate()` على `commute_offers` هيوقف كل الحجوزات على كل الرحلات.
**اقفل `scheduled_trips` بس** — أضيق نطاق ممكن.

### فخ #5 — الـ queue job بيشتغل مرتين
Laravel بيعيد المحاولة عند الفشل. لو الـ job بيخصم فلوس، هيخصم مرتين.
**الحل:** `ShouldBeUnique` + مفتاح idempotency + فحص الحالة في أول الـ job.

---

## ب. الوقت والتواريخ

### 🔴 فخ #6 — تخزين وقت الرحلة المتكررة كـ UTC

**ده أخطر فخ في المشروع.** مصر عندها توقيت صيفي فعلي بقانون 24 لسنة 2023:
من **آخر جمعة في أبريل** لـ **آخر خميس في أكتوبر**. في 2026: 24 أبريل → 30 أكتوبر.

```
❌ خزّنت: departure_time = 05:05 UTC   (7:05 صيفي)
   في نوفمبر بعد رجوع الساعة:
   05:05 UTC = 7:05 بتوقيت مصر الشتوي... لأ!
   05:05 UTC = 7:05 EET صح، بس اللي اتخزن أصلاً كان 04:05 UTC للصيفي
   → كل الركاب هيروحوا الساعة 8:05 أو 6:05 بالغلط
```

```php
// ✅ الحل
// في commute_schedules:
departure_time = '07:05:00'        // وقت الحائط المحلي
timezone       = 'Africa/Cairo'

// وقت توليد كل رحلة:
$departureUtc = CarbonImmutable::parse("{$tripDate} {$schedule->departure_time}",
                                        $schedule->timezone)->utc();
```

**واختبار إجباري:**
```php
it('handles Egypt DST transition', function () {
    // رحلة 7:05 قبل التحويل وبعده
    // 2026-04-23 (شتوي) → 05:05 UTC
    // 2026-04-26 (صيفي) → 04:05 UTC
    // لازم الاتنين يبقوا 07:05 محلي
});
```

### فخ #7 — `Carbon::now()` في الاختبارات
مفيش تحكم في الوقت → اختبارات بتفشل عشوائيًا.
**الحل:** `CarbonImmutable::setTestNow()` في كل اختبار حساس للوقت.

### فخ #8 — استخدام `Carbon` المتغيّر
```php
$start = now();
$end   = $start->addDays(7);   // ❌ غيّر $start كمان!
```
**الحل:** `CarbonImmutable` في كل المشروع. اضبطها في `AppServiceProvider`.

### فخ #9 — تخزين السن بدل تاريخ الميلاد
السن بيتغير. `date_of_birth` بس، والسن يتحسب.

### فخ #10 — مقارنة تواريخ بـ string
`'2026-08-09' > '2026-8-9'` ← مقارنة نصية غلط. استخدم أنواع التاريخ.

---

## ج. توليد الرحلات المتكررة

### 🔴 فخ #11 — توليد كل الرحلات لحد `end_date`
عرض من أغسطس لديسمبر = 110 رحلة. 5000 عرض = **550,000 صف** فورًا.
**الحل:** أفق 30 يوم + job يومي بيمد الأفق + `generated_until`.

### 🔴 فخ #12 — التوليد المكرر
الـ job اشتغل مرتين (retry أو تشغيل يدوي) → رحلتين لنفس اليوم → حجوزات مقسومة.
**الحل:** `UNIQUE (commute_offer_id, trip_date)` + `insertOrIgnore()`.

### فخ #13 — تعديل العرض بيغيّر رحلات محجوزة
السائقة غيّرت السعر من 80 لـ 95 → الناس اللي حاجزين اتخصم منهم 95.
**الحل:** `price_snapshot` في `scheduled_trips` و `bookings`. التعديل بيأثر على
**الرحلات غير المحجوزة والمستقبلية بس**، وبإشعار.

### فخ #14 — أرشفة الرحلات القديمة مش معمولة
بعد سنة `scheduled_trips` هتبقى ملايين، والاستعلامات هتبطأ.
**الحل:** job شهري بينقل الرحلات المكتملة الأقدم من 6 شهور لجدول أرشيف.

---

## د. المطابقة والجغرافيا

### 🔴 فخ #15 — التعارض الصارم بيتحول لخصم نقاط
```php
$score = $offer->audience === 'women_only' && $user->gender !== 'woman' ? 6 : 10;  // ❌
```
**راجل هيظهر في نتائج مجموعة نساء فقط.** ده خرق أمان مش تفضيل.
```php
// ✅ الاستبعاد في الاستعلام نفسه، قبل أي تنقيط
$query->where(fn($q) => $q->where('audience', 'any_verified')
    ->orWhere(fn($q2) => $q2->where('audience','women_only')->where(/* المستخدم امرأة */)));
```

### 🔴 فخ #16 — استدعاء Google جوّه حلقة
```php
foreach ($offers as $offer) {                      // ❌ 800 استدعاء
    $detour = Google::directions($offer->route, $pickup);
}
```
**التكلفة:** بحث واحد ≈ 4 دولار. مستخدم بيبحث 5 مرات = 20 دولار.
**الحل:** bbox أولاً → `ST_Distance_Sphere` → Google للـ 20–40 الباقيين بس، مع كاش.

### فخ #17 — نسيان الـ SPATIAL INDEX
`ST_Distance_Sphere` من غير index = مسح كامل للجدول.
**الحل:** `SPATIAL INDEX` + العمود `NOT NULL` (MySQL بيشترط ده).

### فخ #18 — الـ bbox محسوب غلط
لو حسبته من نقطة البداية والنهاية بس، المسار اللي بيلف هيبقى بره الصندوق.
**الحل:** احسبه من **كل نقاط الـ polyline** + هامش (مثلاً 2 كم).

### فخ #19 — خلط بين الأمتار والدقايق
`max_walk_minutes = 15` مقارنة بـ `distance = 1200` (متر)؟
**الحل:** `Value Objects` — `WalkTime` و `Distance` منفصلين، والتحويل في مكان واحد.

### فخ #20 — كاش المسار من غير TTL للزحمة
المسافة مش بتتغير، بس مدة الرحلة بتتغير. TTL واحد للاتنين = بيانات غلط أو تكلفة زيادة.
**الحل:** عمودين منفصلين بـ TTL مختلف.

---

## هـ. الخصوصية والأمان

### 🔴 فخ #21 — تسريب رقم الموبايل في الـ API
```php
return UserResource::make($driver);   // ❌ بيرجّع phone_e164
```
**وعد المنتج:** *"رقمك يبقى مخفي عن باقي الأعضاء — دايمًا"*.
```php
// ✅ Resource منفصل لكل سياق
class PublicUserResource extends JsonResource {
    public function toArray($r): array {
        return [
            'id'         => $this->id,
            'first_name' => $this->public_first_name,   // مش full_name
            'photo'      => $this->photoUrl(),
            'rating'     => $this->rating,
            'trust_tier' => $this->trustTier(),
            // مفيش phone · مفيش email · مفيش gender · مفيش date_of_birth
        ];
    }
}
```
**اختبار إجباري:** `expect($response->json())->not->toHaveKey('phone_e164')`

### 🔴 فخ #22 — إخفاء الموقع في الواجهة بدل السيرفر
```php
return ['lat' => $exactLat, 'lng' => $exactLng, 'blur' => true];   // ❌
```
أي حد يفتح الـ network tab هيشوف **عنوان بيتها بالظبط**.
```php
// ✅ الإزاحة في السيرفر
$point = $booking?->isConfirmed()
    ? $location->exactPoint()
    : $location->fuzzedPoint(radiusMeters: 200);   // إزاحة عشوائية ثابتة لكل offer
```
> ⚠️ الإزاحة لازم تبقى **ثابتة لنفس العرض** (seed من الـ id) — لو عشوائية كل مرة،
> الجمع بين عدة طلبات بيحدد المركز الحقيقي (triangulation).

### 🔴 فخ #23 — روابط المستندات عامة
`Storage::disk('public')` للرقم القومي = **تسريب هويات**.
**الحل:** disk خاص + `authorize()` + presigned 5 دقايق + `Cache-Control: no-store`.

### 🔴 فخ #24 — بيانات EXIF في الصور
صورة البروفايل فيها إحداثيات البيت.
**الحل:** إزالة كل الـ metadata عند الرفع + إعادة الترميز لـ WebP.

### 🔴 فخ #25 — تسريب وجود الحساب (Enumeration)
```php
if (!User::where('phone', $p)->exists())
    return error('الرقم ده مش مسجّل');   // ❌
```
دلوقتي أي حد يقدر يعرف مين مسجّل عندنا.
**الحل:** نفس الرد ونفس **زمن الرد** في الحالتين. ابعت OTP دايمًا.

### 🔴 فخ #26 — كسر الـ double-blind في التقييمات
لو الـ API رجّع التقييم قبل ما الطرفين يقيّموا، الحماية اتكسرت حتى لو الشاشة مبتعرضهوش.
**الحل:** `whereNotNull('visible_at')` في **كل** استعلام تقييمات + Global Scope.

### 🔴 فخ #27 — فحص الحظر في اتجاه واحد
```php
->whereNotIn('driver_id', $me->blockedUsers())   // ❌ ناقص الاتجاه التاني
```
لو السائقة حظرت الراكب، هيفضل شايفها.
**الحل:** فحص الاتجاهين، والأفضل: view أو scope واحد `notBlockedWith($userId)`.

### 🔴 فخ #28 — قناة WebSocket من غير تفويض
```php
Broadcast::channel('trip.{id}', fn() => true);   // ❌ أي حد يتابع أي رحلة
```
```php
// ✅
Broadcast::channel('trip.{tripSessionId}', function (User $user, string $id) {
    return app(TripAccessPolicy::class)->canTrack($user, $id);
    // راكب في نفس الرحلة، أو السائقة، أو صاحب live_share صالح، أو أدمن مصرّح
});
```

### 🔴 فخ #29 — رابط المشاركة الحية قابل للتخمين
`/share/1042` ← أي حد يعدّي الرقم يتابع رحلات الناس.
**الحل:** توكن عشوائي ≥32 بايت + `expires_at` + `noindex` + إلغاء عند انتهاء الرحلة.

### فخ #30 — تسريب الـ gender في الـ API
`gender` بيدخل في الفلترة الصارمة، بس **ممنوع يظهر** في أي response.

### فخ #31 — الـ OTP بيشتغل لغرض تاني
كود اتبعت للدخول اشتغل لتغيير رقم الموبايل → استيلاء على الحساب.
**الحل:** فحص `purpose` مع الكود.

### فخ #32 — مقارنة الـ OTP بـ `==`
عرضة لهجمات التوقيت. **الحل:** `hash_equals()`.

### فخ #33 — الـ PIN بيروح للسيرفر
حتى لو مشفّر. **الـ PIN محلي بالكامل.** السيرفر بيعرف إن فيه PIN بس.

### فخ #34 — Rate limiting على الـ IP بس
موبايل بيشارك IP (CGNAT) → مستخدمين شرعيين اتحظروا.
**الحل:** حدود مركّبة: رقم الموبايل + الجهاز + الـ IP، بحدود مختلفة.

---

## و. الفلوس

### 🔴 فخ #35 — الفلوس كـ float
```php
$total = 88.20 * 3;              // 264.59999999999997
```
**الحل:** `int` بالقروش دايمًا + `Money` value object.

### 🔴 فخ #36 — webhook من غير idempotency
Paymob بيعيد إرسال نفس الحدث. من غير `UNIQUE(event_id)` → **خصم مزدوج**.

### 🔴 فخ #37 — عدم التحقق من توقيع الـ webhook
أي حد يبعت لك request يقول "الدفع نجح" وخلاص.
**الحل:** تحقق من HMAC **قبل** أي معالجة، وارفض بـ 401.

### 🔴 فخ #38 — معالجة الدفع جوّه الـ webhook request
لو اتأخرت، Paymob هيعتبرها فشلت ويعيد المحاولة → معالجة مزدوجة.
**الحل:** سجّل → تحقق → ادفعها لـ queue → رجّع 200 في أقل من ثانية.

### 🔴 فخ #39 — الرصيد محسوب من عمود مش من دفتر
`driver_balances.outstanding_fee` اتحدّث غلط مرة → الرقم غلط للأبد ومفيش طريقة تعرف.
**الحل:** المصدر هو `driver_fee_ledger` (INSERT فقط). العمود نسخة، و**job يومي بيقارن**:
```php
$ledgerSum = DriverFeeLedger::where(...)->sum('amount_piastres');
if ($ledgerSum !== $balance->outstanding_fee_piastres) {
    Log::critical('Balance drift', [...]);   // تنبيه فوري
}
```

### فخ #40 — الدفع اتم والحجز مش اتحدّث
السيرفر وقع بين الخطوتين.
**الحل:** نمط Outbox + job تسوية بيدوّر على `payments.captured` مع `bookings.payment_status != paid`.

### فخ #41 — الاسترداد أكبر من الأصل
**الحل:** قيد `SUM(refunds) <= payment.amount` + فحص في الـ Action.

### فخ #42 — العمولة محسوبة وقت التحصيل مش وقت الحجز
غيّرنا العمولة من 3% لـ 4% → حجوزات قديمة اتخصم منها 4%.
**الحل:** `platform_fee_snapshot_piastres` في `bookings`.

### فخ #43 — التقريب بيضيّع قرش
240.5 قرش → مين ياخد القرش؟ **الحل:** قاعدة واحدة موثّقة (التقريب لصالح السائقة)
واختبار بيتأكد إن `driver_amount + platform_fee == amount` دايمًا.

---

## ز. الأداء

### 🔴 فخ #44 — N+1 في قائمة النتائج
```php
foreach ($offers as $o) { echo $o->driver->user->public_first_name; }  // ❌ 3 استعلامات × 50
```
**الحل:** `->with('driver.user', 'vehicle')` + `Model::preventLazyLoading()` في التطوير.

### فخ #45 — `count()` على علاقة كبيرة
`$group->members->count()` بيحمّل كل الصفوف. استخدم `withCount('members')`.

### فخ #46 — كتابة `trip_locations` صف بصف
500 سائقة × كل 5 ثواني = **100 كتابة/ثانية**. الداتابيز هتقع.
**الحل:**
```
الموقع → Redis (أحدث موقع) + broadcast فوري
Job كل 30 ثانية → bulk insert للدفعة
```

### فخ #47 — الفهارس ناقصة على الاستعلامات الشائعة
**الحل:** `EXPLAIN` على كل استعلام في مسار حرج + اختبار بيتأكد إن `type != ALL`.

### فخ #48 — تحميل كل الأعمدة
`SELECT *` على جدول فيه `polyline` و `json`. استخدم `->select([...])`.

### فخ #49 — الترقيم بـ OFFSET على جداول كبيرة
`OFFSET 100000` بطيء جدًا. **الحل:** cursor pagination (`->cursorPaginate()`).

### فخ #50 — مفيش كاش على الإعدادات
`platform_settings` بتتقرا في كل request. **الحل:** كاش دائم + مسح عند التعديل.

---

## ح. Laravel و PHP

### فخ #51 — `$fillable` واسع
`protected $guarded = []` → أي حد يبعت `account_status: active` في الـ request.
**الحل:** `$fillable` صريح + استخدام `->validated()` بس.

### فخ #52 — التحقق في الـ Controller
**الحل:** Form Request دايمًا.

### فخ #53 — الصلاحية منسية
`$this->authorize()` مش موجودة → أي مستخدم يقدر يوافق على طلب مقعد لسائقة تانية.
**الحل:** Policy لكل موديل + اختبار يتأكد إن كل route محمي.

### فخ #54 — migration مش قابل للتراجع
كل `up()` لازم يقابله `down()` صحيح.

### فخ #55 — بذور (seeders) في الإنتاج
**الحل:** `DB::prohibitDestructiveCommands($app->isProduction())` + بذور منفصلة للتطوير.

### فخ #56 — `APP_DEBUG=true` في الإنتاج
بيعرض stack traces فيها بيانات الاتصال.
**الحل:** فحص في الـ health endpoint + في الـ deploy pipeline.

### فخ #57 — `.env` في git
**الحل:** `.gitignore` + فحص pre-commit + إدارة أسرار حقيقية.

### فخ #58 — رسائل وSMS حقيقية في الاختبارات
**الحل:** `Notification::fake()` · `Mail::fake()` · `Http::preventStrayRequests()`.

### فخ #59 — enum من MySQL
تعديل قيمة = `ALTER TABLE` مؤلم على جدول كبير.
**الحل:** `varchar` + PHP enum cast.

### فخ #60 — الحذف الناعم بيكسر الفهرس الفريد
مستخدم اتحذف ناعمًا، مايقدرش يسجّل بنفس الرقم لأن `UNIQUE(phone)` لسه شايفه.
**الحل:** `UNIQUE(phone_e164, deleted_at)` أو تشويش الرقم عند الحذف:
`phone_e164 = 'deleted_' . $id`.

---

## ط. العربي والتوطين

### فخ #61 — `utf8` بدل `utf8mb4`
الإيموجي في الرسائل بيكسر الكتابة. **الحل:** `utf8mb4` في كل حتة.

### فخ #62 — الأرقام العربية-الهندية في إدخال الموبايل
المستخدم كتب `٠١٠١٢٣٤٥٦٧٨` → التحقق فشل.
**الحل:** تطبيع الأرقام (`٠-٩` → `0-9`) **قبل** أي تحقق.

### فخ #63 — البحث في الأسماء العربية
`نور` مش هتلاقي `نُور` (بتشكيل) ولا `نور ` (بمسافة).
**الحل:** عمود `name_normalized` (بدون تشكيل، مسافات موحّدة، ألف موحّدة).

### فخ #64 — النصوص بصيغة المؤنث بس
الـ prototype كله مؤنث. بعد قرار Mixed، محتاجين نسخ محايدة أو مزدوجة.
**الحل:** ملفات ترجمة منفصلة + مراجعة لغوية.

---
---

<a name="part6"></a>
# الجزء 6 — الأمان

## 6.1 نموذج التهديد — مين ممكن يأذي مين

| المهاجم | الهدف | الدفاع |
|---|---|---|
| غريب | يعرف مين مسجّل / يوصل لأرقام | منع الـ enumeration · إخفاء الأرقام · حدود المعدل |
| غريب | يتابع رحلة حية | تفويض القنوات · توكنات غير قابلة للتخمين |
| مستخدم عادي | يشوف عنوان بيت حد | تشويش الموقع في السيرفر · إظهار الدقيق بعد القبول بس |
| مستخدم عادي | يدخل مجموعة نساء فقط | استبعاد صارم في الاستعلام + اختبار |
| سائقة | تشوف طلبات الركاب وتصطاد | مفيش endpoint أصلاً + اختبار بيتأكد |
| سائقة | تسجّل حضور وهمي وتفوتر | نافذة اعتراض + GPS + كشف الأنماط |
| مستخدم | يستخدم كاش ومايدفعش عمولة | دفتر ديون + منع النشر |
| مهاجم | يزوّر webhook دفع | التحقق من التوقيع |
| مهاجم | يسرق جلسة | تدوير التوكن + كشف إعادة الاستخدام + إلغاء العائلة |
| موظف داخلي | يتفرج على بيانات المستخدمين | RBAC + سجل تدقيق غير قابل للتعديل + تنبيه على الوصول الشاذ |

## 6.2 قائمة فحص أمنية

### المصادقة
```
☐ OTP: hash · مستخدم مرة واحدة · مربوط بالغرض · مقارنة ثابتة الزمن
☐ حدود معدل مركّبة: رقم + جهاز + IP
☐ رد موحّد وزمن موحّد (منع الـ enumeration)
☐ تدوير refresh token + كشف إعادة الاستخدام + إلغاء العائلة
☐ الـ PIN محلي بالكامل — عمره ما يوصل السيرفر
☐ MFA إجباري لكل حسابات الأدمن
☐ انتهاء جلسة الأدمن بعد خمول
```

### التفويض
```
☐ Policy لكل موديل
☐ اختبار: كل route محمي (اختبار بيمر على كل الـ routes)
☐ اختبار: مستخدم أ مايقدرش يوصل لبيانات مستخدم ب
☐ تفويض قنوات البث
☐ صلاحيات الأدمن مفحوصة على مستوى الإجراء مش الصفحة
```

### البيانات
```
☐ تشفير على مستوى العمود: الرقم القومي · الرخصة · توكن الإشعارات
☐ Hash للبحث بدل فك التشفير
☐ المستندات على disk خاص + presigned قصير
☐ إزالة EXIF من كل صورة
☐ فحص فيروسات على كل رفع + رفض الملفات التنفيذية
☐ مسارات الملفات مش بتتسجل في اللوجز
☐ الـ IP مخزّن كـ hash
☐ حذف تلقائي مجدول (المستندات · مواقع الرحلات)
```

### الطبقة الشبكية
```
☐ HTTPS + HSTS
☐ ترويسات أمنية (CSP · X-Frame-Options · X-Content-Type-Options)
☐ CORS محدّد بدقة
☐ WAF قدام الإنتاج
☐ توقيع الـ webhooks
☐ مفيش أسرار في الـ repo · إدارة أسرار حقيقية
```

### اللوجز
```
☐ ممنوع تسجيل: OTP · التوكنات · الـ PIN · الرقم القومي · مسارات المستندات
☐ تنقية تلقائية للحقول الحساسة قبل التسجيل
☐ سجل التدقيق INSERT-only على مستوى صلاحيات الداتابيز
```

---

<a name="part7"></a>
# الجزء 7 — الأداء

## 7.1 ميزانية الاستجابة

| العملية | الهدف (p95) | الاستراتيجية |
|---|---|---|
| فتح التطبيق (routing) | < 300ms | استعلام واحد خفيف |
| طلب OTP | < 500ms | SMS في queue |
| **البحث عن مطابقات** | **< 800ms** | bbox → spatial → Google مكشوف |
| تفاصيل المطابقة | < 300ms | من الكاش |
| الموافقة على طلب | < 500ms | transaction قصيرة + إشعارات في queue |
| تحديث الموقع | < 100ms | Redis بس |
| صفحة الداشبورد | < 1s | استعلامات مجمّعة + كاش |

## 7.2 استراتيجية البحث بالتفصيل

```
الإدخال: نقطة البداية والنهاية، الأيام، نافذة الوقت، الفلاتر

المرحلة 1 — SQL بدون جغرافيا                    50,000 → 800    ~15ms
  status='published' AND seats_taken < seats_total
  AND days_mask & :day > 0
  AND audience مسموح للمستخدم
  AND bbox متقاطع              ← الفهرس المركّب
  AND driver approved
  AND NOT blocked (الاتجاهين)

المرحلة 2 — مسافة المشي (MySQL spatial)          800 → 40      ~40ms
  ST_Distance_Sphere(user_point, pickup_point) <= radius

المرحلة 3 — الانعطاف (Google + كاش)              40 صف          ~200ms
  90%+ من الكاش. الباقي بـ Distance Matrix دفعة واحدة

المرحلة 4 — التنقيط والترتيب (PHP)                40 صف          ~5ms

المرحلة 5 — تخزين في match_scores (ساعة)
```

## 7.3 استراتيجية الكاش

| البيانات | المكان | المدة | الإبطال |
|---|---|---|---|
| `platform_settings` | Redis | دائم | عند التعديل |
| المسارات (Google) | MySQL | 30 يوم | زمني |
| الزحمة | Redis | ساعة | زمني |
| نتائج المطابقة | MySQL | ساعة | زمني + عند تغيير المقاعد |
| موقع السائق الحالي | Redis | 60 ثانية | كل تحديث |
| إحصاءات المجموعة | Redis | 10 دقايق | عند اكتمال رحلة |
| KPIs الداشبورد | Redis | دقيقة | زمني |

## 7.4 خطة النمو

| المرحلة | المستخدمين | الإجراء |
|---|---|---|
| الإطلاق | < 5k | سيرفر واحد + MySQL + Redis |
| النمو | 5k–50k | فصل قاعدة البيانات · read replica · عمّال queue منفصلين |
| التوسع | 50k–200k | **الهجرة لـ PostGIS** · تقسيم `trip_locations` · CDN |
| الكبير | 200k+ | فصل خدمة المطابقة · بحث مخصص · تقسيم أفقي |

**مؤشرات تقول إن وقت الهجرة لـ PostGIS جه:**
- زمن البحث p95 > 800ms
- `commute_offers` النشطة > 20,000
- الحاجة لخرائط حرارية حية أو تجميع مسارات

---

<a name="part8"></a>
# الجزء 8 — الاختبارات

## 8.1 الهرم

```
       ╱╲       E2E (قليلة)  — سيناريو كامل: تسجيل → بحث → حجز → رحلة → دفع
      ╱──╲      Feature      — كل endpoint، كل Livewire component
     ╱────╲     Integration  — Actions مع داتابيز حقيقية
    ╱──────╲    Unit         — Value Objects · Enums · Scoring · Normalizers
```

**تغطية مطلوبة:** الدومين ≥ 80% · مسارات الفلوس والأمان **100%**

## 8.2 اختبارات إجبارية (مش قابلة للتفاوض)

```php
// التزامن
it('allows only one booking for the last seat under 10 concurrent requests');
it('never lets seats_taken exceed seats_total');

// الوقت
it('generates correct UTC across Egypt DST transition in April and October');
it('does not create duplicate trips when the generator runs twice');

// الخصوصية
it('never returns phone_e164 in any public API response');
it('never returns gender in any API response');
it('returns fuzzed pickup coordinates before booking is confirmed');
it('returns exact coordinates only after confirmation');
it('has no endpoint that exposes commute_demands to drivers');

// المطابقة
it('excludes men entirely from women_only offers — not just scores them lower');
it('excludes blocked users in both directions');
it('never returns offers from unapproved or expired-licence drivers');

// التقييم
it('hides ratings until both parties submit or the window expires');

// الفلوس
it('rejects a webhook with an invalid signature');
it('processes a duplicate webhook event exactly once');
it('keeps driver_amount + platform_fee exactly equal to amount');
it('reconciles driver_balances against driver_fee_ledger');
it('blocks publishing when driver debt exceeds the configured cap');

// التفويض
it('protects every API route with authentication')->with(allRoutes());
it('prevents user A from reading user B data')->with(allResourceRoutes());
it('rejects websocket subscription to a trip the user is not part of');
```

## 8.3 أدوات
```
Pest 5                الإطار
Pest Architecture     يفرض قواعد المعمار:
                      - Controllers مايستخدموش DB مباشرة
                      - Domain مايعرفش Illuminate\Http
                      - مفيش ST_ خارج Domains/Geo
FakeGeoEngine         بدل Google في الاختبارات
Http::preventStrayRequests()   يمنع أي نداء خارجي حقيقي
Paratest              تشغيل متوازي
```

مثال على اختبار معماري:
```php
arch('geo queries stay in the Geo domain')
    ->expect('App')
    ->not->toUse(['DB::raw'])
    ->ignoring('App\Domains\Geo');

arch('domain layer is framework-independent')
    ->expect('App\Domains\*\Actions')
    ->not->toUse(['Illuminate\Http\Request', 'Illuminate\Http\Response']);
```

---

<a name="part9"></a>
# الجزء 9 — خطة التنفيذ خطوة بخطوة

## المرحلة 0 — الأساسات (3–5 أيام)

```
اليوم 1   ☐ تثبيت الحزم (Sanctum · Reverb · Spatie Permission · Pest · PHPStan · Scramble)
          ☐ ضبط قواعد Model الصارمة + CarbonImmutable + timezone
          ☐ Docker: MySQL 8 · Redis · MinIO · Mailpit
اليوم 2   ☐ هيكل app/Domains + Service Providers
          ☐ ApiResponse envelope + معالج الأخطاء + كتالوج الأكواد
          ☐ Value Objects: Money · PhoneNumber · Coordinate · DaysMask
اليوم 3   ☐ CI: Pint + PHPStan level 8 + Pest + اختبارات معمارية
          ☐ ملفات الترجمة ar/en + الهيكل
اليوم 4-5 ☐ توحيد الـ design tokens (لون واحد للـ brand)
          ☐ تخطيط الأدمن الأساسي بـ Livewire (sidebar + header + theme)
```

**معيار الانتهاء:** `composer test` أخضر · بيئة بتشتغل بأمر واحد · صفحة أدمن فاضية شغالة.

---

## المرحلة 1 — الداتابيز (7–10 أيام) ← **نبدأ هنا**

```
الخطوة 1  ☐ ERD كامل بـ Mermaid → مراجعة → اعتماد
الخطوة 2  ☐ الهجرات بالترتيب (14 مجموعة)، كل مجموعة PR منفصل
الخطوة 3  ☐ الموديلات: العلاقات · Casts · Enums · Scopes
الخطوة 4  ☐ Factories لكل موديل
الخطوة 5  ☐ Seeder سيناريو كامل:
             نور (سائقة معتمدة) · مريم (راكبة موثّقة) · مجموعة شغالة
             كورريدور · 24 في طابور التوثيق · حالة أمان مفتوحة
الخطوة 6  ☐ اختبارات القيود:
             ☐ رقم موبايل مكرر مرفوض
             ☐ لوحة مكررة مرفوضة
             ☐ سيارة نشطة واحدة بس
             ☐ رحلة واحدة لكل يوم لكل عرض
             ☐ حجز واحد لكل راكب لكل رحلة
             ☐ المقاعد مايتعدوش سعة العربية
الخطوة 7  ☐ فحص الفهارس: EXPLAIN على كل استعلام حرج
```

**معيار الانتهاء:** `migrate:fresh --seed` نظيف · كل القيود مختبرة · ERD موثّق.

---

## المراحل 2–15

| # | المرحلة | المدة | المخرَج للفريق الخارجي |
|---|---|---|---|
| 2 | المصادقة والجلسات | 5–7 أيام | 🚀 **staging + OpenAPI للـ auth** |
| 3 | البروفايل والتوثيق | 5–7 أيام | endpoints التوثيق |
| 4 | السائق والمركبات | 5 أيام | + طابور المراجعة في الأدمن |
| 5 | الأماكن والمسارات والنشر | 7–10 أيام | endpoints النشر |
| 6 | **محرك المطابقة** ⭐ | 10–14 يوم | endpoint البحث |
| 7 | الطلبات والحجوزات والمجموعات | 10 أيام | دورة الحجز كاملة |
| 8 | المدفوعات | 7–10 أيام | Paymob + الكاش |
| 9 | الرحلة الحية والتتبع | 10 أيام | WebSocket + التتبع |
| 10 | التقييمات والثقة | 4 أيام | |
| 11 | الأمان والحوادث | 7 أيام | SOS + المشاركة الحية |
| 12 | الإشعارات والمحادثة | 5 أيام | |
| 13 | داشبورد الأدمن (9 أقسام) | 12–15 يوم | — (داخلي) |
| 14 | التحليلات والتوصيات | 5 أيام | |
| 15 | التجهيز للإنتاج | 7 أيام | |

**MVP قابل للإطلاق =** 0–9 + الحرج من 11 و 13.

---

## قواعد الشغل اليومية

```
1. كل مرحلة = branch منفصل، PR واحد أو أكتر
2. مفيش merge من غير: Pint ✓ · PHPStan 8 ✓ · Pest ✓ · اختبار معماري ✓
3. كل Action جديد = اختبار في نفس الـ PR
4. كل تغيير في الـ API = تحديث الـ spec في نفس الـ PR
5. كل رقم سحري → platform_settings
6. أي استعلام جغرافي → عبر GeoQueryEngine بس
7. أي فلوس → Money value object
8. قبل ما تبدأ مرحلة: راجع فخاخ الجزء 5 المتعلقة بيها
```

## أول 3 خطوات دلوقتي

```
1️⃣  ERD كامل بـ Mermaid            ← الخطوة الجاية
2️⃣  مراجعتك واعتمادك
3️⃣  المرحلة 0 + المرحلة 1
```

---

**نهاية المستند.**
