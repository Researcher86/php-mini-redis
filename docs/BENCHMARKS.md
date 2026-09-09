# Benchmarks

Measured throughput and latency, so "how fast is it" has an actual answer
instead of a guess.

## How

`bin/bench.php` forks `--clients` child processes (via `pcntl_fork()` -
the same primitive the rest of this codebase's forked-worker phases are
built on), each opening one real connection and running `--requests`
synchronous request/reply round trips against it: send, wait for the
reply, send the next. Every child times its own round trips and writes
them to a temp file; the parent waits for every child to exit, aggregates
every latency sample, and reports throughput and percentiles.

```bash
make run-server              # in one terminal
make bench ARGS="--clients=10 --requests=1000 --command=PING"
```

`--command` is one of `PING`, `SET`, `GET`, `INCR` (`SET`/`GET` both use
the same fixed key throughout the run - the point is measuring the
command's own cost, not a realistic key distribution).

## Environment

All numbers below were measured inside this project's own Docker
container (`php:8.5-cli`, no Xdebug), server and client on the same
machine over the loopback interface - not a tuned, isolated benchmark rig.
Treat every number as "what this container measured", not an absolute
claim about the implementation's ceiling on different hardware.

## Results

| Command | Clients | Requests | Total | Req/s | p50 | p95 | p99 |
|---|---|---|---|---|---|---|---|
| PING | 1 | 2,000 | 2,000 | 21,517 | 0.031 ms | 0.080 ms | 0.163 ms |
| PING | 10 | 2,000 | 20,000 | 40,376 | 0.213 ms | 0.427 ms | 0.463 ms |
| PING | 50 | 500 | 25,000 | 40,733 | 1.174 ms | 1.293 ms | 2.143 ms |
| SET | 10 | 1,000 | 10,000 | 35,743 | 0.238 ms | 0.478 ms | 0.512 ms |
| GET | 10 | 1,000 | 10,000 | 37,094 | 0.231 ms | 0.463 ms | 0.505 ms |
| INCR | 10 | 1,000 | 10,000 | 35,029 | 0.240 ms | 0.481 ms | 0.527 ms |

## Reading these numbers

**Throughput roughly doubles from 1 to 10 concurrent clients, then
flattens.** A single client's requests are strictly serial - each one
waits for its own previous reply - so one connection can never keep the
single-threaded event loop continuously busy between requests. Adding
concurrent clients fills those gaps, up to the point where the one
process handling every connection's I/O and command execution is itself
the bottleneck; 10 and 50 clients land at almost the same throughput,
which is exactly what "one event loop, one thread" predicts.

**p50 latency grows with client count, and that is expected, not a
regression.** More concurrent clients means more connections competing for
the same single-threaded event loop each tick; each individual request
waits a little longer for its turn. This is the same distinction Phase
17's `docs/PHASES.md` note draws about queue wait vs. execution time - the
command itself is not slower, there are just more requests ahead of it.

**PING, SET, GET and INCR cost almost the same.** All four are dominated
by the same fixed overhead - one `stream_select()` round trip, one RESP
parse, one dispatch - not by the handler logic itself, which is a few
array operations either way. This is a small in-memory server; nothing
here does I/O of its own inside a command handler.

## What this does not measure

- **Pipelining and Pub/Sub** are not covered by `bin/bench.php` (it is
  strictly one request, one reply, one client at a time) - both are
  exercised functionally in `tests/Server/RedisServerTest.php`, but not
  benchmarked for throughput here.
- **1,000 concurrent clients**, the plan's own largest experiment, was not
  run - `bin/bench.php` forking 1,000 real child processes on a
  development container is itself a heavier operation than the server
  being measured, and would mostly be benchmarking process-creation
  overhead rather than the server.
- **Memory** is not sampled by this tool at all; nothing in this codebase
  currently exposes it (see `docs/PHASES.md`'s Phase 27 scope notes on
  what `ServerMetrics` does and does not track).
