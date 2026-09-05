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
* Pub/Sub.

This repository is designed as an **executable mental model of an event-driven database server**.

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

```text
php-mini-redis/
│
├── bin/
│   ├── server.php
│   └── client.php
│
├── src/
│   │
│   ├── Server/
│   │   ├── Server.php
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
│   │   ├── Connection.php
│   │   ├── ConnectionManager.php
│   │   └── ConnectionState.php
│   │
│   ├── Protocol/
│   │   ├── RespParser.php
│   │   ├── RespEncoder.php
│   │   └── ProtocolException.php
│   │
│   ├── Command/
│   │   ├── Command.php
│   │   ├── CommandDispatcher.php
│   │   └── Handler/
│   │       ├── GetCommand.php
│   │       ├── SetCommand.php
│   │       ├── DelCommand.php
│   │       └── ...
│   │
│   ├── Storage/
│   │   ├── Storage.php
│   │   ├── InMemoryStorage.php
│   │   └── Entry.php
│   │
│   ├── Expiration/
│   │   ├── ExpirationManager.php
│   │   └── TTL.php
│   │
│   └── PubSub/
│       ├── Channel.php
│       └── PubSubManager.php
│
├── tests/
├── examples/
├── benchmarks/
│
├── docs/
│   └── ARCHITECTURE.md
│
├── README.md
├── PLAN.md
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

The project is implemented incrementally.

## Phase 1 — TCP Server

```text
Socket

Listen

Accept Connection
```

## Phase 2 — Event Loop

```text
Read Events

Write Events

Timers
```

## Phase 3 — Connections

```text
Connection Lifecycle

Read Buffers

Write Buffers

Cleanup
```

## Phase 4 — RESP

```text
Parser

Encoder

Partial Requests
```

## Phase 5 — Commands

```text
PING

SET

GET

DEL

EXISTS
```

## Phase 6 — Storage

```text
In-Memory Storage

Keys

Values
```

## Phase 7 — TTL

```text
Expiration

Timers

Cleanup
```

## Phase 8 — Backpressure

```text
Slow Clients

Write Buffers

Flow Control
```

## Phase 9 — Pub/Sub

```text
Channels

Subscribers

Message Delivery
```

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
