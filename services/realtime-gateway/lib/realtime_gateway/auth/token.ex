defmodule RealtimeGateway.Auth.Token do
  @moduledoc """
  Gateway socket tokens (ADR-002).

  Interim scheme: compact HMAC-SHA256 tokens minted by the control plane with
  a shared secret (`GATEWAY_SIGNING_KEY`), `base64url(payload).base64url(sig)`.
  The ADR's target state is Ed25519 + JWKS published by the control plane;
  the verify/1 contract stays identical when that lands.

  Claims: `org` (organization id), `sub` ("visitor:<uuid>" | "user:<uuid>"),
  `exp` (unix seconds, ≤ 5 minutes at mint time per ADR-002).
  Tokens establish IDENTITY only — conversation access is re-checked against
  the database on every channel join (fail closed).
  """

  @type claims :: %{org: String.t(), sub: String.t(), exp: integer()}

  @spec verify(String.t()) :: {:ok, claims} | {:error, :invalid | :expired}
  def verify(token) when is_binary(token) do
    with [payload_b64, sig_b64] <- String.split(token, "."),
         {:ok, payload} <- Base.url_decode64(payload_b64, padding: false),
         {:ok, signature} <- Base.url_decode64(sig_b64, padding: false),
         true <- Plug.Crypto.secure_compare(signature, sign(payload)),
         {:ok, %{"org" => org, "sub" => sub, "exp" => exp}} <- Jason.decode(payload) do
      if is_integer(exp) and exp > System.system_time(:second) do
        {:ok, %{org: org, sub: sub, exp: exp}}
      else
        {:error, :expired}
      end
    else
      _ -> {:error, :invalid}
    end
  end

  def verify(_), do: {:error, :invalid}

  @doc "Mint a token (tests and local tooling; production mints in the control plane)."
  @spec mint(String.t(), String.t(), integer()) :: String.t()
  def mint(org, sub, ttl_seconds \\ 300) do
    payload =
      Jason.encode!(%{org: org, sub: sub, exp: System.system_time(:second) + ttl_seconds})

    Base.url_encode64(payload, padding: false) <>
      "." <> Base.url_encode64(sign(payload), padding: false)
  end

  defp sign(payload) do
    :crypto.mac(:hmac, :sha256, signing_key(), payload)
  end

  defp signing_key do
    Application.fetch_env!(:realtime_gateway, :gateway_signing_key)
  end
end
