# Create Commute: answers to the backend gaps

Answers to `create_commute_backend_gaps.md`. Every response is captured in `FLUTTER_CREATE_COMMUTE_RESPONSES.md`, and the shapes are in `RAFEEQ_MOBILE_API_GUIDE.md` §4.5.

Product answered every open item on 2026-11-05 (D-0034, awaiting the other partners' approval). This replaces the earlier version of this file.

| # | Item | Answer |
|---|---|---|
| G1 | Route preview | Added: `GET /commutes/{commute}/route-preview` |
| G2 | Earliest departure | **Removed.** Arrival only; ask Design to remove the field |
| G3 | Price per weekday | One price stays; remove the tiers |
| G4 | Start and end dates | Optional. 12 months, renews by itself; never show the end date |
| G5 | `direction` | Optional, defaults to `to_work`; never show it |
| G6 | `commuteType` | Optional, defaults to `recurring`; send nothing |
| G7 | `seatsTotal` | Optional, defaults to `approvedSeats`; each day can lower it |
| G8 | Preferences | `PATCH` works on a draft; keys listed |
| G9 | A/C | The car's value wins; the server refuses AC on a car without it |
| G10 | `allowsCustomPickup` | Defaults to `true`; send nothing |
| G11 | A point used for both | Send it in both lists |
| G12 | Stop limits | Added to `GET /commutes/options`; the guide is corrected |
| G13 | Unverified driver | Drafts are allowed; only publish is refused |
| G14 | Drafts | `?status=draft`, several allowed, `DELETE` discards |
| G15 | Responses and seeder | Added; the live server still has to be seeded |
| G16 | Refusal codes | The guide is corrected; fields listed |

## G1. Route preview

Save the route with `PUT /route`, then call `GET /commutes/{commute}/route-preview`:

```json
{ "polyline": "...", "distanceMeters": 46206, "durationSeconds": 5940 }
```

`routePolyline` and the two other fields on the commute stay `null` until publish.

| Code | Status | The app shows |
|---|---|---|
| `COMMUTE_ROUTE_UNAVAILABLE` | 503 | "Route not available" with Retry, and Next disabled. Nothing is wrong with the driver's points, and a failed answer isn't cached. |
| `COMMUTE_ROUTE_INVALID` | 422 | Comes from `PUT /route`, and means the driver has to change a point. |

## G2. Earliest departure and latest arrival

Arrival only (COM-41). Remove "Earliest departure" and reword the note; we'll need Design to update the step. Send `promisedArrivalTime` at the top level or in `perDay`, and leave `departureTime` out.

```json
{
  "daysMask": 10,
  "perDay": {
    "2": { "promisedArrivalTime": "09:00:00", "seatsTotal": 2 },
    "8": { "promisedArrivalTime": "09:30:00" }
  }
}
```

The promised arrival never moves. The departure moves earlier as riders join, by at most 20 or 30 minutes.

### Detour tiers (COM-40)

| | `on_way` | `far` | Beyond |
|---|---|---|---|
| Her point from the route | up to 1.5 km | up to 3 km | more |
| Added by her | up to 10 min | up to 15 min | more |
| Whole trip | up to 20 min | up to 30 min | more |
| Show | "On your way" | "A bit far · you'd leave about {extraMinutes} min earlier" | Grey the pin; a request answers `422 PICKUP_DETOUR_TOO_LONG` |

- `tier` and `extraMinutes` are on the inbox `impact`, `counteroffer-options`, `pickup-preview` and search results.
- In Discover, `on_way` goes under Matching and `far` under Other commutes, labelled "A bit far".
- After the schedule lock, a request that would make the arrival late is still blocked in either tier: `impact.canAccept: false`.
- When a pickup moves earlier, the passenger gets `pickup_moved_earlier`. If it moved more than 10 minutes earlier, `cancel-preview` answers `reason: pickup_moved_earlier`, fee 0: show **Cancel free**.

## G3. Price per weekday

- One price stays (COM-41, PAY-42). Remove the tiers, and we'll need Design to update the step.
- `GET /commutes/{commute}/price` is the right call once the route is saved.
- `VEHICLE_SEATS_NOT_APPROVED` means the price can't be read yet. Don't block the step: show that the price appears once the car is reviewed.
- Yes, the driver can create the draft on that car. Publish is what's refused.

## G4. Start and end dates

Both are optional, so the app sends neither.

- `startDate` defaults to tomorrow.
- `endDate` defaults to 12 months later. Never show it, not even on Review.
- Saving the schedule again without dates keeps the saved ones.
- Each night, a commute ending within 7 days with a trip in the last 30 days gets 12 more months, and nobody is told.
- Otherwise it is archived the night after `endDate`. The driver gets `commute_archived` (button: Duplicate), and its waiting list gets `waitlisted_commute_ended` (button: Find another commute).

## G5. `direction`

Optional, and defaults to `to_work`. `return` flips it. No matching or search reads it. Don't ask the driver, and never show it.

## G6. `commuteType` and one-time commutes

The beta sends nothing and gets `recurring`. One-time isn't in the form. It still works: `POST` with `one_time`, then `PUT /schedule` with `startDate` alone.

## G7. `seatsTotal`

Optional, and defaults to `approvedSeats`. It's `null` until the car has them. A driver can lower a day with `perDay.{bit}.seatsTotal` (COM-41). The rule makes the seat count an input per weekday, stopping at `approvedSeats`, so keep it as an input and ask Design to show the stepper (G46). The API still accepts a schedule without it. Above `approvedSeats` answers `422 COMMUTE_SEATS_EXCEED_APPROVED`.

## G8. Saving preferences

- `PATCH` works on a `DRAFT`. `rules` isn't on `POST`.
- Offer five keys (COM-39), which `preferences[]` on `GET /commutes/options` also lists: `nonsmoking`, `quiet`, `ac`, `nofood`, `music`.
- The design has three chips and the rule has five. We'll need Design to add Quiet ride and No food.
- `luggage` and `front` are accepted, and hidden in the beta.
- An unknown key answers `422 VALIDATION_FAILED`.
- Sending `rules` replaces the whole set.
- Women-only is `audience` on the same `PATCH`. Show the switch when `canMarkWomenOnly` is `true`. Otherwise the server answers `422 COMMUTE_WOMEN_ONLY_NOT_ALLOWED`, and a man's seat request answers `SEAT_REQUEST_WOMEN_ONLY`.
- The guide's "Revises terms: price" is gone.

## G9. A/C preference and `hasAc`

The car's value wins. Lock the chip to `hasAc`, with "Your car is registered without AC". `rules.ac: true` on a reviewed car without AC answers `422 COMMUTE_AC_NOT_AVAILABLE`, and the chip clears if the car loses AC. Passenger results show the car's value in `rules.ac`, and the `ac` search rule only returns cars with AC.

## G10. `allowsCustomPickup`

The default is now `true`, so don't send it. `false` affects only `POST .../pickup-request` and `pickup-preview`. A seat request's own pin (`custom_map`) never depends on it (BOOK-08).

## G11. A suggested point used for both

Send the same point in `pickups[]` and `dropoffs[]`. It counts once towards each limit, and comes back as two rows in `locations[]`.

## G12. Stop limits

The guide now says 3 and 3 (COM-40). Read `maxPickupStops` and `maxDropoffStops` from `GET /commutes/options`, which follow the admin settings. An unverified driver can read them.

## G13. Unverified driver

Drafts are allowed, as the design shows (PROF-19). Only `publish`, `pause`, `resume`, `duplicate`, `return` and `cancel-preview` still answer `403 VERIFICATION_REQUIRED`. `PATCH` and `DELETE` also need verification on a commute that isn't a draft.

A driver with no approved, active car can also save a draft:

- With no car, `vehicleId` and `seatsTotal` are `null`, and `missing[]` has `vehicle`. Once there's a car, send it with `PATCH`, and the seats fill in from it.
- With an unapproved car, the draft keeps it, and publish answers `COMMUTE_VEHICLE_UNAVAILABLE` or `VEHICLE_SEATS_NOT_APPROVED`.
- `GET /driver/vehicles` now answers an empty list, not `404`, for a driver who hasn't started.

A suspended driver can't create a draft: `403 DRIVER_NOT_ELIGIBLE`.

This changes an answer in Driver Requests G10: `GET /commutes` now answers `200` for an unverified driver.

## G14. Drafts

A draft's `missing[]` can also hold `vehicle_under_review` ("You can publish as soon as we approve your car.", Publish disabled) and `vehicle_rejected` ("Your car was rejected (reason). Fix its details or choose another car to publish.", button: Car details). The reason is `rejectionReason` on `GET /driver/vehicles`.

- `GET /commutes?status=draft` returns them. `status` takes a comma-separated list.
- A driver can hold several.
- `GET /commutes/{commute}` refills the form. The new `weekdays[]` has each day's times and seats.
- `DELETE` removes a draft from the driver's lists, and works for an unverified driver too.
- `missing[]` can return `origin`, `destination`, `schedule`, `vehicle` and `one_time_single_date`.

## G15. Real responses, seeders and live accounts

`FLUTTER_CREATE_COMMUTE_RESPONSES.md` has every state in your table, except `COMMUTE_ROUTE_UNAVAILABLE`: the local route engine can't fail that way, so a feature test covers it. Distances and minutes there come from a straight-line estimate.

Reset a state with `php artisan demo:create-commute-states --state=CC-READY`.

| State | Phone | What it's for |
|---|---|---|
| `CC-READY` | `+201055557401` | Approved car, a published commute and a complete draft |
| `CC-CAR-NOT-REVIEWED` | `+201055557402` | `approvedSeats: null` |
| `CC-CAR-REJECTED` | `+201055557403` | Rejected car |
| `CC-CAR-PENDING` | `+201055557404` | Car waiting for review |
| `CC-NO-CAR` | `+201055557405` | Verified identity, no car |
| `CC-UNVERIFIED` | `+201055557406` | Hasn't started verification |
| `CC-OVERDUE` | `+201055557407` | Commission unpaid after Friday |
| `CC-SUSPENDED` | `+201055557408` | Suspended driver |
| `CC-LICENCE-EXPIRED` | `+201055557409` | Expired licence |
| `CC-CAR-NO-AC` | `+201055557410` | Reviewed car without A/C, with a draft |
| `CC-MAN-DRIVER` | `+201055557411` | Driver whose profile gender is male |

**Not done yet:** the live server hasn't been seeded, so there's no sign-in code to send. The backend team has to run it after this is deployed.

## G16. Refusal codes

The guide's publish section is corrected, and lists each code with its fields. `DRIVER_DEBT_LIMIT_REACHED` is gone.

| Code | `error.fields` |
|---|---|
| `DRIVER_COMMISSION_OVERDUE` | `amountOwedPiastres[]`, `dueAt[]` |
| `COMMUTE_ROUTE_INVALID` | `route[]`, or `pickups.{index}[]` for a stop too far off the road |
| `DRIVER_NOT_ELIGIBLE` | `driver[]`, the profile status, such as `SUSPENDED` or `PENDING_REVIEW` |

## Follow-ups to your live test

- **Rejected car reason:** added, `rejectionReason` on `GET /driver/vehicles`.
- **Licence text:** fixed. `DRIVER_LICENCE_EXPIRED` on publish now reads "Your licence isn't valid. Renew it to continue.", so the app can show the server's text.
- **Captures:** `COMMUTE_AC_NOT_AVAILABLE`, `COMMUTE_WOMEN_ONLY_NOT_ALLOWED`, `COMMUTE_NOT_EDITABLE` and `COMMUTE_ROUTE_UNAVAILABLE` are now in `FLUTTER_CREATE_COMMUTE_RESPONSES.md`. The last was captured on a local server with the routing provider made to fail.
- **Straight-line route:** the live server still uses the straight-line engine until the real engine is switched on, so road, distance, time and price stay estimates, and "Route not available" can't happen there yet.
- **Test accounts:** the `CC-*` states aren't on the live server until they are seeded.
- **Empty `weekdays[]`:** this came from how seeded commutes were created. Commutes made through the API always had rows, and commutes older than the table were filled when it was added. A new migration now fills any that are still empty, so `CC-READY` and its duplicates have rows after the deploy; reseed `CC-READY` to get its 09:00 arrival. A filled older commute has `promisedArrivalTime: null` and `departureIsComputed: false`, so ask for the arrival when refilling the form.
- **Suspended message:** `DRIVER_NOT_ELIGIBLE` now reads "Your driving is suspended, so you can't do this right now." for a suspended driver, so the app can show the server's text.
