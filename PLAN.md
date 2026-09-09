# PHP Mini Redis — Implementation Plan

> A step-by-step plan for building an educational event-driven in-memory database server in PHP.

The goal of this project is not to recreate Redis.

The goal is to understand how an event-driven network server works by implementing a small Redis-like server from scratch.

The project should remain:

* small enough to understand;
* easy to run locally;
* easy to modify;
* easy to debug;
* realistic enough to demonstrate real systems programming concepts.

---

# Final Architecture

```text
                         Clients
                            │
                            │ TCP
                            ▼
                    ┌───────────────┐
                    │  TCP Server   │
                    └───────┬───────┘
                            │
                            ▼
                    ┌───────────────┐
                    │  Event Loop   │
                    └───────┬───────┘
                            │
              ┌─────────────┼─────────────┐
              │             │             │
              ▼             ▼             ▼
          Read Event    Write Event     Timer
              │             │             │
              ▼             │             ▼
        Read Buffer         │        Expiration
              │             │          Cleanup
              ▼             │
       Protocol Parser      │
              │             │
              ▼             │
        Redis Command       │
              │             │
              ▼             │
       Command Dispatcher   │
              │             │
              ▼             │
       Command Handler      │
              │             │
              ▼             │
       In-Memory Store      │
              │             │
              └─────────────┘
```

---

# Progress

- [x] Phase 0 — Project Setup
- [x] Phase 1 — TCP Server
- [x] Phase 2 — Client Connection
- [x] Phase 3 — Event Loop
- [x] Phase 4 — Non-Blocking Sockets
- [x] Phase 5 — Read Buffer
- [x] Phase 6 — Redis Protocol
- [x] Phase 7 — RESP Parser
- [x] Phase 8 — Command Model
- [x] Phase 9 — In-Memory Store
- [x] Phase 10 — Basic Commands
- [x] Phase 11 — Command Dispatcher
- [x] Phase 12 — Response Encoder
- [x] Phase 13 — Write Buffer
- [x] Phase 14 — Multiple Commands
- [x] Phase 15 — Pipelining
- [x] Phase 16 — TTL
- [x] Phase 17 — Expiration Strategy
- [x] Phase 18 — Event Loop Timers
- [ ] Phase 19 — Connection Timeout
- [ ] Phase 20 — Pub/Sub
- [ ] Phase 21 — Transactions
- [ ] Phase 22 — Persistence
- [ ] Phase 23 — Graceful Shutdown
- [ ] Phase 24 — Error Handling
- [ ] Phase 25 — Limits
- [ ] Phase 26 — Backpressure
- [ ] Phase 27 — Metrics
- [ ] Phase 28 — Tests
- [ ] Phase 29 — Benchmarks
- [ ] Phase 30 — Experiments

---

# Phase 0 — Project Setup

## Goal

Create the basic project structure and development environment.

## Tasks

Create:

```text
php-mini-redis/
│
├── bin/
├── src/
├── tests/
├── examples/
├── benchmarks/
├── docs/
│
├── README.md
├── PLAN.md
├── composer.json
├── phpunit.xml
└── phpstan.neon
```

Configure:

* PSR-4 autoloading;
* PHPUnit;
* PHPStan;
* Docker;
* Makefile;
* GitHub Actions.

## Result

The project can:

```text
composer install

↓

run tests

↓

run PHPStan

↓

start development server
```

---

# Phase 1 — TCP Server

## Goal

Understand the lowest-level server architecture.

Implement:

```text
socket()

↓

bind()

↓

listen()

↓

accept()
```

The server should:

1. create a TCP socket;
2. bind to host and port;
3. listen for connections;
4. accept clients;
5. keep track of connected clients.

## Result

A client can establish a TCP connection.

---

# Phase 2 — Client Connection

## Goal

Represent every connected client explicitly.

Create:

```text
ClientConnection
```

Each connection should contain:

```text
Socket

Read Buffer

Write Buffer

State

Last Activity
```

Suggested lifecycle:

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
 └──────► READING
             │
             ▼
           CLOSED
```

## Result

The server can manage multiple clients independently.

---

# Phase 3 — Event Loop

## Goal

Replace blocking client handling with event-driven I/O.

Initial implementation:

```text
stream_select()
```

Conceptually:

```php
while ($running) {
    $events = $eventLoop->wait();

    foreach ($events as $event) {
        $event->handle();
    }
}
```

The Event Loop should initially support:

```text
Readable sockets

Writable sockets
```

Timers will be introduced later.

## Result

One process can manage multiple TCP clients.

---

# Phase 4 — Non-Blocking Sockets

## Goal

Make the server genuinely event-driven.

Configure sockets as:

```text
non-blocking
```

Important rule:

> The Event Loop must never block waiting for an individual client.

Architecture:

```text
Client A ─┐
Client B ─┼──► Event Loop
Client C ─┘
```

The server reacts only when a socket is ready.

## Result

Slow clients do not block other clients.

---

# Phase 5 — Read Buffer

## Goal

Handle TCP as a byte stream.

A command is not guaranteed to arrive in one read.

Example:

```text
SET foo
```

could arrive as:

```text
SET f
```

then:

```text
oo
```

Therefore:

```text
Socket
   │
   ▼
Read
   │
   ▼
Read Buffer
   │
   ▼
Complete Command?
   │
   ├── No → Wait
   │
   └── Yes → Parse
```

Create:

```text
ReadBuffer
```

## Result

Partial TCP messages are handled correctly.

---

# Phase 6 — Redis Protocol

## Goal

Implement a small subset of the Redis Serialization Protocol.

Start with the simplest protocol representation.

For example:

```text
+OK\r\n
```

```text
:100\r\n
```

```text
$5\r\nhello\r\n
```

Then implement arrays:

```text
*2\r\n
$3\r\n
GET\r\n
$3\r\n
foo\r\n
```

Create:

```text
RespParser
RespEncoder
```

## Result

The server can communicate using RESP-compatible messages.

---

# Phase 7 — RESP Parser

## Goal

Convert raw bytes into protocol values.

Example:

```text
*2\r\n
$3\r\n
GET\r\n
$3\r\n
foo\r\n
```

becomes:

```text
[
    'GET',
    'foo',
]
```

The parser must support incomplete input.

Example:

```text
*2\r\n
$3\r\n
GET\r\n
```

should result in:

```text
INCOMPLETE
```

rather than an error.

## Result

The protocol parser can process fragmented network input.

---

# Phase 8 — Command Model

## Goal

Separate protocol parsing from command execution.

Create:

```text
Command
```

Example:

```text
[
    'SET',
    'foo',
    'bar',
]
```

becomes:

```text
SetCommand(
    key: 'foo',
    value: 'bar'
)
```

Possible architecture:

```text
RESP

↓

Parser

↓

Command

↓

Dispatcher

↓

Handler
```

## Result

Protocol logic and database logic are separated.

---

# Phase 9 — In-Memory Store

## Goal

Create the database core.

Initial storage:

```php
array<string, mixed>
```

Architecture:

```text
Command Handler

↓

Store

↓

Memory
```

Create:

```text
StoreInterface
InMemoryStore
```

## Result

The server has a basic in-memory database.

---

# Phase 10 — Basic Commands

## Goal

Implement the first commands.

Start with:

```text
PING
```

```text
SET key value
```

```text
GET key
```

```text
DEL key
```

```text
EXISTS key
```

```text
INCR key
```

Example:

```text
SET name Tanat
```

then:

```text
GET name
```

returns:

```text
Tanat
```

## Result

The server behaves like a tiny Redis-like database.

---

# Phase 11 — Command Dispatcher

## Goal

Route commands to handlers.

Architecture:

```text
Command
   │
   ▼
Command Dispatcher
   │
   ├── PING
   ├── GET
   ├── SET
   ├── DEL
   └── INCR
```

Create:

```text
CommandDispatcher
CommandHandlerInterface
```

Example:

```text
GET

↓

GetCommandHandler
```

## Result

Adding new commands does not require modifying the entire server.

---

# Phase 12 — Response Encoder

## Goal

Convert command results back to RESP.

Example:

```text
PING
```

returns:

```text
+PONG\r\n
```

Example:

```text
GET foo
```

returns:

```text
$3\r\nbar\r\n
```

Architecture:

```text
Command Handler

↓

Result

↓

RESP Encoder

↓

Write Buffer

↓

Socket
```

## Result

Clients receive valid RESP responses.

---

# Phase 13 — Write Buffer

## Goal

Correctly handle partial socket writes.

A response may be larger than what the socket can write immediately.

```text
Response
   │
   ▼
write()
   │
   ▼
Partial Write
   │
   ▼
Write Buffer
   │
   ▼
Writable Event
   │
   ▼
Continue
```

The Event Loop should monitor writable sockets only when necessary.

## Result

Large responses and slow clients are handled correctly.

---

# Phase 14 — Multiple Commands

## Goal

Allow multiple commands in one TCP read.

Example:

```text
PING

SET foo bar

GET foo

DEL foo
```

All commands may arrive in one network packet.

The parser should process:

```text
Buffer

↓

Command 1

↓

Command 2

↓

Command 3

↓

Remaining Partial Data
```

## Result

The server can process multiple commands from one read.

---

# Phase 15 — Pipelining

## Goal

Understand command pipelining.

Client:

```text
SET counter 1
INCR counter
GET counter
```

The client can send all commands without waiting for each response.

Architecture:

```text
Client
   │
   ├── Command 1
   ├── Command 2
   └── Command 3
            │
            ▼
        Event Loop
            │
            ▼
       Command Queue
            │
            ▼
        Responses
```

## Result

The server supports pipelined commands.

---

# Phase 16 — TTL

## Goal

Understand expiration and timers.

Example:

```text
SET session abc EX 30
```

The key should expire after 30 seconds.

Store:

```text
Key

Value

Expiration Timestamp
```

Architecture:

```text
SET

↓

Store Value

+

Expiration

↓

Timer / Expiration Check

↓

Delete Key
```

## Result

Keys can expire automatically.

---

# Phase 17 — Expiration Strategy

## Goal

Explore different TTL implementation strategies.

Implement an initial simple strategy:

```text
Lazy Expiration
```

When accessing a key:

```text
Key Exists?

↓

Expired?

├── Yes → Delete
│
└── No → Return Value
```

Then experiment with:

```text
Active Expiration
```

using Event Loop timers.

Compare:

```text
Lazy Expiration

vs

Active Expiration
```

## Result

The project demonstrates a real database design trade-off.

---

# Phase 18 — Event Loop Timers

## Goal

Extend the Event Loop with timers.

Architecture:

```text
Event Loop
   │
   ├── Read Events
   ├── Write Events
   └── Timers
```

Timers can be used for:

```text
TTL expiration

Connection timeout

Periodic cleanup
```

## Result

Time becomes a first-class Event Loop event.

---

# Phase 19 — Connection Timeout

## Goal

Handle idle clients.

Track:

```text
Last Activity
```

If:

```text
now - lastActivity > timeout
```

then:

```text
Close Connection
```

## Result

Abandoned connections do not remain forever.

---

# Phase 20 — Pub/Sub

## Goal

Explore server-side event distribution.

Commands:

```text
SUBSCRIBE channel
```

```text
PUBLISH channel message
```

Architecture:

```text
Publisher
    │
    ▼
Event Loop
    │
    ▼
Channel Registry
    │
    ├── Subscriber A
    ├── Subscriber B
    └── Subscriber C
```

Example:

```text
Client A

PUBLISH news hello

        │
        ▼

Channel: news

        │
   ┌────┼────┐
   ▼    ▼    ▼
  B     C     D
```

## Result

The server supports basic Pub/Sub.

---

# Phase 21 — Transactions

## Goal

Explore grouped command execution.

Implement:

```text
MULTI
EXEC
DISCARD
```

Conceptually:

```text
MULTI

↓

Queue Commands

↓

EXEC

↓

Execute Commands
```

## Result

The project demonstrates command batching and transaction state.

---

# Phase 22 — Persistence

## Goal

Explore persistence as an optional experiment.

The initial database should remain:

```text
In-Memory Only
```

Then optionally experiment with:

```text
Snapshot

↓

Serialize Store

↓

Write File
```

and:

```text
Server Start

↓

Load Snapshot
```

This should remain optional because persistence is not the core purpose of the project.

## Result

The project demonstrates the difference between:

```text
Memory

vs

Persistent Storage
```

---

# Phase 23 — Graceful Shutdown

## Goal

Stop the server without abruptly dropping active clients.

Lifecycle:

```text
RUNNING
    │
    │ SIGTERM
    ▼
DRAINING
    │
    │ Stop Accepting
    ▼
FINISHING
    │
    │ Flush Responses
    ▼
STOPPED
```

During `DRAINING`:

```text
❌ New Connections

✅ Existing Connections

✅ Pending Responses
```

## Result

The server can shut down cleanly.

---

# Phase 24 — Error Handling

## Goal

Make protocol and command errors explicit.

Examples:

```text
Unknown Command
```

```text
Wrong Number Of Arguments
```

```text
Invalid Integer
```

```text
Protocol Error
```

Responses should use appropriate RESP error values:

```text
-ERR ...
```

## Result

Invalid client input does not crash the server.

---

# Phase 25 — Limits

## Goal

Protect the server from pathological input.

Possible limits:

```text
Maximum command size

Maximum value size

Maximum number of arguments

Maximum connections

Maximum write buffer size
```

Example:

```text
Read Buffer > limit

↓

Protocol Error / Close
```

## Result

The server has explicit resource boundaries.

---

# Phase 26 — Backpressure

## Goal

Understand what happens when a client reads slowly.

Scenario:

```text
Server

↓

Large Response

↓

Client Reads Slowly

↓

Write Buffer Grows
```

Implement:

```text
Maximum Write Buffer Size
```

When exceeded:

```text
Pause Reading

↓

Wait For Writable Event

↓

Buffer Drains

↓

Resume Reading
```

## Result

Slow clients cannot grow memory indefinitely.

---

# Phase 27 — Metrics

## Goal

Make server behavior observable.

Track:

```text
Active Connections

Total Connections

Commands Processed

Commands By Type

Bytes Read

Bytes Written

Errors

Expired Keys
```

Optional command:

```text
INFO
```

## Result

The server exposes basic runtime statistics.

---

# Phase 28 — Tests

Tests should exist at multiple levels.

## Unit Tests

Test independently:

```text
RESP Parser

RESP Encoder

Command Dispatcher

Store

TTL

Router / Registry

Timers
```

## Integration Tests

Test:

```text
TCP Client

↓

Server

↓

Command

↓

Response
```

## Failure Tests

Test:

```text
Partial Request

Invalid RESP

Unknown Command

Slow Client

Large Response

Expired Key

Connection Timeout
```

## Result

The server behavior is reproducible.

---

# Phase 29 — Benchmarks

## Goal

Measure the server rather than guessing.

Tools:

```text
redis-benchmark

wrk

custom PHP client

k6
```

Measure:

```text
Requests / second

Latency

p50

p95

p99

Memory

Active Connections
```

Experiments:

```text
1 client

10 clients

100 clients

1000 clients
```

Compare:

```text
PING

GET

SET

INCR

Pipelining
```

## Result

The project becomes an experimental performance laboratory.

---

# Phase 30 — Experiments

The repository should contain small executable experiments.

Examples:

```text
examples/
│
├── basic-client.php
├── pipelining.php
├── ttl.php
├── pubsub.php
├── slow-client.php
└── graceful-shutdown.php
```

Each experiment should answer one question.

---

# Suggested Final Structure

```text
php-mini-redis/
│
├── bin/
│   └── server.php
│
├── src/
│   ├── Server/
│   │   ├── RedisServer.php
│   │   ├── ServerConfig.php
│   │   └── ServerState.php
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
│   │   ├── RespParser.php
│   │   ├── RespEncoder.php
│   │   ├── RespValue.php
│   │   └── RespException.php
│   │
│   ├── Command/
│   │   ├── Command.php
│   │   ├── CommandDispatcher.php
│   │   ├── CommandHandler.php
│   │   └── Handlers/
│   │       ├── PingCommand.php
│   │       ├── GetCommand.php
│   │       ├── SetCommand.php
│   │       ├── DelCommand.php
│   │       └── IncrCommand.php
│   │
│   ├── Storage/
│   │   ├── Store.php
│   │   ├── InMemoryStore.php
│   │   └── StoredValue.php
│   │
│   ├── Expiration/
│   │   ├── ExpirationManager.php
│   │   └── Ttl.php
│   │
│   ├── PubSub/
│   │   ├── ChannelRegistry.php
│   │   └── Subscriber.php
│   │
│   └── Metrics/
│       └── ServerMetrics.php
│
├── examples/
│
├── benchmarks/
│
├── tests/
│   ├── Unit/
│   └── Integration/
│
├── docs/
│   ├── ARCHITECTURE.md
│   ├── PROTOCOL.md
│   ├── EVENT_LOOP.md
│   ├── CONNECTION_LIFECYCLE.md
│   └── EXPERIMENTS.md
│
├── README.md
├── PLAN.md
├── composer.json
├── phpunit.xml
└── phpstan.neon
```

---

# Implementation Order

The recommended implementation order is:

```text
1. Project Setup
       ↓
2. TCP Server
       ↓
3. Client Connection
       ↓
4. Event Loop
       ↓
5. Non-Blocking Sockets
       ↓
6. Read Buffer
       ↓
7. RESP
       ↓
8. RESP Parser
       ↓
9. Command Model
       ↓
10. In-Memory Store
       ↓
11. Basic Commands
       ↓
12. Command Dispatcher
       ↓
13. Response Encoder
       ↓
14. Write Buffer
       ↓
15. Multiple Commands
       ↓
16. Pipelining
       ↓
17. TTL
       ↓
18. Timers
       ↓
19. Connection Timeout
       ↓
20. Pub/Sub
       ↓
21. Transactions
       ↓
22. Optional Persistence
       ↓
23. Graceful Shutdown
       ↓
24. Error Handling
       ↓
25. Limits
       ↓
26. Backpressure
       ↓
27. Metrics
       ↓
28. Tests
       ↓
29. Benchmarks
       ↓
30. Experiments
```

---

# Final Principle

Do not optimize for:

> Redis compatibility.

Optimize for:

> Understanding.

Every component should answer:

```text
What problem does this solve?

Why does it exist?

What happens if we remove it?

What failure scenario does it prevent?

What trade-off does this design introduce?
```

The final project should provide an:

> **Executable mental model of an event-driven in-memory database server.**
