defmodule RealtimeGatewayWeb.Router do
  use RealtimeGatewayWeb, :router

  pipeline :api do
    plug :accepts, ["json"]
  end

  scope "/api", RealtimeGatewayWeb do
    pipe_through :api
  end
end
