# RAFEEQ — فهم المشروع + خطة التنفيذ الشاملة

> مستند مرجعي واحد: يشرح إيه هو رفيق بالظبط، إيه الفرق بين الـ book والـ prototype،
> تحليل الديزاين (موبايل + داشبورد)، وخطة تنفيذ مرتّبة خطوة بخطوة تبدأ من الداتابيز.
>
> **الحالة:** خطة معتمدة للمراجعة — لسه مفيش كود اتكتب.

---

# الجزء الأول — فهم المنتج

## 1. رفيق إيه بالظبط؟

رفيق **مش تطبيق تاكسي**. ده الأساس اللي كل قرار في المنتج مبني عليه.

| | Uber / inDrive | RAFEEQ |
|---|---|---|
| الطلب | فوري (on-demand) | مُخطَّط مسبقًا (planned) |
| مين بيبدأ | الراكب يطلب، النظام يوزّع | السائق ينشر رحلته، الراكب يبحث ويطلب مقعد |
| العلاقة | مرة واحدة، مجهولة | **مجموعة ثابتة** تتكرر أسبوعيًا |
| الفلوس | أجرة (fare) + surge | **مساهمة في التكلفة** (cost-sharing)، بدون surge |
| القبول | تلقائي | **السائق يوافق يدويًا** على كل طلب مقعد |

الجملة اللي بتلخّص كل حاجة، من الـ prototype نفسه:

> "Contributions are cost-sharing, not fares."
> "RAFEEQ never dispatches drivers like a ride-hailing app."

### الفكرة في جملة واحدة
> نور بتروح شغلها من الرحاب للقرية الذكية كل يوم الساعة 7. رفيق بيخليها تنشر
> الطريق ده، ومريم — اللي بتروح نفس الاتجاه في نفس الميعاد — تطلب مقعد.
> بعد موافقة نور، الاتنين بيبقوا في **مجموعة تنقّل ثابتة** بقواعد وحضور ومحاسبة أسبوعية.

## 2. تحديد المنتج (Positioning) — نقطة حساسة

الـ prototype بيوضّح إن رفيق في الإطلاق هو **شبكة نساء أولًا (women-first)** في القاهرة الكبرى:

- الداشبورد بيقول: `18,402 verified women · 2,714 active drivers`
- كل السائقين والركاب في الـ mock data ستّات
- `Women-only` هو الـ **default filter** في الموبايل (`filters.women: true`)
- في فيتشرز موجودة عشان ده بالذات: **Night escort mode**، **Guardian live share**، **Discreet silent alert**
- كل نصوص الموبايل بالعربي مكتوبة بصيغة المؤنث ("شاركي"، "سجّلي الدخول"، "وثّقي هويتك")

الـ book بيتكلم بصيغة محايدة (Ahmed سائق، Sara راكبة) وبيذكر women-only كـ "preference" اختياري بس.

> **قرار مطلوب منك:** هل الإطلاق women-only (زي الـ prototype)، ولا mixed مع فلتر women-only
> (زي الـ book)؟ ده بيغيّر: قواعد الـ matching، الـ gender في الـ users table، شروط التسجيل،
> نصوص الـ UI كلها، والـ legal/compliance.

## 3. الأدوار

**حساب واحد للشخص الواحد.** مفيش "حساب سائق" و"حساب راكب" منفصلين.

```
users (شخص واحد، رقم موبايل واحد موثّق)
  ├── passenger capability  → متاحة افتراضيًا
  └── driver capability     → مقفولة، بتتفتح بـ driver_profile بعد التوثيق
```

في الموبايل فيه زرار `Switch to Driver / Passenger` بيبدّل الـ `activeRole` بس — نفس الحساب، نفس
التقييم، نفس التاريخ. عند التسجيل بيختار: `driver` / `passenger` / `both`.

## 4. المفاهيم الأساسية (Domain Concepts)

دي أهم حاجة تفهمها — لأن الداتابيز كلها مبنية عليها:

| المفهوم | التعريف | مثال |
|---|---|---|
| **Corridor** | مسار متكرر معروف على مستوى الشبكة، الـ matching engine بيديله أولوية | `Maadi → Smart Village · Weekdays 07:00–08:30` |
| **Commute Offer** | رحلة نشرها سائق — recurring أو one-time | `نور: الرحاب → القرية الذكية، أحد–خميس 7:05` |
| **Commute Schedule** | قاعدة التكرار (أيام + ميعاد + تاريخ بداية/نهاية) | `Sun–Thu, 07:05, حتى 31 ديسمبر` |
| **Scheduled Trip** | **رحلة يوم واحد** متولّدة من الـ schedule — ده اللي بيتحجز فعليًا | `رحلة الأحد 12 يناير 7:05` |
| **Commute Demand** | طلب راكب محفوظ لما مفيش matching حاليًا (خاص، السائق مبيشوفهوش) | `مريم عايزة الرحاب → سمارت فيلدج 7 ص` |
| **Seat Request** | طلب مقعد محتاج موافقة السائق — `trial` أو `recurring` | |
| **Booking** | حجز مقعد مؤكَّد في scheduled_trip | |
| **Commute Group** | المجموعة الثابتة (سائق + ركاب) اللي بتتكرر — ليها أعضاء وقواعد وحضور وكشف حساب | `Rehab → Smart Village · AM` |
| **Trip Session** | التنفيذ الفعلي لرحلة يوم (بدأت، GPS، check-in، انتهت) | |

**قاعدة ذهبية:** الراكب بيحجز `scheduled_trips` — **مش** `commute_offers` ولا `recurrence rules`.

## 5. رحلة المستخدم الكاملة (End-to-end)

```
[راكبة]                                    [سائقة]
تسجيل بالموبايل + OTP + PIN                 نفس التسجيل
       ↓                                          ↓
Verification Centre (4 مستويات)            Verification + رخصة + سيارة
  1. Phone verified          ✓                     ↓
  2. Government ID           ⚠ مطلوب        Admin review (24–48h)
  3. Selfie / liveness       ⏳ pending             ↓
  4. Workplace/Uni badge     ✗ action needed  Publish Route
       ↓                                     (أيام، ميعاد، مقاعد، سعر، قواعد)
Discover / Search                                  ↓
  فلاتر: women-only, same-uni, quiet,        scheduled_trips تتولّد
         min rating, max walk, max detour           ↓
       ↓                                     ← يستقبل Seat Request
Match Details (score من 100)                       ↓
  + Price breakdown + Group rules            Approve / Suggest another pickup / Reject
       ↓                                            ↓
Request Seat  (trial / recurring)  ───────────────→ ✓
  اختيار الأيام + نقطة الالتقاء + رسالة تعريف
       ↓
Pending / Waitlist → Approved
       ↓
دخول الـ Commute Group
  Overview · Members · Payments · Rules
       ↓
يوم الرحلة: Pre-trip check-in → Live trip → Safety button
       ↓
Complete → Rating (نجوم + tags) → أرباح السائق تتحرّر
       ↓
كشف حساب أسبوعي → دفع (InstaPay / Vodafone Cash / بنك)
       ↓
Payout run أسبوعي للسائقة (من الداشبورد)
```

## 6. محرك المطابقة (Matching Score) — من الـ prototype

ده **مش مذكور في الـ book** لكنه موجود بالتفصيل في الموبايل. مجموع 100 نقطة:

| المعيار | الوزن | الحساب في الـ prototype |
|---|---:|---|
| Route overlap | 30 | `overlap% × 0.30` |
| Schedule fit | 25 | 24 لو score ≥95، غير كده 22 |
| Detour | 15 | ≤6د → 15 · ≤8د → 13 · غير كده 11 |
| Audience (women-only) | 10 | مطابق → 10 · غير كده 6 |
| Comfort (quiet…) | 10 | مطابق → 9 · غير كده 7 |
| Price | 5 | 5 |
| Reliability | 5 | `reliability / 20` |

**قاعدة حاسمة:** `"Hard conflicts (audience, verification) never receive a soft score."`
يعني لو المجموعة women-only والطالب مش مستوفي → **استبعاد كامل**، مش خصم نقاط.

### الفلاتر الصارمة (Hard filters) في الـ prototype
`women` · `same university` · `quiet` · `min rating` · `max walk (3–20 دقيقة)` · `max detour (3–20 دقيقة)`

## 7. نموذج الفلوس

مش "أجرة" — **مساهمة في تكلفة الطريق مقسومة**:

```
Route contribution            EGP 240
Split across 3 passengers   ÷ 3
                            = EGP 80 each
Platform fee                  included  (3% حسب الداشبورد)
─────────────────────────────────────
Your total / ride             EGP 88
"لو انضم راكب رابع، حصتك تنزل لـ ~EGP 68"
```

- **كشف حساب أسبوعي** للمجموعة: `4 rides × EGP 88 = EGP 352 · Due Thursday`
- طرق الدفع: **InstaPay · Vodafone Cash · تحويل بنكي (CIB / NBE)**
- **Payout run أسبوعي** للسائقين من الداشبورد (`Release all cleared`)
- الفلوس بتفضل `pending` لحد ما الرحلة تكتمل، وبعدها تتحوّل `available`
- حالات الـ payout: `Cleared` / `On hold` / `Blocked`

## 8. منظومة الأمان (Safety) — أقوى فيتشر في المنتج

**قبل الرحلة**
- 4 مستويات توثيق · `blocked_users` · إخفاء الموقع الدقيق (`blur exact home pins` — نصف قطر 200م لحد ما السائق يبقى على بُعد 5 دقايق)
- رقم الموبايل **مخفي دائمًا** عن الأعضاء التانيين

**أثناء الرحلة**
- زرار Safety ظاهر دايمًا · Call 122 · Alert Rafeeq team
- **Discreet silent alert** — إنذار صامت من غير ما حد ياخد باله
- **Guardian live share** — جهتين اتصال موثوقتين بيتابعوا الرحلة live
- **Auto-share trips** — مشاركة تلقائية لكل رحلة
- PIN عند الالتقاء (اختياري حسب الـ policy)
- تسجيل صوتي مشفّر opt-in (محفوظ 72 ساعة)
- **Night escort mode** — بيتفعّل تلقائيًا 9م–5ص مع مراقبة من فريق العمليات

**بعد الرحلة**
- بلاغ من 5 خطوات: Category → Safety check → Description → Evidence → Done
- التصنيفات: تحرش · قيادة خطرة · عدم تطابق الهوية/السيارة · مشكلة دفع · no-show · فقدان غرض

**عند العمليات (Ops)**
- Safety cases بأربع درجات خطورة · SOS response clock (median first-touch, هدف < دقيقة)
- Escalate to police liaison · Freeze driver account · Message all riders on trip

## 9. حالات الحافة (Edge cases) اللي الـ prototype غطّاها

دي قيمتها عالية جدًا — الـ book مذكرش أغلبها:

`Offline` · `Location permission denied` · `Payment failed` · `Account restricted` ·
`Route changed mid-trip` · `Passenger no-show` · **`Driver wait timer`** (5 دقايق grace بعدّاد
حي، لونه بيتغيّر أحمر تحت الدقيقة، ثم "No-show") · `Driver cancelled → backup search` ·
`Matching spinner` · `Waitlist (position 1 of 2)` · **`Custom pickup approval`** (الراكب يطلب
نقطة التقاء مخصصة → السائق يشوف "+4 min / 1.8 km" ويوافق أو يقترح بديل أو يرفض) ·
`Verification gating` (لو طلبت مقعد وأنتِ مش موثّقة → تتحوّلي للتوثيق وترجعي لنفس المكان)

---

# الجزء الثاني — تحليل الديزاين

## 10. نظام التصميم المشترك (Design Tokens)

الاتنين بيستخدموا نفس النظام الأساسي (اسمه في الملف "Sahla design tokens") مع light + dark:

```css
/* الأساس المشترك */
--brand-violet-500: #1ACB64   /* الاسم "violet" لكن اللون أخضر — الـ brand الأساسي */
--paper-50: #faf7f2 ... --paper-900: #1a1612    /* نيوترال دافي (warm) مش رمادي */
--ink-1: #1a1612 ... --ink-5: #c4bca8            /* درجات النص */
--success-500: #2aa66e · --warning-500: #e89527 · --danger-500: #e25555

/* Typography */
--font-display: 'Anthropic Sans Display'   /* عناوين وأرقام كبيرة */
--font-sans:    'Anthropic Sans Text'      /* نصوص */
--font-mono:    JetBrains Mono             /* IDs، أوقات، أرقام تقنية */

/* Scale */
--radius: xs 4 · sm 6 · md 10 · lg 14 · xl 20 · 2xl 28 · pill 9999
--space:  4 8 12 16 20 24 32 40 48 64 80 96
--dur:    fast 120ms · base 180ms · slow 260ms
--ease-out: cubic-bezier(0.22, 0.61, 0.36, 1)
```

**الداشبورد بيعمل override بطبقة تانية teal:**
```css
--rq-700: #0F766E   /* الـ primary في الداشبورد */
--rq-950: #042f2c   /* الكروت الغامقة */
--desk: #ECEFEA     /* خلفية المكتب */
```

> **ملاحظة مهمة:** الموبايل أخضر (`#1ACB64`) والداشبورد تركوازي (`#0F766E`).
> لازم قرار: نوحّدهم؟ ولا نعتبر الداشبورد أداة داخلية بهوية مختلفة عن قصد؟
> **توصيتي:** نوحّد الـ palette في design system واحد قبل ما نكتب أي CSS.

---

## 11. ديزاين الموبايل (47 شاشة)

**الشكل العام:** إطار موبايل، bottom nav زجاجي (frosted glass) بـ 5 تابات:
`Home · Discover · Create · Trips · Profile`

**دعم كامل:** عربي RTL + إنجليزي LTR · light + dark · حفظ التفضيلات في localStorage

### الشاشات مجمّعة حسب المسار

**أ. الدخول والتسجيل (7)**
`Splash/Welcome` — 3 قيم: شاركي الطريق · تطابقي مع موثّقين · مساهمات واضحة وأمان هادئ
`Login (PIN 4 أرقام)` · `Use another account` (حسابات على نفس الجهاز) · `Forgot PIN`
`Phone` (+20، والرقم "Hidden from other users — always") · `OTP` · `Basic profile` (اسم أول
عام، تاريخ ميلاد، لغة، نوع، **العمل أو الجامعة**) · `Role` (أقود / أحتاج مقعد / الاتنين)

**ب. التوثيق (3)**
`Verification Centre` — شريط تقدّم "Trust level 2 of 4 · 50%" وأربع صفوف بحالات مختلفة
(✓ متحقق · ⚠ مطلوب · ⏳ قيد المراجعة · ✗ محتاج إجراء مع سبب واضح)
`ID capture` · `Vehicle & licence capture`
جملة مهمة في آخر الشاشة: *"Badges show what was checked. They never guarantee a person's behaviour."*

**ج. البحث والمطابقة (5)**
`Home` (تحية + بانر توثيق + الرحلة القادمة + مطابقات على طريقك + المسارات المحفوظة)
`Discover` (list / map toggle، عدّاد الفلاتر، حالة "مفيش مطابقة" مع خيار حفظ البحث)
`Filters` (Audience & trust · Route · Atmosphere)
`Match details` (السكور مفكوك لـ 7 بنود + تفصيل السعر + قواعد المجموعة + التقييمات)
`Matching spinner`

**د. الطلب (2)**
`Seat request` — trial / recurring · اختيار الأيام · نقطة الالتقاء (بوابة/شارع/معلم/**مخصصة**)
· رسالة تعريف · **checkbox موافقة على القواعد إجباري**
`Request done` — نسختين: Pending أو Waitlist

**هـ. الرحلات والمجموعة (3)**
`Trips` (5 تابات: Upcoming · Requests · Groups · History · Cancelled)
`Commute Group` (5 تابات: Overview · Calendar · Members · Payments · Rules)
- Overview: 98% on-time · 42 rides together · 1 seat open · حضور بكرة لكل عضو · "Mark a planned absence"
- Members: **"Full names and numbers stay private within the group"**
- Payments: كشف الأسبوع + زرار InstaPay
- Rules: الشارات + "Minimum commitment: 3 days/week"
`Active trip`

**و. السائق (5)**
`Driver home` · `Publish route` (مقاعد 1–4 · سعر 50–120 مع "Fair recommended = 80" ·
detour 5–30 · قواعد · أيام) · `Publish review` · `Driver request review` · `Custom pickup approval`

**ز. الأمان (3)**
`Safety Centre` (Call 122 · Alert Rafeeq · **Discreet silent alert** · جهات الاتصال الموثوقة ·
Auto-share toggle) · `Discreet alert active` · `Support/incident` (5 خطوات)

**ح. حالات الرحلة (8)**
`Pre-trip check-in` · `Driver wait timer` (حي، 5 دقايق) · `No-show` · `Route changed` ·
`Driver cancelled → backup` · `Rating` (نجوم + 6 tags) · `Cancel confirmation` · `Cancel sheet`

**ط. النظام والحساب (6)**
`Profile` · `Notifications` · `Privacy & blocked` · `Help & legal` · `Offline` ·
`Location denied` · `Payment failed` · `Account restricted`

**ي. Bottom sheets (2)**
`Direction` (إلى العمل صباحًا / إلى المنزل مساءً + swap) · `Cancel today`

### أنماط UI متكررة (لازم تبقى components)
`Pill badge` · `Toggle switch` · `Chip (day/rule/tag)` · `Stat card` · `Match card` ·
`Progress bar` · `Radio card` · `Section header` · `Bottom nav` · `Bottom sheet` ·
`Empty state` · `Toast` · `Stepper`

---

## 12. ديزاين داشبورد الـ Super Admin

**التخطيط:** شاشة desktop (min-width 1280) — كارت أبيض كبير radius 26 داخل خلفية `--desk`

```
┌─ Sidebar 224px ──┬─ Main ────────────────────────────────────┐
│ 🔗 Rafeeq        │ Header 66px:                              │
│                  │  [🔍 Search member, trip ID, plate…  ⌘K]  │
│ OPERATIONS       │  [● All systems normal] [✉][🔔•][🌙][👤] │
│  Dashboard       ├───────────────────────────────────────────┤
│  Live trips  38  │  Page title + subtitle                    │
│  Verification 24 │              [Export] [+ Primary CTA]     │
│  Safety cases  9 │                                           │
│  Members         │  ⚠ Critical alert banner (dash/live/cases)│
│  Corridors       │                                           │
│  Payments        │  ← محتوى القسم                            │
│                  │                                           │
│ GENERAL          │                                           │
│  Audit log       │                                           │
│  Settings        │                                           │
│  Log out         │                                           │
│                  │                                           │
│ [Night-shift     │                                           │
│  escort mode]    │                              [Toast ✓]    │
└──────────────────┴───────────────────────────────────────────┘
```

**تفاصيل الـ nav:** العنصر النشط له `inset 3px 0 0` شريط جانبي + خلفية `--sunken` + أيقونة
داخل مربع لونه brand. الـ badges: live=أخضر · verify=برتقالي · cases=أحمر.

### الأقسام التسعة

| # | القسم | المحتوى |
|---|---|---|
| 1 | **Dashboard** | 4 KPI (أول واحد غامق brand) · Bar chart طلب أسبوعي · "Needs your decision" (قرار واحد + Reinstate/Uphold) · Top corridors · Recent ops actions · Donut throughput (66% cleared in 24h) · **SOS response clock (00:41 mono)** |
| 2 | **Live trips** | جدول 6 أعمدة: Trip · Route · Driver & car · Seats · Status · Action. صف الخطر خلفيته حمراء. أزرار Track / Call rider / Nudge / Refill seat |
| 3 | **Verification** | Grid 3 أعمدة كروت. كل كارت: اسم + دور + مدة انتظار + risk pill + 3 فحوصات (✓/!/✕) + Approve / Ask info / ✕ Reject |
| 4 | **Safety cases** | عمودين: قائمة الحالات (نقطة خطورة + عنوان + ID + تفاصيل + meta) + جانب: **Emergency actions** (Escalate to police liaison · Freeze driver · Message all riders) + Case mix bars |
| 5 | **Members** | تابات (All/Drivers/Riders/Flagged) + جدول: Member · Role · Trips · Rating · Status · Manage |
| 6 | **Corridors** | Grid كروت: اسم + نافذة زمنية + status pill + 3 أرقام (drivers/seekers/seat fill) + bar + Boost/Recruit/Campaign |
| 7 | **Payments** | 4 KPI (Contributions EGP 612,400 · Platform fee 3% · Awaiting release · Disputed) + جدول payouts + "Release all cleared" |
| 8 | **Audit log** | 4 أعمدة: وقت (mono) · إجراء + هدف · الفاعل · tag. "Every admin action, immutable · retained 24 months" |
| 9 | **Settings** | Trust & safety toggles (escort · blur pins · PIN at pickup · guardian share · audio recording) + فريق الأدمن + **Data residency** (Cairo eg-central-1، حذف بعد 90 يوم) |

**أدوار الأدمن (من الـ prototype):** Super admin · Verification · Payments · Safety lead
**(من الـ book):** Super Admin · Operations · Support Agent · Finance Admin
→ لازم نوحّدهم في RBAC واحد.

---

# الجزء الثالث — تحليل الفجوات

## 13. الـ Book مقابل الـ Prototype

| البند | Book | Prototype | القرار المقترح |
|---|---|---|---|
| Positioning | محايد | **women-first** | ← الـ prototype |
| PIN | 6 أرقام | **4 أرقام** | ← الـ prototype |
| Commute Groups | ❌ غير موجود | ✅ محور المنتج | ← الـ prototype (فيتشر أساسي مفقود من الـ book) |
| Corridors | ❌ | ✅ | ← الـ prototype |
| Match score | "sorts by match score" مبهم | **نموذج 100 نقطة مفصّل** | ← الـ prototype |
| موافقة السائق على الحجز | حجز مباشر | **Seat request + approval + waitlist** | ← الـ prototype |
| Custom pickup approval | ❌ | ✅ | ← الـ prototype |
| Trust levels | `profile_status` enum | **4 مستويات مرئية** | دمج |
| توثيق العمل/الجامعة | ❌ | ✅ (badge/email) | ← الـ prototype |
| Discreet alert / Escort mode | ❌ | ✅ | ← الـ prototype |
| Driver wait timer / grace | ❌ | ✅ 5 دقايق | ← الـ prototype |
| كشف حساب أسبوعي | wallet transactions | **group statement أسبوعي** | دمج |
| طرق الدفع | مبهم | **InstaPay / Vodafone Cash / بنك** | ← الـ prototype |
| DB | PostgreSQL | — | المشروع الحالي MySQL ← قرار مطلوب |
| Backend | Microservices | — | المشروع الحالي Laravel monolith ← modular monolith |
| Frontend | Flutter + Admin Web | — | Flutter + Laravel admin |

**الخلاصة:** الـ prototype هو **مصدر الحقيقة للـ UX والفيتشرز**، والـ book هو **مصدر الحقيقة
للقواعد الأمنية وسلوك الـ backend والـ QA checklists**. الاتنين مكمّلين مش متضاربين.

## 14. حالة الكود الحالية

```
Laravel 13.8 · PHP 8.3 · Pest 5 · Pint · Laravel Boost
MySQL (DB_DATABASE=rafeeq) · Queue: database · Cache: database · Session: database
Tailwind 4 + Vite 8
```
**الموجود فعليًا:** هيكل Laravel فاضي — `User` model افتراضي + 3 migrations افتراضية.
**يعني:** بنبدأ من الصفر — وده مثالي، مفيش دين تقني.

---

# الجزء الرابع — الخطة

## 15. القرارات المعمارية (لازم تتحسم قبل السطر الأول)

> **✅ محسومة — 3 أغسطس 2026**

| # | القرار | **المعتمد** | ملاحظات |
|---|---|---|---|
| D1 | قاعدة البيانات | **MySQL 8 دلوقتي · PostGIS لاحقًا** | ⚠️ راجع القسم 15.1 تحت — إجراءات إلزامية لتقليل ألم الهجرة |
| D2 | معمارية الباك | **Modular Monolith** بـ Laravel (`app/Domains/*`) | فريق صغير، سرعة تسليم، سهل التقسيم بعدين |
| D3 | الموبايل | **Flutter — فريق خارجي** | ⚠️ احنا بنسلّم APIs بس. راجع 15.4 |
| D4 | الداشبورد | **Livewire 3 + Alpine + Tailwind 4** | لغة واحدة، مفيش API layer للأدمن |
| D12 | Positioning | **Mixed + فلتر women-only** | الاتنين يسجلوا، والسائقة تحدد جمهور مجموعتها |
| D13 | توقيت الدفع | **بعد إتمام الرحلة** | مش قبلها ومش كشف أسبوعي |
| D14 | وسائل الدفع | **كاش · أونلاين (خصم تلقائي)** | الراكب بيختار وقت الطلب |
| D15 | حركة الأموال | **Paymob marketplace/split** | مفيش محفظة احتجازية عندنا |
| D16 | عمولة الكاش | **دين متراكم على السائقة** | يتخصم من أرباح الأونلاين أو يتدفع دوريًا |
| D17 | الخرائط | **Google Maps للكل + كاش قوي** | ⚠️ الكاش مش اختياري — راجع 15.3 |
| D18 | تأكيد الحضور | **السائقة بتأكد وصول الراكب** | محتاج نافذة اعتراض — راجع 15.5 |
| D19 | Trial → Recurring | **الراكب يطلب · السائقة توافق** | رضا متبادل |
| D20 | توثيق الجهة | **إيميل الدومين لو متاح · وإلا بادج بمراجعة** | |
| D5 | المصادقة | **Sanctum** + refresh token rotation مخصص + PIN محلي | زي ما الفصل 2 موصّف بالظبط |
| D6 | الـ Realtime | **Laravel Reverb** (WebSocket) + Redis | live location + trip status |
| D7 | التخزين | S3-compatible خاص + presigned URLs قصيرة العمر | المستندات حساسة، ممنوع public URLs |
| D8 | الـ IDs | ULID للجداول العامة، bigint للجداول الداخلية | آمن للعرض + ترتيب زمني |
| D9 | العملة | تخزين **integer piastres** مش decimal | تجنّب أخطاء الفاصلة العشرية |
| D10 | التوقيت | تخزين UTC، عرض `Africa/Cairo` | |
| D11 | الـ palette | توحيد على palette واحد | حاليًا الموبايل أخضر والداشبورد تركوازي |

---

## 15.1 نتائج قرار MySQL — إجراءات إلزامية

القرار معتمد. لكن عشان الهجرة لـ PostGIS بعدين ما تبقاش كارثة، **دي مش اختيارية**:

### أ. عزل كل استعلام جغرافي وراء طبقة واحدة
```php
app/Domains/Geo/Contracts/GeoQueryEngine.php     ← interface
app/Domains/Geo/Engines/MySqlGeoEngine.php       ← التنفيذ الحالي
app/Domains/Geo/Engines/PostGisGeoEngine.php     ← يتكتب وقت الهجرة
```
**ممنوع** أي `ST_*` أو `DB::raw` جغرافي في الـ Models أو الـ Controllers أو الـ Actions.
كله يعدّي من الـ interface. الهجرة تبقى = كتابة كلاس واحد جديد + تبديل binding.

الدوال المطلوبة في الـ interface:
```php
distanceMeters(Point $a, Point $b): int
pointsWithinRadius(Point $center, int $meters, string $table): Collection
routeOverlapPercent(string $polylineA, string $polylineB): float
detourMinutes(string $polyline, Point $pickup): float
snapPointToRoute(string $polyline, Point $p): Point
cellsForHeatmap(BoundingBox $box, int $precision): Collection
```

### ب. تخزين الجغرافيا بشكل محايد
- `POINT SRID 4326` + `SPATIAL INDEX` (متوافق مع الاتنين)
- المسارات: **encoded polyline (string)** مش `LINESTRING` — لأن دوال الخطوط في MySQL ضعيفة
  وهنحسبها في PHP دلوقتي. لما نروح PostGIS نحوّلها لـ `geography(LineString)` من غير ما نغيّر الـ API.
- خزّن `bbox_min_lat / bbox_max_lat / bbox_min_lng / bbox_max_lng` كأعمدة عادية مفهرسة
  → الـ pre-filter السريع بيشتغل على أي قاعدة بيانات بدون دوال جغرافية أصلاً.

### ج. استراتيجية المطابقة على MySQL (مرحلة 6)
```
1. bounding box filter        → أعمدة عادية + index      (يقلل 95% من الصفوف)
2. ST_Distance_Sphere         → MySQL native             (walk distance)
3. route overlap + detour     → PHP على المرشحين فقط     (~20-50 صف)
4. cache النتيجة في match_scores مع expires_at
```
ده يشتغل كويس لحد **عشرات الآلاف من العروض النشطة**. بعد كده الـ overlap في PHP هيبقى العنق.

### د. مؤشرات تقول إن وقت الهجرة جه
- زمن استجابة البحث > 800ms في الـ p95
- عدد `commute_offers` النشطة > ~20,000
- الحاجة لـ heatmaps حية أو route clustering (الفصل 13)

### هـ. اختبارات تحمي الهجرة
كل دالة في `GeoQueryEngine` يبقى ليها contract test بنفس الـ fixtures.
لما نكتب `PostGisGeoEngine` نشغّل نفس الاختبارات عليه → لو عدّت، الهجرة آمنة.

---

## 15.2 نتائج قرار Mixed + فلتر women-only

- `users.gender` = `woman | man | prefer_not_to_say` — **مطلوب** (مش optional)
  لأنه بيدخل في الـ hard filter. محتاج مراجعة قانونية للتخزين والاستخدام.
- `commute_offers.audience` = `women_only | any_verified` — السائقة هي اللي بتحدد
- `commute_demands.audience_preference` = نفس القيم
- **قاعدة صارمة:** لو `audience = women_only` والطالب مش `gender = woman`
  → **استبعاد كامل من النتائج**، مش خصم نقاط (مبدأ الـ hard conflict من الـ prototype)
- المعيار العاشر في نموذج الـ 100 نقطة (`Audience: 10`) بيبقى للتطابق **الناعم** بس:
  عضوة في مجموعة women-only بتفضّل women-only → 10، مقابل 6 لو محايدة
- الـ UI: الفلتر `women` يفضل **default = true** للمستخدمات، و`false` للمستخدمين
- نصوص الموبايل: محتاجة نسختين عربي (مؤنث/محايد) — الـ prototype كله مؤنث دلوقتي

---

## 15.3 Google Maps — الكاش مش اختياري

الـ matching بينادي الخرائط **بشراهة**: كل بحث فيه 20–50 مرشح، وكل مرشح محتاج
حساب detour. من غير كاش، البحث الواحد ممكن يكلّف دولار.

### جدول `route_cache` (جزء أساسي من Phase 1)
```
route_cache   cache_key(unique)          ← hash(origin_rounded, dest_rounded, waypoints, mode)
              origin_point destination_point waypoints_hash
              polyline distance_meters duration_seconds duration_in_traffic_seconds
              provider('google') fetched_at expires_at hit_count
```

### قواعد إلزامية
1. **تقريب الإحداثيات لـ 4 خانات عشرية (~11 متر)** قبل عمل الـ key — المسارات المتكررة
   من نفس الكمبوند هتشارك نفس الكاش
2. **الـ TTL طويل للمسافة والمسار (30 يوم)** لأن الطرق مبتتغيرش. الـ traffic لوحده قصير
3. **مفيش استدعاء Google داخل حلقة `foreach`** — كله عبر `GeoQueryEngine` مع batching
4. **Distance Matrix** بدل Directions المتكرر لما نحتاج مسافات كتير دفعة واحدة
5. `commute_offers.route_polyline` بيتحسب **مرة واحدة عند النشر** ويتخزن — مش كل بحث
6. مراقبة: لوحة تعرض عدد الاستدعاءات والتكلفة اليومية + تنبيه عند تجاوز حد

### الخطوة 1 و 2 من الـ matching **من غير Google خالص**
```
bbox filter (أعمدة عادية)  →  ST_Distance_Sphere (MySQL)  →  Google للمرشحين الناجين بس
```

---

## 15.4 عقد الـ API — فيه فريق Flutter خارجي

ده بيرفع الـ API من "تفصيلة تنفيذ" لـ **منتج بذاته**. تبعاته:

| المطلوب | التفصيل |
|---|---|
| **OpenAPI 3.1 spec** | يتولّد تلقائيًا من الكود (Scramble أو L5-Swagger) — مش يتكتب بالإيد |
| **Postman collection** | يتصدّر من الـ spec، بأمثلة حقيقية لكل endpoint |
| **بيئة staging** | جاهزة من **نهاية Phase 2** — الفريق التاني مش هيستنى |
| **Seeded demo data** | حسابات ثابتة (سائقة موافق عليها، راكبة موثّقة، مجموعة شغالة) عشان يجربوا |
| **Versioning صارم** | `/v1` مايتغيرش breaking. أي تغيير كاسر = `/v2` أو حقل جديد optional |
| **Envelope ثابت** | `{ success, data, error: { code, message, fields } }` — نفس الشكل في كل response |
| **كتالوج أكواد أخطاء** | مستند منفصل، كل كود ليه معنى ورسالة عربي/إنجليزي |
| **Changelog للـ API** | كل تغيير موثّق بتاريخ |
| **Contract tests** | Pest tests بتتأكد إن شكل الـ response مطابق للـ spec — تكسر الـ CI لو اتغير |
| **Mock server** | من الـ OpenAPI عشان يشتغلوا قبل ما الـ endpoint نفسه يخلص |

**ترتيب التسليم للفريق التاني:** Auth (Phase 2) → Profile/Verification (3) → Search (6)
→ Requests/Bookings (7) → Payments (8) → Trip (9) → Safety (11)

**مراجعة مشتركة للـ spec قبل ما نكتب كود كل مرحلة** — أرخص بكتير من إعادة العمل.

---

## 15.5 نموذج الفلوس المعتمد

```
الراكب يطلب مقعد ──> يختار: كاش أو أونلاين
                          │
السائقة توافق  ───────────┤
                          │  لو أونلاين: يربط وسيلة دفع (Paymob token)
                          ▼
                    الرحلة بتتم
                          │
          السائقة بتأكد "الراكب وصل"  ← D18
                          ▼
        ┌─────────────────┴─────────────────┐
        │                                   │
    [أونلاين]                            [كاش]
   خصم تلقائي عبر Paymob          الراكب بيدي الفلوس للسائقة
   split: السائقة + عمولتنا       السائقة بتأكد "استلمت"
        │                                   │
   Paymob بيحوّل للسائقة          عمولتنا بتتسجل كـ دين على السائقة
        │                                   │
        └──────────> يتخصم الدين من أول تحويل أونلاين جاي <──┘
```

### جداول متأثرة
```
bookings                  + payment_type(cash|online)  + payment_status
payments                  booking_id user_id provider('paymob') provider_ref
                          amount_piastres platform_fee_piastres driver_amount_piastres
                          type(charge|refund) status idempotency_key captured_at
payment_methods           user_id provider provider_token type(card|wallet|instapay)
                          last4 brand is_default
driver_fee_ledger         driver_profile_id booking_id
                          type(fee_due|fee_settled|adjustment) amount_piastres
                          balance_after settled_from_payment_id   [IMMUTABLE]
driver_balances           driver_profile_id outstanding_fee_piastres
                          lifetime_earnings_piastres last_settled_at
                          is_blocked_from_publishing block_reason
payouts                   ← تتبّع لتحويلات Paymob للعرض والتسوية، مش احتجاز
```

### قواعد
- **`wallets` بالمعنى الاحتجازي اتشالت** — إحنا مش بنمسك أرصدة. الـ ledger للعرض والمحاسبة بس
- حد أقصى للدين (مثلاً 200 ج.م) → لو اتعدّى: **منع نشر رحلات جديدة** لحد السداد
- كل عملية دفع ليها `idempotency_key` إجباري
- Webhooks من Paymob لازم تتحقق من التوقيع + تتعامل مع التكرار
- **الرحلة الكاش مالهاش دليل دفع من طرف تالت** → السائقة بتأكد الاستلام، والراكب يقدر يعترض

---

## 15.6 تأكيد الحضور — السائقة بتأكد (D18)

ده أبسط تشغيليًا لكن معناه إن **طرف واحد بيقرر فاتورة الطرف التاني**. لازم ضمانات:

1. **نافذة اعتراض 24 ساعة** — الراكب بيوصله إشعار "تم تسجيل رحلتك · EGP 88"
   وفيه زرار "مش صح". الاعتراض بيوقف الخصم ويفتح تذكرة.
2. **GPS كدليل مساعد** — بنسجّل `trip_locations` على أي حال. لو حصل نزاع، وجود موبايل
   الراكب داخل مسار الرحلة دليل قوي. **مش شرط للتأكيد، بس متسجّل للنزاع.**
3. **الخصم الأوتوماتيكي يتأخر ساعتين** بعد التأكيد — يقلّل الأخطاء البشرية
4. **مؤشر إساءة**: سائقة معدل اعتراضات عليها مرتفع → flag في الداشبورد
5. `attendance.confirmed_by` + `attendance.disputed_at` + `attendance.dispute_resolution`

## 16. خريطة المراحل

```
Phase 0  الأساسات والقرارات            ← أسبوع
Phase 1  الداتابيز الكاملة              ← 2 أسابيع   ★ نبدأ هنا
Phase 2  المصادقة والهوية
Phase 3  البروفايل والتوثيق (Trust levels)
Phase 4  السائق والمركبات
Phase 5  الأماكن والـ Corridors ونشر الرحلات
Phase 6  محرك البحث والمطابقة           ★ قلب المنتج
Phase 7  طلبات المقاعد والحجوزات والمجموعات
Phase 8  المدفوعات والمحفظة
Phase 9  دورة حياة الرحلة والتتبع الحي
Phase 10 التقييمات ودرجة الثقة
Phase 11 الأمان والحوادث
Phase 12 الإشعارات والمحادثة
Phase 13 داشبورد الأدمن (9 أقسام)
Phase 14 التحليلات والتوصيات
Phase 15 التجهيز للإنتاج
```

**MVP قابل للإطلاق =** Phases 0–9 + الأجزاء الحرجة من 11 (SOS، trusted contacts، البلاغات)
+ 13 (Verification queue، Live trips، Safety cases، Members). الباقي بعد الإطلاق.

---

## Phase 0 — الأساسات (أسبوع)

1. حسم القرارات D1–D11 + سؤال الـ women-only
2. توحيد الـ design system في ملف tokens واحد (`resources/css/tokens.css` + Flutter theme)
3. إعداد Laravel: حزم (Sanctum, Reverb, Spatie Permission, Laravel Media/S3, Pest)
4. اتفاقيات المشروع:
   - Prefix: `/v1` · Envelope: `{ success, data, error: { code, message, fields } }`
   - كتالوج أكواد الأخطاء (`AUTH_OTP_EXPIRED`, `SEAT_UNAVAILABLE`, …)
   - Localization: `ar` / `en` من الـ header `Accept-Language`
   - Rate limiting policy لكل endpoint
5. هيكل المجلدات:
```
app/Domains/{Identity,Verification,Driver,Commute,Matching,Booking,Group,
             Payment,Trip,Safety,Rating,Notification,Admin,Analytics}/
  ├── Models/  Actions/  DTOs/  Events/  Listeners/  Policies/  Rules/
app/Http/Controllers/Api/V1/…
app/Http/Controllers/Admin/…
```
6. CI: Pint + Pest + PHPStan level 6
7. Docker compose للتطوير (Postgres/MySQL + Redis + MinIO + Mailpit)

**Definition of Done:** `composer test` بيعدّي، بيئة تطوير بتشتغل بأمر واحد، ملف اتفاقيات معتمد.

---

## Phase 1 — الداتابيز الكاملة (أسبوعين) ★ نقطة البداية

**الطريقة:** ERD كامل الأول على ورق/Mermaid → مراجعة معاك → بعدين migrations.
كل مجموعة = migrations + Models + Relations + Factories + Seeders + اختبارات.

### مجموعة 1 — الهوية والجلسات (من الفصل 2)
```
users                 id(ulid) phone_e164(unique) phone_verified_at full_name public_first_name
                      profile_photo_path gender date_of_birth email email_verified_at
                      account_status(active|suspended|pending_deletion|deleted)
                      profile_status(not_started|basic_complete|…)
                      preferred_language org_type(work|university) organization_id
                      registered_role(driver|passenger|both) timestamps soft_deletes
otp_challenges        phone_e164 purpose code_hash expires_at attempt_count max_attempts
                      resend_count status device_fingerprint_hash ip_hash verified_at
devices               user_id device_public_id platform device_model os_version app_version
                      push_token(encrypted) is_trusted last_seen_at revoked_at
sessions              user_id device_id refresh_token_hash access_token_id
                      access_expires_at refresh_expires_at last_refreshed_at
                      revoked_at revocation_reason
device_pins           device_id user_id pin_verifier salt algo failed_attempts locked_until
security_events       user_id device_id event_type risk_level metadata reviewed_at
user_consents         user_id document_type document_version accepted_at withdrawn_at source
```

### مجموعة 2 — التوثيق والثقة
```
organizations              name type(work|university) domain city verified
user_verifications         user_id type(phone|government_id|selfie|organization)
                           status(not_started|pending|approved|rejected|action_needed)
                           reviewed_by reviewed_at rejection_reason expires_at
identity_documents         user_verification_id kind file_path(private) file_hash
                           ocr_payload(json) expires_at
trust_levels               user_id level(0-4) computed_at
```

### مجموعة 3 — السائق والمركبة (الفصل 3)
```
driver_profiles       user_id status national_id(encrypted) licence_number(encrypted)
                      licence_expiry verified_at reviewer_id rejection_reason
vehicles              driver_profile_id make model year colour plate_number(unique)
                      seats transmission fuel_type photo_path is_active verification_status
vehicle_documents     vehicle_id type(registration|insurance|inspection) file_path
                      expires_at verification_status
verification_logs     entity_type entity_id admin_id action old_value new_value reason
```

### مجموعة 4 — الجغرافيا والـ Corridors
```
places            name name_ar type(compound_gate|street|landmark|station|campus|office)
                  point(geography) city district is_public
corridors         name origin_place_id destination_place_id
                  window_start window_end days_mask status(healthy|driver_short|critical)
                  drivers_count seekers_count seat_fill_pct
corridor_stats    corridor_id date drivers seekers fill_pct (يومي)
route_cache       cache_key(unique) origin_point destination_point waypoints_hash
                  polyline distance_meters duration_seconds duration_in_traffic_seconds
                  provider fetched_at expires_at hit_count      ← حرج للتكلفة، راجع 15.3
```

### مجموعة 5 — عروض التنقّل (الفصل 4)
```
commute_offers        driver_profile_id vehicle_id corridor_id commute_type(recurring|one_time)
                      status(draft|published|paused|archived) direction(to_work|to_home)
                      seats_total price_per_seat_piastres currency max_detour_minutes
                      audience(women_only|any_verified) route_polyline published_at
commute_locations     commute_offer_id type(origin|pickup|dropoff|destination)
                      place_id point address sequence
commute_schedules     commute_offer_id days_mask departure_time start_date end_date timezone
commute_rules         commute_offer_id key(nonsmoking|quiet|ac|nofood|luggage|front) value
scheduled_trips       commute_offer_id schedule_id trip_date departure_at
                      seats_total seats_taken price_snapshot
                      status(scheduled|preparing|en_route|in_progress|completed|cancelled)
```

### مجموعة 6 — الطلب والبحث (الفصل 5)
```
commute_demands      passenger_user_id origin_place_id destination_place_id origin_point
                     destination_point commute_type days_mask preferred_time_window
                     max_walk_minutes max_detour_minutes budget_monthly audience status
saved_searches       user_id title filters(json) signature(unique per user)
match_notifications  demand_id commute_offer_id delivered_at clicked_at
match_scores         (cache) demand_id scheduled_trip_id total overlap schedule detour
                     audience comfort price reliability expires_at
```

### مجموعة 7 — طلبات المقاعد والحجوزات (الفصل 6)
```
seat_requests           passenger_user_id commute_offer_id commitment(trial|recurring)
                        requested_days_mask meeting_preference custom_pickup_place_id
                        intro_message agreed_to_rules_at
                        status(pending|approved|rejected|waitlisted|withdrawn|expired)
                        waitlist_position responded_by responded_at response_note
pickup_point_requests   seat_request_id proposed_point added_minutes added_km
                        status(pending|approved|suggested_alternative|rejected)
                        alternative_place_id
bookings                scheduled_trip_id passenger_user_id driver_profile_id
                        commute_group_id seats_reserved price_snapshot_piastres
                        status(pending|confirmed|completed|cancelled_by_passenger|
                               cancelled_by_driver|expired|no_show)
booking_events          booking_id event_type actor_type actor_id metadata
```

### مجموعة 8 — المجموعات (من الـ prototype — مفقودة من الـ book)
```
commute_groups           commute_offer_id name direction status
                         on_time_pct rides_together seats_open
                         min_commitment_days_per_week
group_members            commute_group_id user_id role(driver|member|trial)
                         joined_at left_at notice_given_at status
group_attendance         commute_group_id scheduled_trip_id user_id
                         status(coming|away|no_show|attended) marked_at
group_absences           commute_group_id user_id from_date to_date reason
```

### مجموعة 9 — الفلوس ✏️ *(معدّلة بعد D13–D16 — راجع 15.5)*
```
payments             booking_id user_id payment_method_id provider('paymob') provider_ref
                     amount_piastres platform_fee_piastres driver_amount_piastres
                     type(charge|refund) status(pending|authorized|captured|failed|refunded)
                     idempotency_key(unique) captured_at failure_reason
payment_methods      user_id provider provider_token type(card|wallet|instapay)
                     last4 brand is_default
driver_fee_ledger    driver_profile_id booking_id type(fee_due|fee_settled|adjustment)
                     amount_piastres balance_after settled_from_payment_id  [IMMUTABLE]
driver_balances      driver_profile_id outstanding_fee_piastres lifetime_earnings_piastres
                     last_settled_at is_blocked_from_publishing block_reason
payouts              driver_profile_id period_start period_end amount_piastres method
                     status(pending|cleared|on_hold|blocked|released)
                     provider_transfer_ref completed_at released_by   ← تتبّع، مش احتجاز
refunds              payment_id amount_piastres reason status approved_by
payment_webhooks     provider event_id(unique) payload signature_valid
                     processed_at retry_count      ← منع المعالجة المكررة
```
❌ **اتشال:** `wallets` و `wallet_transactions` — مفيش أرصدة احتجازية (D15)
📄 **بقى عرض بس:** كشف حساب المجموعة الأسبوعي = **تجميع للـ payments**، مش فاتورة مستحقة

### مجموعة 10 — الرحلة الحية (الفصل 8)
```
trip_sessions      scheduled_trip_id started_at completed_at current_status
                   distance_travelled_m duration_seconds
attendance         booking_id status(present|late|passenger_no_show|driver_no_show|cancelled)
                   checked_in_at checked_out_at
                   confirmed_by(driver) confirmed_at              ← D18
                   disputed_at dispute_reason dispute_resolution
                   dispute_resolved_by gps_corroborated(bool)     ← دليل مساعد، راجع 15.6
trip_locations     trip_session_id point recorded_at   [partitioned + TTL]
trip_wait_timers   trip_session_id booking_id started_at grace_seconds extended_seconds
                   outcome(arrived|no_show|driver_left)
```

### مجموعة 11 — التقييم والثقة (الفصل 9)
```
ratings          booking_id reviewer_user_id reviewed_user_id stars comment
                 visible_at edited_at deleted_at
rating_tags      rating_id tag
trust_scores     user_id score components(json) updated_at
review_reports   rating_id reporter_id reason status resolved_by
```

### مجموعة 12 — الأمان (الفصل 10 + prototype)
```
incidents            booking_id trip_session_id reporter_user_id reported_user_id
                     category severity description status assigned_admin_id resolved_at
incident_evidence    incident_id file_path kind hash
emergency_contacts   user_id name phone relationship auto_share is_guardian
live_shares          trip_session_id user_id token(hash) expires_at revoked_at viewed_count
blocked_users        blocker_user_id blocked_user_id reason
safety_events        type(sos|discreet_alert|live_share|incident|escort_armed|admin_action)
                     user_id trip_session_id metadata created_at   [NEVER DELETE]
sos_events           safety_event_id countdown_seconds cancelled_at
                     first_touch_at responder_admin_id resolution
escort_windows       corridor_id starts_at ends_at trips_covered armed_by auto
```

### مجموعة 13 — التواصل (الفصل 11)
```
notifications              user_id type title body data(json) read_at
notification_preferences   user_id channel category enabled  (safety = غير قابل للإيقاف)
conversations              booking_id commute_group_id opened_at closed_at
messages                   conversation_id sender_user_id body read_at flagged
```

### مجموعة 14 — الأدمن (الفصل 12)
```
admin_users        (أو roles على users) name email role status mfa_secret last_login_at
roles/permissions  (Spatie)
admin_actions      admin_id action entity_type entity_id old_value new_value
                   ip_hash created_at   [IMMUTABLE · retention 24 شهر]
support_tickets    user_id category priority status assigned_admin_id
platform_settings  key value(json) updated_by   ← الـ toggles بتاعة Settings
feature_flags      key enabled rollout_pct audience
```

### مجموعة 15 — التحليلات (الفصل 13)
```
analytics_events      user_id event_name entity_type entity_id metadata
recommendation_cache  user_id commute_offer_id score expires_at
demand_heatmap_cells  cell_geohash date demand_count supply_count  (مجهّل الهوية)
```

**Definition of Done للمرحلة 1:**
- ERD كامل مراجَع ومعتمد
- كل الـ migrations شغالة `migrate:fresh` نظيف
- كل الـ Models بعلاقاتها + Casts + Enums (PHP 8.3 backed enums)
- Factories لكل موديل + Seeder بيبني سيناريو كامل من الـ prototype
  (نور سائقة، مريم راكبة، مجموعة الرحاب→سمارت فيلدج، كورريدور، حالة أمان مفتوحة، 24 في طابور التوثيق)
- اختبارات تكامل للقيود: unique phone · unique plate · one active vehicle ·
  seats ≤ vehicle capacity · one pending driver application
- تشفير على مستوى العمود لـ national_id / licence_number
- Indexes: كل FK، `phone_e164`، `plate_number`، spatial على النقاط، composite على
  `(scheduled_trip_id, status)` و `(user_id, created_at)`

---

## Phase 2 — المصادقة والهوية (الفصل 2)
Splash router · OTP request/verify (rate limits + hashing + single-use) ·
تسجيل/دخول موحّد · PIN 4 أرقام (verifier محلي، السيرفر مش بيشوفه) · Biometric ·
Session refresh مع rotation + reuse detection · Logout · Device management ·
Forgot PIN · Suspended/restricted routing · Consents versioning

## Phase 3 — البروفايل والتوثيق
Basic profile (اسم أول عام، DOB، gender، لغة، العمل/الجامعة) ·
Verification Centre بالمستويات الأربعة · رفع مستندات آمن (private disk + presigned + virus scan + strip EXIF) ·
**Verification gating** (الـ `pendingIntent` pattern: امنع الإجراء → وثّق → ارجع لنفس المكان)

## Phase 4 — السائق والمركبات (الفصل 3)
Become a driver + شروط الأهلية · رخصة + هوية + OCR · سيارة + مستنداتها ·
Admin review queue · حالات السائق · كشف التكرار (نفس الرقم القومي/الرخصة/اللوحة)

## Phase 5 — الأماكن والـ Corridors والنشر (الفصل 4)
Places CRUD + بحث · Corridors + إحصاءاتها · Create commute wizard (7 خطوات) ·
توليد `scheduled_trips` من الـ recurrence (job يومي بيولّد أفق 30 يوم) ·
State machine: draft→published→paused→archived · حالات الحافة (سيارة موقوفة، رخصة منتهية، تغيير ميعاد)

## Phase 6 — البحث والمطابقة ★
تنفيذ نموذج الـ 100 نقطة · **hard filters قبل الـ scoring** ·
حساب route overlap + walk distance + detour بـ PostGIS ·
Save demand + saved searches · Background job بيطابق الـ demands مع العروض الجديدة ويبعت إشعار ·
Ranking + pagination + caching · Rate limiting للبحث

## Phase 7 — طلبات المقاعد والحجوزات والمجموعات
Seat request (trial/recurring) + موافقة إجبارية على القواعد ·
Custom pickup request → موافقة/اقتراح بديل/رفض مع حساب الـ detour ·
Waitlist + الترتيب · قبول → إنشاء `booking` + انضمام للـ `commute_group` ·
Group: overview، members (بخصوصية)، attendance، absences، rules، leave notice ·
إلغاء بالسياسات

## Phase 8 — المدفوعات والمحفظة (الفصل 7)
Wallet ledger غير قابل للتعديل · Idempotency keys · تكامل InstaPay/Vodafone Cash/بنك ·
Webhooks موقّعة + retry · كشف حساب أسبوعي للمجموعة · pending→available عند إتمام الرحلة ·
Payout runs + حالات (cleared/on hold/blocked) · Refund policy · Payment failed handling

## Phase 9 — دورة حياة الرحلة (الفصل 8)
Start trip + التحقق (رخصة/سيارة/GPS) · Pre-trip check-in · Check-in methods (QR/PIN/GPS/driver) ·
**Driver wait timer** (5 دقايق grace + تمديد + no-show) · Live location عبر Reverb + Redis ·
Route deviation detection → alert للـ ops · Complete → تحرير الأرباح + طلب تقييم ·
Driver cancelled → backup search

## Phase 10 — التقييمات (الفصل 9)
Double-blind (مخفي لحد ما الاتنين يقيّموا أو تنتهي المدة) · نجوم + tags ·
حساب trust score دوري · تقارير المراجعات + moderation

## Phase 11 — الأمان (الفصل 10 + prototype)
Safety Centre · SOS + countdown · **Discreet silent alert** · Live share بروابط مؤقتة ·
Trusted contacts / guardians · Auto-share · Blocking (ومنع المطابقة مستقبلًا) ·
Incident wizard 5 خطوات + أدلة · Escort mode · `safety_events` لا تُحذف أبدًا

## Phase 12 — الإشعارات والمحادثة (الفصل 11)
FCM/APNs · in-app notifications · التفضيلات (safety غير قابلة للإيقاف) ·
Trip chat (يفتح بعد التأكيد، يقفل بعد الرحلة + مهلة) · فلترة الإساءة · منع المحظورين

## Phase 13 — داشبورد الأدمن (الفصل 12)
RBAC + MFA · الأقسام التسعة بالتفصيل المذكور فوق · Audit log غير قابل للتعديل ·
Platform settings toggles · Fraud indicators · Export CSV

## Phase 14 — التحليلات والتوصيات (الفصل 13)
Event tracking · Ops dashboard metrics · توصيات الراكب · رؤى السائق · Heatmaps مجهّلة

## Phase 15 — الإنتاج (الفصل 14)
Rate limiting · WAF · secrets · نسخ احتياطي + استعادة مُختبرة · مراقبة + تنبيهات ·
CI/CD · load testing · pen testing · سياسات الاحتفاظ بالبيانات (trip_locations، سجلات التوثيق)

---

## 17. مخاطر لازم ناخد بالنا منها

| الخطر | التأثير | التخفيف |
|---|---|---|
| **MySQL بدل PostGIS (معتمد)** | route overlap في PHP بدل SQL → بطء بعد ~20k عرض نشط | طبقة `GeoQueryEngine` + bbox pre-filter + contract tests (القسم 15.1) |
| **`users.gender` إجباري** | مخاطر قانونية/خصوصية | مراجعة قانونية · مبدأ تقليل البيانات · مش بيتعرض للأعضاء التانيين |
| **تكلفة Google Maps** | فاتورة تنفجر مع نمو البحث | `route_cache` + bbox pre-filter + تنبيه تكلفة يومي (15.3) |
| **دين الكاش مايتحصّلش** | خسارة إيراد | حد أقصى للدين → منع النشر · تسوية تلقائية من أول تحويل أونلاين |
| **السائقة بتأكد الحضور لوحدها** | فوترة غلط أو إساءة | نافذة اعتراض 24 ساعة + GPS للنزاع + flag للمعدلات الشاذة (15.6) |
| **فريق Flutter خارجي** | إعادة عمل لو الـ API اتغير | OpenAPI + contract tests + staging من Phase 2 + مراجعة spec قبل كل مرحلة (15.4) |
| **الكاش بيغلب الأونلاين** | ضعف التتبع والإيراد | حوافز للأونلاين (خصم/أولوية) + مراقبة النسبة |
| `scheduled_trips` بتكبر بسرعة | ملايين الصفوف | توليد بأفق 30 يوم + أرشفة + partitioning |
| `trip_locations` ضخمة | تكلفة + مخاطر خصوصية | TTL + تجميع + سياسة احتفاظ واضحة |
| المستندات الحساسة | مخاطر قانونية | تشفير + private disk + presigned قصير + حذف بعد 90 يوم |
| الـ double-blind ratings | معقّد | حالة `visible_at` + job |
| Idempotency في الدفع | خصم مزدوج | مفتاح إجباري على كل عملية |
| تناقض الـ book/prototype | إعادة عمل | جدول القرارات في القسم 13 يتعتمد قبل Phase 1 |
| women-only غير محسوم | إعادة كتابة كل الـ UI والـ matching | يتحسم في Phase 0 |

---

## 18. الخطوة التالية

✅ كل القرارات المعمارية اتحسمت (D1–D20).

**التالي:** أرسم **ERD كامل بـ Mermaid** لكل الـ 15 مجموعة — تراجعه وتعتمده،
وبعدها أبدأ Phase 1 (migrations + models + factories + seeders + tests).

**مش هكتب أي كود قبل ما تعتمد الـ ERD.**

---

## 19. أسئلة لسه مفتوحة (مش بتوقّف Phase 1)

| # | السؤال | محتاجينه في |
|---|---|---|
| 1 | **الفصل الأول من الـ book مفقود** (`Chapter_02` هو أول ملف، والفصل 2 بيقول إنه بيعتمد على الفصل 1) | لو موجود ابعتهولي |
| 2 | مزوّد الـ SMS لإرسال الـ OTP؟ وهل واتساب خيار؟ | Phase 2 |
| 3 | تاريخ الإطلاق المستهدف؟ | يحدد حجم الـ MVP |
| 4 | مدن الإطلاق — القاهرة الكبرى بس ولا أكتر؟ | Phase 5 (الـ corridors) |
| 5 | نسبة عمولة المنصة مؤكدة 3%؟ وهل ثابتة ولا بتختلف؟ | Phase 8 |
| 6 | حد الدين اللي بعده نمنع النشر — كام؟ | Phase 8 |
| 7 | سياسة الإلغاء والاسترداد بالتفصيل (الفصل 7 عام جدًا) | Phase 8 |
| 8 | مدة الاحتفاظ بـ `trip_locations` — قانونيًا وتشغيليًا؟ | Phase 9 |
| 9 | توحيد لون الـ brand: أخضر الموبايل ولا تركوازي الداشبورد؟ | Phase 0 |
