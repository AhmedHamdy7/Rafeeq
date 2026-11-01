<!-- task: 123uve060j6 · assign: Omar Aly (94558637) · status: backend answered - check -->
## 🔧 Push: the backend side is ready, the switch is an ops step

The code is on `main`: `FcmPushSender` (FCM HTTP v1, no SDK). It turns on with:

| Env | Value |
|---|---|
| `RAFEEQ_PUSH_DRIVER` | `fcm` |
| `FIREBASE_CREDENTIALS` | **path** to the Firebase service-account JSON on the server (never committed) |
| `FIREBASE_PROJECT_ID` | optional; read from the JSON if missing |

Tokens FCM reports as unregistered are removed automatically. Every notification type in the guide §4.14 goes out once this is on. Until then they wait in the inbox.

### Next: @Omar Aly (ops)
1. Firebase console → Project settings → Service accounts → generate a key; put the JSON on the server and set `FIREBASE_CREDENTIALS` to its path.
2. Firebase → Cloud Messaging → upload the **APNs auth key (.p8)** with its Key ID and Team ID (Apple Developer → Keys). Without it, iPhones get nothing.
3. Set `RAFEEQ_PUSH_DRIVER=fcm`, redeploy, and send a test (e.g. trigger `trip_starts_in_hour` on staging).
