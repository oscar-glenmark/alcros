# Deploy ALCROS: Cursor → GitHub → InfinityFree

Same flow as SmarterASP, but uses **plain FTP** and workflow **Deploy to InfinityFree**.

## GitHub secrets

| Secret | Example for this account |
|--------|--------------------------|
| `INFINITYFREE_FTP_SERVER` | `ftpupload.net` |
| `INFINITYFREE_FTP_USERNAME` | `if0_43556916` |
| `INFINITYFREE_FTP_PASSWORD` | vPanel password (after any reset) |
| `INFINITYFREE_FTP_REMOTE_DIR` | **`/alcros.wuaze.com/htdocs/`** |

### Finding `INFINITYFREE_FTP_REMOTE_DIR`

1. vPanel → **File Manager**.
2. Open **`alcros.wuaze.com`** → **`htdocs`**.
3. Use that path with a **leading and trailing slash**.

**Do not use** the panel **Home directory** (`/home/vol9_...`) — FTP uses the domain path above.

### Test with FileZilla (optional)

- Host: `ftpupload.net`, user `if0_43556916`, port 21.
- After login, confirm you can open the same `htdocs` folder you put in the secret.

## Server files (once)

`config/database.local.php` on the server — MySQL host e.g. `sql305.infinityfree.com`.

Then run `install.php` once in the browser.

## SmarterASP

SmarterASP deploy is **manual only** (`workflow_dispatch`) so pushes only hit InfinityFree.