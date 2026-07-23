defmodule RealtimeGateway.Messaging do
  @moduledoc """
  Message ingest hot path (ADR-002): accept → persist → confirm, in one
  transaction against the shared PostgreSQL system of record.

  Tenant context: every operation runs inside a transaction whose first
  statement is `SET LOCAL app.current_org_id` — the same two-layer model as
  the control plane (ADR-007); RLS is the backstop for every query here.

  Ordering & idempotency (RULES-message-ordering): the per-conversation
  sequence is incremented under row lock; a duplicate idempotency key
  returns the ORIGINAL message instead of inserting.
  """

  import Ecto.Query

  alias RealtimeGateway.Repo

  @type message :: %{
          message_id: String.t(),
          conversation_id: String.t(),
          sender_type: String.t(),
          sender_id: String.t(),
          sequence_number: integer(),
          content_type: String.t(),
          body: String.t(),
          lifecycle_state: String.t(),
          sent_at: DateTime.t()
        }

  @doc "True when the subject may join the conversation (visitor owns it / agent is in org)."
  @spec authorized?(String.t(), String.t(), String.t()) :: boolean()
  def authorized?(org_id, conversation_id, sub) do
    in_org_transaction(org_id, fn ->
      case String.split(sub, ":", parts: 2) do
        ["visitor", visitor_id] ->
          from(c in "conversations",
            where: c.id == type(^conversation_id, :binary_id),
            where: c.visitor_id == type(^visitor_id, :binary_id),
            select: count()
          )
          |> Repo.one() > 0

        ["user", user_id] ->
          # Any active user of the org may join (department policies later);
          # RLS already scopes both lookups to the org.
          conversation_exists =
            from(c in "conversations",
              where: c.id == type(^conversation_id, :binary_id),
              select: count()
            )
            |> Repo.one() > 0

          user_exists =
            from(u in "users",
              where: u.id == type(^user_id, :binary_id),
              where: u.status == "active",
              select: count()
            )
            |> Repo.one() > 0

          conversation_exists and user_exists

        _ ->
          false
      end
    end)
  end

  @doc "Persist a message (durable ack). Returns the message row — original one on duplicate idempotency keys."
  @spec send_message(String.t(), String.t(), String.t(), String.t(), String.t()) ::
          {:ok, message} | {:error, :conversation_closed | :not_found}
  def send_message(org_id, conversation_id, sub, body, idempotency_key) do
    [sender_type, sender_id] = String.split(sub, ":", parts: 2)

    in_org_transaction(org_id, fn ->
      existing =
        from(m in "messages",
          where: m.conversation_id == type(^conversation_id, :binary_id),
          where: m.idempotency_key == type(^idempotency_key, :binary_id),
          select: %{
            message_id: m.id,
            conversation_id: m.conversation_id,
            sender_type: m.sender_type,
            sender_id: m.sender_id,
            sequence_number: m.sequence_number,
            content_type: m.content_type,
            body: m.body,
            lifecycle_state: m.lifecycle_state,
            sent_at: m.sent_at
          }
        )
        |> Repo.one()

      if existing do
        {:ok, normalize(existing), :duplicate}
      else
        # Row lock + atomic increment define the per-conversation total order.
        case Repo.one(
               from(c in "conversations",
                 where: c.id == type(^conversation_id, :binary_id),
                 select: %{status: c.status},
                 lock: "FOR UPDATE"
               )
             ) do
          nil ->
            {:error, :not_found}

          %{status: "closed"} ->
            {:error, :conversation_closed}

          _open ->
            {1, [%{last_sequence: sequence}]} =
              from(c in "conversations",
                where: c.id == type(^conversation_id, :binary_id),
                update: [inc: [last_sequence: 1]],
                select: [:last_sequence]
              )
              |> Repo.update_all([])

            now = DateTime.utc_now() |> DateTime.truncate(:microsecond)
            message_id = Uniq.UUID.uuid7()

            {1, _} =
              Repo.insert_all("messages", [
                %{
                  id: Ecto.UUID.dump!(message_id),
                  organization_id: Ecto.UUID.dump!(org_id),
                  conversation_id: Ecto.UUID.dump!(conversation_id),
                  sender_type: sender_type,
                  sender_id: Ecto.UUID.dump!(sender_id),
                  sequence_number: sequence,
                  content_type: "text",
                  body: body,
                  lifecycle_state: "persisted",
                  idempotency_key: Ecto.UUID.dump!(idempotency_key),
                  sent_at: now,
                  created_at: now,
                  updated_at: now
                }
              ])

            {:ok,
             %{
               message_id: message_id,
               conversation_id: conversation_id,
               sender_type: sender_type,
               sender_id: sender_id,
               sequence_number: sequence,
               content_type: "text",
               body: body,
               lifecycle_state: "persisted",
               sent_at: now
             }, :created}
        end
      end
    end)
  end

  @doc "Sequence-ordered replay for reconnect (RULES-message-ordering #11)."
  @spec list_after(String.t(), String.t(), integer()) :: [message]
  def list_after(org_id, conversation_id, after_sequence) do
    in_org_transaction(org_id, fn ->
      from(m in "messages",
        where: m.conversation_id == type(^conversation_id, :binary_id),
        where: m.sequence_number > ^after_sequence,
        order_by: m.sequence_number,
        limit: 100,
        select: %{
          message_id: m.id,
          conversation_id: m.conversation_id,
          sender_type: m.sender_type,
          sender_id: m.sender_id,
          sequence_number: m.sequence_number,
          content_type: m.content_type,
          body: m.body,
          lifecycle_state: m.lifecycle_state,
          sent_at: m.sent_at
        }
      )
      |> Repo.all()
      |> Enum.map(&normalize/1)
    end)
  end

  @doc "Run fun in a transaction with RLS tenant context (ADR-007)."
  def in_org_transaction(org_id, fun) do
    {:ok, result} =
      Repo.transaction(fn ->
        # Parameterized via quote_literal-safe cast: org ids come from verified
        # tokens, but defense in depth all the same.
        Repo.query!("SELECT set_config('app.current_org_id', $1, true)", [org_id])
        fun.()
      end)

    result
  end

  defp normalize(%{message_id: id, conversation_id: conv, sender_id: sender} = m)
       when is_binary(id) and byte_size(id) == 16 do
    %{
      m
      | message_id: Ecto.UUID.load!(id),
        conversation_id: Ecto.UUID.load!(conv),
        sender_id: Ecto.UUID.load!(sender)
    }
  end

  defp normalize(m), do: m
end
