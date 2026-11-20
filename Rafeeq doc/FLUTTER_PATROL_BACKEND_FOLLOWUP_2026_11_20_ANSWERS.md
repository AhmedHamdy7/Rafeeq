# Answers: backend follow-up after the 2026-11-20 answers

Answers to `backend-followup-2026-11-20.md`. The fix reaches the test server with the next deploy. Guide section: 4.17 (testing calls). The guide's change log has a second 2026-11-20 row.

## F1 · `GET /testing/push-log` answers 500

✅ **Fixed.**

**Cause:** we have no server log for your call, so we can't say which of two causes it was. Both of these made the call fail, and only on the test server, never in our own tests:

- **A push token that can't be decrypted.** Tokens are stored encrypted. A token saved before the server's key changed can't be read any more. Reading it threw an error, so one old device row on Omar's account was enough to break the call.
- **A push provider that can't start**, for example missing or broken FCM credentials. Checking `pushProviderConfigured` threw an error.

🔴 **Both also stopped the push itself.** The job that sends a push hit the same error, so the push never left the server and the row stayed `queued`. This may be why g242 saw nothing on the emulator.

**Now:** both cases answer `200`, and they are reported instead of failing.

**New on `GET /testing/push-log`:**

| Field | Meaning |
|---|---|
| `pushProviderError` | `null`, or the error class when the push provider can't start (for example `RuntimeException` for missing FCM credentials). `pushProviderConfigured` is then `false` |
| `devices[].pushTokenReadable` | `false` when the stored token can't be decrypted. `pushTokenEnd` is then `null` |

**New `failedReason` values on `pushes[]`:**

| Value | Meaning |
|---|---|
| `token_unreadable` | The stored token couldn't be decrypted. The server clears it, and the app sends a fresh one the next time it registers the device |
| `push_provider_error: …` | The push provider couldn't start. Nothing was sent |

**For g242,** read `push-log` after the call:

| What you see | What it means |
|---|---|
| `pushProviderError` is set | The test server's FCM setup is broken. Tell us, and we fix the server's settings |
| `failedReason: token_unreadable` | Sign out and back in on the emulator, or restart the app, so it registers a fresh token. Then send the notification again |
| `state: sent` but no row on the device | The push reached FCM, so look at the app side |

## `meta` on `POST /testing/notifications`

✅ **Confirmed.** It has been on the call since the deploy with the 2026-11-20 answers. It sits at the top level of the response, next to `data`:

```json
{
  "success": true,
  "data": { "id": "01…", "type": "waitlist_cleared_by_change", "…": "…" },
  "meta": { "inboxWritten": true, "pushAllowed": true, "pushTokens": 1, "pushIds": ["01…"] }
}
```

One small change: `pushTokens` now counts the tokens **before** the push is sent. Before, a token the send cleared (rejected or unreadable) was already gone from the count.

## Checking the other answers

Nothing else needs a server change. Please add anything from the remaining runs (B2, B3, B4, B6, T16, T17, T18) that doesn't behave as the answers say.
