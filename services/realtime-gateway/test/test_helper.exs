# :database-tagged tests need a migrated PostgreSQL (run by the control
# plane's Laravel migrations). CI always enables them (GATEWAY_DB_TESTS=1)
# and treats skips as failure; locally without Postgres only pure tests run.
exclude = if System.get_env("GATEWAY_DB_TESTS") == "1", do: [], else: [:database]
exclude = if System.get_env("NATS_URL") in [nil, ""], do: [:nats | exclude], else: exclude
ExUnit.start(exclude: exclude)

if System.get_env("GATEWAY_DB_TESTS") == "1" do
  Ecto.Adapters.SQL.Sandbox.mode(RealtimeGateway.Repo, :manual)
end
