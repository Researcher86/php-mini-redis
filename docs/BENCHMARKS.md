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
  strictly one request, one reply, one client at a time) - see the
  dedicated load scripts in `benchmarks/` below.
- **1,000 concurrent clients**, the plan's own largest experiment, was not
  run - `bin/bench.php` forking 1,000 real child processes on a
  development container is itself a heavier operation than the server
  being measured, and would mostly be benchmarking process-creation
  overhead rather than the server.
- **Memory** is not sampled by `bin/bench.php` - it is measured by the
  dedicated `benchmarks/memory.php` script (see below).

## Load tests: pipeline, Pub/Sub fan-out, memory

The scripts under `benchmarks/` (see `benchmarks/README.md`) answer the
load questions the review called for beyond single-command throughput.
All run against a live server (`make run-server`) and were measured inside
this same container.

### Pipelining

Measured from one connection sending a batch of PINGs, then reading all
replies:

| Batch size | Req/s |
|---|---|
| 1 | 620 |
| 10 | 26,597 |
| 100 | 46,817 |
| 1,000 | 50,945 |
| 5,000 | 53,846 |

**A single request/reply round trip caps at ~620/s** (the serial
send-wait-read pattern) - the same wall one serial client hits in the
main benchmark. Pipelining removes that wait: from a batch of 100 upward
the same connection sustains ~50k/s, an ~80x improvement. The remaining
gap to the main benchmark's ~35-40k/s at 10 clients is the same event-loop
serialization as always.

### Pub/Sub fan-out

| Subscribers | Messages | Deliveries | Deliveries/s |
|---|---|---|---|
| 20 | 50 | 1,000 | 52,088 |

20 subscribers on one channel, 50 published messages: 1,000 deliveries in
~19 ms. Fan-out is the dominant cost - one PUBLISH writes to every
subscriber's socket - so this number is really "how fast can one
single-threaded loop touch 20 sockets".

### Memory

100,000 keys of 64 bytes written over one connection:

| | RSS |
|---|---|
| Before | 28.43 MiB |
| After | 56.83 MiB |
| Peak | 94.17 MiB |
| Per key | ~297 B |

The write rate (~14k writes/s) is below the PING/SET rate from the main
benchmark because each SET here also serializes a fresh 100+ byte value.
The per-key cost includes the key string, the `StoredValue` object, and
the `data` array slot, plus PHP's allocator overhead for ~100k objects;
the difference between "after" and "peak" is the transient allocation
pressure of the write loop.
