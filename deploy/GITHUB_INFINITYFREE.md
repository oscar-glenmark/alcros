# Deploy ALCROS: Cursor → GitHub → InfinityFree

Same flow as SmarterASP, but uses **plain FTP** and workflow **Deploy to InfinityFree**.

## GitHub secrets (this account)

| Secret | Value |
|--------|--------|
| `INFINITYFREE_FTP_SERVER` | `ftpupload.net` |
| `INFINITYFREE_FTP_USERNAME` | `if0_43556916` |
| `INFINITYFREE_FTP_PASSWORD` | vPanel password (after any reset) |
| `INFINITYFREE_FTP_REMOTE_DIR` | **`/htdocs/`** |

### Why `/htdocs/` and not `/alcros.wuaze.com/htdocs/`?

On this InfinityFree account, **two** `htdocs` folders exist in File Manager:

| Folder | What happens on `git push` (if secret points here) | What the browser shows |
|--------|------------------------------------------------------|-------------------------|
| **`alcros.wuaze.com` → `htdocs`** | Gets the **new** files from GitHub | **Not** used for `https://alcros.wuaze.com` on this account |
| **Top-level `htdocs`** (next to `alcros.wuaze.com`, near “DO NOT UPLOAD FILES HERE”) | Gets the **new** files when secret is **`/htdocs/`** | **This is what `https://alcros.wuaze.com` serves** |

If push updates `alcros.wuaze.com/htdocs/index.php` but the site still looks like old `htdocs/index.php`, set the secret to **`/htdocs/`**, re-run **Deploy to InfinityFree**, then hard-refresh the site.

### Secret format

- Must start and end with `/`: **`/htdocs/`**
- **Do not** use the panel Home directory (`/home/vol9_...`).

### Test with FileZilla (optional)

- Host: `ftpupload.net`, user `if0_43556916`, port 21.
- After login, confirm **`/htdocs/index.php`** changes after a deploy (that file should match the live site).

## Server files (once)

`config/database.local.php` on the server — MySQL host e.g. `sql305.infinityfree.com`.

Then run `install.php` once in the browser.

## SmarterASP

SmarterASP deploy is **manual only** (`workflow_dispatch`) so pushes only hit InfinityFree.