# Deploy ALCROS: Cursor → GitHub → SmarterASP

This guide explains how to **update the live website automatically** when you push code to GitHub.

**In simple terms:**

1. You edit ALCROS in **Cursor** on your PC.
2. You **push** to **GitHub** (online copy of your code).
3. **GitHub Actions** copies changed files to **SmarterASP** over FTP (secure FTP = FTPS).
4. Your **database password** and **uploaded files** on the server are **not** replaced by git.

SmarterASP’s own article: [Publish with GitHub Actions](https://www.smarterasp.net/support/kb/a2275/how-to-use-github-actions-to-auto-publish-your-website-file-to-our-server_.aspx).

---

## What gets uploaded vs what stays on the server

| Stays on SmarterASP only (you create once on the server) | Comes from GitHub every deploy |
|----------------------------------------------------------|--------------------------------|
| MySQL database (data) | PHP pages, CSS, JS |
| `config/database.local.php` (MySQL login) | `web.config`, `install.php`, etc. |
| `config/hosting.local.php` (HTTPS) | Most of the project |
| Files citizens/staff uploaded (`uploads/`, backups in `storage/`) | |

---

# STEP 1 — Set up SmarterASP (do this once)

Skip if your site **already works** (you ran `install.php` and can log in).

### 1.1 Open hosting

1. Log in at [smarterasp.net](https://www.smarterasp.net).
2. Click **Manage** on your plan (e.g. **capstone2026-001**).
3. You are in the **Hosting Control Panel**.

### 1.2 Create MySQL

1. Left menu → **Databases** → **MySQL**.
2. **+ Database** → name e.g. `alcros` → password → **Create Database**.
3. Click **Manager** on that row → write down in Notepad:
   - Server (host)
   - Database name (e.g. `db_acfa0a_alcros`)
   - Username
   - Password

### 1.3 Know your website folder

1. **Websites** → site **alcros** → **File Manager** (folder icon).
2. Your ALCROS files must live in **`/alcros`** (you should see `index.php` here after upload).

### 1.4 Put secret config on the server (not in GitHub)

In **File Manager → `/alcros/config`**:

**File `database.local.php`** (create new, paste, edit values):

```php
<?php
return [
    'DB_HOST' => 'paste-mysql-server-here',
    'DB_NAME' => 'db_acfa0a_alcros',
    'DB_USER' => 'paste-username-here',
    'DB_PASS' => 'paste-password-here',
];
```

**File `hosting.local.php`**:

```php
<?php
define('ALCROS_TRUST_PROXY_HTTPS', true);
```

### 1.5 Install tables (once)

1. Browser: `https://YOUR-TRIAL-URL.itempurl.com/install.php`
2. Click **Install Database**.
3. Open **login.php** → sign in → change admin password in **Settings → Security**.

### 1.6 PHP version

**Websites → alcros** → set **PHP 8.x**.

More (SSL, domain, cron): **`deploy/SMARTERASP.md`**.

---

# STEP 2 — Put your code on GitHub (do this once)

### 2.1 Create a GitHub repository

1. Go to [github.com](https://github.com) → **New repository**.
2. Name it e.g. `alcros` → **Private** is recommended → **Create** (no README needed if you already have code).

### 2.2 Push from Cursor

1. In Cursor, open the folder that contains **`index.php`** (your ALCROS project).
2. Open terminal (**Terminal → New Terminal**).
3. Run (replace `YOUR_USER` and `YOUR_REPO`):

```bash
git init
git add .
git commit -m "Initial ALCROS"
git remote add origin https://github.com/YOUR_USER/YOUR_REPO.git
git branch -M main
git push -u origin main
```

**Important:** Git must **not** upload `config/database.local.php` or `config/hosting.local.php` — they are in `.gitignore`. Passwords stay on SmarterASP only.

---

# STEP 3 — Get FTP login from SmarterASP (do this once)

GitHub needs FTP details to send files.

1. **Hosting Control Panel → Websites → alcros**.
2. Click **⋯** (three dots) on the site card → **FTP Access**.
3. Copy to Notepad:
   - **FTP server** (hostname)
   - **Username**
   - **Password** (use **Reset** in panel if you forgot it)
   - **Path / remote directory** — usually **`/alcros/`** (must end with `/`)

This path must be the **same folder** as File Manager where `index.php` lives.

---

# STEP 4 — Add secrets on GitHub (do this once)

Secrets = passwords stored safely for the automatic deploy.

1. Open your repo on GitHub.
2. **Settings** tab → left side **Secrets and variables** → **Actions**.
3. Click **New repository secret** four times:

| Name (copy exactly) | What to paste |
|---------------------|---------------|
| `SMARTERASP_FTP_SERVER` | FTP server hostname |
| `SMARTERASP_FTP_USERNAME` | FTP username |
| `SMARTERASP_FTP_PASSWORD` | FTP password |
| `SMARTERASP_FTP_REMOTE_DIR` | `/alcros/` (or path from FTP Access) |

No spaces before/after names. Values are only the host/user/pass/path — not URLs like `https://`.

---

# STEP 5 — First automatic deploy

### Option A — Push code (normal way)

In Cursor terminal:

```bash
git add .
git commit -m "Enable SmarterASP deploy"
git push
```

### Option B — Run by hand (test)

1. GitHub → your repo → **Actions** tab.
2. Left list → **Deploy to SmarterASP.NET**.
3. **Run workflow** → **Run workflow**.
4. Wait until the row shows a **green checkmark** (about 1–3 minutes).
5. Click the run → read the log if it failed (red X).

---

# STEP 6 — Check the live site

1. Open your site URL (e.g. `https://capstone2026-001-site1.itempurl.com/`).
2. Home page loads.
3. **login.php** works.
4. In SmarterASP **File Manager**, `config/database.local.php` is still there.

---

# Every day after setup (your routine)

```text
Edit in Cursor  →  git add .  →  git commit -m "what you changed"  →  git push
                                                      ↓
                              GitHub Actions uploads to SmarterASP (automatic)
```

1. Change code in Cursor.
2. Save files.
3. Terminal:

```bash
git add .
git commit -m "Describe your change"
git push
```

4. GitHub → **Actions** → wait for green check.
5. Refresh your live website and test.

---

# If something goes wrong

| What you see | What to do |
|--------------|------------|
| GitHub Action: login failed | Wrong FTP secret → fix in **Settings → Secrets**; reset FTP password in SmarterASP |
| Website 404 or empty | Wrong **`SMARTERASP_FTP_REMOTE_DIR`** → use `/alcros/` (site root) |
| Files in `alcros/alcros/` folder | Remote path too high → set secret to **`/alcros/`** not `/` |
| Database error after deploy | Recreate **`database.local.php`** on server (Step 1.4) — deploy does not delete it, but if missing, add it back |
| White screen / 500 | **PHP 8.x**; check **`hosting.local.php`** exists on server |
| Action never starts | Push to branch **`main`** or **`master`**, or use **Run workflow** in Actions |

---

# More help

- **SSL, domain, cron-job.org:** `deploy/SMARTERASP.md`
- **Workflow file (advanced):** `.github/workflows/deploy-smarterasp.yml`
