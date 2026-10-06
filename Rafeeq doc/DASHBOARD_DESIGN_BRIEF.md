# RAFEEQ — operations dashboard design brief

> **Who this is for:** an AI design tool. Paste this whole file as the prompt.
>
> **Who uses the result:** a small operations team in Cairo — verification reviewers, a safety lead,
> finance. They sit on this screen for hours, approving identity documents and watching live
> journeys. It is a working tool, not a marketing page.

---

## 1. What you are designing

Rafeeq is an Egyptian **planned commute-sharing** platform — people who travel the same route to
work every day share a car. It is explicitly **not** ride-hailing: no hailing, no surge, no
strangers-on-demand. The majority of drivers and passengers are women, and "women-only" is the
default audience filter, so the tone must read as calm and trustworthy rather than playful.

You are designing the **internal operations dashboard**: nine sections, desktop-first but usable on
a phone, in **Arabic (RTL) and English (LTR)**.

## 2. Hard constraints — the output is unusable without these

| | |
|---|---|
| **One self-contained HTML file** | Inline `<style>`. **No** external fonts, no CDN, no icon library, no JS framework. The page is served under a strict CSP and the dashboard has no asset build step. Use system fonts and inline SVG or Unicode for icons. |
| **Plain CSS custom properties — not Tailwind** | There is no node/vite build in production on purpose. A Tailwind output cannot be used. |
| **Logical properties only** | `margin-inline-start`, `padding-inline`, `inset-inline-start`, `border-start-width`. **Never** `margin-left`/`right`. One stylesheet must serve both directions — if you write `left`, I have to rewrite every rule. |
| **No text in CSS** | Never `content: "Approve"`. Every visible string is translated at runtime from a PHP array; text baked into a stylesheet cannot be. Icons via `content:` are fine. |
| **The drawer must not use `display: none`** | Use `transform: translateX(...)` so it slides and stays focusable for keyboard and screen-reader users. |
| **Dark mode as token overrides** | `:root` is light; `[data-theme='dark']` redefines the same token names. No duplicated component rules. |

## 3. Keep these token names

A stylesheet already exists with tokens taken from an earlier approved design. **Reuse these exact
names** — then adopting your design is a CSS swap rather than a markup rewrite. Change the *values*
freely; that is what I am asking you for.

```css
/* brand scale (currently teal) */
--rq-950 --rq-900 --rq-800 --rq-700 --rq-600 --rq-500 --rq-400 --rq-300 --rq-200 --rq-100 --rq-50

/* semantic marks */
--ok-600 --ok-500 --ok-100        /* approved, healthy, cleared */
--warn-600 --warn-500 --warn-100  /* waiting, needs info */
--bad-600 --bad-500 --bad-100     /* rejected, critical, SOS */

/* surfaces, outside in */
--desk      /* the page behind the card */
--surface   /* the main card */
--sunken    /* insets, active nav background */
--rail      /* the sidebar */

--shadow-drawer
```

Component class names are prefixed `rq-` (`rq-desk`, `rq-shell`, `rq-rail`, `rq-nav`, `rq-card`,
`rq-btn`, `rq-pill`, `rq-table`). Keep that prefix.

## 4. Layout

```
┌─ Sidebar 224px ──┬─ Main ─────────────────────────────────────┐
│  Rafeeq          │ Header 66px:                               │
│                  │  [search: member, trip ID, plate…   ⌘K]    │
│ OPERATIONS       │  [● all systems normal] [🔔•] [🌙] [ع/EN] │
│  Dashboard       ├────────────────────────────────────────────┤
│  Live trips  38  │  Page title + one-line subtitle            │
│  Verification 24 │                      [Export] [Primary CTA]│
│  Safety cases  9 │                                            │
│  Members         │  ⚠ critical alert banner (when present)    │
│  Corridors       │                                            │
│  Payments        │  ← section content                         │
│                  │                                            │
│ GENERAL          │                                            │
│  Audit log       │                                            │
│  Settings        │                                            │
│  Log out         │                                            │
│                  │                                            │
│ [Night escort]   │                               [toast ✓]    │
└──────────────────┴────────────────────────────────────────────┘
```

- A large rounded card (radius ~26px) on a `--desk` background. Desktop target 1280px+.
- **Active nav item:** a 3px bar on the inline-start edge (`box-shadow: inset 3px 0 0 …`), `--sunken`
  background, and the icon in a small brand-coloured tile.
- **Nav badges:** live trips **green** · verification **amber** · safety cases **red**.
- The **language toggle belongs in the header** next to the theme toggle. Show both states.

### Responsive — please design these, not just the desktop

| Width | Behaviour |
|---|---|
| **≥ 1080px** | Sidebar is a fixed column. |
| **< 1080px** | Sidebar becomes a slide-over drawer with a scrim and a close button; a hamburger appears in the header. |
| **≤ 480px** | **Tables must not scroll sideways.** Turn each row into a stacked card with the column name as a label. A reviewer on a phone approving a document is a real case. |

## 5. The nine sections — and the data that actually exists

🔴 **Do not invent fields.** Everything below is what the backend really returns. If a design needs a
number that is not listed, it cannot be built. Where I have written *(not available)* the data does
not exist yet — design the space for it, or leave it out.

**1. Dashboard** — the landing page. Real figures available:

- live trips: a total plus a breakdown by status (`preparing`, `en_route`, `at_pickup`,
  `in_progress`, `completed`, `cancelled`, `emergency`)
- seats booked today (one integer)
- verifications pending: a count **and how long the oldest has waited**
- driver applications pending (integer)
- safety: live SOS alerts · open reports · **critical reports with nobody assigned**
- escort: windows armed now · trips covered tonight
- overdue member holds (integer)
- a recent operations-actions feed: timestamp, action, target, which admin
- *(not available: a weekly demand bar chart, a throughput donut, corridor health — leave these out
  or mark them clearly as empty states)*

**2. Live trips** — a table: trip · route · driver & car · seats · status · action. A trip in
`emergency` status must be visually unmistakable (red row). Filters: flagged-only, women-only.

**3. Verification** — a 3-column grid of cards. Each card: person's name, role, **how long they have
waited**, and the document checks as pass/warn/fail. Actions: approve · ask for more information ·
reject. 🔒 An identity document is opened through a separate authenticated route — design a thumbnail
or a "view document" affordance, never an `<img src>` to a public URL.

**4. Safety cases** — two columns. Left: the case list (severity dot, title, case id, detail, meta).
Right: **emergency actions** (escalate · freeze driver · message all riders) and a case-mix
breakdown. This is the screen somebody uses while a person is in trouble — it must be the calmest,
clearest screen in the set. No ambiguity about which button does what.

**5. Members** — tabs (all · drivers · riders · flagged) and a table: member · role · trips · rating
· status · manage. A rating can be **null** — design "not rated yet", never zero stars.

**6. Corridors** — a card grid: name, time window, status pill (`healthy` · `driver_short` ·
`critical_gap`), three numbers (drivers, seekers, seat-fill %), a bar, and actions.
*(This section is not built yet — design it, I will wire it later.)*

**7. Payments** — 4 KPIs, a payouts table, and a "release all cleared" action.
*(Partly built: cash settlement and the driver's fee debt exist. Payouts and refunds wait on a
payment provider. Design the full screen; I will show what exists and empty-state the rest.)*

**8. Audit log** — four columns: time (monospace) · action + target · actor · a tag. Carries the line
"every admin action, immutable · retained 24 months".

**9. Settings** — grouped toggles (night escort · blur meeting points · PIN at pickup · guardian
share · audio recording), the admin team list, and a data-residency panel (Cairo, documents deleted
after 90 days).

## 6. Arabic and English

- Show **every screen in both**, or at minimum the dashboard, one table and one card grid in Arabic
  RTL so I can see the mirroring you intend.
- Arabic text is **not** a translation afterthought — it is the primary language. Arabic numerals in
  the UI are Western digits (`1 2 3`), and money is Egyptian pounds shown as `٨٠ ج.م` or `EGP 80`.
- Arabic strings run longer than English. **Do not design to a fixed pixel width** that only fits
  English, and do not truncate a person's name.
- Times are shown in Cairo local time; the audit log uses a monospace timestamp.

## 7. Tone

Calm, dense, legible. This is a tool used for hours by people making consequential decisions about
other people — approving a woman's identity document, freezing a driver, reading a harassment
report. Avoid: gradients, glassmorphism, oversized hero numbers, playful illustration, decorative
animation. Prefer: clear hierarchy, generous row height, unmistakable state colours, a 4px/8px
spacing rhythm.

## 8. What I need back

1. **One HTML file**, inline `<style>`, self-contained.
2. All nine sections as static markup with **realistic placeholder content** — Arabic names, Cairo
   routes, plausible numbers.
3. The **token block at the top**, using the names in section 3.
4. **A dark-mode block** overriding the same tokens.
5. **At least one screen rendered RTL** (`<html dir="rtl">`) so the mirroring is visible.
6. **Narrow-width states shown** — the drawer open, and a table collapsed into cards.

Do not include build config, a framework, or a README. One file.
