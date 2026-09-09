# PHP Mini Redis

> An educational event-driven in-memory database server written in PHP.

`php-mini-redis` is a small educational project for exploring how an event-driven in-memory database server works internally.

The goal is not to replace Redis.

The goal is to build a simplified server that exposes the fundamental ideas behind systems such as:

* TCP servers;
* event loops;
* client connections;
* non-blocking I/O;
* command parsing;
* in-memory storage;
* TTL;
* Pub/Sub;
* transactions.

This repository is designed as an **executable mental model of an event-driven database server**.

---

## 30-second demo

Requires Docker. Nothing is installed on your machine.

```bash
make install       # build the image and install dependencies
make run-server     # start listening on 127.0.0.1:6380
```

In a second terminal:

```bash
make run-client ARGS="PING"
# PONG

make run-client ARGS="SET name Tanat"
# OK

make run-client ARGS="GET name"
# Tanat
```

`bin/client.php` is a minimal RESP client - one command per invocation, the
reply printed to stdout. It exists to make this demo runnable without
`redis-cli`, not as a general-purpose client library.

Past `PING`/`SET`/`GET`, [examples/](examples/) has one small standalone
script per mechanism worth watching rather than just reading about:

```bash
make run-server                          # in one terminal, then:
make example NAME=ttl                    # a key outliving one read, then expiring
make example NAME=pipelining             # the same 500 PINGs, timed both ways
make example NAME=pubsub                 # a forked subscriber actually receiving a publish
make example NAME=slow-client            # a paused slow reader vs. an unaffected one
```

---

## What it does

| | |
|---|---|
| **Event loop** | one `stream_select()` over the listener and every client, non-blocking sockets throughout |
| **RESP protocol** | simple strings, errors, integers, bulk strings, arrays - encoder and a parser that tolerates fragmented/partial input |
| **Read/write buffering** | partial reads accumulate until a complete command exists; partial writes are queued and finished on the next writable event |
| **Commands** | `PING`, `SET` (with `EX seconds`), `GET`, `DEL`, `EXISTS`, `INCR` |
| **Pipelining** | multiple commands in one read, applied and answered in order |
| **TTL** | lazy expiration on access, plus active expiration on a repeating timer |
| **Connection timeout** | idle connections are closed after a configurable period |
| **Pub/Sub** | `SUBSCRIBE` / `PUBLISH`, delivered to every subscriber of a channel |
| **Transactions** | `MULTI` / `EXEC` / `DISCARD`, queued per connection |
| **Persistence** | optional snapshot to disk, reloaded on startup - in-memory-only unless configured |
| **Graceful shutdown** | `SIGTERM`/`SIGINT` stop new connections and drain existing ones before exiting |
| **Limits** | capped read buffer size, arguments per command, and connection count - each replies with a RESP error instead of growing unbounded |
| **Backpressure** | a slow reader's write buffer is capped - reading from it pauses until it drains, instead of growing unbounded |
| **Metrics** | `INFO` reports connections, commands (overall and per name), bytes in/out, errors, expired keys |

Every phase in [docs/PHASES.md](docs/PHASES.md) is done, including the
tests, the measured benchmarks, and the standalone `examples/` scripts.

---

## Documentation

| | |
|---|---|
| **[docs/PHASES.md](docs/PHASES.md)** | how it was built - the phases, each with what it had to achieve, and (for the finished ones) which tests hold it |
| **[docs/DECISIONS.md](docs/DECISIONS.md)** | why the code is shaped this way: what was tried, what was rejected, which ordering problems forced a change |
| **[docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md)** | what breaks, what survives it, and what this server does *not* guarantee - read before trusting it with anything |
| **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** | the mental model of how the pieces talk to each other, traced through a request |
| **[docs/BENCHMARKS.md](docs/BENCHMARKS.md)** | measured throughput and latency, where it scales and where it flattens |
| the rest of this file | the concepts, in depth |

---

# Why?

Redis looks deceptively simple from the client side.

You connect:

```text
Client

↓

TCP Connection

↓

SET key value

↓

OK
```

But internally, the server has to solve many problems:

```text
Accept Connections

↓

Manage Many Clients

↓

Read Data

↓

Buffer Partial Requests

↓

Parse Commands

↓

Execute Commands

↓

Write Responses

↓

Handle Slow Clients

↓

Manage Expiration

↓

Keep Event Loop Responsive
```

This project explores what happens inside that server.

---

# Core Idea

The basic architecture:

```text
                    Clients
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
       Client A     Client B     Client C
          │            │            │
          └────────────┼────────────┘
                       ▼
                 TCP Server
                       │
                       ▼
                  Event Loop
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
      Read Events  Write Events   Timers
          │            │            │
          └────────────┼────────────┘
                       ▼
                Command Parser
                       │
                       ▼
                Command Handler
                       │
                       ▼
                In-Memory Store
```

The central idea is:

> **One Event Loop can manage many client connections without creating one process per client.**

---

# Event-Driven Architecture

Instead of:

```text
Client

↓

Create Process

↓

Block

↓

Wait
```

the server uses:

```text
One Process

↓

Event Loop

↓

Many Connections

↓

React To Events
```

Conceptually:

```text
while (running) {

    events = waitForEvents();

    foreach (events as event) {

        handle(event);
    }
}
```

The server waits until something happens:

```text
New Connection

↓

Client Data

↓

Socket Writable

↓

Timer Fired
```

Then reacts to that event.

---

# Architecture

```text
┌─────────────────────────────────────┐
│ Network Layer                       │
│                                     │
│ TCP Server                          │
│ Client Connections                  │
│ Socket Management                   │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Event Loop                          │
│                                     │
│ Read Events                         │
│ Write Events                        │
│ Timers                              │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Protocol Layer                      │
│                                     │
│ RESP Parser                         │
│ Request Decoder                     │
│ Response Encoder                    │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Command Layer                       │
│                                     │
│ Command Dispatcher                  │
│ Command Handlers                    │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Storage Layer                       │
│                                     │
│ In-Memory Store                     │
│ Keys                                │
│ Values                              │
│ TTL                                 │
└─────────────────────────────────────┘
```

---

# Project Structure

The tree below is what actually exists today, not an aspirational sketch -
each directory maps to one layer of [ARCHITECTURE.md](docs/ARCHITECTURE.md)'s
separation (Networking → Protocol → Commands → State).

```text
php-mini-redis/
│
├── bin/
│   ├── server.php              # entry point: RedisServer::run()
│   ├── client.php              # one-shot RESP client, for the demo above
│   └── bench.php               # throughput/latency benchmark - docs/BENCHMARKS.md
│
├── examples/                   # standalone scripts, one question each
│   ├── bootstrap.php
│   ├── ttl.php, pipelining.php, pubsub.php, slow-client.php
│
├── src/
│   │
│   ├── Server/
│   │   ├── RedisServer.php      # wires every layer below together
│   │   ├── ServerConfig.php
│   │   └── ServerSocket.php
│   │
│   ├── EventLoop/
│   │   ├── EventLoop.php
│   │   ├── SelectLoop.php
│   │   ├── Timer.php
│   │   └── TimerManager.php
│   │
│   ├── Connection/
│   │   ├── ClientConnection.php
│   │   ├── ConnectionManager.php
│   │   ├── ConnectionState.php
│   │   ├── ReadBuffer.php
│   │   └── WriteBuffer.php
│   │
│   ├── Protocol/
│   │   ├── RespValue.php
│   │   ├── RespParser.php
│   │   ├── RespEncoder.php
│   │   ├── RespStreamReader.php
│   │   └── ProtocolException.php
│   │
│   ├── Command/
│   │   ├── Command.php
│   │   ├── CommandDispatcher.php
│   │   ├── CommandHandler.php
│   │   └── Handler/
│   │       ├── PingCommand.php, SetCommand.php, GetCommand.php,
│   │       │   DelCommand.php, ExistsCommand.php, IncrCommand.php
│   │       ├── SubscribeCommand.php, PublishCommand.php
│   │       ├── MultiCommand.php, ExecCommand.php, DiscardCommand.php
│   │       └── InfoCommand.php
│   │
│   ├── Storage/
│   │   ├── Store.php
│   │   ├── InMemoryStore.php
│   │   └── StoredValue.php
│   │
│   ├── Persistence/
│   │   └── SnapshotStore.php
│   │
│   ├── PubSub/
│   │   └── ChannelRegistry.php
│   │
│   ├── Transaction/
│   │   └── TransactionManager.php
│   │
│   ├── Metrics/
│   │   └── ServerMetrics.php
│   │
│   ├── Support/
│   │   ├── Clock.php
│   │   └── SystemClock.php
│   │
│   └── Logging/
│       ├── Logger.php, ConsoleLogger.php, NullLogger.php
│
├── tests/                       # mirrors src/, one test class per class
│
├── docs/
│   ├── ARCHITECTURE.md
│   ├── PHASES.md
│   ├── DECISIONS.md
│   ├── FAILURE-MODEL.md
│   └── BENCHMARKS.md
│
├── README.md
├── composer.json
└── phpunit.xml
```

---

# Event Loop

The Event Loop is the heart of the server.

Conceptually:

```text
Server Starts

↓

Event Loop Starts

↓

Wait For Events

↓

Event Occurs?

├── New Connection
│
├── Client Data
│
├── Socket Writable
│
└── Timer
```

Then:

```text
Handle Event

↓

Return To Event Loop
```

The important rule:

> **Do not block the Event Loop.**

Bad:

```text
Event Loop

↓

Client Request

↓

sleep(10)

↓

Everything Stops 💀
```

Better:

```text
Event Loop

↓

Handle Small Unit Of Work

↓

Return Immediately
```

---

# TCP Server

The server listens on a TCP socket.

```text
Server

↓

Listen

↓

Accept Connections
```

Example:

```text
Client A ─────┐
Client B ─────┼────► TCP Server
Client C ─────┘
```

Each client receives its own connection.

The server must manage:

```text
Accept

Read

Write

Close
```

for many connections.

---

# Connection Lifecycle

A connection has a lifecycle.

```text
NEW
 │
 ▼
CONNECTED
 │
 ▼
READING
 │
 ▼
PROCESSING
 │
 ▼
WRITING
 │
 ├───────────────┐
 │               │
 ▼               ▼
READING        CLOSED
```

Simplified:

```text
CONNECT

↓

READ

↓

PROCESS

↓

WRITE

↓

READ AGAIN
```

---

# RESP Protocol

The server uses a simplified version of the Redis Serialization Protocol.

A client sends commands.

Example:

```text
SET user:1 Tanat
```

The protocol layer:

```text
Raw TCP Bytes

↓

RESP Parser

↓

Command

↓

Arguments
```

The response:

```text
Result

↓

RESP Encoder

↓

TCP Bytes

↓

Client
```

Architecture:

```text
Client

↓

TCP

↓

RESP Parser

↓

Command Dispatcher

↓

Command Handler

↓

Storage

↓

RESP Encoder

↓

Client
```

---

# Commands

The server starts with a small command set.

## PING

```text
PING

↓

PONG
```

---

## SET

```text
SET key value
```

Example:

```text
SET user:1 Tanat
```

---

## GET

```text
GET key
```

Example:

```text
GET user:1
```

---

## DEL

```text
DEL key
```

---

## EXISTS

```text
EXISTS key
```

---

## INCR

```text
INCR key
```

Increments the integer stored at `key` by one, treating a missing key as
`0`. Errors if the existing value is not an integer.

---

## SUBSCRIBE / PUBLISH

```text
SUBSCRIBE channel

PUBLISH channel message
```

`PUBLISH` delivers a `message` push to every connection currently
subscribed to `channel`, and replies with how many it reached. See
[Pub/Sub](#pubsub) below.

---

## MULTI / EXEC / DISCARD

```text
MULTI

↓

queue commands, replying +QUEUED to each

↓

EXEC (run the queue, one array reply)

or

DISCARD (cancel it)
```

Queuing and execution are per connection - one client's `MULTI` has no
effect on another's commands. See [Transactions](#transactions) below.

---

## INFO

```text
INFO
```

Replies with one bulk string of `key:value` lines - connections,
commands processed overall and by name, bytes read/written, errors,
expired keys. Matches real Redis's own `INFO` reply shape, though only a
small subset of what it actually reports.

---

# Command Flow

Every command follows the same pipeline.

```text
Client

↓

TCP Data

↓

Read Buffer

↓

RESP Parser

↓

Command

↓

Command Dispatcher

↓

Command Handler

↓

Storage

↓

Response

↓

Write Buffer

↓

Client
```

---

# In-Memory Storage

The storage keeps data in memory.

Conceptually:

```text
Storage

├── user:1
│     └── Tanat
│
├── counter
│     └── 42
│
└── session:123
      └── ...
```

A simplified representation:

```php
$storage = [
    'user:1' => 'Tanat',
    'counter' => 42,
];
```

---

# Key Lifecycle

```text
SET

↓

KEY CREATED

↓

AVAILABLE

↓

GET

↓

VALUE
```

A key can later be removed:

```text
DEL

↓

KEY REMOVED
```

Or expire:

```text
SET

↓

TTL

↓

EXPIRED

↓

REMOVED
```

---

# TTL

Keys may have a lifetime.

Example:

```text
SET session:123 value

↓

TTL = 60 seconds
```

Lifecycle:

```text
KEY CREATED
     │
     ▼
ACTIVE
     │
     │ TTL expires
     ▼
EXPIRED
     │
     ▼
REMOVED
```

The server must periodically check expiration.

This introduces timers:

```text
Event Loop

↓

Timer

↓

Check Expired Keys

↓

Remove Expired Keys
```

---

# Timers

Timers allow the Event Loop to schedule future work.

Example:

```text
Every 1 second

↓

Check Expired Keys
```

Architecture:

```text
Event Loop
    │
    ├── Network Events
    │
    └── Timers
          │
          ▼
    Expiration Manager
```

---

# Non-Blocking I/O

A server should not wait on one client while ignoring others.

Bad:

```text
Client A

↓

Slow Request

↓

Server Blocks

↓

Client B Waits 💀
```

Better:

```text
Client A ──┐
Client B ──┼──► Event Loop
Client C ──┘

Ready Events

↓

Process Only Ready Connections
```

This is one of the main ideas behind event-driven servers.

---

# Partial Reads

TCP does not guarantee that one `read()` contains one complete command.

Example:

```text
Client sends:

SET user:1 Tanat
```

The server might receive:

```text
SET user:
```

and later:

```text
1 Tanat
```

Therefore:

```text
Read

↓

Append To Buffer

↓

Complete Command?

├── No → Wait For More Data
│
└── Yes → Parse Command
```

Each connection needs its own read buffer.

---

# Partial Writes

Writing is also not guaranteed to complete in one operation.

```text
Response

↓

Write Attempt

↓

Everything Written?

├── Yes → Continue
│
└── No → Store Remaining Data
            │
            ▼
         Wait For Writable Event
```

Each connection may need a write buffer.

---

# Backpressure

A slow client can become a problem.

Imagine:

```text
Server

↓

Produces Responses Faster

than

Client Can Read
```

The write buffer grows:

```text
Write Buffer

↓

10 KB

↓

100 KB

↓

1 MB

↓

💥 Memory Problem
```

Backpressure helps control this.

Example:

```text
Write Buffer Too Large?

↓

Pause Reading

↓

Wait Until Client Catches Up

↓

Resume Reading
```

---

# Pub/Sub

Pub/Sub introduces message delivery between clients.

```text
Publisher

↓

PUBLISH news "Hello"

↓

Channel

↓

Subscribers
```

Architecture:

```text
Publisher
    │
    ▼
 Channel
    │
 ┌──┼──┐
 ▼  ▼  ▼
 A  B  C
```

The server maintains:

```text
Channel

↓

Subscribers
```

When a message arrives:

```text
PUBLISH

↓

Find Subscribers

↓

Write Message To Their Buffers
```

---

# Transactions

`MULTI` starts queuing commands for that connection instead of running
them immediately.

```text
MULTI
   │
   ▼
queue commands (each replies +QUEUED)
   │
   ▼
EXEC
   │
   ▼
run the queue, in order, through the same dispatcher
   │
   ▼
one array reply, one element per queued command
```

`DISCARD` cancels the queue instead of running it - also replies `+OK`, but
nothing executes.

```text
MULTI
   │
   ▼
queue commands
   │
   ▼
DISCARD
   │
   ▼
+OK, queue thrown away
```

The queue is per connection: one client's `MULTI` never sees another
client's commands, and a disconnecting client's open transaction is
discarded rather than left dangling.

---

# Connection Cleanup

When a client disconnects:

```text
Connection

↓

CLOSED
```

The server must clean up:

```text
Socket

Read Buffer

Write Buffer

Subscriptions

Connection Metadata
```

Otherwise:

```text
Disconnected Clients

↓

Memory Leak 💀
```

---

# Failure Scenarios

One of the main goals of this project is experimentation.

## Slow Client

```text
Server

↓

Client Cannot Read Fast Enough

↓

Write Buffer Grows

↓

Backpressure
```

---

## Partial Request

```text
Client

↓

Half Command

↓

Server Waits

↓

Remaining Data

↓

Parse
```

---

## Client Disconnect

```text
Client

↓

Disconnect

↓

Cleanup Connection
```

---

## Blocking Operation

```text
Event Loop

↓

Blocking Code

↓

Everything Stops 💀
```

This demonstrates why event loops require careful design.

---

# Experiments

The repository is designed to be executed and modified.

## Multiple Clients

Open several clients:

```text
Client A

Client B

Client C
```

Observe:

```text
One Server

↓

One Event Loop

↓

Many Connections
```

---

## Partial Requests

Modify the client to send data in pieces:

```text
SET user:
```

Wait.

Then:

```text
1 Tanat
```

Observe buffering and parsing.

---

## Slow Client

Create a client that reads responses slowly.

Observe:

```text
Write Buffer

↓

Growth

↓

Backpressure
```

---

## TTL

Create a key:

```text
SET session:1 value
```

Add TTL:

```text
60 seconds
```

Observe:

```text
ACTIVE

↓

EXPIRED

↓

REMOVED
```

---

## Pub/Sub

Start multiple subscribers.

```text
Subscriber A ──┐
Subscriber B ──┼──► Channel
Subscriber C ──┘
```

Publish a message and observe delivery.

---

# Roadmap

The project was implemented incrementally, thirty phases in total, every
one of them finished. The full list, with a Definition of Done and the
exact tests behind each one, lives in [docs/PHASES.md](docs/PHASES.md) -
this is the short version:

TCP server, event loop, non-blocking sockets, read/write buffering, RESP
protocol (parser + encoder, fragmentation-tolerant), command model,
in-memory store, `PING`/`SET`/`GET`/`DEL`/`EXISTS`/`INCR`, command
dispatcher, multiple commands per read, pipelining, TTL (lazy and active
expiration), event loop timers, connection timeout, Pub/Sub,
transactions, persistence, graceful shutdown, explicit RESP error
handling, resource limits, backpressure, metrics, a measured benchmark
pass, and a handful of standalone `examples/` scripts.

---

# Related Projects

This project is part of a collection of educational PHP backend and concurrency projects.

## [PHP Concurrency](https://github.com/Researcher86/php-concurrency)

A practical collection of experiments exploring concurrency in PHP.

It focuses on:

* processes;
* `pcntl_fork`;
* IPC;
* Fibers;
* event loops;
* asynchronous execution;
* concurrency patterns.

It provides the foundation for understanding concurrent and event-driven programming.

## [PHP Worker Pool](https://github.com/Researcher86/php-worker-pool)

An educational implementation of a reusable Worker Pool.

It explores:

* Worker lifecycle;
* process management;
* Worker states;
* Worker recycling;
* graceful shutdown;
* `DRAINING`.

While `php-worker-pool` focuses on managing multiple Worker processes, `php-mini-redis` explores another concurrency model:

```text
Multiple Processes

↓

vs

↓

One Process

+

One Event Loop

+

Many Client Connections
```

The projects form a progression:

```text
php-concurrency
        ↓
Concurrency fundamentals
        ↓
php-worker-pool
        ↓
Worker processes and lifecycle management
        ↓
php-job-queue
        ↓
Background job processing
        ↓
php-mini-redis
        ↓
Event-driven server architecture
```

Each project focuses on a different engineering problem while remaining small enough to understand and experiment with.

---

# What This Project Is Not

This project is intentionally **not** trying to become Redis.

A production-grade server includes many additional concerns:

```text
Replication

Persistence

RDB

AOF

Clustering

High Availability

Authentication

Authorization

Transactions

Lua Scripting

Streams

Modules

Memory Optimization

Eviction Policies

Monitoring
```

Those are valuable problems.

But they can hide the fundamental architecture.

This project focuses on:

```text
TCP

↓

Event Loop

↓

Connections

↓

Protocol

↓

Commands

↓

In-Memory Storage
```

---

# Mental Model

The entire server can be reduced to:

```text
                   CLIENTS
                      │
                      ▼
                 TCP SERVER
                      │
                      ▼
                  EVENT LOOP
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
        READ        WRITE       TIMERS
          │           │           │
          └───────────┼───────────┘
                      ▼
                RESP PROTOCOL
                      │
                      ▼
              COMMAND DISPATCHER
                      │
                      ▼
                COMMAND HANDLER
                      │
                      ▼
                 IN-MEMORY DB
```

---

# Final Principle

The purpose of this project is not to build a Redis replacement.

The purpose is to build an:

> **Executable mental model of an event-driven in-memory database server.**

The project should remain:

> **Small enough to understand.**

> **Real enough to experiment with.**

> **Simple enough to modify.**

> **Complex enough to demonstrate real server engineering problems.**

## License

MIT
