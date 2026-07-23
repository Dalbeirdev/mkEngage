defmodule RealtimeGatewayWeb.ChannelCase do
  @moduledoc """
  Channel test case: Phoenix.ChannelTest + Ecto sandbox in SHARED mode —
  channels run in their own processes and must see the test's transaction.
  """

  use ExUnit.CaseTemplate

  using do
    quote do
      import Phoenix.ChannelTest
      import RealtimeGatewayWeb.ChannelCase

      @endpoint RealtimeGatewayWeb.Endpoint
    end
  end

  setup _tags do
    pid = Ecto.Adapters.SQL.Sandbox.start_owner!(RealtimeGateway.Repo, shared: true)
    on_exit(fn -> Ecto.Adapters.SQL.Sandbox.stop_owner(pid) end)
    :ok
  end
end
