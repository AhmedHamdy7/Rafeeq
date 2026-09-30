# RAFEEQ — نشر على Oracle Cloud Always Free

> **الغرض:** سيرفر فاتح ٢٤ ساعة، مجاني للأبد، فريق الموبايل يشتغل عليه واللاب بتاعك مقفول.
>
> **الوقت:** حوالي ساعة أول مرة. الخطوات مرتّبة بحيث كل خطوة تتأكد إنها نجحت قبل اللي بعدها.
>
> **اللي إنت محتاجه:** كارت (للتحقق من الهوية — Always Free مش بيتحوّل مدفوع لوحده) · رقم موبايل.

---

## ⚠️ حاجتين لازم تقراهم قبل ما تبدأ

**١. اختيار المنطقة (Region) قرار نهائي.** Oracle بتربط الحساب بمنطقة واحدة وقت التسجيل، وتغييرها بعد كده وجع. اختار منطقة قريبة من مصر: **Frankfurt** أو **Amsterdam** أو **Jeddah** لو متاحة. ماتختارش منطقة أمريكية.

**٢. نسخة ARM المجانية كتير بترفض.** هتشوف `Out of host capacity` — مش غلطة منك، دي أشهر شكوى في Always Free. الحلول بالترتيب:

| الحل | التفصيل |
|---|---|
| جرّب Availability Domain تانية | في القائمة نفسها، AD-1 → AD-2 → AD-3 |
| جرّب أوقات مختلفة | الصبح بدري بتوقيت المنطقة أحسن |
| خد **AMD** بدل ARM | `VM.Standard.E2.1.Micro` — دايمًا متاحة تقريبًا، بس **١ جيجا رام** |

**عن الـ١ جيجا:** هتشيل nginx + PHP-FPM + MariaDB + queue worker + Reverb، بس على الحدود. **لازم تعمل swap** (خطوة ٥ تحت) وإلا MariaDB هتتقتل وقت الضغط. لو ARM اشتغلت (٤ أنوية / ٢٤ جيجا) ماتقلقش من حاجة.

---

## ١. الحساب والنسخة

1. `cloud.oracle.com` → **Sign up for free**
2. **المنطقة:** Frankfurt (أو الأقرب لمصر). ⚠️ قرار نهائي
3. الكارت للتحقق بس — مبلغ صغير بيتحجز ويترجع. Always Free مابيتحوّلش مدفوع من نفسه
4. من الـ Console: **Compute → Instances → Create instance**

| الإعداد | القيمة |
|---|---|
| Image | **Ubuntu 24.04** (أو 22.04) |
| Shape | `VM.Standard.A1.Flex` → **4 OCPU · 24 GB** · وكله في حدود Always Free |
| البديل لو رفضت | `VM.Standard.E2.1.Micro` |
| Boot volume | 50 GB (الحد المجاني 200 GB إجمالي) |
| SSH keys | **Save private key** — نزّل الملف واحفظه، مفيش نسخة تانية منه |
| Networking | اسيب الافتراضي، بس **اعمل tick على Assign a public IPv4 address** |

اكتب الـ **Public IP** في مكان. هنسمّيه `$IP`.

---

## ٢. افتح المنافذ — **وده مكان الوقوع المعتاد**

Oracle بتقفل كل حاجة غير SSH، **في مكانين مختلفين**. لازم الاتنين. أغلب الناس بيعملوا الأول وينسوا التاني ويقعدوا ساعة يدوّروا على غلط مش موجود.

### أ. في الـ Console (شبكة Oracle)

**Networking → Virtual Cloud Networks → [VCN بتاعتك] → Security Lists → Default → Add Ingress Rules**

| Source CIDR | Protocol | Destination Port |
|---|---|---|
| `0.0.0.0/0` | TCP | `80` |
| `0.0.0.0/0` | TCP | `443` |

### ب. جوّه السيرفر نفسه (iptables)

صور Ubuntu بتاعة Oracle بتجيب قواعد iptables بتحجب كل حاجة. اتصل بالسيرفر:

```bash
ssh -i /path/to/private.key ubuntu@$IP
```

وبعدين:

```bash
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

**تأكد إن الاتنين اشتغلوا** قبل ما تكمل — أسرع طريقة:

```bash
sudo apt update && sudo apt install -y nginx
# من جهازك:  افتح http://$IP  →  لازم تشوف صفحة nginx الافتراضية
```

لو ماظهرتش، المشكلة في المنافذ مش في أي حاجة تانية. ماتكملش.

---

## ٣. البرامج الأساسية

PHP 8.4 من الـ PPA بتاع ondrej (نسخة Ubuntu الافتراضية أقدم من اللي المشروع محتاجه):

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl \
  mariadb-server nginx git unzip

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

> **عن `php8.4-gd`:** مش اختيارية. رفع المستندات بيعيد ترميز الصورة عشان يشيل الميتاداتا (فخ #24 — صور البطاقات بتحمل مكان التصوير)، وده محتاج GD.

تأكد:

```bash
php -v          # لازم 8.4 أو أعلى
php -m | grep -E "gd|intl|bcmath|pdo_mysql"
```

---

## ٤. قاعدة البيانات

```bash
sudo mysql_secure_installation     # حدّد root password واقبل الافتراضيات
sudo mysql -u root -p
```

```sql
CREATE DATABASE rafeeq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rafeeq'@'localhost' IDENTIFIED BY 'حط-باسورد-قوي-هنا';
GRANT ALL PRIVILEGES ON rafeeq.* TO 'rafeeq'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

> **`utf8mb4_unicode_ci` مش اختيارية كمان** — الأسماء والعناوين عربي، و collation غلط بيخلّي البحث والفرز يطلعوا غلط بطرق مش واضحة.

تأكد من النسخة: `mysql -V` — أي MariaDB ١٠.٤ أو أعلى تمام (المشروع مختبَر على ١٠.٤).

---

## ٥. Swap — **إجباري لو خدت نسخة AMD بـ ١ جيجا**

من غيره MariaDB بتتقتل أول ضغط، والرسالة بتظهر في `dmesg` مش في أي لوج بتبصّ فيه.

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h     # لازم تشوف Swap: 2.0Gi
```

على ARM بـ ٢٤ جيجا مش محتاجها، بس ماتضرّش.

---

## ٦. الكود

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <عنوان-الريبو> rafeeq
sudo chown -R www-data:www-data /var/www/rafeeq
cd /var/www/rafeeq

sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data cp .env.production.example .env
sudo -u www-data php artisan key:generate
```

بعدين `sudo -u www-data nano .env` وحدّد:

| المفتاح | القيمة |
|---|---|
| `APP_URL` | `https://api.example.com` (أو `http://$IP` مؤقتًا) |
| `DB_PASSWORD` | الباسورد من خطوة ٤ |
| `REVERB_APP_KEY` / `SECRET` / `ID` | قيم عشوائية — `openssl rand -hex 16` لكل واحدة |
| `RAFEEQ_DEV_OTP_CODE` | كود ثابت لفريق الموبايل، لحد ما يبقى فيه مزوّد SMS (اقرا التحذير تحت) |

بعدين:

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force     # نور ومريم ومسار الرحاب ← القرية الذكية
sudo -u www-data php artisan storage:link

sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

---

## ٧. nginx والعمليات في الخلفية

```bash
sudo cp deploy/nginx/rafeeq.conf /etc/nginx/sites-available/rafeeq
sudo nano /etc/nginx/sites-available/rafeeq      # بدّل api.example.com
sudo ln -s /etc/nginx/sites-available/rafeeq /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo cp deploy/systemd/rafeeq-queue.service /etc/systemd/system/
sudo cp deploy/systemd/rafeeq-reverb.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now rafeeq-queue rafeeq-reverb

sudo crontab -u www-data -e
# والصق السطر اللي في deploy/systemd/rafeeq-scheduler.cron
```

**تأكد إن التلاتة ماشيين:**

```bash
systemctl status rafeeq-queue rafeeq-reverb --no-pager
sudo crontab -u www-data -l
```

> ⚠️ التلاتة دول **غيابهم فشل صامت**. مفيش رسالة خطأ في أي مكان لو واحد منهم واقف — التفاصيل في كل ملف `.service` وفي `DEPLOYMENT.md §1`.

---

## ٨. TLS (مجاني)

محتاج دومين يشاور على `$IP`. لو عندك واحد:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.example.com
```

بيعدّل ملف nginx لوحده ويجدّد لوحده.

**ومن غير دومين؟** استخدم `http://$IP` مؤقتًا. بس **اعرف إن ده يعني:**
- الـ WebSocket هيبقى `ws://` مش `wss://` — والتوكنات والمواقع بتمشي واضحة على الشبكة
- iOS و Android بيرفضوا HTTP العادي افتراضيًا؛ الفريق هيحتاج استثناء في الإعدادات

**ماينفعش للإنتاج.** للتجربة المشتركة بس. دومين `.com` بـ ١٥ دولار في السنة والمشكلة تخلص.

---

## ٩. التأكيد النهائي

```bash
curl -s http://$IP/up                    # صحة التطبيق
curl -s http://$IP/api/v1/places         # لازم 401 بالـ envelope — يعني الـ API شغّال وبيحمي نفسه
```

الرد المتوقع للتاني:

```json
{"success":false,"error":{"code":"UNAUTHENTICATED","message":"...","fields":{}}}
```

**٤٠١ دي نجاح.** معناها الـ routing والـ PHP وقاعدة البيانات والـ envelope والحماية كلهم ماشيين.

وافتح `http://$IP/docs/api` في المتصفح — توثيق OpenAPI تفاعلي لفريق الموبايل.

---

## 🔴 قبل ما تبعت الـ IP للفريق

| | ليه |
|---|---|
| `APP_DEBUG=false` | موجودة في `.env.production.example` — **اتأكد**. لو `true`، صفحة الخطأ بتعرض الـ SQL والـ env وأجزاء من الكود |
| `APP_ENV=production` | بيخلّي `LogOtpSender` **يرفض يشتغل** — وده مقصود: مايوصلش إنتاج بلا مزوّد SMS. **يعني لازم `RAFEEQ_DEV_OTP_CODE`** (اقرا تحت) أو `APP_ENV=staging` |
| `/request-docs` | متحصّنة أصلاً من قبل، بس اتأكد إنها مش فاتحة |
| باسورد قاعدة البيانات | مش `password` ومش فاضي |

### الـ OTP — الحاجة اللي هتوقّفهم في أول ساعة

مفيش مزوّد SMS. `LogOtpSender` بيكتب الكود في اللوج وبس، والفريق مش شايف اللوج.

عندك اختياران:

1. **`RAFEEQ_DEV_OTP_CODE=123456`** ✅ **مبني.** كود ثابت لأي رقم. لازم يبقى **٦ أرقام بالظبط** (زي `auth.otp.length`) وإلا التطبيق بيرفض بصوت عالي — لأن طول غلط معناه "محدش يقدر يسجّل دخول ومفيش رسالة خطأ في أي مكان". وفي production **مابيتجاهلش الإعداد، بيرفض يصدر كود خالص** — عشان التجاهل الصامت هو بالظبط اللي بيخلّيه يفضل مضبوط.
2. **`APP_ENV=staging`** + الفريق ياخد الكود من اللوج:
   ```bash
   sudo tail -f /var/www/rafeeq/storage/logs/laravel.log | grep -A3 "OTP issued"
   ```
   بيشتغل من غير أي كود جديد، بس معناه حد لازم يبقى على SSH وقت ما يسجّلوا دخول.

**الاختيار الأول أنضف.** والاختيار الثالث — نرجّع الكود في رد الـ API لما debug مفتوح — **مش هعمله**: ده بالظبط اللي بيوصل production بالغلط.

---

## التحديث بعد كده

```bash
cd /var/www/rafeeq
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
sudo systemctl restart rafeeq-queue rafeeq-reverb php8.4-fpm
```

> **`restart rafeeq-queue` مش اختيارية.** عامل الطابور عملية PHP طويلة العمر بتمسك الكود اللي قامت بيه — من غير restart بيفضل شغّال بالكود القديم لحد ما حد يفتكر.

---

## لو حاجة ماشتغلتش

| الأعراض | السبب الأغلب |
|---|---|
| المتصفح بيعمل timeout على `http://$IP` | خطوة ٢ — واحد من المكانين الاتنين ناقص. `sudo iptables -L INPUT -n --line-numbers` |
| 502 Bad Gateway | PHP-FPM واقف أو الـ socket بمسار غلط. `systemctl status php8.4-fpm` و`ls /run/php/` |
| 500 وصفحة فاضية | `sudo tail -50 /var/www/rafeeq/storage/logs/laravel.log` · وأغلب الوقت صلاحيات: `sudo chown -R www-data:www-data storage bootstrap/cache` |
| الترحيل بيفشل على عمود spatial | نسخة MariaDB قديمة. `mysql -V` — محتاج ١٠.٤+ |
| الرحلة الحيّة مش بتتحدّث | Reverb واقف، أو nginx مش بيوجّه `/app`. الـ polling (`GET /v1/trips/{trip}/location`) بيفضل شغّال فبيبان إن كله تمام |
| مسارات GPS مش بتتسجّل | الـ cron مش مركّب. `sudo crontab -u www-data -l` |

---

## اللي الملف ده مابيغطيهوش

بصراحة، ودي حاجات إنتاج حقيقي محتاجها ومش موجودة هنا:

- **نسخ احتياطي** — مفيش. `mysqldump` في cron يومي على الأقل، ومُختبَر إنه بيرجّع فعلًا
- **مراقبة** — مفيش. لو Reverb وقعت ٣ الصبح محدش هيعرف
- **rate limiting على مستوى الشبكة** — الموجود في التطبيق بس
- **تشديد SSH** — اقفل `PasswordAuthentication`، والأفضل `fail2ban`

دي المرحلة ١٥ في الخطة. الملف ده هدفه **بيئة مشتركة لفريق الموبايل**، مش إنتاج لمستخدمين حقيقيين — والفرق مهم.
