# RAFEEQ — operations dashboard design brief (v2)

> **Who this is for:** an AI design tool. Paste this whole file as the prompt.
>
> **Who uses the result:** a small operations team in Cairo — verification reviewers, a safety lead,
> finance. They sit on this screen for hours, approving identity documents and watching live
> journeys. It is a working tool used for consequential decisions about real people, not a
> marketing page.
>
> **v2 changes:** the real screen list (eleven, from the router — not the nine in the old plan),
> every empty / loading / error / permission state, a concrete visual specification instead of
> "make it nice", and the UX rules that decide whether an operator can be trusted with a
> destructive button.

---

## 1. What you are designing

Rafeeq is an Egyptian **planned commute-sharing** platform — people who travel the same route to
work every day share a car. Explicitly **not** ride-hailing: no hailing, no surge, no
strangers-on-demand. Most drivers and passengers are women and "women-only" is the default audience
filter, so the tone must read as calm and trustworthy, never playful.

This is the **internal operations dashboard**. Eleven screens, Arabic (RTL) and English (LTR),
desktop-first but genuinely usable on a phone.

---

## 2. Hard constraints — the output is unusable without these

| | |
|---|---|
| **One self-contained HTML file** | Inline `<style>`. **No** external fonts, no CDN, no icon library, no JS framework. The page is served under a strict CSP and the dashboard has no asset build step. System fonts; inline SVG or Unicode for icons. |
| **Plain CSS custom properties — not Tailwind** | There is no node/vite build in production, on purpose. A Tailwind output cannot be used at all. |
| **Logical properties only** | `margin-inline-start`, `padding-inline`, `inset-inline-start`, `border-inline-start`. **Never** `left`/`right`/`margin-left`. One stylesheet serves both directions — every physical property is a rule I must rewrite by hand. |
| **No text inside CSS** | Never `content: "Approve"`. Every visible string is translated at runtime from a PHP array. Decorative icons via `content:` are fine. |
| **The drawer must not use `display: none`** | `transform: translateX(…)` so it slides and stays focusable for keyboard and screen readers. |
| **Dark mode as token overrides only** | `:root` is light; `[data-theme='dark']` redefines the same token names. Do not duplicate component rules. |
| **No `<img>` to a document** | Identity documents open through an authenticated route. Design a thumbnail placeholder or a "view document" button, never a direct image URL. |

---

## 3. Keep these token names, change their values

A stylesheet already exists using these names. **Reuse them exactly** — then adopting your design is
a CSS swap rather than a markup rewrite. The *values* are what I am asking you to improve.

```css
/* brand scale — currently teal; propose a better one if teal is wrong for this */
--rq-950 --rq-900 --rq-800 --rq-700 --rq-600 --rq-500 --rq-400 --rq-300 --rq-200 --rq-100 --rq-50

/* semantic marks */
--ok-600 --ok-500 --ok-100        /* approved · healthy · cleared */
--warn-600 --warn-500 --warn-100  /* waiting · needs info */
--bad-600 --bad-500 --bad-100     /* rejected · critical · SOS */

/* surfaces, outside in */
--desk --surface --sunken --rail

--shadow-drawer
```

Add any tokens you need (type scale, spacing, radii, elevation) using the same `--rq-` or plain
semantic naming. Class names are prefixed `rq-` — keep that.

---

## 4. The visual specification

This is the part the previous brief left vague. Be concrete and consistent.

**Type.** One family (system stack). Define a scale and use only it — e.g. 30 / 22 / 18 / 15 / 13 /
11px with line-heights, mapped to tokens (`--text-page-title`, `--text-body`, `--text-meta`). Numbers
in tables and timestamps use `font-variant-numeric: tabular-nums` so columns align. Timestamps and
IDs are monospace.

**Spacing.** A 4px base, used as 4 / 8 / 12 / 16 / 24 / 32 / 48. No arbitrary values.

**Density.** Table rows ~52px tall with 12–16px cell padding — dense enough to see a dozen rows,
loose enough to read for an hour. Cards 20–24px internal padding.

**Elevation.** Two levels only: the main card rests on `--desk` with a soft shadow; the drawer and
modals float. Everything else is flat, separated by 1px borders in a token colour. No nested shadows.

**Radii.** Three: the big card ~26px, cards/inputs ~12px, pills fully rounded.

**Colour discipline.** The brand colour is for the active nav, primary buttons and one highlighted
KPI — not for decoration. State colours mean one thing each and never get reused for emphasis.

**Focus.** A visible 2px focus ring in the brand colour with a 2px offset, on **every** interactive
element. This is a keyboard-heavy tool; an invisible focus ring makes it unusable.

**Motion.** 120–180ms, ease-out, on the drawer, toasts and hover only. Wrap everything in
`@media (prefers-reduced-motion: reduce)`.

**Contrast.** Body text ≥ 7:1 on its background, secondary text ≥ 4.5:1, and **never** rely on
colour alone — a status pill carries a word, a severity dot carries a label.

**Hit targets.** 40px minimum, 44px on touch widths.

**Avoid:** gradients, glassmorphism, blur, oversized hero numbers, illustration, decorative
animation, more than two font weights in one row, icon-only buttons without a label or tooltip.

---

## 5. Layout

```
┌─ Sidebar 224px ──┬─ Main ─────────────────────────────────────┐
│  Rafeeq          │ Header 66px:                               │
│                  │  [search: member, trip ID, plate…   ⌘K]    │
│ OPERATIONS       │  [● all systems normal] [🔔•] [🌙] [ع/EN] │
│  Dashboard       ├────────────────────────────────────────────┤
│  Live trips  38  │  Page title + one-line subtitle            │
│  Verification 24 │                      [Export] [Primary CTA]│
│  Driver apps  11 │                                            │
│  Safety cases  9 │  ⚠ critical alert banner (when present)    │
│  Escort          │                                            │
│  Members         │  ← screen content                          │
│                  │                                            │
│ GENERAL          │                                            │
│  Audit log       │                                            │
│  Settings        │                                            │
│  Log out         │                               [toast ✓]    │
└──────────────────┴────────────────────────────────────────────┘
```

- **Active nav item:** 3px bar on the inline-start edge (`box-shadow: inset 3px 0 0 …`), `--sunken`
  background, icon in a small brand-coloured tile.
- **Nav badges:** live trips **green** · verification **amber** · driver apps **amber** · safety
  cases **red**. A badge of zero is hidden, not shown as "0".
- **Language toggle in the header**, next to the theme toggle. Show both states.

### Responsive — design these, not only the desktop

| Width | Behaviour |
|---|---|
| **≥ 1080px** | Sidebar is a fixed column. |
| **< 1080px** | Sidebar becomes a slide-over drawer with a scrim and a close button; a hamburger appears in the header. |
| **≤ 480px** | 🔴 **Tables must not scroll sideways.** Each row becomes a stacked card with the column name as a label. A reviewer approving a document from her phone is a real case, not an edge case. |

---

## 6. The eleven screens — with the data that actually exists

🔴 **Do not invent fields.** Everything below is what the backend really returns. A design built
around a number that is not listed cannot be implemented. Where it says *(not available)*, design an
empty state or leave it out.

**1. Sign in** (`/admin/login`) — email, password, then a **second step** for a 6-digit
authenticator code. Two separate states, both needed. One error message for a wrong password and a
wrong code (deliberate — distinguishing them tells an attacker the password was right).

**2. Dashboard** (`/admin/dashboard`) — the landing page. Available:
- live trips: a total plus a breakdown by status (`preparing`, `en_route`, `at_pickup`,
  `in_progress`, `completed`, `cancelled`, `emergency`)
- seats booked today (integer)
- verifications pending: a count **and how long the oldest has waited**
- driver applications pending (integer)
- safety: live SOS alerts · open reports · **critical reports with nobody assigned**
- escort: windows armed now · trips covered tonight
- overdue member holds (integer)
- recent operations actions: timestamp · action · target · which admin
- *(not available: weekly demand chart, throughput donut, corridor health)*

**3. Live trips** (`/admin/trips`) — table: trip · route · driver & car · seats · status · action. A
trip in `emergency` must be **unmistakable**. Filters: flagged-only, women-only.

**4. Verification** (`/admin/verifications`) — a card grid. Each card: name, role, **wait time**,
document checks as pass/warn/fail, and a document viewer affordance. Actions: approve · ask for more
information (requires a typed reason) · reject (requires a reason).

**5. Driver applications** (`/admin/drivers`) — a separate queue from verification: licence details,
vehicle, documents, and approve / reject with a reason.

**6. Safety cases** (`/admin/safety`) — two columns. Left: case list (severity dot, title, case id,
detail, meta). Right: **emergency actions** (escalate · freeze driver · message all riders) and a
case-mix breakdown. 🔴 **This is the screen somebody uses while a person is in trouble.** It must be
the calmest and clearest in the set, with zero ambiguity about which button does what.

**7. Night escort** (`/admin/escort`) — corridor-level night windows: which are armed, trips covered,
and arming one for N hours with a reason.

**8. Members** (`/admin/members`) — tabs (all · drivers · riders · flagged), search, and a table:
member · role · trips · rating · status · manage. A rating can be **null** — design "not rated yet",
never zero stars.

**9. Audit log** (`/admin/audit`) — four columns: time (monospace) · action + target · actor · tag.
Filters by area, admin and subject. Carries the line "every admin action, immutable · retained 24
months".

**10. Settings** (`/admin/settings`) — grouped toggles and numeric policy values, each with the
current value and who last changed it. A data-residency panel (Cairo; documents deleted after 90
days).

**11. Document viewer** (`/admin/files/documents/{id}`) — a full-bleed view of one identity document
or piece of evidence. 🔒 Needs a visible "this is being viewed as part of case X" framing and no
download affordance.

**Not built yet — design them so I can wire them later:**
- **Corridors** — card grid: name · time window · status pill (`healthy`/`driver_short`/
  `critical_gap`) · three numbers (drivers, seekers, seat-fill %) · a bar · actions.
- **Payments** — 4 KPIs, a payouts table, "release all cleared". Cash settlement and driver fee debt
  exist; payouts and refunds wait on a provider, so design them and I will empty-state what is not
  there.

---

## 7. Every state, not just the full one

🔴 **This is the biggest gap in most designs I am given, and the most expensive to fix later.** For
each list, table and card grid, show:

| State | What it needs |
|---|---|
| **Empty** | An explanation of what would appear here and what to do — never just "No data". "No verifications waiting" on a queue screen is good news, and should look like it. |
| **Loading** | Skeleton rows that match the real row height, so the page does not jump. |
| **Error** | What failed and a retry, inside the card — not a page-level crash. |
| **No permission** | 🔒 Operators have different roles. A section an admin may not open must say so plainly rather than showing an empty table, which reads as a bug. |
| **Partial permission** | One screen, some actions hidden. A reviewer who may read a document but not decide needs to see the document with the buttons gone and a reason. |
| **Paginated** | Page controls, and a row count. |
| **Filtered to nothing** | Different from empty: "no results for X" with a clear-filters action. |

Also needed, as components:
- **Confirmation modal** for anything destructive, with a **required typed reason** — freezing a
  driver, rejecting a document, suspending a member.
- **Toast** for success and failure, bottom of the content area, dismissible.
- **Irreversible marker** — a visual convention for actions that cannot be undone. Evidence and
  audit rows are permanent by design, and the UI must say so before, not after.
- **Pill set** for every status in section 6 — show them all together once so the colour language is
  visible in one place.

---

## 8. UX rules that are not negotiable

1. **Every destructive action states its consequence before it happens**, in the button or the
   modal: "Freeze driver — her published commutes stop taking bookings; today's runs continue."
2. **A reason field is required** on every decision that affects somebody's account. It is written to
   an immutable audit log and the person may see it.
3. **Nothing is destructive by accident.** Destructive buttons are never the default, never adjacent
   to a common action, and never icon-only.
4. **Wait time is a first-class number.** On every queue, how long the oldest item has waited is more
   important than the count — it is the number that means somebody is being kept waiting.
5. **`null` is "not known", never zero.** A driver with no rating is not a nought-star driver.
6. **Nothing says "blocked" when it means "cannot publish".** Over-limit drivers keep their booked
   runs; copy that implies a frozen account is both frightening and untrue.

---

## 9. Arabic and English

- **Show both.** At minimum: the dashboard, one table, one card grid and one modal in Arabic RTL, so
  the mirroring you intend is visible.
- Arabic is the **primary** language, not a translation afterthought. UI digits are Western
  (`1 2 3`); money is `٨٠ ج.م` or `EGP 80`.
- **Arabic strings run 20–40% longer than English.** Do not design to a width that only fits
  English, and never truncate a person's name — wrap it.
- The language toggle changes `dir` on `<html>`. Show that the layout mirrors rather than just
  re-aligning text: the nav bar, the active-item edge bar, chevrons and progress bars all flip.
- Cairo local time throughout; monospace timestamps.

---

## 10. What I need back

1. **One HTML file**, inline `<style>`, self-contained, no build config and no README.
2. **All eleven screens** plus corridors and payments, as static markup with realistic placeholder
   content — Arabic names, Cairo routes (Rehab → Smart Village), plausible numbers.
3. **The token block at the top**, using the names in section 3, plus your type/space/radius scales.
4. **A `[data-theme='dark']` block** overriding the same tokens.
5. **Every state from section 7**, visibly, even if that means repeating a table four times.
6. **The components from section 7** — modal, toast, pill set — shown once each.
7. **At least four screens rendered RTL** (`<html dir="rtl">` on a wrapper or a separate section).
8. **Narrow-width states shown**: the drawer open over a scrim, and a table collapsed into cards.

If you must choose between covering more screens and making one screen prettier, **cover more
screens** — I can refine a colour, but I cannot invent a state you did not design.
