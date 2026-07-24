defmodule RealtimeGatewayWeb.HealthControllerTest do
  use RealtimeGatewayWeb.ConnCase, async: true

  test "GET /health returns 200 liveness", %{conn: conn} do
    conn = get(conn, ~p"/health")
    assert json_response(conn, 200) == %{"status" => "ok"}
  end

  @tag :database
  test "GET /health/ready returns 200 when the database is reachable", %{conn: conn} do
    conn = get(conn, ~p"/health/ready")
    assert json_response(conn, 200)["status"] == "ok"
  end
end
