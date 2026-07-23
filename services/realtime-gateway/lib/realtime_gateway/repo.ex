defmodule RealtimeGateway.Repo do
  use Ecto.Repo,
    otp_app: :realtime_gateway,
    adapter: Ecto.Adapters.Postgres
end
