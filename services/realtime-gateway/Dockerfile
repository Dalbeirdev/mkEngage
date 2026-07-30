# mkEngage realtime gateway (Phoenix) — OTP release, small runtime image.
FROM elixir:1.18-alpine AS build
RUN apk add --no-cache build-base git
WORKDIR /app
ENV MIX_ENV=prod
RUN mix local.hex --force && mix local.rebar --force
COPY mix.exs mix.lock ./
RUN mix deps.get --only prod && mix deps.compile
COPY . .
RUN mix compile && mix release

# Runtime must share the build stage's base: the release's crypto NIF links
# against the builder's OpenSSL, and a different Alpine release ships an
# OpenSSL with mismatched symbols (EVP_PKEY_* "symbol not found" crash loop).
FROM elixir:1.18-alpine
WORKDIR /app
COPY --from=build /app/_build/prod/rel/realtime_gateway ./
ENV PHX_SERVER=true PORT=4000
EXPOSE 4000
CMD ["bin/realtime_gateway", "start"]
