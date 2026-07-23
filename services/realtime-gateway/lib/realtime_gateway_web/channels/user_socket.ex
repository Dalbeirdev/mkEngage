defmodule RealtimeGatewayWeb.UserSocket do
  use Phoenix.Socket

  alias RealtimeGateway.Auth.Token

  channel "conv:*", RealtimeGatewayWeb.ConversationChannel

  @impl true
  def connect(%{"token" => token}, socket, _connect_info) do
    case Token.verify(token) do
      {:ok, claims} ->
        {:ok,
         socket
         |> assign(:org_id, claims.org)
         |> assign(:sub, claims.sub)}

      {:error, _reason} ->
        # Fail closed; no detail leaks to unauthenticated sockets (ADR-009).
        :error
    end
  end

  def connect(_params, _socket, _connect_info), do: :error

  @impl true
  def id(socket), do: "sub:#{socket.assigns.sub}"
end
