import Config

# No force_ssl here: TLS termination and HTTP→HTTPS redirects are Caddy's
# job in the public deployment, and LAN mode is plain HTTP by design — a
# compiled-in Plug.SSL 301 breaks proxied WebSocket upgrades there
# (X-Forwarded-Proto: http from the LAN path router).

# Do not print debug messages in production
config :logger, level: :info

# Runtime production configuration, including reading
# of environment variables, is done on config/runtime.exs.
