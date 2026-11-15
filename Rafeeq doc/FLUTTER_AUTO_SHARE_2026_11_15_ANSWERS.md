# Answers: auto-share for trusted contacts (2026-11-15)

Answers to `backend-auto-share-2026-11-15.md`. It reaches the test server with the next deploy. Guide sections: 4.12 (trusted contacts, `PUT /safety/auto-share`, Shared links), 4.14 (notifications), 4.17 (testing call).

| # | Answer |
|---|---|
| 1 | ✅ Built, **Option A**: one switch for the account, `PUT /safety/auto-share` and `meta.autoShare` |
| 2 | ✅ Built: when her trip leaves, each trusted contact gets one SMS with a live link |
| 3 | ✅ Built: `meta.autoShareAvailable` |
| 4 | ✅ Built: `GET /testing/safety/auto-share/messages` |

## 1 · The switch

```
GET /safety/emergency-contacts
→ { "success": true, "data": [ … ],
    "meta": { "limit": 3, "canAdd": true, "autoShare": false, "autoShareAvailable": true } }

PUT /safety/auto-share   { "enabled": true }
→ { "success": true, "data": { "enabled": true, "available": true } }
```

- Off by default. One call per tap; it can't be half applied.
- `enabled` missing or not a boolean: `422 VALIDATION_FAILED`.
- Turning it on with no contacts is allowed. Nothing is sent until she adds one.
- `autoShareTrips` on each contact is **ignored** from now on. Keep sending `false`, or leave it out.

## 2 · What happens when her trip starts

The server acts when the run **leaves**: its status goes from `PREPARING` to `EN_ROUTE`. That is the same moment for the driver and every confirmed passenger on it. A link made earlier, at `POST /trips/{trip}/start`, would show a car that hasn't moved, and manual sharing refuses that too (`TRIP_NOT_STARTED`).

For each person on the run (driver or passenger) with the switch on:

- One live share **per trusted contact**. These are the same kind as `POST /trips/{trip}/live-share` makes, with `sharedWithContactId` set.
- Each contact gets **one SMS** with the link, in her app language (`preferredLanguage`).
- The share ends with the trip, the same way as a manual one.
- They appear in `GET /safety/live-shares` with the new field **`source: "AUTO"`**. Manual ones say `"MANUAL"`. Stop works the same: `DELETE /safety/live-shares/{share}`.
- **Once per trip.** Going `AT_PICKUP` → `EN_ROUTE` at the next pickup sends nothing new.

New: **removing a trusted contact ends that contact's live links at once**, both automatic and manual ones.

### Your questions

| Question | Answer |
|---|---|
| Does she get a notification? | Yes, in the inbox and by push, category **safety** (she can't switch it off). `live_trip_auto_shared` when at least one message went out. `live_trip_auto_share_failed` when none did. `data`: `tripId`, `tripSessionId`, `sentTo` (contact ids, comma-separated; not on `_failed`), `failedTo` (contact ids, comma-separated, may be empty). The text says "Your live trip link went to 2 trusted contact(s)", with no names, because a push shows on the lock screen. Build "Your trip was shared with Hoda and Nada" from `sentTo` and her contact list. |
| What if a message can't be delivered? | That contact's link is ended at once (`isActive: false`), so a link nobody got isn't left open. Its id is in `failedTo`. If none went out, she gets `live_trip_auto_share_failed`: "We couldn't send your live trip link. Share it from the trip if you need to." Show it; she may be counting on it. |
| Is a contact with `verifiedAt: null` sent the link? | **Yes.** No number is verified today, so skipping them would make the switch do nothing. The number is one she typed herself. Keep the "unconfirmed" label on the contact. ⚠️ This is our interim choice. The business spec doesn't say, so it may change. |
| Is there a limit? | One message per contact per trip. Nothing else. With 3 contacts and 2 trips a day, that's at most 6 messages. |
| What does the message say? | English: "{first name} is sharing a Rafeeq trip with you. Follow it live until the ride ends: {link}". Arabic: "{first name} بيشاركك رحلة على رفيق. تابعها مباشر لحد ما الرحلة تخلص: {link}". `{first name}` is her public first name. |

**Channel:** SMS only, through the same gateway as the OTP code. No WhatsApp yet.

**SOS:** auto-share never sends anything in an SOS. It only sends the live-trip link (SAFE-14).

## 3 · `meta.autoShareAvailable`

Show the switch only when it is `true`. It is `false` when:

- staff turned the feature off (dashboard setting **Trusted contacts get the live trip link automatically**), or
- the server has no SMS gateway set up.

On staging it is `true`. Messages there are **recorded, not sent**, so no real phone gets one. On production it stays `false` until the SMS provider is chosen and set up. That provider is the same launch blocker as the OTP SMS. When it's `false`, a switch she already turned on stays stored and nothing is sent.

## 4 · Testing call

```
GET /testing/safety/auto-share/messages
→ { "success": true,
    "data": [ { "contactId": "01J…", "liveShareId": "01J…", "channel": "SMS", "to": "+201223334444",
                "status": "SENT", "failureReason": null,
                "url": "https://…/s/…", "sentAt": "2026-11-15T05:10:00+00:00" } ],
    "meta": { "tripId": "01J…", "tripSessionId": "01J…" } }
```

- **Caller:** the account whose trip started (passenger or driver).
- Messages for her **latest trip that sent any**. Empty `data` and `meta` ids `null` when none.
- `status`: `SENT` or `FAILED`. `failureReason`: `DELIVERY_FAILED` or `null`.
- `url` opens the real public page. It is only kept on staging, where messages are recorded.
- Staging only (`404` elsewhere).

Patrol step:

1. Add a contact. `PUT /safety/auto-share {enabled: true}`.
2. The driver starts the run and moves it to `EN_ROUTE`.
3. `GET /testing/safety/auto-share/messages`: one `SENT` row per contact. Open `url`. The messages are sent by a queued job, so they arrive **a few seconds after** `EN_ROUTE`: poll every second for up to 15 seconds until `data` is not empty.
4. `GET /safety/live-shares`: rows with `source: "AUTO"`. Stop one with `DELETE`.

## What changed for the app

- New `PUT /safety/auto-share`.
- `GET /safety/emergency-contacts` → `meta.autoShare`, `meta.autoShareAvailable` (new keys).
- `GET /safety/live-shares` and `DELETE /safety/live-shares/{share}` → `source` (new key).
- New notification types `live_trip_auto_shared`, `live_trip_auto_share_failed`.
- Removing a contact ends that contact's live links.
- `autoShareTrips` on a contact is ignored.
- New staging-only `GET /testing/safety/auto-share/messages`.

Nothing existing changed shape.
