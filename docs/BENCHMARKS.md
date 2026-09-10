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

**Run-to-run spread is large enough to matter.** The single-client figure
moved between 12.0k and 22.9k req/s across runs of the same commit, on
the same machine, depending on what else it was doing at the time. The
server itself is not what moves: across six consecutive runs its
`eventloop_iterations` grew by exactly the work it was given, and its
RSS and `eventloop_max_lag_sec` did not change at all.

So a difference under about 1.5x between two runs here says nothing. The
changes recorded below as improvements were each 5x or more, and were
confirmed by measuring the tree before and after the change back to back
rather than against a number written down on another day.

## Results

Each row is the median of three consecutive runs:

| Command | Clients | Requests | Total | Req/s | p50 | p95 | p99 |
|---|---|---|---|---|---|---|---|
| PING | 1 | 2,000 | 2,000 | 13,446 | 0.072 ms | 0.080 ms | 0.092 ms |
| PING | 10 | 2,000 | 20,000 | 35,569 | 0.239 ms | 0.480 ms | 0.511 ms |
| PING | 50 | 500 | 25,000 | 35,633 | 1.322 ms | 1.508 ms | 2.392 ms |
| SET | 10 | 1,000 | 10,000 | 30,153 | 0.278 ms | 0.555 ms | 0.605 ms |
| GET | 10 | 1,000 | 10,000 | 31,292 | 0.273 ms | 0.548 ms | 0.577 ms |
| INCR | 10 | 1,000 | 10,000 | 28,361 | 0.289 ms | 0.586 ms | 0.694 ms |

The one-client row is the least repeatable of the six: the same three
runs gave 21,533, 13,446 and 12,025 req/s. A single serial client is a
ping-pong between two processes, so it measures the host scheduler as
much as the server - the ten- and fifty-client rows, where the loop
always has work waiting, repeat within a few percent.

## Reading these numbers

**Throughput roughly doubles or better from 1 to 10 concurrent clients,
then flattens.** A single client's requests are strictly serial - each one
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
| 1 | 14,416 |
| 10 | 62,690 |
| 100 | 99,798 |
| 1,000 | 114,163 |
| 5,000 | 119,626 |

**A batch of one is a round trip**, and lands where one serial client
lands in the main benchmark above - it is the same send-wait-read pattern
under a different name. Pipelining removes the waiting: ten commands per
batch more than quadruples throughput, and from a hundred upward the same
single connection sustains 100-120k/s, past what ten separate connections
manage, because none of it is spent waiting for a round trip.

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
| 20 | 50 | 1,000 | 33,583 |

20 subscribers on one channel, 50 published messages: 1,000 deliveries in
~30 ms. Fan-out is the dominant cost - one PUBLISH writes to every
subscriber's socket - so this number is really "how fast can one
single-threaded loop touch 20 sockets", plus the publisher's own round
trip per message, which is serial by construction here.

### Fairness: what one client's pipeline costs everyone else

The question a single-threaded server has to answer honestly: while the
loop is busy serving somebody's 100,000-command pipeline, what does an
ordinary client's `PING` cost? `benchmarks/fairness.php` forks the flood
client and times a plain round trip throughout - once with the server
idle, then continuously while the flood runs - and reads the loop's own
counters over the same stretch.

| | p50 | p95 | p99 | max |
|---|---|---|---|---|
| PING, server idle | 0.113 ms | 0.331 ms | 0.745 ms | 0.846 ms |
| PING, during the flood | 2.513 ms | 2.835 ms | 2.906 ms | 3.322 ms |

100,000 pipelined commands are served in ~0.83s, and over that stretch:

```text
passes:             807
busy / idle:        0.727s / 0.818s
mean work per pass: 0.901 ms
mean wait per pass: 1.013 ms
worst single pass:  4.518 ms
```

**Latency grows by about 20x and stays bounded.** A competing client waits
roughly one pass of the loop, and a pass under this load is about a
millisecond of work: 100,000 commands are spread over 807 passes, ~125
each, because that is how much of the pipeline has arrived by the time
each pass looks. The tail is tight - p50 2.5 ms against p99 2.9 ms - so
nobody is starved; they are queued, briefly, behind work that is already
in progress.

The loop is also idle for slightly more of that stretch than it is busy
(0.82s against 0.73s), which says the bottleneck is not the server's own
execution but how fast the flood client can push and pull bytes.

**Why there is no `maxCommandsPerTick`.** The obvious next lever is a
budget on commands per pass. Measured, there is nothing for it to fix
here: mean work per pass is under a millisecond and the worst pass over
three runs was 6.5 ms. The upper bound already exists and is expressed in
bytes rather than commands - `READ_CHUNK_SIZE` (64 KiB) caps what one
readable event can take in, which for `PING` is about 4,600 commands.
Setting it to 8 KiB and to 1 MiB changed none of the numbers above, since
the network never delivers that much between two passes anyway. A command
budget would bound the pathological case (a client fast enough to keep
the buffer full), at the cost of state and a continuation path for
something no measurement here reaches - so it stays unbuilt, and the
first lever if it is ever needed is the read chunk, which is one
constant.

### Memory

100,000 keys of 64 bytes written over one connection:

| | RSS |
|---|---|
| Before | 28.19 MiB |
| After | 52.77 MiB |
| Peak | 89.17 MiB |
| Per key | ~257 B |

The write rate (~21k writes/s) is below the PING/SET rate from the main
benchmark because each SET here also serializes a fresh 100+ byte value.
The per-key cost includes the key string, the `StoredValue` object, and
the `data` array slot, plus PHP's allocator overhead for ~100k objects;
the difference between "after" and "peak" is the transient allocation
pressure of the write loop.
