# Deploying mkEngage (self-hosted)

One server, Docker Compose, automatic HTTPS. The stack:

| Service       | Image source                  | Role                                   |
|---------------|-------------------------------|----------------------------------------|
| postgres      | pgvector/pgvector:pg17        | Database (RLS + pgvector)              |
| nats          | nats:2-alpine (`-js`)         | Event backbone (JetStream)             |
| control-plane | apps/control-plane Dockerfile | Laravel API (FrankenPHP)               |
| queue-worker  | same image                    | `php artisan queue:work`               |
| outbox-relay  | same image                    | `php artisan outbox:relay` → NATS      |
| dashboard     | apps/dashboard Dockerfile     | Next.js agent app + marketing site     |
| gateway       | services/realtime-gateway     | Phoenix WebSockets (OTP release)       |
| ai-service    | services/ai-service           | FastAPI AI/RAG                         |
| caddy         | caddy:2-alpine                | Reverse proxy + Let's Encrypt          |

> These images are built on the server (`--build`). They have not been built
> on the development machine (no Docker there), so expect the first
> `compose build` to be where any environment-specific wrinkle shows up —
> fix-forward from the build output.

## Prerequisites

- A Linux server with Docker Engine + the compose plugin
  (`docker --version`, `docker compose version`).
- A domain with three A records pointing at the server:
  `app.YOURDOMAIN`, `api.YOURDOMAIN`, `ws.YOURDOMAIN`.
- Ports 80 and 443 open in the firewall.

### Windows server?

The same compose stack runs on Windows via Docker Desktop (WSL 2 backend):

1. Don't work inside `C:\Windows\System32` — clone into a real folder, e.g.
   `C:\mkengage`. If you already cloned in System32, move it:
   `move C:\Windows\System32\mkEngage C:\mkengage`
2. Install WSL 2, reboot, then install Docker Desktop:
   ```powershell
   wsl --install -d Ubuntu     # reboot when prompted
   winget install Docker.DockerDesktop
   ```
   Start Docker Desktop once and wait until it reports "running"
   (`docker version` works in a new terminal).
3. Command translations for the steps below (PowerShell/cmd):
   `cp .env.example .env` → `copy .env.example .env`, and edit with
   `notepad .env` instead of nano. Everything `docker compose ...` is
   identical.
4. Let's Encrypt still needs the three public DNS records reaching this
   machine on ports 80/443 (router/NAT + Windows Firewall). Without a
   public domain, use **LAN-only mode** below instead.

### LAN-only mode (no public domain, plain HTTP)

For an internal box that only needs to be reachable on the local network:

1. In `deploy/.env` keep placeholder `DOMAIN`/`ACME_EMAIL` values and set
   `SERVER_ADDR` to this machine's LAN IP (e.g. `192.168.1.50`).
2. Start with the LAN override added:
   ```bash
   sudo docker compose -f docker-compose.prod.yml -f docker-compose.lan.yml up -d --build
   sudo docker compose -f docker-compose.prod.yml -f docker-compose.lan.yml exec control-plane php artisan migrate --force
   ```
3. Access: everything on one clean origin — `http://SERVER_ADDR` (the
   caddy-lan service routes `/` → dashboard, `/api` + `/storage` →
   control-plane, `/socket` → gateway; see Caddyfile.lan). The direct ports
   still work as fallback: dashboard `:8080`, API `:8000`, WebSockets
   `:4000`. ACME/HTTPS is disabled; the login cookie is issued without the
   Secure flag (`INSECURE_COOKIES=1`) so plain HTTP works. **Trusted
   internal networks only.**

Running inside WSL 2 and other LAN machines can't reach the ports? Enable
mirrored networking (Windows 11) so WSL services bind on the host address:

```powershell
# In Windows (not WSL): create %UserProfile%\.wslconfig containing
#   [wsl2]
#   networkingMode=mirrored
wsl --shutdown     # then reopen Ubuntu and: sudo service docker start
New-NetFirewallRule -DisplayName "mkEngage LAN" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,8000,8080,4000
```

## Security first

- **Never paste server passwords into chats or tickets.** If one leaks,
  rotate it immediately (`passwd` / your provider's console).
- Prefer SSH keys over passwords: `ssh-copy-id user@server`, then disable
  password auth in `/etc/ssh/sshd_config` (`PasswordAuthentication no`).
- All app secrets are generated fresh on the server (next section) and live
  only in `deploy/.env`, which is never committed.

## Steps

```bash
# 1) Get the code
git clone https://github.com/Dalbeirdev/mkEngage.git
cd mkEngage/deploy

# 2) Configure
cp .env.example .env
# fill DOMAIN, ACME_EMAIL, DB_PASSWORD and the four generated secrets:
#   APP_KEY="base64:$(openssl rand -base64 32)"
#   SECRET_KEY_BASE="$(openssl rand -hex 64)"
#   GATEWAY_SIGNING_KEY="$(openssl rand -hex 32)"
#   AI_SERVICE_TOKEN="$(openssl rand -hex 32)"
#   DB_PASSWORD="$(openssl rand -hex 24)"

# 3) Build and start everything
docker compose -f docker-compose.prod.yml up -d --build

# 4) Run the database migrations
docker compose -f docker-compose.prod.yml exec control-plane php artisan migrate --force
```

## First workspace

Self-serve signup is live at `https://app.YOURDOMAIN/signup` — create the
first organization + owner there. (Or seed one from the CLI with
`php artisan tinker` inside the control-plane container.)

## Checks

```bash
docker compose -f docker-compose.prod.yml ps          # everything Up?
curl -s https://api.YOURDOMAIN/api/user               # 401 JSON = API alive
docker compose -f docker-compose.prod.yml logs -f caddy   # certificate issuance
```

- Dashboard: `https://app.YOURDOMAIN` (marketing home; `/login`, `/signup`).
- Channel webhooks to paste into provider consoles use
  `https://api.YOURDOMAIN/api/channels/...` (shown in Settings → Channels).

## Operations

```bash
# Update to the latest code
git pull && docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec control-plane php artisan migrate --force

# Backups run automatically: the db-backup service writes a gzip'd
# pg_dump to deploy/backups/ every BACKUP_INTERVAL_HOURS (default 24)
# and prunes files older than BACKUP_KEEP_DAYS (default 14). Check it:
ls -lh backups/
docker compose -f docker-compose.prod.yml logs --tail=5 db-backup
# Still copy the files OFF the server on a schedule — same-disk backups
# don't survive a dead disk.

# Restore (DESTRUCTIVE — replaces current data with the chosen backup):
#   docker compose -f docker-compose.prod.yml stop control-plane queue-worker outbox-relay gateway
#   docker compose -f docker-compose.prod.yml exec -T postgres psql -U mkengage -d postgres \
#     -c "DROP DATABASE mkengage;" -c "CREATE DATABASE mkengage OWNER mkengage;"
#   gunzip -c backups/mkengage-YYYY-MM-DD_HHMMSS.sql.gz | \
#     docker compose -f docker-compose.prod.yml exec -T postgres psql -U mkengage -d mkengage
#   docker compose -f docker-compose.prod.yml up -d

# Logs
docker compose -f docker-compose.prod.yml logs -f control-plane queue-worker outbox-relay
```

## Notes

- Outbound email uses `MAIL_*` (global) unless an email channel carries its
  own SMTP credentials (Settings → Channels → Email).
- The AI service runs with the fake provider until one of the
  `AI_SERVICE_*_API_KEY` variables is set.
- Widget embeds on customer sites point at `https://api.YOURDOMAIN` as the
  API URL and load the widget bundle you host (see packages/chat-widget).
