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

FROM alpine:3.20
RUN apk add --no-cache libstdc++ ncurses-libs openssl
WORKDIR /app
COPY --from=build /app/_build/prod/rel/realtime_gateway ./
ENV PHX_SERVER=true PORT=4000
EXPOSE 4000
CMD ["bin/realtime_gateway", "start"]
