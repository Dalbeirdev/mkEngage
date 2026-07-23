defmodule RealtimeGatewayWeb.Presence do
  use Phoenix.Presence,
    otp_app: :realtime_gateway,
    pubsub_server: RealtimeGateway.PubSub
end
