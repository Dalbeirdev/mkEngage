defmodule RealtimeGatewayWeb.Router do
  use RealtimeGatewayWeb, :router

  pipeline :api do
    plug :accepts, ["json"]
  end

  scope "/", RealtimeGatewayWeb do
    pipe_through :api

    # Ops probes (DEF-001): liveness + DB readiness. No tenant data.
    get "/health", HealthController, :live
    get "/health/ready", HealthController, :ready
  end

  scope "/internal", RealtimeGatewayWeb do
    pipe_through :api

    post "/broadcast", InternalController, :broadcast
  end
end
