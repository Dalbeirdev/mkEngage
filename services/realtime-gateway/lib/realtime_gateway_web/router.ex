defmodule RealtimeGatewayWeb.Router do
  use RealtimeGatewayWeb, :router

  pipeline :api do
    plug :accepts, ["json"]
  end

  scope "/internal", RealtimeGatewayWeb do
    pipe_through :api

    post "/broadcast", InternalController, :broadcast
  end
end
