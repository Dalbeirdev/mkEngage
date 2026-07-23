defmodule RealtimeGateway.Events.ConsumerTest do
  use RealtimeGatewayWeb.ChannelCase, async: false

  @moduletag :database
  @moduletag :nats

  alias RealtimeGateway.Repo

  defp nats_configured?, do: System.get_env("NATS_URL") not in [nil, ""]

  setup do
    if not nats_configured?() do
      raise "NATS_URL required (test is excluded unless GATEWAY_DB_TESTS=1 and NATS_URL set)"
    end

    uri = URI.parse(System.get_env("NATS_URL"))

    # Isolated durable: each durable is its own cursor, so a gateway running
    # against the same NATS server cannot steal this test's delivery.
    durable = "gateway-fanout-test-#{System.unique_integer([:positive])}"
    Application.put_env(:realtime_gateway, :fanout_durable, durable)

    on_exit(fn ->
      Application.delete_env(:realtime_gateway, :fanout_durable)
    end)

    start_supervised!(
      {Gnat.ConnectionSupervisor,
       %{
         name: :gnat_conn,
         backoff_period: 1_000,
         connection_settings: [
           %{host: to_charlist(uri.host || "127.0.0.1"), port: uri.port || 4222}
         ]
       }}
    )

    # Wait for the named connection to come up.
    wait_for_connection(50)

    # Pre-create the durable with deliver_policy :new so it does NOT replay
    # stream history: replayed envelopes would run in_org_transaction on the
    # shared sandbox connection, where the "transaction-local" set_config is
    # actually scoped to the sandbox's outer transaction and would clobber
    # this test's org context (RLS violations on the inserts below).
    alias Gnat.Jetstream.API.{Consumer, Stream}

    case Stream.info(:gnat_conn, "CONVERSATIONS") do
      {:ok, _} ->
        :ok

      {:error, _} ->
        {:ok, _} =
          Stream.create(:gnat_conn, %Stream{
            name: "CONVERSATIONS",
            subjects: ["conv.>"],
            retention: :limits
          })
    end

    {:ok, _} =
      Consumer.create(:gnat_conn, %Consumer{
        stream_name: "CONVERSATIONS",
        durable_name: durable,
        ack_policy: :explicit,
        deliver_policy: :new,
        max_deliver: 5
      })

    on_exit(fn ->
      # Best-effort cleanup of the per-test durable (a fresh Gnat connection —
      # the supervised one is gone by the time on_exit runs).
      case Gnat.start_link(%{host: to_charlist(uri.host || "127.0.0.1"), port: uri.port || 4222}) do
        {:ok, conn} ->
          Consumer.delete(conn, "CONVERSATIONS", durable)
          Gnat.stop(conn)

        _ ->
          :ok
      end
    end)

    start_supervised!(RealtimeGateway.Events.Consumer)
    :ok
  end

  defp wait_for_connection(0), do: raise("gnat connection not up")

  defp wait_for_connection(attempts) do
    case Process.whereis(:gnat_conn) do
      pid when is_pid(pid) -> :ok
      _ -> Process.sleep(100) && wait_for_connection(attempts - 1)
    end
  end

  defp dump(uuid), do: Ecto.UUID.dump!(uuid)

  test "consumes conv.message.accepted from JetStream and broadcasts message:new with the DB body" do
    org_id = Uniq.UUID.uuid7()
    visitor_id = Uniq.UUID.uuid7()
    conversation_id = Uniq.UUID.uuid7()
    message_id = Uniq.UUID.uuid7()

    Repo.query!(
      "INSERT INTO organizations (id, name, slug, region, settings, created_at) VALUES ($1, 'Ev', $2, 'us', '{}', now())",
      [dump(org_id), "ev-#{String.slice(org_id, 0, 8)}"]
    )

    Repo.query!("SELECT set_config('app.current_org_id', $1, false)", [org_id])

    Repo.query!(
      "INSERT INTO visitors (id, organization_id, consent_state, created_at) VALUES ($1, $2, 'unknown', now())",
      [dump(visitor_id), dump(org_id)]
    )

    Repo.query!(
      "INSERT INTO conversations (id, organization_id, status, visitor_id, last_sequence, created_at, updated_at) VALUES ($1, $2, 'open', $3, 1, now(), now())",
      [dump(conversation_id), dump(org_id), dump(visitor_id)]
    )

    Repo.query!(
      "INSERT INTO messages (id, organization_id, conversation_id, sender_type, sender_id, sequence_number, content_type, body, lifecycle_state, idempotency_key, sent_at, created_at, updated_at) VALUES ($1, $2, $3, 'agent', $4, 1, 'text', 'Full body from the database', 'persisted', $5, now(), now(), now())",
      [
        dump(message_id),
        dump(org_id),
        dump(conversation_id),
        dump(visitor_id),
        dump(Uniq.UUID.uuid7())
      ]
    )

    RealtimeGatewayWeb.Endpoint.subscribe("conv:#{org_id}:#{conversation_id}")

    envelope =
      Jason.encode!(%{
        specversion: "1.0",
        id: Uniq.UUID.uuid7(),
        type: "conv.message.accepted.v1",
        source: "control-plane",
        orgid: org_id,
        data: %{
          message_id: message_id,
          conversation_id: conversation_id,
          sequence_number: 1,
          content_preview: "Full body from…"
        }
      })

    :ok = Gnat.pub(:gnat_conn, "conv.message.accepted", envelope)

    # Consumer fetches the FULL body from PostgreSQL (envelope is minimized).
    assert_receive %Phoenix.Socket.Broadcast{
                     event: "message:new",
                     payload: %{body: "Full body from the database", sequence_number: 1}
                   },
                   10_000
  end
end
