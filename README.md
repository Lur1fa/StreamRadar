<p align="center">
  <img src="public/images/logo.png" alt="StreamRadar" width="500">
</p>

<p align="center">A self-hosted dashboard for monitoring Twitch streams. Track categories and channels, filter by language and viewers, get alerts via email, Discord or webhooks.</p>

<p align="center">
  <img src="https://img.shields.io/badge/license-MIT-blue" alt="License">
</p>

## Why

Twitch lets you follow categories, but it won't notify you when a new stream goes live in one. **There's no way to say "tell me when someone starts streaming this game in English with more than 50 viewers."** You're left refreshing the category page manually and scrolling through hundreds of streams.

StreamRadar watches the categories and channels you pick, checks for new streams automatically, and alerts you via email, Discord, or any webhook when something matches your conditions — language, viewer count, keywords, you name it. Self-hosted, private, no account needed.

## What it does

- **Track categories** — follow Twitch game categories (e.g. "Just Chatting", "Fortnite")
- **Track channels** — follow specific Twitch channels regardless of what they stream
- **Live dashboard** — see all live streams in one place with sorting, grouping, and filtering
- **Alerts** — get notified when streams go live via email, Discord, Telegram, or generic webhook
- **Blacklist** — block channels, keywords, or tags from appearing in results
- **Dark / Light mode** — system-aware theme with manual toggle
- **Pin streams** — pin your favorites to the top
- **Export / Import** — backup and restore all your settings
- **Self-hosted** — your data stays on your machine, SQLite database, no external services required

---

https://github.com/user-attachments/assets/fc9bbc7b-7b03-4267-8387-af9e16e64329

---

## Installation

### Quick install (recommended)

You only need [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed. The install script does everything else.

**Windows** — open PowerShell or Command Prompt and paste:
```
curl -fsSL https://raw.githubusercontent.com/stmn/StreamRadar/main/install.bat -o install.bat && install.bat
```

Or [download install.bat](https://raw.githubusercontent.com/stmn/StreamRadar/main/install.bat) and double-click it.

**Mac / Linux** — open Terminal and paste:
```bash
curl -fsSL https://raw.githubusercontent.com/stmn/StreamRadar/main/install.sh | bash
```

That's it. Open **http://localhost:8080** in your browser.

### Manual Docker install

If you prefer to do it yourself:

```bash
git clone https://github.com/stmn/StreamRadar.git
cd streamradar
docker compose up -d
```

Everything is automatic — database, encryption keys, and migrations are set up on first launch.

**Create the first administrator.** StreamRadar requires a login and ships with no default account. On first start it prints a one-time setup token to the container log:

```bash
docker compose logs streamradar        # look for "Setup token:"
# or generate a fresh one at any time:
docker compose exec streamradar php artisan auth:setup-token
```

Open the app, go to the setup page, and paste the token to create your admin account. Prefer the command line? Run `docker compose exec streamradar php artisan user:create --admin`.

**Access from other devices.** By default the container is published on `127.0.0.1` only. To expose it on your LAN set `APP_BIND=0.0.0.0` in `.env` (ideally behind a TLS reverse proxy).

**Behind a TLS reverse proxy** set both of these in `.env`, then run `docker compose up -d`:

```
APP_URL=https://streamradar.example.com
TRUSTED_PROXIES=172.16.0.0/12
```

With an `https://` `APP_URL`, every link and redirect (including the SSO callback address) is built from that URL and the session cookie is marked `Secure`, so the app is only usable through that address, not by IP over plain HTTP. `TRUSTED_PROXIES` (IPs or CIDR ranges of your proxy) lets the app see the real client IP for login throttling and logs.

**Change the port** (e.g. to 3000): add `APP_PORT=3000` to `.env` file, then run `docker compose up -d`

**Stop:** `docker compose stop`

**Update:** `git pull && docker compose up -d --build`

---

### Option 2: Manual installation

Requirements: PHP 8.3+, Composer, Node.js 20+, npm

```bash
git clone https://github.com/stmn/StreamRadar.git
cd streamradar

# Install dependencies
composer install
npm install

# Build the frontend
npm run build

# Set up the app
cp .env.example .env
php artisan key:generate
php artisan app:setup          # migrations, defaults, and prints a setup token

# Create the first administrator (either option works)
php artisan user:create --admin
#   …or use the setup token printed by app:setup on the /setup page

# Start the server
php artisan serve
```

Open **http://localhost:8000** in your browser and sign in.

For automatic syncing, run this in a separate terminal:
```bash
php artisan schedule:work
```

---

## Getting started

### 1. Connect your Twitch account

StreamRadar needs a Twitch API key to fetch stream data. Don't worry — it only reads public data, no Twitch login is needed.

1. Go to the [Twitch Developer Console](https://dev.twitch.tv/console)
2. Log in with your Twitch account
3. Click **Register Your Application**
4. Fill in:
   - **Name:** anything (e.g. "StreamRadar")
   - **OAuth Redirect URLs:** `http://localhost` (required but not used)
   - **Category:** Analytics Tool
5. Click **Create**
6. Click **Manage** on your new app
7. Copy the **Client ID**
8. Click **New Secret** and copy the **Client Secret**
9. In StreamRadar, go to **Settings** → paste both values → click **Save**
10. Click **Test Connection** to verify it works

### 2. Track your first category

1. Go to the **Tracking** tab
2. Type a category name (e.g. "Just Chatting")
3. Select from the dropdown and click **Track**
4. StreamRadar will immediately fetch live streams for that category

### 3. Track a specific channel

1. In the **Tracking** tab, switch to **Channels**
2. Type a channel login (e.g. "shroud") and click **Track**
3. Their stream will appear on the dashboard whenever they're live

### 4. Set up alerts (optional)

1. Go to the **Alerts** tab
2. Click **New Alert**
3. Configure what you want to be notified about
4. Choose notification channels: Email, Discord, Telegram, and/or Webhook

Configure each channel in **Settings** first (SMTP for Email, webhook URL for Discord, bot token for Telegram, endpoint URL for Webhook).

---

## Notifications

StreamRadar can send alerts through multiple channels:

| Method | Setup |
|--------|-------|
| **Email** | Settings → Email/SMTP. Works with any SMTP server, Gmail, Mailgun, etc. |
| **Discord** | Settings → Discord. Paste a [webhook URL](https://support.discord.com/hc/en-us/articles/228383668) from your server. |
| **Telegram** | Settings → Telegram. Create a bot via [@BotFather](https://t.me/BotFather), copy the bot token, send `/start` to the bot, then enter the bot token and your chat ID. To find your chat ID, message [@userinfobot](https://t.me/userinfobot). |
| **Webhook** | Settings → Webhook. POST JSON to any URL — works with [ntfy.sh](https://ntfy.sh), Zapier, Make, or your own endpoint. |

---

## Automatic syncing

StreamRadar automatically refreshes stream data. The sync frequency is configurable in **Settings** (default: every 5 minutes).

**Docker:** Automatic syncing is built-in, no extra setup needed.

**Manual installation:** Keep `php artisan schedule:work` running in a separate terminal, or add a cron job:
```
* * * * * cd /path/to/streamradar && php artisan schedule:run >> /dev/null 2>&1
```

You can also manually sync anytime by clicking the **Sync** button in the top bar.

---

## Security

Authentication is always required — there is no open mode and no default password.

**Accounts and roles.** Each person has their own account with one of two roles:

- **admin** — full control: settings, user management, tracking, alerts, blacklist, manual sync.
- **user** — read-only: dashboard, alerts, history.

Admins manage accounts under **Users**, or from the command line (`user:create`, `user:password`, `user:role`, `user:list`, `user:delete`). The last administrator can never be demoted, disabled or deleted, so you cannot lock yourself out.

**First administrator.** Created with the one-time token from `php artisan auth:setup-token` (printed automatically on first Docker start) on the `/setup` page, or directly with `php artisan user:create --admin`. No credentials are ever shipped or defaulted.

**Single sign-on (optional).** StreamRadar speaks OpenID Connect (authorization-code flow with PKCE). Point it at Authentik, Keycloak, Entra ID, Google, etc. with a few environment variables:

```dotenv
OIDC_ENABLED=true
OIDC_ISSUER=https://auth.example.com/application/o/streamradar/
OIDC_CLIENT_ID=...
OIDC_CLIENT_SECRET=...
# optional: map an IdP group to the admin role
OIDC_ADMIN_CLAIM=groups
OIDC_ADMIN_VALUES=streamradar-admins
```

Group mapping only works if the provider sends the claim. If it needs its own scope (Authelia: `groups`), request it with `OIDC_SCOPES="openid profile email groups"` and allow that scope on the client. The role is re-evaluated on every sign-in.

By default, anyone who signs in through the provider gets a read-only account on first sign-in. Admins can turn that off on the **Users** page (or set `OIDC_AUTO_PROVISION=false` as the starting value); after that only people who already have an account can use SSO. To add someone, create the account on the Users page with the e-mail address they have at the provider; the password can be left empty for an account that signs in with SSO only.

The redirect URI is `https://your-host/auth/oidc/callback`. SSO is additive: local password login stays available (set `AUTH_LOCAL_LOGIN=false` to require SSO), and the `user:*` commands always work, so an unreachable identity provider never locks admins out.

**Lockout protection.** Failed logins are rate-limited per account (progressive lockout) and per IP. Passwords are bcrypt-hashed with a configurable minimum length (`AUTH_PASSWORD_MIN_LENGTH`, default 12).

**Secrets.** API secrets, SMTP passwords, bot tokens and webhook URLs are encrypted at rest, never sent to the browser (only a mask is shown), and never included in exports.

**Outbound webhooks.** Webhook and Discord URLs are validated against SSRF: they must resolve to public addresses, with no redirects and a response-size cap. To target a self-hosted endpoint on your LAN (e.g. ntfy), allow its exact hostname via `WEBHOOK_ALLOWED_HOSTS=ntfy.home.lan`.

**Production hardening.** Keep `APP_ENV=production` and `APP_DEBUG=false` (the app forces both off if misconfigured). Developer tooling (Inertia DevTools) is disabled unless explicitly enabled and is never active in production; its endpoints additionally require an admin session.

---

## Running 24/7

To keep StreamRadar running continuously (including after system restarts):

**Docker (recommended):** The `docker-compose.yml` already includes `restart: unless-stopped`, so the container will automatically restart after a system reboot — as long as Docker itself starts on boot.

- **Windows:** Docker Desktop starts with Windows by default. Check Docker Desktop → Settings → General → "Start Docker Desktop when you sign in".
- **Mac:** Docker Desktop starts automatically. Check Docker Desktop → Settings → General → "Start Docker Desktop when you log in".
- **Linux:** Enable the Docker service:
  ```bash
  sudo systemctl enable docker
  ```

**Manual installation (without Docker):** Use a process manager or system service:

- **Linux (systemd):**
  ```bash
  # Create /etc/systemd/system/streamradar.service
  [Unit]
  Description=StreamRadar
  After=network.target

  [Service]
  Type=simple
  User=www-data
  WorkingDirectory=/path/to/streamradar
  ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8080
  Restart=always

  [Install]
  WantedBy=multi-user.target
  ```
  Then: `sudo systemctl enable --now streamradar`

- **Mac (launchd):** Use `launchctl` or a tool like [pm2](https://pm2.keymetrics.io/):
  ```bash
  npm install -g pm2
  pm2 start "php artisan serve --port=8080" --name streamradar
  pm2 startup   # generates auto-start config
  pm2 save
  ```

---

## Tech stack

- **Backend:** Laravel 13, PHP 8.4, SQLite
- **Frontend:** Vue 3, Inertia.js 3, Tailwind CSS v4, TypeScript
- **Icons:** Lucide
- **Deployment:** Docker (nginx + PHP-FPM + scheduler in one container)

## Commands

| Command | Description |
|---------|-------------|
| `php artisan streams:sync` | Manually sync all streams |
| `php artisan app:setup` | Initial setup (migrations + defaults) |
| `php artisan auth:setup-token` | Print a one-time token to create the first admin on `/setup` |
| `php artisan user:create --admin` | Create a user account (admin or user) |
| `php artisan user:password <email>` | Reset an account's password (recovery) |
| `php artisan user:role <email> <role>` | Change an account's role |
| `php artisan user:list` | List accounts |
| `php artisan user:delete <email>` | Delete an account |
| `php artisan schedule:work` | Start the scheduler (auto-sync) |

## License

MIT — do whatever you want with it.
