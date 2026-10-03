# ALCROS on SmarterASP.NET Premium

This guide deploys the full ALCROS stack (PHP + MySQL, queue, requests, printing, email/SMS reminders) on **.NET PREMIUM** without removing features.

## 1. Plan requirements

- **.NET PREMIUM** (PHP 8.x + MySQL 8, Schedule Tasks, larger app pool)
- Enable **free SSL** for your domain
- Create **one MySQL database** in the hosting control panel (note **host**, **database name**, **user**, **password**)

## 2. Upload the project

Upload the entire ALCROS folder to your site root (where `index.php` is the default page).

Ensure these folders exist and are **writable** by the app:

- `storage/`
- `uploads/ids/`
- `uploads/staff/`

## 3. PHP settings (control panel)

In **Hosting Control Panel → PHP**, set (match `.user.ini` in the project root):

| Setting | Value |
|---------|--------|
| upload_max_filesize | 128M |
| post_max_size | 128M |
| max_input_vars | 10000 |
| max_execution_time | 300 |
| memory_limit | 256M (512M if large exports or imports fail) |

Confirm extensions: **PDO MySQL**, **curl**, **openssl**, **zip** (ZipArchive).

Set the site to **PHP 8.x**.

## 4. Database configuration

On the server only:

1. Copy `config/database.local.php.example` → `config/database.local.php`
2. Paste MySQL credentials from SmarterASP
3. Copy `config/hosting.local.php.example` → `config/hosting.local.php` (enables HTTPS behind proxy for secure cookies)

Import schema:

- **Option A:** phpMyAdmin → import `database/alcros.sql`
- **Option B:** Visit `https://yourdomain.com/install.php` once → Install → then restrict access to `install.php`

Sign in at `login.php` and **change the default administrator password** immediately.

## 5. IIS (`web.config`)

The repo includes `web.config` for:

- Default document `index.php`
- 128 MB request size
- Legacy redirects (`track.php`, `analytics.php`)
- Deny web access to `config/`, `database/`, `cron/`, `storage/`, `uploads/*` (IDs still served via `file.php` after staff login)

Apache `.htaccess` remains for XAMPP; production on SmarterASP uses `web.config`.

## 6. Schedule Tasks (Premium)

**Hosting Control Panel → Advance → Schedule Tasks → Call URL**

SmarterASP sends **HTTP GET** only. Copy exact URLs from **System Settings → Admin Tools → Backup** (violet SmarterASP box).

| Task | Interval | Endpoint |
|------|----------|----------|
| Appointment / visit reminders | **15** minutes | `api/appointment_reminders.php?cron_secret=...` |
| Registry backup (civil records, staff, print) | **Once daily** (e.g. 2:00 AM) | `api/registry_backup.php?token=...` |

The reminder secret is generated on first use in `storage/cron_secret.txt`.

### 6.1 Registry backup setup (recommended)

This backs up **only registry data** (same scope as the green “What gets backed up” list in ALCROS): civil records, staff, print templates/calibrations/settings, staff photos, and print form files. It does **not** include requests, appointments, queue, or activity logs.

1. Sign in as **Administrator** → **System Settings** → **Admin Tools** → **Backup**.
2. Under **Automatic registry backup (hosted / SmarterASP)**:
   - Check **Enable scheduled registry backup**.
   - Set **Keep zip files on server** (default 14 — older zips are deleted automatically).
   - If the cron URL shows the wrong domain, set **Public site URL** to `https://yourdomain.com` (no trailing slash) and save.
3. Click **Run registry backup now** once. Confirm **Status** is success and a file appears under **Stored archives**.
4. Optional test: paste the **Registry backup** URL into a browser (you do not need to be logged in). Expect JSON like `{"ok":true,"filename":"alcros-registry-....zip",...}`.
5. In SmarterASP **Schedule Tasks**:
   - **Task type:** Call URL  
   - **URL:** copy **Registry backup — once daily** from the Backup page  
   - **Interval:** daily at off-peak time (backup can take up to 5 minutes; `max_execution_time` should be 300 in PHP settings)  
   - Save the task.
6. **Download copies off the server** (do not rely on zips staying on hosting forever):
   - **File Manager / FTP:** `storage/backups/registry/` → download `alcros-registry-*.zip` files, or  
   - **Admin UI:** **Download** link next to each archive on the Backup page (requires staff login).

Zips are **not** served publicly (`storage/` is denied in `web.config`). Treat the backup URL token like a password — use **Regenerate URL token** if it is exposed.

Inside each zip: `alcros-backup.sql`, `manifest.json`, and `files/` (uploaded assets from the bundle step).

## 7. Post-deploy checks

1. Public home, request wizard, appointment booking, kiosk, queue display
2. Staff login over **HTTPS** (session cookie must be secure)
3. SMTP / SMS test from System Settings
4. Trigger reminder URL once in browser (should return JSON `{"sent":...}`)
5. Confirm SmarterASP **Schedule Task** for reminders is saved (15-minute interval)
6. Enable registry backup, run once manually, then confirm the daily **Schedule Task** for `api/registry_backup.php` is saved

## 8. Security — restrict `install.php` after setup

ALCROS already **disables re-install via POST** when the database exists. On a **live host** (not localhost/XAMPP), it also **returns 404** for `install.php` once `databaseIsInstalled()` is true.

### Automatic (built-in)

No action needed after a successful install on SmarterASP: visiting `/install.php` should show **Not found**.

To run the web installer again on production (emergency only):

- Create an empty file `storage/allow_web_install.txt` on the server, run install if needed, then **delete that file**, or
- Temporarily add to `config/hosting.local.php`: `define('ALCROS_ALLOW_WEB_INSTALL', true);` and remove it after.

### Extra hardening (recommended)

Pick one or more:

1. **Delete or rename** `install.php` on the server (e.g. `install.php.disabled`) after setup.
2. **IIS** — add inside root `web.config` before `</configuration>`:

```xml
  <location path="install.php">
    <system.webServer>
      <security>
        <authorization>
          <remove users="*" roles="" verbs="" />
          <add accessType="Deny" users="*" />
        </authorization>
      </security>
    </system.webServer>
  </location>
```

3. **Apache (XAMPP)** — in `.htaccess` or a rule only on production:

```apache
<Files "install.php">
    Require all denied
</Files>
```

4. **SmarterASP File Manager** — remove `install.php` from the site root after you confirm login works.

- Do not commit `config/database.local.php` or `config/hosting.local.php`
- Treat cron URLs and registry backup `token=` as passwords

## 9. Local XAMPP unchanged

Without `database.local.php` / `hosting.local.php`, defaults remain `localhost` / XAMPP — local development is unchanged.
