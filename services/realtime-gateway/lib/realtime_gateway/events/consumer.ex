defmodule RealtimeGateway.Events.Consumer do
  @moduledoc """
  JetStream consumer for conv.message.accepted (ADR-005): the durable,
  backbone-driven replacement for the interim /internal/broadcast fan-out.

  Envelopes are data-minimized (content preview only), so the consumer loads
  the full message from PostgreSQL under the event's org context (RLS) before
  broadcasting `message:new` to the conversation topic.

  Idempotent by nature: re-delivered events re-broadcast, and every client
  dedups by message_id (RULES-message-ordering #9) — no inbox table needed
  for a fan-out-only consumer.
  """

  use Gnat.Jetstream.PullConsumer

  require Logger

  @stream "CONVERSATIONS"
  @subjects ["conv.>"]

  def start_link(opts \\ []) do
    Gnat.Jetstream.PullConsumer.start_link(__MODULE__, opts, name: __MODULE__)
  end

  @impl true
  def init(_opts) do
    ensure_stream_and_consumer()

    {:ok, nil,
     connection_name: :gnat_conn, stream_name: @stream, consumer_name: durable(), domain: nil}
  end

  # Configurable so tests can use an isolated durable (each durable is its own
  # cursor — a concurrently running gateway must not steal test deliveries).
  defp durable do
    Application.get_env(:realtime_gateway, :fanout_durable, "gateway-fanout")
  end

  @impl true
  def handle_message(message, state) do
    case Jason.decode(message.body) do
      {:ok, %{"type" => "conv.message.accepted" <> _, "orgid" => org_id, "data" => data}} ->
        broadcast_message(org_id, data)

      {:ok, _other_event} ->
        :ok

      {:error, _} ->
        Logger.warning("events_consumer_bad_envelope")
    end

    {:ack, state}
  end

  defp broadcast_message(org_id, %{"message_id" => message_id, "conversation_id" => conv_id}) do
    case RealtimeGateway.Messaging.fetch_message(org_id, message_id) do
      nil ->
        Logger.warning("events_consumer_message_missing")

      message ->
        RealtimeGatewayWeb.Endpoint.broadcast("conv:#{org_id}:#{conv_id}", "message:new", %{
          message_id: message.message_id,
          conversation_id: message.conversation_id,
          sender_type: message.sender_type,
          sender_id: message.sender_id,
          sequence_number: message.sequence_number,
          content_type: message.content_type,
          body: message.body,
          sent_at: message.sent_at,
          attachments: Map.get(message, :attachments, [])
        })
    end
  end

  defp broadcast_message(_org_id, _data), do: :ok

  # On a cold boot Gnat.ConnectionSupervisor registers :gnat_conn only once
  # the NATS connection is actually up, so the JetStream calls below can race
  # it (noproc exit) or reach a NATS that is still initializing. Retry rather
  # than crash the VM into a container restart loop.
  defp ensure_stream_and_consumer(attempts \\ 60) do
    do_ensure_stream_and_consumer()
  catch
    kind, reason ->
      if attempts > 0 do
        Process.sleep(1_000)
        ensure_stream_and_consumer(attempts - 1)
      else
        :erlang.raise(kind, reason, __STACKTRACE__)
      end
  end

  defp do_ensure_stream_and_consumer do
    alias Gnat.Jetstream.API.{Consumer, Stream}

    stream = %Stream{name: @stream, subjects: @subjects, retention: :limits}

    case Stream.info(:gnat_conn, @stream) do
      {:ok, _} -> :ok
      {:error, _} -> {:ok, _} = Stream.create(:gnat_conn, stream)
    end

    consumer = %Consumer{
      stream_name: @stream,
      durable_name: durable(),
      ack_policy: :explicit,
      max_deliver: 5
    }

    case Consumer.info(:gnat_conn, @stream, durable()) do
      {:ok, _} -> :ok
      {:error, _} -> {:ok, _} = Consumer.create(:gnat_conn, consumer)
    end
  end
end
