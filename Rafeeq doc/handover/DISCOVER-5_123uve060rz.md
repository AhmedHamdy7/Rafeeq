<!-- task: 123uve060rz · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend confirmed: DISCOVER-5 vehicle.tier, vehicle.hasAc and rules on every result

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

📝 Already returned on every search result; now documented in the match-result shape (guide §4.6) and pinned by a test. Also added on `/matches` rows (with `colour`).

```json
"vehicle": { "make": "Hyundai", "model": "Elantra", "colour": "Silver", "tier": "comfort", "hasAc": true },
"rules": { "nonsmoking": true, "quiet": true }
```

### Next: @ahmed Ibrahim (Flutter)
Split results (Q5) on these: "Standard car" when `tier` is not `comfort`, "No AC" when `hasAc` is false, "Smoking allowed" when `rules.nonsmoking` is not true.
