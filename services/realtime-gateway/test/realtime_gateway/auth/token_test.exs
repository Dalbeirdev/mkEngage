defmodule RealtimeGateway.Auth.TokenTest do
  use ExUnit.Case, async: true

  alias RealtimeGateway.Auth.Token

  @org "0198c5a0-1111-7000-8000-000000000001"

  test "round-trips valid tokens" do
    token = Token.mint(@org, "visitor:0198c5a0-2222-7000-8000-000000000002")

    assert {:ok, claims} = Token.verify(token)
    assert claims.org == @org
    assert claims.sub == "visitor:0198c5a0-2222-7000-8000-000000000002"
  end

  test "rejects expired tokens" do
    token = Token.mint(@org, "user:abc", -10)
    assert {:error, :expired} = Token.verify(token)
  end

  test "rejects tampered payloads (signature mismatch)" do
    token = Token.mint(@org, "visitor:abc")
    [_payload, sig] = String.split(token, ".")

    forged_payload =
      %{org: "attacker-org", sub: "user:evil", exp: System.system_time(:second) + 300}
      |> Jason.encode!()
      |> Base.url_encode64(padding: false)

    assert {:error, :invalid} = Token.verify(forged_payload <> "." <> sig)
  end

  test "rejects garbage and empty input" do
    assert {:error, :invalid} = Token.verify("not-a-token")
    assert {:error, :invalid} = Token.verify("")
    assert {:error, :invalid} = Token.verify("a.b.c")
  end

  test "verification matches the control plane's HMAC construction" do
    # The control plane mints base64url(json).base64url(hmac_sha256(json, key));
    # this pins the cross-service contract.
    payload =
      Jason.encode!(%{org: @org, sub: "user:x", exp: System.system_time(:second) + 60})

    key = Application.fetch_env!(:realtime_gateway, :gateway_signing_key)
    sig = :crypto.mac(:hmac, :sha256, key, payload)

    token =
      Base.url_encode64(payload, padding: false) <>
        "." <> Base.url_encode64(sig, padding: false)

    assert {:ok, %{sub: "user:x"}} = Token.verify(token)
  end
end
