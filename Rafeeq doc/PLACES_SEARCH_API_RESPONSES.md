# `GET /api/v1/places`: responses after the place-search change

Real responses captured from the backend after Rafeeq-Backend PR #72, against the seeded Greater Cairo catalogue (`places:seed-greater-cairo`). IDs are from a test database.

## The one endpoint that changed

`GET /api/v1/places` (signed in). No endpoint was added.

| Query field | Type | Notes |
|---|---|---|
| `q` | string, max 100 | Part of a place name, district or city, in Arabic or English. With `q`, places are found wherever they are. |
| `lat`, `lng` | number, sent together | Where the driver is. With `q`: only orders the results and adds `distanceMetres`. Without `q`: the centre of the nearby search. |
| `radiusMetres` | integer 100–50,000 | Without `q`: defaults to 5 km. With `q`: applies only when sent, and the results are then ordered by distance. |
| `limit` | integer 1–20 | **New.** How many rows to return, best first. Default 20. |

### Row fields (no new fields)

| Field | Type | Notes |
|---|---|---|
| `id` | string (ULID) | |
| `name` | string | English name |
| `nameAr` | string | Arabic name. The app picks `name` or `nameAr` by its language. |
| `type` | string | `compound_gate` · `street` · `landmark` · `station` · `campus` · `office` |
| `lat`, `lng` | number | |
| `city` | string | **Now follows `Accept-Language`** (`Cairo` / `القاهرة`) |
| `district` | string | **Now follows `Accept-Language`** (`Rehab` / `الرحاب`) |
| `distanceMetres` | integer | Only when `lat`/`lng` were sent: metres from that point |

### Order when `q` is sent

1. Name (`name` or `nameAr`) **starts with** `q`
2. Name **contains** `q`
3. **Area** match only (district or city, including the English area name)

Inside each group: nearest first (when `lat`/`lng` sent), then the most used. Show the rows in the order the API returns them.

---

## 1. Search by name, far place still returned (English)

The driver is at Rehab Gate 6. Smart Village is about 46 km away. Before this change, a search with location returned nothing past 5 km (50 km with `radiusMetres=50000`). Now every match is returned, and the location only sets the order and adds `distanceMetres`.

```http
GET /api/v1/places?q=smart&lat=30.0626&lng=31.4913&limit=8
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfsx5sab1c7mevpeahhv",
      "name": "Smart Village",
      "nameAr": "القرية الذكية",
      "type": "office",
      "lat": 30.071,
      "lng": 31.017,
      "city": "Giza",
      "district": "6th of October",
      "distanceMetres": 45653
    }
  ]
}
```


## 2. Same search in Arabic

Same request with `Accept-Language: ar`. Only `city` and `district` change: they come back in Arabic, as stored. `name` and `nameAr` are always both sent.

```http
GET /api/v1/places?q=smart&lat=30.0626&lng=31.4913&limit=8
Accept-Language: ar
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfsx5sab1c7mevpeahhv",
      "name": "Smart Village",
      "nameAr": "القرية الذكية",
      "type": "office",
      "lat": 30.071,
      "lng": 31.017,
      "city": "الجيزة",
      "district": "6 أكتوبر",
      "distanceMetres": 45653
    }
  ]
}
```


## 3. Ranking by match quality, then distance

`q=new` shows the three groups in order:

1. **Name starts with `q`**: New Cairo (4.2 km), New Administrative Capital (26.3 km)
2. **Name contains `q`**: AUC New Cairo (4.9 km)
3. **Area match only**: places in the New Cairo district, nearest first: Mivida, Downtown Mall, Cairo Festival City, German University in Cairo

New Administrative Capital is far but comes before the nearer AUC New Cairo, because its name starts with `q`. Only 7 places match, so 7 come back even though `limit=8`.

```http
GET /api/v1/places?q=new&lat=30.0626&lng=31.4913&limit=8
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfs4ampd4s43qx17s2rr",
      "name": "New Cairo",
      "nameAr": "القاهرة الجديدة",
      "type": "landmark",
      "lat": 30.03,
      "lng": 31.47,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 4165
    },
    {
      "id": "01m79xvfsw2n80gfj2sq7q7mm3",
      "name": "New Administrative Capital",
      "nameAr": "العاصمة الإدارية",
      "type": "landmark",
      "lat": 30.0197,
      "lng": 31.76,
      "city": "Cairo",
      "district": "New Administrative Capital",
      "distanceMetres": 26301
    },
    {
      "id": "01m79xvft9fsp3t22yf6wzghm0",
      "name": "AUC New Cairo",
      "nameAr": "الجامعة الأمريكية بالقاهرة",
      "type": "campus",
      "lat": 30.0195,
      "lng": 31.5005,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 4874
    },
    {
      "id": "01m79xvft1d0g1rtgy3saa6ba0",
      "name": "Mivida",
      "nameAr": "ميفيدا",
      "type": "office",
      "lat": 30.0105,
      "lng": 31.528,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 6785
    },
    {
      "id": "01m79xvft7ry561h222xyq3sdq",
      "name": "Downtown Mall",
      "nameAr": "داون تاون مول",
      "type": "office",
      "lat": 30.018,
      "lng": 31.43,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 7708
    },
    {
      "id": "01m79xvfszwztvgt1tcks33a8d",
      "name": "Cairo Festival City",
      "nameAr": "كايرو فستيفال سيتي",
      "type": "office",
      "lat": 30.0283,
      "lng": 31.4085,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 8835
    },
    {
      "id": "01m79xvfta0g77qkxgftxmg39j",
      "name": "German University in Cairo",
      "nameAr": "الجامعة الألمانية بالقاهرة",
      "type": "campus",
      "lat": 29.9866,
      "lng": 31.4413,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 9726
    }
  ]
}
```


## 4. English area name

`q=rehab` also matches places stored with district `الرحاب`. In the seeded catalogue the only place in Rehab is the Rehab landmark, which also matches by name.

```http
GET /api/v1/places?q=rehab&lat=30.0626&lng=31.4913&limit=8
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfs98sr78xfhn0k5v92g",
      "name": "Rehab",
      "nameAr": "الرحاب",
      "type": "landmark",
      "lat": 30.062,
      "lng": 31.495,
      "city": "Cairo",
      "district": "Rehab",
      "distanceMetres": 362
    }
  ]
}
```


## 5. Arabic spelling variants

`القريه الذكيه` (with ه instead of ة) finds `القرية الذكية`. Also ignored: أ/إ/آ vs ا, ى vs ي, and tashkeel.

```http
GET /api/v1/places?q=القريه الذكيه&lat=30.0626&lng=31.4913&limit=8
Accept-Language: ar
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfsx5sab1c7mevpeahhv",
      "name": "Smart Village",
      "nameAr": "القرية الذكية",
      "type": "office",
      "lat": 30.071,
      "lng": 31.017,
      "city": "الجيزة",
      "district": "6 أكتوبر",
      "distanceMetres": 45653
    }
  ]
}
```


## 6. Search without location

No `lat`/`lng`: same match-quality order, then the most used. No `distanceMetres` on any row.

```http
GET /api/v1/places?q=university&limit=8
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfta0g77qkxgftxmg39j",
      "name": "German University in Cairo",
      "nameAr": "الجامعة الألمانية بالقاهرة",
      "type": "campus",
      "lat": 29.9866,
      "lng": 31.4413,
      "city": "Cairo",
      "district": "New Cairo"
    },
    {
      "id": "01m79xvftc7ckb5dn59qy70qe3",
      "name": "Cairo University",
      "nameAr": "جامعة القاهرة",
      "type": "campus",
      "lat": 30.0262,
      "lng": 31.2088,
      "city": "Giza",
      "district": "Giza"
    },
    {
      "id": "01m79xvftea800kk41ac37fgxz",
      "name": "Ain Shams University",
      "nameAr": "جامعة عين شمس",
      "type": "campus",
      "lat": 30.077,
      "lng": 31.285,
      "city": "Cairo",
      "district": "Abbassia"
    }
  ]
}
```


## 7. Search with an explicit radius (old behaviour kept)

When `radiusMetres` is sent with `q`, results are still limited to that radius and sorted by distance. Smart Village is 46 km away, so nothing comes back inside 5 km. **For the Choose a place screen, don't send `radiusMetres` with `q`.**

```http
GET /api/v1/places?q=smart&lat=30.0626&lng=31.4913&radiusMetres=5000&limit=8
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": []
}
```


## 8. Places near a point, no `q` (unchanged)

Used for "Use my current location" and naming a dropped pin. Limited to `radiusMetres` (default 5 km), nearest first. Only `city`/`district` localisation is new.

```http
GET /api/v1/places?lat=30.0626&lng=31.4913&radiusMetres=5000
Accept-Language: en
```

**200**

```json
{
  "success": true,
  "data": [
    {
      "id": "01m79xvfs98sr78xfhn0k5v92g",
      "name": "Rehab",
      "nameAr": "الرحاب",
      "type": "landmark",
      "lat": 30.062,
      "lng": 31.495,
      "city": "Cairo",
      "district": "Rehab",
      "distanceMetres": 362
    },
    {
      "id": "01m79xvfs4ampd4s43qx17s2rr",
      "name": "New Cairo",
      "nameAr": "القاهرة الجديدة",
      "type": "landmark",
      "lat": 30.03,
      "lng": 31.47,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 4165
    },
    {
      "id": "01m79xvft2ct95bg8gqf3a2s8w",
      "name": "Point 90",
      "nameAr": "بوينت 90",
      "type": "office",
      "lat": 30.0207,
      "lng": 31.497,
      "city": "Cairo",
      "district": "Fifth Settlement",
      "distanceMetres": 4691
    },
    {
      "id": "01m79xvft9fsp3t22yf6wzghm0",
      "name": "AUC New Cairo",
      "nameAr": "الجامعة الأمريكية بالقاهرة",
      "type": "campus",
      "lat": 30.0195,
      "lng": 31.5005,
      "city": "Cairo",
      "district": "New Cairo",
      "distanceMetres": 4874
    }
  ]
}
```


## 9. Invalid `limit`

`limit` must be 1 to 20. `0` or `21` returns the usual validation error.

```http
GET /api/v1/places?q=smart&limit=21
Accept-Language: en
```

**422**

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "limit": [
        "The limit field must not be greater than 20."
      ]
    }
  }
}
```
