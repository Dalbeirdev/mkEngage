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

# Backups (do this on a schedule + copy off the server)
docker compose -f docker-compose.prod.yml exec postgres \
  pg_dump -U mkengage mkengage | gzip > mkengage-$(date +%F).sql.gz

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
