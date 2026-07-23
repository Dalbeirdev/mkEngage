defmodule RealtimeGatewayWeb.InternalControllerTest do
  use RealtimeGatewayWeb.ConnCase, async: true

  @token "test-internal-broadcast-token"
  @payload %{
    "organization_id" => "0198c5a0-1111-7000-8000-000000000001",
    "conversation_id" => "0198c5a0-2222-7000-8000-000000000002",
    "message_id" => "0198c5a0-3333-7000-8000-000000000003",
    "sender_type" => "agent",
    "sender_id" => "0198c5a0-4444-7000-8000-000000000004",
    "sequence_number" => 7,
    "content_type" => "text",
    "body" => "From the control plane",
    "sent_at" => "2026-07-24T00:00:00Z"
  }

  test "broadcasts to the conversation topic for authorized callers", %{conn: conn} do
    RealtimeGatewayWeb.Endpoint.subscribe(
      "conv:#{@payload["organization_id"]}:#{@payload["conversation_id"]}"
    )

    conn =
      conn
      |> put_req_header("authorization", "Bearer #{@token}")
      |> post(~p"/internal/broadcast", @payload)

    assert json_response(conn, 200)["status"] == "broadcast"

    assert_receive %Phoenix.Socket.Broadcast{
      event: "message:new",
      payload: %{body: "From the control plane", sequence_number: 7}
    }
  end

  test "rejects missing and wrong bearer tokens", %{conn: conn} do
    assert conn |> post(~p"/internal/broadcast", @payload) |> json_response(401)

    assert conn
           |> put_req_header("authorization", "Bearer wrong")
           |> post(~p"/internal/broadcast", @payload)
           |> json_response(401)
  end

  test "rejects incomplete payloads", %{conn: conn} do
    conn =
      conn
      |> put_req_header("authorization", "Bearer #{@token}")
      |> post(~p"/internal/broadcast", %{"body" => "missing everything"})

    assert json_response(conn, 422)
  end
end
