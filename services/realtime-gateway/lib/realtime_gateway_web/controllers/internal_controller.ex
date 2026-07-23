defmodule RealtimeGatewayWeb.InternalController do
  @moduledoc """
  Internal fan-out endpoint: the control plane calls this after persisting a
  message through a NON-gateway path (agent REST reply, chatbot job, widget
  REST fallback) so WebSocket subscribers still get instant delivery.

  INTERIM until the NATS backbone carries conv.message.accepted and the
  gateway consumes it (ADR-005) — the payload already matches that event's
  needs. Authenticated with a dedicated internal bearer (never the socket
  signing key); network policy additionally isolates it in production
  (ADR-009). Best-effort by contract: the caller must never fail its own
  request when this endpoint is unavailable.
  """

  use RealtimeGatewayWeb, :controller

  @required ~w(organization_id conversation_id message_id sender_type sender_id sequence_number content_type body sent_at)

  def broadcast(conn, params) do
    with :ok <- authorize(conn),
         :ok <- validate(params) do
      topic = "conv:#{params["organization_id"]}:#{params["conversation_id"]}"

      RealtimeGatewayWeb.Endpoint.broadcast(topic, "message:new", %{
        message_id: params["message_id"],
        conversation_id: params["conversation_id"],
        sender_type: params["sender_type"],
        sender_id: params["sender_id"],
        sequence_number: params["sequence_number"],
        content_type: params["content_type"],
        body: params["body"],
        sent_at: params["sent_at"]
      })

      json(conn, %{status: "broadcast"})
    else
      {:error, :unauthorized} ->
        conn |> put_status(401) |> json(%{error: "unauthorized"})

      {:error, :invalid} ->
        conn |> put_status(422) |> json(%{error: "invalid payload"})
    end
  end

  defp authorize(conn) do
    expected = Application.fetch_env!(:realtime_gateway, :internal_api_token)

    case get_req_header(conn, "authorization") do
      ["Bearer " <> token] ->
        if Plug.Crypto.secure_compare(token, expected), do: :ok, else: {:error, :unauthorized}

      _ ->
        {:error, :unauthorized}
    end
  end

  defp validate(params) do
    if Enum.all?(@required, &(is_binary(params[&1]) or is_integer(params[&1]))) and
         is_integer(params["sequence_number"]) do
      :ok
    else
      {:error, :invalid}
    end
  end
end
