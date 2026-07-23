defmodule RealtimeGatewayWeb.ConversationChannelTest do
  use RealtimeGatewayWeb.ChannelCase, async: false

  @moduletag :database

  alias RealtimeGateway.Auth.Token
  alias RealtimeGateway.Repo

  # Seeds an org + visitor + conversation directly (raw SQL under explicit
  # tenant context — the schema is owned by the control plane's migrations).
  defp seed! do
    org_id = Uniq.UUID.uuid7()
    visitor_id = Uniq.UUID.uuid7()
    conversation_id = Uniq.UUID.uuid7()

    Repo.query!(
      "INSERT INTO organizations (id, name, slug, region, settings, created_at) VALUES ($1, 'Test Org', $2, 'us', '{}', now())",
      [dump(org_id), "org-#{String.slice(org_id, 0, 8)}"]
    )

    Repo.query!("SELECT set_config('app.current_org_id', $1, false)", [org_id])

    Repo.query!(
      "INSERT INTO visitors (id, organization_id, consent_state, created_at) VALUES ($1, $2, 'unknown', now())",
      [dump(visitor_id), dump(org_id)]
    )

    Repo.query!(
      "INSERT INTO conversations (id, organization_id, status, visitor_id, last_sequence, created_at, updated_at) VALUES ($1, $2, 'open', $3, 0, now(), now())",
      [dump(conversation_id), dump(org_id), dump(visitor_id)]
    )

    %{org_id: org_id, visitor_id: visitor_id, conversation_id: conversation_id}
  end

  defp dump(uuid), do: Ecto.UUID.dump!(uuid)

  defp join_as(sub, %{org_id: org_id, conversation_id: conversation_id}) do
    {:ok, socket} =
      connect(RealtimeGatewayWeb.UserSocket, %{"token" => Token.mint(org_id, sub)})

    subscribe_and_join(socket, "conv:#{org_id}:#{conversation_id}")
  end

  test "visitor joins own conversation and sends a persisted message" do
    ctx = seed!()
    assert {:ok, _reply, socket} = join_as("visitor:#{ctx.visitor_id}", ctx)

    key = Uniq.UUID.uuid7()
    ref = push(socket, "message:send", %{"body" => "Hello!", "idempotency_key" => key})

    assert_reply ref, :ok, %{sequence_number: 1, message_id: message_id}
    assert is_binary(message_id)

    # Fan-out to subscribers, sequence-ordered payload.
    assert_broadcast "message:new", %{body: "Hello!", sequence_number: 1}
  end

  test "duplicate idempotency keys return the original ack without re-broadcast" do
    ctx = seed!()
    {:ok, _reply, socket} = join_as("visitor:#{ctx.visitor_id}", ctx)

    key = Uniq.UUID.uuid7()
    ref1 = push(socket, "message:send", %{"body" => "Once", "idempotency_key" => key})
    assert_reply ref1, :ok, %{message_id: original_id, sequence_number: 1}
    assert_broadcast "message:new", %{sequence_number: 1}

    ref2 = push(socket, "message:send", %{"body" => "Once", "idempotency_key" => key})
    assert_reply ref2, :ok, %{message_id: ^original_id, sequence_number: 1}
    refute_broadcast "message:new", %{sequence_number: 1}
  end

  test "replay returns sequence-ordered history after last seen" do
    ctx = seed!()
    {:ok, _reply, socket} = join_as("visitor:#{ctx.visitor_id}", ctx)

    for body <- ["a", "b", "c"] do
      ref =
        push(socket, "message:send", %{"body" => body, "idempotency_key" => Uniq.UUID.uuid7()})

      assert_reply ref, :ok, _
    end

    ref = push(socket, "replay:request", %{"last_seen_seq" => 1})
    assert_reply ref, :ok, %{messages: messages, has_more: false}
    assert Enum.map(messages, & &1.sequence_number) == [2, 3]
    assert Enum.map(messages, & &1.body) == ["b", "c"]
  end

  test "rejects joins to another visitor's conversation (no existence oracle)" do
    ctx = seed!()
    other_visitor = Uniq.UUID.uuid7()

    Repo.query!(
      "INSERT INTO visitors (id, organization_id, consent_state, created_at) VALUES ($1, $2, 'unknown', now())",
      [dump(other_visitor), dump(ctx.org_id)]
    )

    assert {:error, %{reason: "unauthorized"}} = join_as("visitor:#{other_visitor}", ctx)
  end

  test "rejects cross-org joins even with a valid other-org token" do
    ctx = seed!()

    other_org = Uniq.UUID.uuid7()

    Repo.query!(
      "INSERT INTO organizations (id, name, slug, region, settings, created_at) VALUES ($1, 'Other', $2, 'us', '{}', now())",
      [dump(other_org), "other-#{String.slice(other_org, 0, 8)}"]
    )

    {:ok, socket} =
      connect(RealtimeGatewayWeb.UserSocket, %{
        "token" => Token.mint(other_org, "visitor:#{ctx.visitor_id}")
      })

    assert {:error, %{reason: "unauthorized"}} =
             subscribe_and_join(socket, "conv:#{ctx.org_id}:#{ctx.conversation_id}")
  end

  test "rejects sockets with invalid tokens" do
    assert :error = connect(RealtimeGatewayWeb.UserSocket, %{"token" => "garbage"})
    assert :error = connect(RealtimeGatewayWeb.UserSocket, %{})
  end

  test "typing events fan out without persistence" do
    ctx = seed!()
    {:ok, _reply, socket} = join_as("visitor:#{ctx.visitor_id}", ctx)

    push(socket, "typing", %{"is_typing" => true})
    assert_broadcast "typing", %{is_typing: true}

    %{rows: [[count]]} =
      RealtimeGateway.Messaging.in_org_transaction(ctx.org_id, fn ->
        Repo.query!("SELECT count(*) FROM messages WHERE conversation_id = $1", [
          dump(ctx.conversation_id)
        ])
      end)

    assert count == 0
  end

  test "oversized and empty bodies are rejected" do
    ctx = seed!()
    {:ok, _reply, socket} = join_as("visitor:#{ctx.visitor_id}", ctx)

    ref =
      push(socket, "message:send", %{
        "body" => String.duplicate("x", 16_001),
        "idempotency_key" => Uniq.UUID.uuid7()
      })

    assert_reply ref, :error, %{code: "payload_invalid"}

    ref = push(socket, "message:send", %{"body" => "  ", "idempotency_key" => Uniq.UUID.uuid7()})
    assert_reply ref, :error, %{code: "payload_invalid"}
  end
end
