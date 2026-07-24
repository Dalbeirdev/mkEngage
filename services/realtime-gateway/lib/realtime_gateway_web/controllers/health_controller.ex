defmodule RealtimeGatewayWeb.HealthController do
  @moduledoc """
  Liveness and readiness (ops/monitoring). `/health` is a cheap liveness
  probe (process is up). `/health/ready` additionally checks the PostgreSQL
  system of record so a load balancer / k8s readiness probe can drain a
  node that has lost its database. Unauthenticated by design — exposes no
  tenant data, only up/down.
  """

  use RealtimeGatewayWeb, :controller

  def live(conn, _params) do
    json(conn, %{status: "ok"})
  end

  def ready(conn, _params) do
    case db_ok?() do
      true ->
        json(conn, %{status: "ok", database: "ok"})

      false ->
        conn
        |> put_status(503)
        |> json(%{status: "unavailable", database: "down"})
    end
  end

  defp db_ok? do
    case RealtimeGateway.Repo.query("SELECT 1", []) do
      {:ok, _} -> true
      _ -> false
    end
  rescue
    _ -> false
  end
end
