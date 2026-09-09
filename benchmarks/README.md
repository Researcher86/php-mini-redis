# Benchmarks: load tests

`bin/bench.php` (documented in [docs/BENCHMARKS.md](../docs/BENCHMARKS.md))
measures single-command throughput and latency. These scripts answer the
other load questions the review called for: sustained **pipelined**
throughput, **Pub/Sub fan-out** to many subscribers, and **memory**
growth. The `examples/` directory holds the single-connection
demonstrations of the same behaviors.

All of them connect to a running server, so start one first:

```bash
make run-server        # in another terminal
```

## Pipelining

```bash
make up
docker compose exec php php benchmarks/pipeline.php
```

Reports pipelined PING commands/sec for batch sizes 1 / 10 / 100 / 1000 /
5000, showing how much of the per-command cost pipelining removes. (The
`examples/pipelining.php` script answers the same question for one fixed
batch size with a round-trip comparison.)

## Pub/Sub fan-out

```bash
make up
docker compose exec php php benchmarks/pubsub-fanout.php
```

Forks 20 subscriber connections on one shared channel, publishes 50
messages from a publisher, and reports total deliveries and deliveries/s -
how the server scales with subscriber count.

## Memory

```bash
make up
docker compose exec php php benchmarks/memory.php
```

Writes 100,000 64-byte keys and reports the server process's RSS before,
after and peak, plus bytes-per-key. Pass `SERVER_PID` to point at the
server process if it is not discoverable automatically (in Docker the
server usually runs as the container's PID 1):

```bash
docker compose exec php bash -c 'SERVER_PID=1 php benchmarks/memory.php'
```