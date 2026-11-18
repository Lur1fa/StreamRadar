<p align="center">
  <img src="public/images/logo.png" alt="StreamRadar" width="500">
</p>

<p align="center">A self-hosted dashboard for monitoring Twitch streams. Track categories and channels, filter by language and viewers, get alerts via ntfy, email, Discord, Telegram or webhooks.</p>

<p align="center">
  <img src="https://img.shields.io/badge/license-MIT-blue" alt="License">
</p>

## Why

Twitch lets you follow categories, but it won't notify you when a new stream goes live in one. **There's no way to say "tell me when someone starts streaming this game in English with more than 50 viewers."** You're left refreshing the category page manually and scrolling through hundreds of streams.

StreamRadar watches the categories and channels you pick, checks for new streams automatically, and alerts you via ntfy, email, Discord, Telegram or any webhook when something matches your conditions — language, viewer count, keywords, you name it. Self-hosted, private, no account needed.

## What it does

- **Track categories** — follow Twitch game categories (e.g. "Just Chatting", "Fortnite")
- **Track channels** — follow specific Twitch channels regardless of what they stream
- **Live dashboard** — see all live streams in one place with sorting, grouping, and filtering
- **Alerts** — get notified when streams go live via ntfy, email, Discord, Telegram, or generic webhook
- **Blacklist** — block channels, keywords, or tags from appearing in results
- **Dark / Light mode** — system-aware theme with manual toggle
- **Pin streams** — pin your favorites to the top
- **Export / Import** — backup and restore all your settings
- **Self-hosted** — your data stays on your machine, SQLite database, no external services required

---

https://github.com/user-attachments/assets/fc9bbc7b-7b03-4267-8387-af9e16e64329

---

## Installation

StreamRadar runs from a published Docker image.

```bash
mkdir streamradar && cd streamradar
curl -fsSL https://raw.githubusercontent.com/Lur1fa/StreamRadar/main/docker-compose.yml -o docker-compose.yml
echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env
docker compose up -d
```

Open **http://localhost:8080** in your browser. The database and migrations are set up on first start; your data lives in `./database`.

**`APP_KEY` is required.** It encrypts the stored secrets (Twitch client secret, SMTP password, alert destination credentials). Back it up together with `./database`: a different key makes those secrets unreadable. StreamRadar refuses to start without it.

**Create the first administrator.** StreamRadar requires a login and ships with no default account. On first start it prints a one-time setup token to the container log:

```bash
docker compose logs streamradar        # look for "Setup token:"
# or generate a fresh one at any time:
docker compose exec streamradar php artisan auth:setup-token
```

Open the app, go to the setup page, and paste the token to create your admin account. Prefer the command line? Run `docker compose exec streamradar php artisan user:create --admin`.

**Access from other devices.** By default the container is published on `127.0.0.1` only. To expose it on your LAN set `APP_BIND=0.0.0.0` in `.env`.

**Behind a TLS reverse proxy** set both of these in `.env`, then run `docker compose up -d`:

```
APP_URL=https://streamradar.example.com
TRUSTED_PROXIES=172.16.0.0/12
```

With an `https://` `APP_URL`, every link and redirect (including the SSO callback address) is built from that URL and the session cookie is marked `Secure`, so the app is only usable through that address, not by IP over plain HTTP. `TRUSTED_PROXIES` (IPs or CIDR ranges of your proxy) lets the app see the real client IP for login throttling and logs.

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
4. Choose where it goes: Discord, Email, Telegram, ntfy and/or Webhook

Add the destinations under **Settings → Notifications** first. A rule sends to every enabled destination of each type it has switched on.

---

## Notifications

Each account keeps a list of destinations under **Settings → Notifications**: click **Add Destination**, pick the type, and fill in its fields. You can have several of the same type (two ntfy topics, say), switch each on or off, and send a test alert from the list.

| Type | Fields |
|------|--------|
| **ntfy** | Server URL and topic. For a protected topic, an access token (sent as `Authorization: Bearer`) or a username and password (Basic). Optional title prefix. |
| **Webhook** | Method (POST, PUT, PATCH), URL, query parameters, headers, and authentication (none, Basic or Bearer token). Each batch of alerts is sent as JSON (`{"event": "stream.alerts.batch", "count": …, "alerts": […]}`). Works with Zapier, Make or your own endpoint. |
| **Email** | One or more recipient addresses and an optional subject prefix. Sent through the mail server an admin sets up under **Settings → Mail Server**. |
| **Telegram** | Bot token from [@BotFather](https://t.me/BotFather) and your chat ID (send `/start` to the bot; [@userinfobot](https://t.me/userinfobot) tells you the ID). |
| **Discord** | A [webhook URL](https://support.discord.com/hc/en-us/articles/228383668) from your server. |

Credentials never go in a URL: use the access token or username/password fields (ntfy) or **Authentication** (webhook). Upgrading turns the old Email, Discord, Telegram and Webhook settings into destinations; a webhook that used the ntfy format becomes an ntfy destination, and the alert rules that sent to it send to ntfy.

---

## Automatic syncing

StreamRadar automatically refreshes stream data. The sync frequency is configurable in **Settings** (default: every 5 minutes).

Syncing runs inside the container; there is nothing to set up.

You can also manually sync anytime by clicking the **Sync** button in the top bar.

---

## Security

Authentication is always required — there is no open mode and no default password.

**Accounts and roles.** Each person has their own account with one of two roles:

- **user** — their own streams, tracking, alerts, blacklist and history, their own default filters, and their own alert destinations (ntfy, webhook, e-mail, Telegram, Discord).
- **admin** — the same for their own account, plus the installation: Twitch API keys, scanning, SMTP server, backup, and user management.

**Each account has its own data.** Tracked categories and channels, alerts, blacklist, history and the stream list belong to the account that created them; no account sees or changes another account's. Alerts are delivered to the destinations of the account that owns the alert. Twitch is still asked only once per category on each scan, however many accounts track it. Deleting an account deletes its data.

Because every account can add tracked categories and outbound alert destinations, only give accounts to people you trust with that: each tracked category costs Twitch API requests, e-mails go out through your SMTP server, and webhooks can reach the hosts listed in `WEBHOOK_ALLOWED_HOSTS`.

**Upgrading from a version with shared data.** Everything that existed goes to the oldest administrator account; other accounts start empty. If you use a different account day to day (for example one created by single sign-on), move the data to it:

```bash
php artisan user:move-data old-admin@example.com you@example.com
```

The command lists what it will move and asks before doing it. It refuses if the target account already has tracking data of its own, unless you add `--replace`, which deletes that data first.

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

By default, anyone who signs in through the provider gets a regular account on first sign-in. Admins can turn that off on the **Users** page (or set `OIDC_AUTO_PROVISION=false` as the starting value); after that only people who already have an account can use SSO. To add someone, create the account on the Users page with the e-mail address they have at the provider; the password can be left empty for an account that signs in with SSO only.

The redirect URI is `https://your-host/auth/oidc/callback`. SSO is additive: local password login stays available (set `AUTH_LOCAL_LOGIN=false` to require SSO), and the `user:*` commands always work, so an unreachable identity provider never locks admins out.

**Lockout protection.** Failed logins are rate-limited per account (progressive lockout) and per IP. Passwords are bcrypt-hashed with a configurable minimum length (`AUTH_PASSWORD_MIN_LENGTH`, default 12).

**Secrets.** API secrets, SMTP passwords and every destination's credentials (tokens, passwords, webhook URLs, header and query values) are encrypted at rest, never sent to the browser (only a mask is shown) and never included in exports.

**Outbound webhooks.** ntfy, webhook and Discord URLs are validated against SSRF: they must resolve to public addresses, with no redirects and a response-size cap. To target a self-hosted endpoint on your LAN (e.g. ntfy), allow its exact hostname via `WEBHOOK_ALLOWED_HOSTS=ntfy.home.lan`.

**Production hardening.** Keep `APP_ENV=production` and `APP_DEBUG=false` (the app forces both off if misconfigured). Developer tooling (Inertia DevTools) is disabled unless explicitly enabled and is never active in production; its endpoints additionally require an admin session.

---

## Tech stack

- **Backend:** Laravel 13, PHP 8.4, SQLite
- **Frontend:** Vue 3, Inertia.js 3, Tailwind CSS v4, TypeScript
- **Icons:** Lucide
- **Deployment:** Docker (nginx + PHP-FPM + scheduler in one container)

## Commands

Run them inside the container: `docker compose exec streamradar php artisan <command>`.

| Command | Description |
|---------|-------------|
| `php artisan streams:sync` | Manually sync all streams |
| `php artisan auth:setup-token` | Print a one-time token to create the first admin on `/setup` |
| `php artisan user:create --admin` | Create a user account (admin or user) |
| `php artisan user:password <email>` | Reset an account's password (recovery) |
| `php artisan user:role <email> <role>` | Change an account's role |
| `php artisan user:list` | List accounts |
| `php artisan user:move-data <from> <to>` | Move one account's tracking, alerts, blacklist, history and alert destinations to another account |
| `php artisan user:delete <email>` | Delete an account |

## License

MIT — do whatever you want with it.
