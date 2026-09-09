# PHP Mini Redis — How It Was Built

The plan this project is being built from: thirty phases, each with what it
had to achieve and how it was confirmed done. Phases 0-21 are finished; this
is their record, not work outstanding for them. Phases 22-30 are still
ahead and are written the way a phase looks before it exists - a goal and
a task list, no Definition of Done or Tests section yet.

Two things that live elsewhere:

- **Why decisions were made**, what was rejected, and the bugs or ordering
  problems that changed a design: [DECISIONS.md](DECISIONS.md).
- **What the finished system does not guarantee**:
  [FAILURE-MODEL.md](FAILURE-MODEL.md).

The file was called `PLAN.md` while every phase in it was still ahead of
the code. It is named for what most of it now contains: the phases already
built, plus the ones that aren't yet.

Each finished phase ends with a **Tests** section: the tests that hold that
phase's Definition of Done, named down to the individual test method where
one test answers for one line of the plan. The whole suite runs with
`make test`.

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
- [x] Phase 19 — Connection Timeout
- [x] Phase 20 — Pub/Sub
- [x] Phase 21 — Transactions
- [x] Phase 22 — Persistence
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

* [x] Configure PSR-4 autoloading
* [x] Configure PHPUnit
* [x] Configure PHPStan (level 6)
* [x] Configure Docker
* [x] Configure Makefile
* [x] Configure GitHub Actions

## Definition of Done

`composer install`, then tests and PHPStan both run clean.

## Tests

No tests of its own - this phase produced the harness every other phase is
asserted with: [phpunit.xml](../phpunit.xml) (a single `unit` suite over
`tests/`), [phpstan.neon](../phpstan.neon) (level 6 over `src` and `bin`),
run by `make test` / `make analyse`.

---

# Phase 1 — TCP Server

## Goal

Understand the lowest-level server architecture: `socket()` → `bind()` →
`listen()` → `accept()`.

## Tasks

* [x] Create a TCP socket
* [x] Bind to host and port
* [x] Listen for connections
* [x] Accept clients
* [x] Keep track of connected clients

## Definition of Done

A client can establish a TCP connection.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testItAcceptsAConnectingClient` (a real `stream_socket_client()` against
  a real `RedisServer`) and `testAcceptTimesOutWithoutAClient`.

---

# Phase 2 — Client Connection

## Goal

Represent every connected client explicitly: socket, read buffer, write
buffer, state, last activity.

## Tasks

* [x] Create `ClientConnection`
* [x] Store the socket
* [x] Store connection state
* [x] Store last activity
* [x] Track connections in a `ConnectionManager`

## Definition of Done

The server can manage multiple clients independently.

## Tests

- [tests/Connection/ClientConnectionTest.php](../tests/Connection/ClientConnectionTest.php) -
  `testNewConnectionStartsInNewState`, `testSetStateUpdatesStateAndLastActivity`,
  `testReadAndWriteBuffersAccumulateBytes`, `testCloseMarksConnectionAsClosed`.
- [tests/Connection/ConnectionManagerTest.php](../tests/Connection/ConnectionManagerTest.php) -
  `testAddAndCount`, `testRemove`,
  `testCloseAllClosesEveryConnectionAndEmptiesTheManager`.

---

# Phase 3 — Event Loop

## Goal

Replace blocking client handling with event-driven I/O, built on
`stream_select()`.

## Tasks

* [x] Create an `EventLoop` interface and a `SelectLoop` implementation
* [x] Support readable streams
* [x] Support writable streams

## Definition of Done

One process can manage multiple TCP clients without blocking on any one of
them.

## Tests

- [tests/EventLoop/SelectLoopTest.php](../tests/EventLoop/SelectLoopTest.php) -
  `testOnReadableFiresWhenDataArrives`, `testOnWritableFiresForAWritableStream`,
  `testRemoveReadableStopsDispatching`, `testStopEndsRun`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testRunAcceptsClientsThroughTheEventLoop`.

---

# Phase 4 — Non-Blocking Sockets

## Goal

Make the server genuinely event-driven: the event loop must never block
waiting for an individual client.

## Tasks

* [x] Switch every accepted client socket to non-blocking mode

## Definition of Done

Slow or silent clients do not block other clients.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testAcceptedSocketIsNonBlocking`: a read with nothing sent returns `''`
  immediately instead of stalling the test.

---

# Phase 5 — Read Buffer

## Goal

Handle TCP as a byte stream: a command is not guaranteed to arrive in one
read.

## Tasks

* [x] Create `ReadBuffer`
* [x] Read available bytes into it on every readable event
* [x] Detect disconnects via EOF

## Definition of Done

Partial TCP messages are handled correctly.

## Tests

- [tests/Connection/ReadBufferTest.php](../tests/Connection/ReadBufferTest.php) -
  `testAppendAccumulatesFragmentedWrites`, `testConsumeRemovesAPrefix`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testPartialCommandsAccumulateInTheReadBufferAcrossTicks`,
  `testClientDisconnectIsDetectedAndCleanedUp`.

---

# Phase 6 — Redis Protocol

## Goal

Implement a subset of RESP: simple strings, errors, integers, bulk strings,
arrays.

## Tasks

* [x] Create `RespValue`
* [x] Create `RespEncoder`
* [x] Create `RespParser` (complete-buffer parsing)

## Definition of Done

The server can communicate using RESP-compatible messages.

## Tests

- [tests/Protocol/RespEncoderTest.php](../tests/Protocol/RespEncoderTest.php) -
  one test per RESP type, plus the null bulk string/array forms
  (`testEncodesNullBulkStringAsNegativeOne`, `testEncodesNullArrayAsNegativeOne`).
- [tests/Protocol/RespParserTest.php](../tests/Protocol/RespParserTest.php) -
  the matching decode side, and `testRoundTripsThroughTheEncoder`.

---

# Phase 7 — RESP Parser

## Goal

Convert raw bytes into protocol values, supporting incomplete input: a
partial buffer must produce "need more data", not an error or a crash.

## Tasks

* [x] Return `null` from `RespParser::parse()` on an incomplete buffer
* [x] Add `RespStreamReader`, pulling every complete value out of a buffer
      while leaving trailing partial bytes untouched

## Definition of Done

The protocol parser can process fragmented network input.

## Tests

- [tests/Protocol/RespParserTest.php](../tests/Protocol/RespParserTest.php) -
  `testReturnsNullWhenTheLineIsIncomplete`,
  `testReturnsNullWhenABulkStringBodyIsIncomplete`,
  `testReturnsNullWhenAnArrayElementIsIncomplete`.
- [tests/Protocol/RespStreamReaderTest.php](../tests/Protocol/RespStreamReaderTest.php) -
  `testExtractsAValueOnceItArrivesAcrossMultipleReads`,
  `testLeavesATrailingPartialValueUnconsumed`.

---

# Phase 8 — Command Model

## Goal

Separate protocol parsing from command execution: a parsed RESP array of
bulk strings becomes a `Command` (name + arguments), not a raw value passed
straight to a handler.

## Tasks

* [x] Create `Command::fromRespValue()`
* [x] Normalize the command name to uppercase
* [x] Reject malformed command shapes with `CommandException`

## Definition of Done

Protocol logic and command logic are separated.

## Tests

- [tests/Command/CommandTest.php](../tests/Command/CommandTest.php) -
  `testBuildsACommandFromAnArrayOfBulkStrings`,
  `testNormalizesTheCommandNameToUppercase`, and the four
  `testRejects*` cases for malformed input.

---

# Phase 9 — In-Memory Store

## Goal

Create the database core: a keyed store that knows nothing about
TCP/RESP/connections.

## Tasks

* [x] Create the `Store` interface
* [x] Create `InMemoryStore`

## Definition of Done

The server has a basic in-memory database.

## Tests

- [tests/Storage/InMemoryStoreTest.php](../tests/Storage/InMemoryStoreTest.php) -
  `testSetAndGetRoundTrip`, `testSetOverwritesAnExistingValue`,
  `testDeleteRemovesAnExistingKeyAndReturnsTrue`.

---

# Phase 10 — Basic Commands

## Goal

Implement the first commands: `PING`, `SET`, `GET`, `DEL`, `EXISTS`, `INCR`.

## Tasks

* [x] `CommandHandler` interface
* [x] `PingCommand`, `SetCommand`, `GetCommand`, `DelCommand`,
      `ExistsCommand`, `IncrCommand`

## Definition of Done

The server behaves like a tiny Redis-like database.

## Tests

- One test file per handler under
  [tests/Command/Handler/](../tests/Command/Handler/):
  `PingCommandTest`, `SetCommandTest`, `GetCommandTest`, `DelCommandTest`,
  `ExistsCommandTest`, `IncrCommandTest` - each covering its happy path and
  its argument-count/type error path.

---

# Phase 11 — Command Dispatcher

## Goal

Route a `Command` to the handler registered for its name.

## Tasks

* [x] Create `CommandDispatcher`
* [x] `register()` / `dispatch()`
* [x] Reply with a RESP error for an unregistered command name

## Definition of Done

Adding a new command does not require modifying the dispatcher's callers.

## Tests

- [tests/Command/CommandDispatcherTest.php](../tests/Command/CommandDispatcherTest.php) -
  `testRoutesACommandToItsRegisteredHandler`, `testRoutingIsCaseInsensitive`,
  `testReturnsAnErrorForAnUnregisteredCommand`,
  `testDefaultHandlersCoverTheBasicCommands`.

---

# Phase 12 — Response Encoder

## Goal

Convert command results back to RESP and write them to the client - the
full loop: read → parse → dispatch → encode → write.

## Tasks

* [x] Wire `RespStreamReader` → `Command::fromRespValue()` →
      `CommandDispatcher::dispatch()` → `RespEncoder::encode()` into
      `RedisServer`
* [x] Malformed protocol input disconnects only the offending client

## Definition of Done

Clients receive valid RESP responses over a real socket.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testExecutesCommandsSentByARealClientAndRepliesWithResp` (PING/SET/GET
  over a real connection) and `testMalformedInputDisconnectsOnlyThatClient`.

---

# Phase 13 — Write Buffer

## Goal

Correctly handle partial socket writes: a response can be larger than what
`fwrite()` accepts in one call.

## Tasks

* [x] Create `WriteBuffer`
* [x] Queue the encoded response instead of a single blind `fwrite()`
* [x] Watch the socket for writability only while there is something left
      to send

## Definition of Done

Large responses and slow-to-drain clients are handled correctly instead of
silently truncated or blocking the event loop.

## Tests

- [tests/Connection/WriteBufferTest.php](../tests/Connection/WriteBufferTest.php) -
  `testConsumeRemovesAPrefixAfterAPartialWrite`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testAPartialWriteIsQueuedInTheWriteBufferInsteadOfBlocking`: an 8 MB
  response does not fit in one `fwrite()`, proven by inspecting the queued
  remainder rather than by timing.

---

# Phase 14 — Multiple Commands

## Goal

Allow multiple commands to arrive in one TCP read, with any trailing
partial command left for the next one.

## Tasks

* [x] `RespStreamReader::readAll()` already loops until the buffer holds no
      complete value

## Definition of Done

The server can process multiple commands from one read, leaving a partial
one queued.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testMultipleCommandsPlusATrailingPartialOneAreHandledCorrectly`: two
  complete `PING`s plus the start of a third in one write, the two replies
  arrive immediately and the partial remainder stays in the read buffer.

---

# Phase 15 — Pipelining

## Goal

A client can send several commands without waiting for each reply, and
later commands in the same pipeline must observe earlier ones' effects.

## Tasks

* [x] Confirm command effects apply in arrival order within one buffer

## Definition of Done

The server supports pipelined commands.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testPipelinedCommandsAreAppliedInOrderWithoutWaitingForEachReply`: `SET`,
  `INCR`, `GET` on the same key, sent back to back, in one read.

---

# Phase 16 — TTL

## Goal

`SET key value EX seconds` - the key expires after that many seconds.

## Tasks

* [x] `StoredValue` (value + optional expiry timestamp)
* [x] `Store::set()` gains an optional `ttlSeconds`
* [x] `SetCommand` parses `EX seconds`
* [x] Lazy expiration: an expired entry is removed the moment it is
      accessed

## Definition of Done

Keys can expire automatically.

## Tests

- [tests/Storage/StoredValueTest.php](../tests/Storage/StoredValueTest.php) -
  `testAValueExpiresOnceNowReachesItsExpiryTimestamp`.
- [tests/Storage/InMemoryStoreTest.php](../tests/Storage/InMemoryStoreTest.php) -
  `testAValueWithATtlIsAvailableBeforeItExpires`,
  `testAValueWithATtlIsGoneOnceItExpires`,
  `testOverwritingAKeyReplacesItsPreviousTtl` - all driven by an injected
  clock closure, not real sleeps.
- [tests/Command/Handler/SetCommandTest.php](../tests/Command/Handler/SetCommandTest.php) -
  `testStoresTheValueWithATtlWhenGivenEx`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testAKeySetWithExExpiresAfterItsTtl`, over a real connection.

---

# Phase 17 — Expiration Strategy

## Goal

Compare lazy expiration (Phase 16) against active expiration: a timer
sweeps expired keys even if nothing ever reads them.

**Built after Phase 18**, which it depends on - see
[DECISIONS.md](DECISIONS.md#phase-18-before-phase-17).

## Tasks

* [x] `Store::sweepExpired()`
* [x] `RedisServer` runs it on a repeating timer

## Definition of Done

The project demonstrates both expiration strategies, and a key can be
proven to disappear without ever being read.

## Tests

- [tests/Storage/InMemoryStoreTest.php](../tests/Storage/InMemoryStoreTest.php) -
  `testSweepExpiredRemovesOnlyExpiredEntriesAndReportsHowMany`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testExpiredKeysAreActivelyRemovedByTheSweepTimerWithoutBeingRead`: reads
  the store's raw entries via `ReflectionProperty` specifically to avoid
  triggering Phase 16's own lazy expiration and calling the wrong mechanism
  proven.

---

# Phase 18 — Event Loop Timers

## Goal

Extend the event loop with timers, so time - not just I/O - can wake it up.

**Built before Phase 17**: active expiration needs a timer to sweep on, and
`stream_select()` alone has nothing to wake it between socket events. See
[DECISIONS.md](DECISIONS.md#phase-18-before-phase-17).

## Tasks

* [x] `Timer` / `TimerManager`
* [x] `EventLoop::every()` / `EventLoop::after()`
* [x] `SelectLoop::tick()` waits no longer than the next timer's due time,
      and sleeps for it even with zero registered streams

## Definition of Done

Time becomes a first-class event loop event.

## Tests

- [tests/EventLoop/TimerManagerTest.php](../tests/EventLoop/TimerManagerTest.php) -
  `testAfterFiresOnceOnceItsDelayHasPassed`, `testEveryFiresRepeatedly`,
  `testCancelStopsARepeatingTimer`, `testNextDueInReturnsTheSoonestTimer`.
- [tests/EventLoop/SelectLoopTest.php](../tests/EventLoop/SelectLoopTest.php) -
  `testEveryFiresARepeatingTimerEvenWithoutAnyStreams`,
  `testATimerFiresAlongsideStreamActivity`.

---

# Phase 19 — Connection Timeout

## Goal

Handle idle clients: a connection with no activity for longer than a
configured timeout is closed.

## Tasks

* [x] `RedisServer` accepts an optional `idleTimeoutSeconds`
* [x] A repeating timer closes connections past it, leaving active ones
      alone

## Definition of Done

Abandoned connections do not remain forever, and active ones are never
mistaken for idle ones.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testIdleConnectionsAreClosedAfterTheTimeoutButActiveOnesAreNot`: an idle
  client is closed while a second, active client (touched moments before
  the same check) is not - both compared within one `tick()` so the
  assertion cannot be thrown off by unrelated test overhead.

---

# Phase 20 — Pub/Sub

## Goal

`SUBSCRIBE channel` / `PUBLISH channel message` - server-side event
distribution to every subscriber of a channel.

## Tasks

* [x] `ChannelRegistry`
* [x] `SubscribeCommand` / `PublishCommand`
* [x] `CommandHandler` gains the issuing `ClientConnection` as a third
      parameter - see
      [DECISIONS.md](DECISIONS.md#commandhandler-gained-a-connection-parameter)
* [x] A disconnecting client is unsubscribed from every channel

## Definition of Done

A subscriber receives a message published by a different connection, and
disconnecting removes it from every channel it had joined.

## Tests

- [tests/PubSub/ChannelRegistryTest.php](../tests/PubSub/ChannelRegistryTest.php) -
  `testSubscribersReturnsEveryoneSubscribedToAChannel`,
  `testUnsubscribeAllRemovesAConnectionFromEveryChannel`.
- [tests/Command/Handler/SubscribeCommandTest.php](../tests/Command/Handler/SubscribeCommandTest.php)
  and [PublishCommandTest.php](../tests/Command/Handler/PublishCommandTest.php) -
  `testDeliversTheMessageToEverySubscriberAndReturnsHowMany`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testASubscriberReceivesAPublishedMessage` (two real connections) and
  `testDisconnectingASubscriberRemovesItFromItsChannels`.

---

# Phase 21 — Transactions

## Goal

`MULTI` / `EXEC` / `DISCARD` - grouped command execution.

## Tasks

* [x] `TransactionManager`, keyed per connection
* [x] `MultiCommand` begins a transaction (rejects nesting)
* [x] Non-`MULTI`/`EXEC`/`DISCARD` commands are queued instead of run while
      a transaction is active, replying `+QUEUED`
* [x] `ExecCommand` runs the queue in order through the same
      `CommandDispatcher`, replying with an array of results
* [x] `DiscardCommand` cancels the queue
* [x] A disconnecting client's open transaction is discarded

## Definition of Done

Command batching and per-connection transaction state both work over a
real connection.

## Tests

- [tests/Transaction/TransactionManagerTest.php](../tests/Transaction/TransactionManagerTest.php) -
  `testQueueAccumulatesCommandsInOrder`, `testDrainEndsTheTransaction`,
  `testEachConnectionHasItsOwnTransaction`.
- [tests/Command/Handler/MultiCommandTest.php](../tests/Command/Handler/MultiCommandTest.php),
  [ExecCommandTest.php](../tests/Command/Handler/ExecCommandTest.php),
  [DiscardCommandTest.php](../tests/Command/Handler/DiscardCommandTest.php).
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testMultiQueuesCommandsAndExecRunsThemInOrder`,
  `testDiscardCancelsAQueuedTransaction`.

---

# Phase 22 — Persistence

## Goal

Explore persistence as an optional experiment. The database stays
in-memory by default; optionally, a snapshot can be written and reloaded
on startup.

## Tasks

* [x] `InMemoryStore::snapshot()` / `restore()` (value + TTL, per key)
* [x] `SnapshotStore` writes to a temp file and renames into place (a
      crash mid-write cannot leave a half-written snapshot)
* [x] `RedisServer` loads a snapshot at construction time if a
      `snapshotPath` is configured, and exposes `saveSnapshot()`
* [x] An optional repeating timer calls `saveSnapshot()` automatically
* [x] Keep persistence optional - both parameters default to `null`/off

## Definition of Done

A key set by one `RedisServer` instance survives into a second instance
constructed with the same snapshot path afterward.

## Tests

- [tests/Storage/InMemoryStoreTest.php](../tests/Storage/InMemoryStoreTest.php) -
  `testSnapshotAndRestoreRoundTripValuesAndTtls`,
  `testRestoreReplacesWhateverWasThereBefore`.
- [tests/Persistence/SnapshotStoreTest.php](../tests/Persistence/SnapshotStoreTest.php) -
  `testSavedValuesAreRestoredIntoAnotherStore`, `testATtlSurvivesTheRoundTrip`,
  `testSaveOverwritesAPreviousSnapshot`,
  `testLoadIntoAFreshStoreIsANoOpWhenNoSnapshotExists`.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testDataSavedByOneServerIsLoadedByTheNext` (the Definition of Done,
  literally) and `testPeriodicSnapshotsSaveWithoutBeingAskedExplicitly`.

---

# Phase 23 — Graceful Shutdown

## Goal

Stop the server on `SIGTERM` without abruptly dropping active clients:
stop accepting, finish in-flight responses, then exit.

## Tasks

* [ ] Handle `SIGTERM`
* [ ] Stop accepting new connections
* [ ] Let existing connections finish their pending responses
* [ ] Exit once drained (or after a safety timeout)

---

# Phase 24 — Error Handling

## Goal

Make protocol and command errors explicit RESP errors (`-ERR ...`) instead
of the interim disconnect-on-malformed-input behavior from Phase 12.

## Tasks

* [ ] Unknown command / wrong number of arguments / invalid integer as
      proper RESP errors where possible
* [ ] Decide, and document in DECISIONS.md, which protocol errors still
      have to disconnect (a genuinely desynced stream cannot recover)

---

# Phase 25 — Limits

## Goal

Protect the server from pathological input: maximum command size, maximum
value size, maximum argument count, maximum connections.

## Tasks

* [ ] Enforce a maximum read buffer size before parsing
* [ ] Enforce a maximum number of arguments per command
* [ ] Enforce a maximum connection count

---

# Phase 26 — Backpressure

## Goal

A client that reads slowly must not let its write buffer grow without
bound.

## Tasks

* [ ] Cap the write buffer size
* [ ] Pause reading from a client whose write buffer is over the cap
* [ ] Resume once it drains

---

# Phase 27 — Metrics

## Goal

Make server behavior observable: connections, commands processed, bytes
in/out, expired keys.

## Tasks

* [ ] `MetricsCollector`
* [ ] An `INFO`-like command, or a signal-driven dump (see the sibling
      `php-worker-pool` project's `SIGUSR1` convention)

---

# Phase 28 — Tests

## Goal

Tests at multiple levels: unit, integration (a real TCP client against a
real server), and failure scenarios.

## Status

Largely already true as a consequence of how phases 1-21 were built rather
than a separate pass: every phase above already has both handler-level unit
tests and `RedisServerTest` integration tests over a real socket. What
remains here specifically: an explicit pass over failure scenarios not yet
covered (large responses beyond the write-buffer test's own scope,
connection timeout combined with pub/sub, and whatever Phase 25's limits
introduce).

---

# Phase 29 — Benchmarks

## Goal

Measure the server rather than guessing: requests/second, latency
(p50/p95/p99), memory, under 1/10/100/1000 concurrent clients.

## Tasks

* [ ] A benchmark script (`bin/bench.php` or `redis-benchmark` against it,
      since the protocol is RESP-compatible for the commands implemented)
* [ ] Record results in `docs/BENCHMARKS.md`

---

# Phase 30 — Experiments

## Goal

Small, standalone scripts under `examples/`, each answering one question
(a slow client, TTL, pub/sub, pipelining).

## Tasks

* [ ] `examples/slow-client.php`
* [ ] `examples/ttl.php`
* [ ] `examples/pubsub.php`
* [ ] `examples/pipelining.php`

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
