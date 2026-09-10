# PHP Mini Redis — How It Was Built

The plan this project was built from: thirty-nine phases, Phase 0 through
Phase 38, each with what it had to achieve and how it was confirmed done.
Every one of them is finished except Phase 36, the epoll reactor, which is
deferred and says why - this is kept as the record of the order things
were built in and what each step was actually for, not as work
outstanding.

Two things that live elsewhere:

- **Why decisions were made**, what was rejected, and the bugs or ordering
  problems that changed a design: [DECISIONS.md](DECISIONS.md).
- **What the finished system does not guarantee**:
  [FAILURE-MODEL.md](FAILURE-MODEL.md).

The file was called `PLAN.md` while it still was one. Everything in it is
done, so it is named for what it now contains: the phases.

Each phase ends with a **Tests** section: the tests that hold that
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
- [x] Phase 23 — Graceful Shutdown
- [x] Phase 24 — Error Handling
- [x] Phase 25 — Limits
- [x] Phase 26 — Backpressure
- [x] Phase 27 — Metrics
- [x] Phase 28 — Tests
- [x] Phase 29 — Benchmarks
- [x] Phase 30 — Experiments

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
* [x] `stop()` writes one final snapshot, synchronously, so a shutdown the
      server sees (including `SIGTERM` through Phase 23's
      `requestShutdown()`) keeps what was written since the last one
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
  literally), `testPeriodicSnapshotsSaveWithoutBeingAskedExplicitly` and
  `testAGracefulShutdownSnapshotsWhatWasWrittenSinceTheLastOne`.

---

# Phase 23 — Graceful Shutdown

## Goal

Stop the server on `SIGTERM` without abruptly dropping active clients:
stop accepting, finish in-flight responses, then exit.

## Tasks

* [x] `RedisServer::requestShutdown()`: idempotent, removes the listening
      socket's readable registration (no new connections accepted through
      the event loop) but leaves existing connections' read/write
      listeners untouched
* [x] A repeating checker (self-cancelling once done) calls `stop()` once
      either every connection is gone or `shutdownGraceSeconds` has
      elapsed, whichever comes first
* [x] `SIGTERM`/`SIGINT` wired to `requestShutdown()` via
      `pcntl_async_signals()`, installed when `run()` starts

## Definition of Done

A real `SIGTERM` to the running process closes it cleanly (no orphaned
process, no hung shutdown), and a connection already accepted when
shutdown begins is not simply cut off - only new ones are refused.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testRequestShutdownStopsAcceptingNewConnectionsButDrainsExistingOnes`
  (a second client, connected before shutdown was requested, is never
  accepted through the loop afterward), `testRequestShutdownIsIdempotent`,
  and `testSigtermTriggersAGracefulShutdown` - a real `SIGTERM` delivered
  to the test process itself via `posix_kill()` from a timer callback,
  proving the signal wiring (not just `requestShutdown()` called
  directly) actually works.

---

# Phase 24 — Error Handling

## Goal

Make protocol and command errors explicit RESP errors (`-ERR ...`) instead
of the interim disconnect-on-malformed-input behavior from Phase 12.

## Tasks

* [x] Unknown command name → `-ERR unknown command '...'`
      (`CommandDispatcher`, Phase 11)
* [x] Wrong number of arguments → `-ERR wrong number of arguments for
      '...' command` (every handler, Phase 10/16/20/21)
* [x] Non-integer `INCR` target → `-ERR value is not an integer or out of
      range` (`IncrCommand`, Phase 10)
* [x] Malformed command shape (e.g. a non-array RESP value where a
      command is expected) → `-ERR ...` (`Command::fromRespValue()` +
      `CommandException`, caught in `RedisServer::executeValue()`)
* [x] A malformed protocol stream (`ProtocolException`) now writes a
      `-ERR Protocol error: ...` reply before disconnecting, instead of
      disconnecting silently - see
      [DECISIONS.md](DECISIONS.md#malformed-input-still-disconnects-but-with-a-resp-error-first)
      for why it still has to disconnect

## Definition of Done

Every category of invalid client input gets an explicit RESP error; only a
byte stream that is no longer parseable as RESP at all still ends the
connection, and even that case is told why before it closes.

## Tests

- [tests/Command/CommandDispatcherTest.php](../tests/Command/CommandDispatcherTest.php) -
  `testReturnsAnErrorForAnUnregisteredCommand`.
- Every handler test's `testRejects*`/`testRejectsTheWrongNumberOfArguments`
  case, e.g.
  [IncrCommandTest::testRejectsANonIntegerValue](../tests/Command/Handler/IncrCommandTest.php).
- [tests/Command/CommandTest.php](../tests/Command/CommandTest.php) - the
  `testRejects*` cases for a malformed command shape.
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testMalformedInputGetsARespErrorBeforeOnlyThatClientDisconnects`.

---

# Phase 25 — Limits

## Goal

Protect the server from pathological input: maximum command size, maximum
number of arguments, maximum connections.

## Tasks

* [x] `maxReadBufferBytes` (default 512 KiB): a connection's ReadBuffer
      left over after every complete value has been consumed is checked
      against this after each read - a value that can never complete
      (e.g. a simple string whose terminating CRLF never arrives) gets a
      RESP protocol error and disconnects, instead of growing forever
* [x] The same number bounds the largest value a command may carry: the
      parser's `maxBulkStringBytes` is derived from it (Phase 31), so a
      bulk string too big to ever fit in the buffer that must hold it is
      refused on its declared length, before its body is sent, rather
      than after half a megabyte of it has been read
* [x] `maxArgumentsPerCommand` (default 1024): a command with more
      elements than this gets `-ERR too many arguments` - a command-level
      error like Phase 24's others, so the connection itself survives
* [x] Per-command metrics are keyed only by names the server knows;
      unknown ones are counted as a single `unknown_commands` total, so a
      client cannot grow the counter map one made-up name at a time
* [x] `maxConnections` (null = unbounded): a connection accepted past the
      limit gets `-ERR max number of clients reached` and is closed
      immediately, before it is ever tracked or counted

## Definition of Done

None of the three limits affect a well-behaved client at all, and each one
independently protects against the specific pathological input it names
without tearing down anything it doesn't have to (the argument-count limit
in particular does not disconnect - only the buffer-size and
connection-count limits do, since only those two are about bytes/sockets
rather than a single malformed command).

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testAConnectionOverTheLimitIsRejectedWithARespErrorAndClosed`,
  `testACommandWithTooManyArgumentsIsRejectedWithoutDisconnecting` (the
  connection keeps working afterward - a following command still gets a
  normal reply), `testAnOversizedReadBufferGetsARespErrorAndIsDisconnected`.

---

# Phase 26 — Backpressure

## Goal

A client that reads slowly must not let its write buffer grow without
bound.

## Tasks

* [x] `maxWriteBufferBytes` (default 16 MiB) caps how much a connection's
      WriteBuffer may hold
* [x] A connection over the cap stops being read from
      (`pauseReadingIfWriteBufferTooLarge()`) - its socket stays open and
      whatever it already queued keeps trying to flush, but nothing new is
      read from it
* [x] Reading resumes once the WriteBuffer drains back below the low
      watermark (`resumeReadingIfPaused()`, called from
      `flushWriteBuffer()`'s low-watermark branch, which subsumes the
      original empty-buffer branch; refined in Phase 32)
* [x] Applies uniformly to every source of a queued response - a normal
      command reply, a too-many-arguments error, and a Pub/Sub message
      delivered to a subscriber - via one shared `queueForWrite()`

## Definition of Done

A client that never reads its socket gets paused instead of letting the
server buffer its responses without bound, and resumes automatically once
its backlog is gone - proven against the connection's actual queued byte
count and the store's own state, not by timing.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testASlowReaderIsPausedThenResumedOnceItsWriteBufferDrains`: an 8 MB
  response overflows a 1000-byte limit, a command sent while paused is
  never read into the connection's buffer at all, and once the backlog is
  gone (verified via the connection's own `WriteBuffer`, not by re-reading
  the noisy socket stream) the paused command is read and its effect shows
  up in the store.

---

# Phase 27 — Metrics

## Goal

Make server behavior observable: connections, commands processed, bytes
in/out, expired keys.

## Tasks

* [x] `ServerMetrics`: connections total, commands processed (overall and
      per command name), bytes read/written, errors, expired keys
* [x] `RedisServer` records into it at every point that already existed
      for another reason - accepting a connection, reading a chunk,
      dispatching a command, an error reply, a partial write, the
      expiration sweep timer - rather than a second pass over the code
* [x] `INFO` command: one bulk string of `key:value` lines, matching real
      Redis's own convention for the reply shape

## Definition of Done

A running server's traffic is visible from the outside, through the
protocol itself (`INFO`) rather than a log file or a second channel.

## Tests

- [tests/Metrics/ServerMetricsTest.php](../tests/Metrics/ServerMetricsTest.php) -
  every counter, independently.
- [tests/Command/Handler/InfoCommandTest.php](../tests/Command/Handler/InfoCommandTest.php) -
  the formatted reply, and that `connected_clients` reflects the
  `ConnectionManager` rather than the metrics object (they track different
  things: connections *ever* accepted vs. connections *currently* open).
- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testMetricsTrackRealTrafficAndInfoReportsThem`: real commands over a
  real connection, then `INFO` itself confirms its own call was counted.

---

# Phase 28 — Tests

## Goal

Tests at multiple levels: unit, integration (a real TCP client against a
real server), and failure scenarios.

## Status

Largely already true as a consequence of how phases 0-27 were built rather
than a separate pass: every phase above already has both handler-level unit
tests and `RedisServerTest` integration tests over a real socket, and each
of the plan's own named failure scenarios already has coverage somewhere -
partial requests (Phase 5/7), invalid RESP (Phase 24), unknown commands
(Phase 11), a slow/absent reader (Phase 26), a large response (Phase 13),
an expired key (Phase 16/17), a connection timeout (Phase 19).

What this phase actually added: the one *combination* those per-phase
tests never exercised together - a subscriber that goes idle. Each
mechanism (Phase 19's timeout, Phase 20's Pub/Sub) was tested on its own,
but nothing confirmed an idle-timeout disconnect cleans up a subscription
the same way a client-initiated one does.

## Definition of Done

`make test` and `make analyse` both pass, and every failure scenario the
plan names has a test that exercises it specifically, including the one
combination above that no single phase's own tests would have caught.

## Tests

- [tests/Server/RedisServerTest.php](../tests/Server/RedisServerTest.php) -
  `testAnIdleTimedOutSubscriberIsUnsubscribedFromItsChannels`: a subscriber
  goes silent past the idle timeout, and a subsequent `PUBLISH` to its
  channel reaches zero recipients, the same as a clean disconnect would
  produce.

---

# Phase 29 — Benchmarks

## Goal

Measure the server rather than guessing: requests/second, latency
(p50/p95/p99), memory, under 1/10/100/1000 concurrent clients.

## Tasks

* [x] `bin/bench.php`: forks `--clients` real child processes
      (`pcntl_fork()`), each running `--requests` synchronous
      request/reply round trips against its own connection, aggregating
      every latency sample once every child exits
* [x] `make bench ARGS="--clients=N --requests=M --command=PING"`
* [x] Results recorded in [docs/BENCHMARKS.md](BENCHMARKS.md), measured
      inside this project's own container - not fabricated numbers

## Definition of Done

Real, reproducible throughput and latency numbers exist for `PING`, `SET`,
`GET` and `INCR` at more than one concurrency level, with an honest
account of what they do and do not show (see BENCHMARKS.md's own "What
this does not measure").

## Tests

None of its own - `bin/bench.php` is a measurement tool exercised by
running it, the same way `bin/client.php` is; both are covered
functionally by `RedisServerTest`'s exercise of the same protocol paths.

---

# Phase 30 — Experiments

## Goal

Small, standalone scripts under `examples/`, each answering one question
(a slow client, TTL, pub/sub, pipelining).

## Tasks

* [x] `examples/bootstrap.php` - the connect/send/receive boilerplate
      shared by every script below, so each one is only about the one
      thing it demonstrates
* [x] `examples/ttl.php` - a key set with `EX` outlives being read once,
      then disappears once its TTL passes
* [x] `examples/pipelining.php` - the same 500 `PING`s, timed one at a
      time vs. pipelined
* [x] `examples/pubsub.php` - a forked subscriber (`pcntl_fork()`, since a
      single-threaded script can't subscribe and publish at once) actually
      receives what a separate connection publishes
* [x] `examples/slow-client.php` - a connection that pipelines many GETs
      of a sizeable value without ever reading a reply gets paused, while
      a second, ordinary connection keeps getting fast replies throughout

## Definition of Done

Each script runs standalone against a real `make run-server` and answers
its one question by what it prints, not by reading the source.

## Tests

None of their own, deliberately - each script's entire point is being run
and read, not asserted on programmatically; the mechanism each one
demonstrates already has its own test from the phase that introduced it
(Phase 16/17 for `ttl.php`, Phase 15 for `pipelining.php`, Phase 20 for
`pubsub.php`, Phase 26 for `slow-client.php`).

---

# Phase 31 — Strict RESP

## Goal

The parser was lenient in ways that silently corrupt data or invite
memory abuse: a bulk string's trailing bytes were assumed to be CRLF
without actually being checked, `:abc` and `$abc` were cast to integers
(and came out as `0`), lengths below `-1` were not validated, and the
limits lived *above* the parser — a `*1000000` header was turned into a
million-element array before the command layer could reject it.

Make parsing strict, and move the protocol-shaped limits into the parser
itself.

## Tasks

* [x] Bulk strings: the two bytes after the body must actually be `\r\n`
      — `$5\r\nhelloXX` is a protocol error, not `hello` (it was accepted
      before, since only the presence of two bytes was checked)
* [x] Integers (`:`): strict `-?(0|[1-9][0-9]*)` grammar validated by
      `filter_var(..., FILTER_VALIDATE_INT)` — `:abc`, `:1.5`, `: 1` and
      a 64-bit overflowing literal are all protocol errors instead of
      silent `0`
* [x] Lengths (`$`/`*`): only `-1` (null) or a non-negative count; `$-2`
      and `*-2` are now protocol errors instead of being treated as a
      single element
* [x] Parser-level limits, enforced on the declared header before the
      body/elements are looked at:
      - `maxBulkStringBytes` (the parser's own default is 1 MiB;
        `RedisServer` derives it from `maxReadBufferBytes` instead, see
        Phase 25) — a `$999999999...` header errors up front instead of
        making the parser scan for a huge body
      - `maxArrayElements` (1 000 000) — a structural backstop so an
        absurdly large array is never built
      - `maxNestingDepth` (32) — nested arrays can no longer exhaust the
        stack
* [x] `RespParserFuzzTest` — a table-driven sweep over truncations,
      malformed lengths, missing/wrong terminators, and out-of-limit
      input, asserting the three-way contract (more bytes / value /
      protocol error) across ~40 cases

## Definition of Done

Every value that is *present* but not valid RESP raises a
`ProtocolException`; nothing is silently corrupted by an `(int)` cast, and
no amount of hostile header can make the parser grow memory before the
command layer sees the value.

## Tests

* `RespParserFuzzTest::testNeedsMoreBytes`* — incomplete input still
  returns `null`
* `RespParserFuzzTest::testMalformedInputRaisesAProtocolError`* — the
  strictness rows (wrong body terminator, non-integer lengths, lengths
  below `-1`, oversized/nested past limits)
* `RespParserFuzzTest::testValidInputStillParses`* — the valid edge cases
  (`$0\r\n\r\n`, `*-1`, exact nesting limit) keep working
* the existing `RespParserTest` and `RespStreamReaderTest` suites still
  pass unchanged, which is what pins the strictness to the *grammar*
  rather than changing valid inputs' behavior

---

# Phase 32 — High/Low Watermarks

## Goal

Phase 26 paused reading at a single threshold and only resumed once the
backlog had fully drained. A slow reader that kept trickling progress
would therefore oscillate at the boundary: its buffer fills past the cap
and it is paused, it drains a little and is resumed, it fills again and
is paused anew. Give the backpressure hysteresis - a high watermark where
reading pauses and a lower one where it resumes - so a connection that
has caught up enough is let back in without waiting for a complete drain.

## Tasks

* [x] `lowWriteBufferBytes` - reading resumes once the queued backlog
      drains to this level, *while bytes are still queued*, instead of
      waiting for an empty buffer
* [x] Default is a quarter of the pause level, so the out-of-the-box
      behavior is "pause at 16 MiB, resume at 4 MiB"
* [x] The resumed connection keeps its writable listener until the
      buffer fully empties - the tail of the backlog still flushes, and
      a fresh burst of responses can re-pause it by crossing the high
      watermark again
* [x] `hardSubscriberWriteBufferBytes` (four times the pause level by
      default) - a watermark neither of the two above can serve: Pub/Sub
      bytes come from the publisher, so pausing a subscriber's own reads
      throttles nothing, and past this line the subscriber is dropped
      rather than queued for

## Definition of Done

A connection paused by a slow reader resumes as soon as its backlog
crosses below the low watermark, without the server having to wait for
the buffer to hit zero - observable as its previously-paused commands
being processed while response bytes are still queued.

## Tests

* `RedisServerTest::testASlowReaderResumesWhenItsWriteBufferHitsTheLowWatermark` -
  a 20 MB response pauses a 16 MiB-cap connection; the backlog is drained
  to 6 MiB, the client catches up in megabyte chunks until the queue
  crosses the 4 MiB resumption level (still holding bytes), and the
  command sent while paused is then processed - all while the WriteBuffer
  is never empty
* `RedisServerTest::testASlowReaderIsPausedThenResumedOnceItsWriteBufferDrains`
  (Phase 26) still passes unchanged - an empty-buffer resume remains a
  special case of the low-watermark rule
* `RedisServerTest::testASubscriberThatNeverReadsIsDroppedInsteadOfQueuedForever` -
  a subscriber that reads nothing while a publisher keeps publishing is
  disconnected and unsubscribed once its backlog passes the hard limit,
  rather than being paused (which would throttle nothing) and queued for
  until the server runs out of memory

---

# Phase 33 — Expiration Heap

## Goal

Phase 17's active-expiration sweep walked *every* key on each interval to
find the ones whose TTL had passed - O(N) per sweep even when almost
nothing was due. A store holding hundreds of thousands of long-lived keys
paid that cost unconditionally on every tick. Track the due keys in a
min-heap instead, so a sweep only touches the entries that are actually
due.

## Tasks

* [x] `InMemoryStore` keeps an `SplMinHeap` of `[expiresAt, key]`, so the
      earliest-due entry is always at the top
* [x] `set()` with a TTL pushes the key; `set()` without a TTL does not
* [x] `sweepExpired()` pops due entries and removes a key only if the
      key's *current* expiry is still the one the entry was queued with -
      which is the entire staleness test: an overwritten key is never
      killed by its old TTL, and a deleted key's leftover entry finds
      nothing to remove
* [x] Nothing else is tracked per key. Stale heap entries are left to come
      due and be discarded, so the bookkeeping is bounded by the keys that
      are live plus the expiries not yet reached, never by every key ever
      written
* [x] `sweepExpired()` stops at the first non-due entry, leaving the whole
      scan O(due) instead of O(all keys)
* [x] Lazy expiration in `entryOrNull()` stays untouched; a key removed
      lazily leaves a stale heap entry that the version/`expiresAt` check
      safely skips on the next sweep
* [x] `restore()` rebuilds both the data map and the heap, and finally
      implements the documented-but-missing rule that entries already
      expired at restore time are dropped (the review's "variant B" doc
      fix)

## Definition of Done

Sweeping is proportional to how much is actually expired, not to how much
is stored; the behavior of every expiration path (sweep, lazy, overwrite,
delete, restore) is identical to before and pinned by tests.

## Tests

* `InMemoryStoreTest::testSweepExpiredRemovesOnlyExpiredEntriesAndReportsHowMany`
  (Phase 17) - unchanged
* `InMemoryStoreTest::testOverwritingAnExpiringKeyDoesNotLetItsOldTtlKillTheNewValue`
  - a key overwritten without a TTL survives its old due time and is not
  swept
* `InMemoryStoreTest::testSweepSkipsAStaleHeapEntryAfterTheKeyWasDeleted`
  - deleting a key leaves its heap entry inert
* `InMemoryStoreTest::testAKeyWrittenAgainAfterDeletionSurvivesItsPreviousTtl`
  - a key's second life is not killed by the first life's heap entry
* `InMemoryStoreTest::testKeysThatCameAndWentLeaveNoBookkeepingBehind`
  - a hundred keys through all three ways of leaving, and the heap is
  empty afterwards
* `InMemoryStoreTest::testRestoreDropsEntriesAlreadyExpiredAtRestoreTime`
  - restore applies the documented expired-filter

---

# Phase 34 — Event Loop Metrics

## Goal

The loop's own health was invisible: nothing in INFO said how many
wait/dispatch passes had happened, how long the loop spent busy dispatching
callbacks versus idle in `select()`, or how far a single slow callback could
push the loop away from servicing sockets and timers. That last number is
the practical measure of "event loop lag" - the worst case for how delayed
any I/O or timer can get.

## Tasks

* [x] New `EventLoopMetrics` records, per completed pass: `iterations`,
      accumulated `busySeconds` (dispatching callbacks), accumulated
      `idleSeconds` (waiting in `select()`), and `maxLagSeconds` (the
      longest single busy stretch - the furthest the loop has fallen behind)
* [x] `SelectLoop::tick()` times its `stream_select()` wait (idle) and its
      callback dispatch (busy) with `hrtime()` and records both; the
      empty-listener path records idle time too
* [x] `EventLoop` gains a `metrics()` accessor so every loop (including the
      Phase 36 EpollLoop) exposes the same observability
* [x] `INFO` reports `eventloop_iterations`, `eventloop_busy_sec`,
      `eventloop_idle_sec` and `eventloop_max_lag_sec`

## Definition of Done

A slow command handler shows up as a spike in `eventloop_max_lag_sec` and
`eventloop_busy_sec` - the loop's responsiveness to other clients and its
own timers is directly visible in INFO.

## Tests

* `SelectLoopTest::testMetricsCountIterations` - each tick is counted
* `SelectLoopTest::testMetricsRecordBusyTimeAndLagFromCallbackDispatch` -
  a deliberately slow writable callback is reflected in `busySeconds` and
  `maxLagSeconds`
* `InfoCommandTest::testReportsConnectionAndCommandCounts` now also asserts
  the four `eventloop_*` fields

---

# Phase 35 — Forked Persistence Worker

## Goal

Phase 11's snapshot ran synchronously on the event loop's timer: copying
the store into an array, serializing it, and writing it to disk all
happened inside a loop callback. A large store blocked the loop - every
client and timer waited for the disk write to finish. Do the write off the
loop in a forked child.

## Tasks

* [x] New `ForkingSnapshotWorker` forks a child per save; the child
      serializes and writes the snapshot then `exit(0)`, never returning
      to the parent's event loop
* [x] PHP's copy-on-write means the child's view of the store is a
      consistent point-in-time snapshot taken with no upfront copy in the
      parent - the parent keeps serving the loop throughout
* [x] The parent reaps forked children via a `SIGCHLD` handler
      (`pcntl_waitpid(-1, ..., WNOHANG)`) installed alongside the existing
      SIGTERM/SIGINT handlers, so they don't linger as zombies
* [x] Falls back to a synchronous save when `pcntl_fork` is unavailable or
      the fork fails - correct, just blocks the loop for the duration

## Definition of Done

A snapshot no longer blocks the event loop: the parent returns from
`saveSnapshot()` as soon as the child is forked, and the file lands on
disk asynchronously. Loading remains unchanged.

## Tests

* `ForkingSnapshotWorkerTest::testTheForkedChildWritesALoadableSnapshot` -
  the forked child's write lands and reloads into a fresh store
* `RedisServerTest::testDataSavedByOneServerIsLoadedByTheNext` and
  `testPeriodicSnapshotsSaveWithoutBeingAskedExplicitly` were updated for
  the async write: they wait for the snapshot file to land (clearing PHP's
  stat cache, which otherwise keeps reporting the stale empty `tempnam`
  file) before loading

---

# Phase 36 — Epoll Loop (deferred)

The review proposed an `EpollLoop` - an epoll reactor built on `FFI`
(`epoll_create1`/`epoll_ctl`/`epoll_wait`) with a fallback to `SelectLoop`
when FFI or the syscalls are unavailable.

**Status: deferred.** The development container's PHP build does not
include `ext-ffi` (`class_exists(FFI::class)` is `false`), so the epoll
path could be neither built nor tested here. Writing a substantial,
untestable FFI/native-interop reactor blind would be irresponsible.

This stays the case until either:
* `ext-ffi` is enabled in the environment (then the epoll reactor can be
  implemented behind an `EventLoop::metrics()`-style contract and tested),
  or
* `stream_select`'s limits (FD_SETSIZE ~1024 fds, O(n) scan per wait)
  actually become the server's bottleneck in load testing - at which
  point a real epoll/kqueue loop is the answer.

The `EventLoop` interface (including the Phase 34 `metrics()` accessor)
was deliberately shaped so such a loop can drop in without touching
`RedisServer` or its tests.

---

# Phase 37 — Load Testing

## Goal

Phase-by-phase work had proven each behavior correct against targeted
tests, but nothing showed how the server fared under realistic load. The
review called for evidence across the shapes of traffic a Redis server
actually sees: a pipelined client, a slow client under backpressure,
Pub/Sub fan-out to many subscribers, and memory growth.

## Tasks

* [x] Load-test scripts under `benchmarks/`:
  - `pipeline.php` - one client driving many commands back-to-back without
    waiting per command, measuring throughput
  - `pubsub-fanout.php` - a publisher and many subscribers, measuring
    message fan-out
  - `memory.php` - write many keys and report peak/current memory usage
* [x] The slow-reader shape is `examples/slow-client.php` rather than a
      fourth benchmark: it exercises the Phase 32 backpressure and Phase 19
      idle timeout, and what it has to show is one paused connection next
      to an unaffected one - something to watch, not a number to compare
* [x] Document how to run each in `benchmarks/README.md`

## Definition of Done

Each traffic shape has a runnable, repeatable benchmark that starts a real
server (via `make`), drives it, and reports a concrete number; the
benchmarks are documented so they can be reproduced.

## Tests

Load tests are manual/scripted (not unit tests) by nature; the unit tests
already pin each underlying behavior (backpressure in Phase 32, Pub/Sub in
Phase 15, pipelining in Phase 10).

---

# Phase 38 — Client SDK

## Goal

Everything that talked to the server - `bin/client.php`, every script in
`examples/`, every load test in `benchmarks/` - carried its own copy of
connect, encode, write, read-until-a-value-parses. Four copies of the same
forty lines, each subtly different about timeouts and short reads, and
nothing a reader of this project could pick up and use against a server of
their own. Give the server one client, the way `php-worker-pool` has one
`WorkerPoolClient`.

## Tasks

* [x] `App\Sdk\RedisClient`: one method per command the server implements,
      plus `command()` for anything else
* [x] Pipelining as `pipeline()` - every command written before any reply
      is read, one round trip for the batch
* [x] Transactions as `multi()` / `queue()` / `exec()` / `discard()`,
      explicit because inside a transaction the server answers `+QUEUED`
      rather than a result
* [x] Pub/Sub as `subscribe()` plus `nextMessage()`, where "nothing
      arrived in time" is a `null`, not an exception
* [x] Failures are exceptions under one `RedisClientException`: the server
      refusing a command, the connection going away, a reply not arriving
      in time, and never connecting at all
* [x] Non-blocking socket underneath, so every wait is bounded by the
      client's own timeout rather than by the server's goodwill
* [x] `bin/`, `examples/` and `benchmarks/` all use it

## Definition of Done

A PHP process can use the server through one object, with no knowledge of
RESP framing, partial reads or `stream_select()`, and every failure mode
surfaces as a typed exception rather than as a value that has to be
checked.

## Tests

* [tests/Sdk/RedisClientTest.php](../tests/Sdk/RedisClientTest.php) -
  drives `bin/server.php` as a real process on a kernel-picked port and
  exercises each shape against it: the typed commands, an error reply
  raised rather than returned, a pipeline whose failed command does not
  cost the others their replies, a transaction that is invisible until
  `exec()`, a published message reaching a subscriber, silence coming
  back as `null`, and a closed client reconnecting on its next command.

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
