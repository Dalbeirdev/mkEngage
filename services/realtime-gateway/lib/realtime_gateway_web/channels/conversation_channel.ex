defmodule RealtimeGatewayWeb.ConversationChannel do
  @moduledoc """
  Per-conversation channel: `conv:{org_id}:{conversation_id}` (AsyncAPI
  contract, ADR-002).

  Join is re-authorized against the DATABASE (token = identity only);
  `message:send` persists before ack (§27); `typing` is ephemeral fan-out;
  `replay:request` returns sequence-ordered history after a given sequence.
  Presence tracks who is in the conversation.
  """

  use Phoenix.Channel

  alias RealtimeGateway.Messaging
  alias RealtimeGatewayWeb.Presence

  @impl true
  def join("conv:" <> ids, _params, socket) do
    with [org_id, conversation_id] <- String.split(ids, ":", parts: 2),
         true <- org_id == socket.assigns.org_id,
         true <- Messaging.authorized?(org_id, conversation_id, socket.assigns.sub) do
      send(self(), :after_join)
      {:ok, assign(socket, :conversation_id, conversation_id)}
    else
      # Same shape for wrong-org and nonexistent: no existence oracle
      # (RULES-tenant-isolation #4).
      _ -> {:error, %{reason: "unauthorized"}}
    end
  end

  @impl true
  def handle_info(:after_join, socket) do
    {:ok, _ref} =
      Presence.track(socket, socket.assigns.sub, %{
        joined_at: System.system_time(:second)
      })

    push(socket, "presence_state", Presence.list(socket))
    {:noreply, socket}
  end

  @impl true
  def handle_in("message:send", %{"body" => body, "idempotency_key" => key}, socket)
      when is_binary(body) and is_binary(key) do
    body = String.trim(body)

    cond do
      body == "" or byte_size(body) > 16_000 ->
        {:reply, {:error, %{code: "payload_invalid"}}, socket}

      not match?({:ok, _}, Ecto.UUID.cast(key)) ->
        {:reply, {:error, %{code: "payload_invalid"}}, socket}

      true ->
        case Messaging.send_message(
               socket.assigns.org_id,
               socket.assigns.conversation_id,
               socket.assigns.sub,
               body,
               key
             ) do
          {:ok, message, :created} ->
            broadcast!(socket, "message:new", message_payload(message))
            {:reply, {:ok, ack_payload(message, key)}, socket}

          {:ok, message, :duplicate} ->
            # Retry with the same key ⇒ original ack, no re-broadcast (§27).
            {:reply, {:ok, ack_payload(message, key)}, socket}

          {:error, :conversation_closed} ->
            {:reply, {:error, %{code: "conversation_closed"}}, socket}

          {:error, :not_found} ->
            {:reply, {:error, %{code: "unauthorized"}}, socket}
        end
    end
  end

  def handle_in("typing", %{"is_typing" => is_typing}, socket) when is_boolean(is_typing) do
    [sub_type, sub_id] = String.split(socket.assigns.sub, ":", parts: 2)

    broadcast_from!(socket, "typing", %{
      # Contract enum is visitor|contact|agent; internal subs say "user".
      sender_type: if(sub_type == "user", do: "agent", else: sub_type),
      sender_id: sub_id,
      is_typing: is_typing
    })

    {:noreply, socket}
  end

  def handle_in("replay:request", %{"last_seen_seq" => last_seen}, socket)
      when is_integer(last_seen) and last_seen >= 0 do
    messages =
      Messaging.list_after(socket.assigns.org_id, socket.assigns.conversation_id, last_seen)

    {:reply,
     {:ok,
      %{
        messages: Enum.map(messages, &message_payload/1),
        has_more: length(messages) == 100
      }}, socket}
  end

  def handle_in(_event, _payload, socket) do
    {:reply, {:error, %{code: "unknown_event"}}, socket}
  end

  defp ack_payload(message, idempotency_key) do
    %{
      idempotency_key: idempotency_key,
      message_id: message.message_id,
      sequence_number: message.sequence_number,
      persisted_at: message.sent_at
    }
  end

  defp message_payload(message) do
    %{
      message_id: message.message_id,
      conversation_id: message.conversation_id,
      sender_type: message.sender_type,
      sender_id: message.sender_id,
      sequence_number: message.sequence_number,
      content_type: message.content_type,
      body: message.body,
      sent_at: message.sent_at,
      attachments: Map.get(message, :attachments, [])
    }
  end
end
