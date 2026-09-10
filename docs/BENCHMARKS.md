# Benchmarks

Measured throughput and latency, so "how fast is it" has an actual answer
instead of a guess.

## How

`bin/bench.php` forks `--clients` child processes (via `pcntl_fork()` -
the same primitive the rest of this codebase's forked-worker phases are
built on), each opening one `RedisClient` connection and running
`--requests` synchronous request/reply round trips against it: send, wait
for the reply, send the next. Every child times its own round trips and writes
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

**Run-to-run spread is large enough to matter.** The same commit measured
twice on the same machine, minutes apart, produced 22.9k and 13.4k
requests/sec for a single serial client, depending on nothing more than
what else the laptop was doing. So a difference under about 1.5x between
two runs here says nothing at all; the changes recorded below as
improvements were each 5x or more, and were confirmed by measuring the
tree before and after the change back to back rather than against a
number written down earlier.

## Results

| Command | Clients | Requests | Total | Req/s | p50 | p95 | p99 |
|---|---|---|---|---|---|---|---|
| PING | 1 | 2,000 | 2,000 | 22,883 | 0.038 ms | 0.048 ms | 0.060 ms |
| PING | 10 | 2,000 | 20,000 | 38,625 | 0.223 ms | 0.449 ms | 0.481 ms |
| PING | 50 | 500 | 25,000 | 38,040 | 1.254 ms | 1.363 ms | 2.294 ms |
| SET | 10 | 1,000 | 10,000 | 30,947 | 0.272 ms | 0.545 ms | 0.587 ms |
| GET | 10 | 1,000 | 10,000 | 33,221 | 0.252 ms | 0.507 ms | 0.542 ms |
| INCR | 10 | 1,000 | 10,000 | 30,346 | 0.272 ms | 0.549 ms | 0.619 ms |

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
replies. Every batch size runs the same 5,000 commands, so the sizes can
be compared against each other rather than against different amounts of
work:

| Batch size | Req/s |
|---|---|
| 1 | 24,355 |
| 10 | 82,416 |
| 100 | 108,809 |
| 1,000 | 116,114 |
| 5,000 | 120,593 |

**A batch of one is a round trip**, and lands where one serial client
lands in the main benchmark above (~22k/s) - it is the same
send-wait-read pattern under a different name. Pipelining removes the
waiting: ten commands per batch more than triples throughput, and from a
hundred upward the same single connection sustains 110-120k/s, past what
ten separate connections manage, because none of it is spent waiting for
a round trip.

Two things had to be fixed before these numbers looked like this, and
both are worth knowing about because neither is visible in a profile:

- The server used to answer each command in a pipeline with its own
  `fwrite()`. A pipeline is then a stream of tiny packets, and TCP's
  Nagle algorithm holds each one back until the previous is acknowledged
  - so pipelining was *slower* than the round trips it replaces (500
  pipelined PINGs at 0.5x the speed of 500 separate ones). The replies of
  one read now go out together.
- With that fixed, a batch of 1,000 still sat at 21k/s while a batch of
  100 did 100k: a batch that large arrives in several reads, so it is
  answered in several writes, and Nagle held every write after the first
  for a delayed ACK's worth of time - a stable 47 ms per batch. Both ends
  now set `tcp_nodelay`, the way real Redis does.

### Pub/Sub fan-out

| Subscribers | Messages | Deliveries | Deliveries/s |
|---|---|---|---|
| 20 | 50 | 1,000 | 34,140 |

20 subscribers on one channel, 50 published messages: 1,000 deliveries in
~29 ms. Fan-out is the dominant cost - one PUBLISH writes to every
subscriber's socket - so this number is really "how fast can one
single-threaded loop touch 20 sockets", plus the publisher's own round
trip per message, which is serial by construction here.

### Memory

100,000 keys of 64 bytes written over one connection:

| | RSS |
|---|---|
| Before | 28.19 MiB |
| After | 56.83 MiB |
| Peak | 94.17 MiB |
| Per key | ~300 B |

The write rate (~21k writes/s) is below the PING/SET rate from the main
benchmark because each SET here also serializes a fresh 100+ byte value.
The per-key cost includes the key string, the `StoredValue` object, and
the `data` array slot, plus PHP's allocator overhead for ~100k objects;
the difference between "after" and "peak" is the transient allocation
pressure of the write loop.
