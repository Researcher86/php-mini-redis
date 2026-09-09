# PHP Mini Redis Architecture

> Architecture and internal data flow of an educational event-driven in-memory database server written in PHP.

This document describes how the main components of `php-mini-redis` work together.

The goal is not to document every implementation detail.

The goal is to provide a **mental model of the system**.

If you forget how something works, this document should help answer:

```text
What component is responsible for this?

Where does data come from?

Where does it go next?

Why does this component exist?
```

---

# Table of Contents

* [Architecture Overview](#architecture-overview)
* [Core Components](#core-components)
* [Server Lifecycle](#server-lifecycle)
* [Event Loop](#event-loop)
* [Connection Lifecycle](#connection-lifecycle)
* [Read Path](#read-path)
* [RESP Parsing](#resp-parsing)
* [Command Lifecycle](#command-lifecycle)
* [Database](#database)
* [Write Path](#write-path)
* [Backpressure](#backpressure)
* [TTL and Expiration](#ttl-and-expiration)
* [Pub/Sub Architecture](#pubsub-architecture)
* [Persistence](#persistence)
* [Component Responsibilities](#component-responsibilities)
* [Important Invariants](#important-invariants)

---

# Architecture Overview

The server is built around a single event-driven architecture.

```text
                         Clients
                            │
                            │ TCP
                            ▼
                  ┌───────────────────┐
                  │   Server Socket   │
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │    Event Loop     │
                  └─────────┬─────────┘
                            │
             ┌──────────────┼──────────────┐
             ▼              ▼              ▼
        Connection A   Connection B   Connection C
             │              │              │
             └──────────────┼──────────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │    Read Buffer    │
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │    RESP Parser    │
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │ Command Dispatcher│
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │     Commands      │
                  └─────────┬─────────┘
                            │
             ┌──────────────┼──────────────┐
             ▼              ▼              ▼
        Database         Pub/Sub      Transactions
             │
             ▼
                  ┌───────────────────┐
                  │   RESP Encoder    │
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │   Write Buffer    │
                  └─────────┬─────────┘
                            │
                            ▼
                          Client
```

The main principle is:

> **One Event Loop manages many client connections.**

The server does not create:

```text
One Process Per Client
```

or:

```text
One Thread Per Client
```

Instead:

```text
                    One Process

                         │

                         ▼

                    Event Loop

                         │

        ┌────────────────┼────────────────┐

        ▼                ▼                ▼

    Client A          Client B          Client C
```

---

# Core Components

The architecture consists of several independent layers.

```text
┌─────────────────────────────────────┐
│ Networking                          │
│                                     │
│ Server Socket                       │
│ Event Loop                          │
│ Connections                         │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Protocol                            │
│                                     │
│ RESP Parser                         │
│ RESP Encoder                        │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Command Processing                  │
│                                     │
│ Command Dispatcher                  │
│ Command Handlers                    │
└──────────────────┬──────────────────┘
                   │
                   ▼
┌─────────────────────────────────────┐
│ Application State                   │
│                                     │
│ Database                            │
│ TTL                                 │
│ Pub/Sub                             │
│ Transactions                        │
└─────────────────────────────────────┘
```

The layers should not depend unnecessarily on implementation details of other layers.

For example:

```text
Command
```

should not know about:

```text
TCP Socket
```

And:

```text
Database
```

should not know about:

```text
RESP
```

The desired separation is:

```text
Networking
    ↓
Protocol
    ↓
Commands
    ↓
State
```

---

# Server Lifecycle

The server lifecycle begins with initialization.

```text
STARTING
    │
    ▼
Create Server Socket
    │
    ▼
Bind Address
    │
    ▼
Listen
    │
    ▼
Register Server Socket
    │
    ▼
RUNNING
    │
    │
    ▼
Event Loop
    │
    │ shutdown requested
    ▼
STOPPING
    │
    ▼
Close Connections
    │
    ▼
Close Server Socket
    │
    ▼
STOPPED
```

Conceptually:

```php
$server->start();

$eventLoop->run();

$server->stop();
```

---

# Event Loop

The Event Loop is the central coordinator of the server.

Its responsibility is:

```text
Wait for events.

↓

Find ready sockets.

↓

Execute appropriate handlers.
```

Conceptually:

```text
while (running) {

    wait for readable streams

    wait for writable streams

    handle events
}
```

With PHP streams:

```text
stream_select()
```

can tell the server which streams are ready.

---

## Read Events

A stream becomes readable when:

```text
Server Socket

↓

New connection
```

or:

```text
Client Socket

↓

New data available
```

The event loop must distinguish between them.

```text
Readable Stream

        │

        ├── Server Socket
        │
        │       ↓
        │
        │    Accept Client
        │
        └── Client Socket
                │
                ↓
             Read Data
```

---

## Write Events

A stream becomes writable when the operating system can accept more outgoing data.

```text
Write Buffer

↓

Socket Writable?

├── No
│
└── Wait

Yes

↓

Write Data
```

A connection should only be monitored for write events when it actually has data waiting.

```text
Write Buffer Empty

↓

No Write Watcher
```

```text
Write Buffer Has Data

↓

Register Write Watcher
```

---

# Connection Lifecycle

Every client has a `Connection`.

The lifecycle:

```text
ACCEPTED
    │
    ▼
OPEN
    │
    ├───────────────┐
    │               │
    │               ▼
    │          PROCESSING
    │               │
    │               ▼
    └────────────── OPEN
                    │
                    │ disconnect
                    ▼
                 CLOSING
                    │
                    ▼
                  CLOSED
```

A connection owns:

```text
Connection

├── Socket
│
├── Read Buffer
│
├── Write Buffer
│
├── Protocol Parser State
│
└── Connection State
```

---

# Read Path

When a client sends data:

```text
Client

↓

TCP

↓

Socket

↓

Event Loop

↓

Connection
```

The server reads bytes:

```text
socket

↓

Read Buffer
```

The important rule:

> **One TCP read is not equal to one command.**

Example:

The client sends:

```text
SET name Tanat
```

But TCP may deliver:

```text
Read #1

SET na
```

Then:

```text
Read #2

me Ta
```

Then:

```text
Read #3

nat
```

Therefore:

```text
Incoming Data

↓

Append To Read Buffer

↓

RESP Parser

↓

Complete Command?

├── No
│
└── Wait For More Data

Yes

↓

Dispatch Command
```

---

# RESP Parsing

RESP defines how clients and servers exchange structured messages.

Example:

```text
SET name Tanat
```

can be represented as:

```text
*3\r\n
$3\r\n
SET\r\n
$4\r\n
name\r\n
$5\r\n
Tanat\r\n
```

The parser transforms:

```text
Raw Bytes

↓

RESP Parser

↓

["SET", "name", "Tanat"]
```

The parser must be incremental.

Example:

```text
Buffer:

*3\r\n$3\r\nSE
```

This is incomplete.

The parser must not fail.

It must return:

```text
Need More Data
```

Later:

```text
More Bytes

↓

Append To Buffer

↓

Continue Parsing
```

---

# Command Lifecycle

Once a complete command exists:

```text
["SET", "name", "Tanat"]
```

the command lifecycle begins.

```text
Parsed RESP Array
        │
        ▼
Normalize Command Name
        │
        ▼
Validate Arguments
        │
        ▼
Command Dispatcher
        │
        ▼
Command Handler
        │
        ▼
Application Logic
        │
        ▼
Result
        │
        ▼
RESP Encoder
        │
        ▼
Write Buffer
```

Example:

```text
SET name Tanat

↓

SetCommand

↓

Database::set()

↓

OK

↓

+OK\r\n
```

---

# Database

The initial database is an in-memory key-value store.

Conceptually:

```text
Database

┌───────────────┬───────────────┐
│ Key           │ Value         │
├───────────────┼───────────────┤
│ name          │ Tanat         │
│ city          │ Kostanay      │
│ counter       │ 10            │
└───────────────┴───────────────┘
```

Internally:

```text
Database

├── Data
│
└── Expiration Metadata
```

Conceptually:

```php
$data = [
    'name' => 'Tanat',
];

$expiresAt = [
    'session' => 1234567890,
];
```

The database should not know about:

```text
TCP
RESP
Connections
```

Its responsibility is only:

```text
Store

Read

Update

Delete
```

---

# Write Path

After command execution, the server must send a response.

```text
Command Result

↓

RESP Encoder

↓

Serialized Response

↓

Connection Write Buffer

↓

Event Loop

↓

Socket

↓

Client
```

Example:

```text
Command:

PING

↓

Result:

PONG

↓

RESP:

+PONG\r\n

↓

Write Buffer
```

The response might not be written completely in one operation.

Therefore:

```text
Write Buffer

↓

Try Write

↓

All Written?

├── Yes
│
│   ↓
│
│ Remove Write Watcher
│
└── No
    │
    ↓
Wait For Next Writable Event
```

---

# Backpressure

Backpressure occurs when:

```text
Server Produces Data

>

Client Consumes Data
```

Example:

```text
Server

100 MB/s

↓

Write Buffer

↓

Slow Client

1 MB/s
```

Without protection:

```text
Write Buffer

↓

Unlimited Growth

↓

Out Of Memory 💀
```

The connection must have a limit.

```text
Write Buffer Size

↓

Below Limit?

├── Yes
│
│ Continue
│
└── No
    │
    ▼
Slow Client Protection
```

Possible strategy:

```text
Maximum Buffer Size

↓

Disconnect Client
```

The exact strategy can evolve, but the invariant remains:

> **A slow client must not be allowed to consume unlimited server memory.**

---

# TTL and Expiration

Keys can expire.

Example:

```text
SET session abc EX 60
```

The key lifecycle:

```text
SET
 │
 ▼
ACTIVE
 │
 ├──────────────► DEL
 │                   │
 │                   ▼
 │                 REMOVED
 │
 ▼

EXPIRES

 │
 ▼

EXPIRED

 │
 ▼

REMOVED
```

---

## Lazy Expiration

Check expiration when accessing a key.

```text
GET key

↓

Expiration Exists?

↓

Expired?

├── No
│
│   ↓
│
│ Return Value
│
└── Yes
    │
    ▼
Delete Key
```

---

## Active Expiration

The server periodically checks for expired keys.

```text
Timer

↓

Expiration Manager

↓

Find Expired Keys

↓

Delete
```

The two strategies solve different problems:

```text
Lazy Expiration

=

Cheap

but

Expired keys can remain in memory
```

```text
Active Expiration

=

Proactive cleanup

but

Consumes CPU
```

The project can demonstrate both approaches.

---

# Pub/Sub Architecture

Pub/Sub introduces message broadcasting.

```text
Publisher

    │

    ▼

Channel

    │

 ┌──┼──┐

 ▼  ▼  ▼

 A  B  C

Subscribers
```

The `PubSubManager` manages:

```text
Channel

↓

Subscribers

↓

Connections
```

Conceptually:

```text
news

↓

[
    Connection A,
    Connection B,
    Connection C,
]
```

When:

```text
PUBLISH news hello
```

the manager:

```text
Find Channel

↓

Find Subscribers

↓

Encode Message

↓

Append To Write Buffers
```

Important:

> Pub/Sub does not bypass backpressure.

A slow subscriber can still become a slow client.

Therefore:

```text
Pub/Sub

↓

Connection Write Buffer

↓

Backpressure Rules
```

---

# Persistence

The in-memory database loses data after a restart.

```text
Server Stops

↓

Memory Lost
```

Persistence introduces recovery.

---

## Snapshot

```text
Database

↓

Serialize

↓

Snapshot File
```

On startup:

```text
Snapshot File

↓

Deserialize

↓

Database
```

Lifecycle:

```text
RUNNING

↓

Save Snapshot

↓

STOP

↓

START

↓

Load Snapshot

↓

RUNNING
```

---

## Append Only Log

**Not implemented here** - this section is the alternative, kept for
contrast with the snapshot above. `SnapshotStore` is the only persistence
this server has; the README lists AOF among the things the project
deliberately does not become. What follows is the shape of the mechanism,
not a description of code in this repository.

Every mutation is recorded.

```text
SET name Tanat

↓

Append Command
```

Example:

```text
SET name Tanat
SET city Kostanay
DEL temporary
```

Recovery:

```text
Start Server

↓

Read Log

↓

Replay Commands

↓

Restore State
```

---

# Component Responsibilities

| Component                     | Responsibility                               |
| ----------------------------- | --------------------------------------------- |
| `RedisServer`                 | Server lifecycle and component wiring          |
| `ServerSocket`                | Accept new TCP connections                     |
| `EventLoop` / `SelectLoop`    | Wait for readable/writable streams and timers   |
| `Timer` / `TimerManager`      | Scheduled callbacks (TTL sweep, idle timeout)   |
| `ClientConnection`            | One client's socket, buffers, state, activity  |
| `ConnectionManager`           | Track active connections                       |
| `ReadBuffer` / `WriteBuffer`  | Hold bytes until a value is complete / sent    |
| `RespValue` / `RespParser`    | Convert bytes into structured RESP values      |
| `RespStreamReader`            | Pull every complete value out of a buffer      |
| `RespEncoder`                 | Convert results back into RESP                 |
| `Command`                     | A parsed command: name + arguments             |
| `CommandDispatcher`           | Route a Command to its handler                 |
| `CommandHandler` implementations | Execute one command's logic                 |
| `Store` / `InMemoryStore`     | Store key-value data, with TTL                 |
| `ChannelRegistry`             | Manage Pub/Sub channels and subscribers        |
| `TransactionManager`          | Per-connection MULTI/EXEC/DISCARD queue        |
| `SnapshotStore`               | Write the store to disk and read it back       |
| `ForkingSnapshotWorker`       | Take that snapshot in a child, off the loop    |
| `ServerMetrics`               | Count connections, commands, bytes, errors     |
| `EventLoopMetrics`            | Count the loop's own passes, busy/idle, lag    |
| `Clock` / `SystemClock`       | "What time is it", injectable for tests        |

---

# Important Invariants

The system should maintain several important rules.

---

## The Event Loop Must Not Block

Bad:

```text
Event Loop

↓

Long Blocking Operation

↓

All Clients Wait 💀
```

The Event Loop must remain responsive.

---

## TCP Reads Are Not Commands

Never assume:

```text
read()

=

one command
```

Always use a buffer.

---

## TCP Writes Can Be Partial

Never assume:

```text
write(response)

=

entire response written
```

Always use a write buffer.

---

## Slow Clients Must Be Limited

Never allow:

```text
Slow Client

↓

Unlimited Write Buffer

↓

Unlimited Memory Usage
```

---

## Database Must Not Know About Networking

Avoid:

```text
Database

↓

TCP Socket
```

The correct separation is:

```text
Networking

↓

Protocol

↓

Commands

↓

Database
```

---

## Commands Must Not Depend on Event Loop Details

A command should not care whether it was executed from:

```text
TCP

Unix Socket

Tests

CLI
```

The command layer should operate on application-level data.

---

# Typical Request Example

Let's trace:

```text
SET name Tanat
```

through the entire system.

```text
1. Client sends TCP bytes
        │
        ▼
2. Socket becomes readable
        │
        ▼
3. Event Loop detects readable socket
        │
        ▼
4. Connection reads bytes
        │
        ▼
5. Bytes are appended to Read Buffer
        │
        ▼
6. RESP Parser parses command
        │
        ▼
7. Command Dispatcher finds SET
        │
        ▼
8. SetCommand executes
        │
        ▼
9. Database stores value
        │
        ▼
10. Command returns OK
        │
        ▼
11. RESP Encoder creates +OK\r\n
        │
        ▼
12. Response enters Write Buffer
        │
        ▼
13. Socket becomes writable
        │
        ▼
14. Event Loop writes response
        │
        ▼
15. Client receives OK
```

---

# Mental Model

The entire server can be reduced to:

```text
                    EVENT LOOP
                         │
        ┌────────────────┼────────────────┐
        │                │                │
        ▼                ▼                ▼
   Connections        Timers          Server Socket
        │
        ▼
     Read Data
        │
        ▼
   Parse Protocol
        │
        ▼
 Execute Command
        │
        ▼
   Change State
        │
        ▼
 Encode Response
        │
        ▼
   Write Buffer
        │
        ▼
   Send Data
```

---

# Final Architecture Principle

The project should remain an:

> **Executable mental model of an event-driven database server.**

You should be able to open this repository and quickly answer:

```text
How does the server accept connections?
```

→ `ServerSocket`

```text
How does it handle many clients?
```

→ `EventLoop`

```text
How does TCP data become a command?
```

→ `RespParser`

```text
How does a command change the database?
```

→ `Command`

```text
How are responses returned?
```

→ `RespEncoder + WriteBuffer`

```text
What happens when a client is slow?
```

→ `Backpressure`

```text
How do keys expire?
```

→ `ExpirationManager`

---

# Summary

```text
PHP Mini Redis

=

Event Loop
+
TCP Connections
+
Read Buffers
+
RESP
+
Commands
+
In-Memory Database
+
Write Buffers
+
Backpressure
+
TTL
+
Pub/Sub
+
Persistence
```

The project is not intended to compete with Redis.

Its purpose is simpler:

> **Build a small server that exposes the important engineering ideas normally hidden inside a production system.**
