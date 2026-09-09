# Decisions

Why the code is shaped this way: what was tried, what was rejected, which
ordering problems or bugs forced a change. [PHASES.md](PHASES.md) says what
was built and when; this says why it looks like that.

---

## Phase 18 before Phase 17

The plan orders "Expiration Strategy" (compare lazy vs. active expiration)
before "Event Loop Timers" - but active expiration cannot be built without
a timer to sweep on. `SelectLoop::tick()` blocks on `stream_select()`,
which only ever wakes up for socket activity; with nothing registered it
returned immediately rather than waiting, so there was no way for time
alone to trigger a callback.

Rather than force a half-working "active expiration" ahead of the timer
primitive it needs, Phase 18 was implemented first and Phase 17 built on
top of it. Both phases are still recorded under their original numbers in
PHASES.md, with a cross-reference at the top of each noting the swap - the
numbering describes the plan's own logical order, not necessarily
build order.

## `CommandHandler` gained a connection parameter

Through Phase 19, `CommandHandler::handle()` took only `(Command $command,
Store $store)`. Pub/Sub (Phase 20) broke that: `SUBSCRIBE` needs to know
*which* connection is subscribing, and `PUBLISH` needs to write to
connections other than the one that issued the command - neither is
reachable from a `Store`.

The signature became `handle(Command $command, Store $store,
ClientConnection $connection): RespValue`, and every existing handler (six
of them, all pure Store operations that ignore the new parameter) was
updated mechanically. Transactions (Phase 21) reused the same parameter
immediately - `MULTI`/`EXEC`/`DISCARD` are inherently per-connection state -
which is the reason the type was widened at the interface level instead of
adding a narrower, Pub/Sub-specific hook.

The alternative considered was keeping `CommandHandler` untouched and
special-casing `SUBSCRIBE`/`PUBLISH`/`MULTI`/`EXEC`/`DISCARD` directly
inside `RedisServer::executeValue()`, bypassing the dispatcher for those
five names. Rejected: it would have meant two different ways a command
could be handled depending on its name, which is exactly the kind of
special-casing the dispatcher exists to avoid (Phase 11's own stated goal:
"adding a new command does not require modifying the entire server").

## Where connection-scoped state lives

Pub/Sub subscriptions and transaction queues are both "per connection"
state, but neither lives on `ClientConnection` itself. `ClientConnection`
is a Networking-layer object (socket, buffers, state, last activity); a
list of subscribed channels or queued `Command` objects would pull the
Command and PubSub layers underneath it, inverting the dependency
direction the rest of the codebase follows (Networking → Protocol →
Commands → State, restated in
[ARCHITECTURE.md](ARCHITECTURE.md#important-invariants)).

Instead, `ChannelRegistry` and `TransactionManager` each keep their own
`array<int, ...>` keyed by `ClientConnection::id()` (the resource id of the
connection's socket). Cleanup on disconnect is explicit -
`RedisServer::disconnectClient()` calls `unsubscribeAll()` and `discard()`
on both - rather than relying on the connection object's own destruction to
imply it.

## The RESP parser returns `null`, not an exception, for incomplete input

`RespParser::parse()` returning `null` to mean "not enough bytes yet" was
built into Phase 6 from the start, rather than added in Phase 7 as a
separate capability layered on top of a parser that originally threw. The
two phases remain separate because they prove different things - Phase 6
is the encode/decode round trip for a complete buffer, Phase 7 is
specifically about fragmentation - not because the implementation changed
shape between them.

`ProtocolException` is reserved for input that is unambiguously not RESP
at all (an unrecognized type byte), which cannot be resolved by waiting for
more bytes the way a merely-incomplete value can.

## Malformed input still disconnects, but with a RESP error first

A `ProtocolException` while parsing a connection's buffer
(`RedisServer::processBufferedCommands()`) writes a `-ERR Protocol
error: ...` reply and then closes the connection
(`sendErrorAndDisconnect()`), rather than either silently disconnecting
(Phase 12's original interim behavior) or trying to keep the connection
open. Real Redis does the same for the same reason: a genuinely-not-RESP
byte stream cannot be resynchronized - there is no reliable way to find
the start of the next value once framing is lost - so the connection
still has to end, but the client is not left guessing why.

The write is a direct, best-effort `fwrite()` rather than going through
the normal `WriteBuffer`/writable-event machinery: the connection is
torn down in the same call, so there is no next event loop tick left for
a queued partial write to finish on.

Commands that already arrived complete *before* the bad byte are still
applied in order, mirroring how real Redis processes a pipeline: a client
that mixed one valid command and one broken one sees the valid one take
effect and *then* the `-ERR Protocol error: ...` reply before the
disconnect. To make that possible, `RespStreamReader::readAll()` returns
the values parsed so far together with the `ProtocolException` it ran
into, instead of throwing it away mid-buffer - see
`RespStreamReaderTest::testKeepsValuesParsedBeforeAMalformedTailAndReportsTheError`.

## `SetCommand`'s `EX` option, not a generic options parser

`SET key value EX seconds` is parsed as a fixed 4- or 2-argument shape
inside `SetCommand` itself, rather than through a generic
option-parsing helper shared across commands. Real Redis's `SET` accepts
several mutually-exclusive and combinable options (`EX`, `PX`, `NX`, `XX`,
`KEEPTTL`, ...); this project implements exactly the one option Phase 16
asks for. A shared parser would be premature generalization for a single
call site - see the project's own stated preference (README) for a small
number of concrete cases over an abstraction built ahead of a second user.

## `InMemoryStore` and `SelectLoop` both take an injectable clock

Both accept an optional `\Closure(): float` in their constructor, defaulting
to `microtime(true)`. Without it, testing TTL expiry or timer firing would
mean either a real `sleep()` (slow, and still not exact) or asserting
nothing at all. A test supplies a closure over a local `$now` variable and
advances it explicitly between calls - see
`InMemoryStoreTest::testAValueWithATtlIsGoneOnceItExpires` and
`SelectLoopTest::testATimerFiresAlongsideStreamActivity`.

The closure must be a genuine closure captured with `use (&$now)`, not a
`static fn(): float => $now` arrow function - an arrow function captures
`$now` *by value* at the moment it is created, so incrementing the outer
variable afterward has no effect on what the closure returns. This was a
real bug during Phase 16/17 test-writing (both `InMemoryStoreTest` and
`RedisServerTest`'s TTL/idle-timeout tests initially used the arrow form
and silently asserted against a clock that never advanced), fixed by
switching every test clock to `static function () use (&$now): float {
return $now; }`.

## Two independent buffer classes, introduced two phases apart

`ReadBuffer` (Phase 5) and `WriteBuffer` (Phase 13) are nearly identical in
shape - both just accumulate or trim a string - and were deliberately kept
as two separate classes rather than one shared `Buffer` used in both
directions. `ReadBuffer` exists to answer "has a complete value arrived
yet", is only ever appended to and consumed by the parser as it recognizes
complete values; `WriteBuffer` exists to answer "how much is left to
flush", is only ever appended to by the encoder and consumed by however
many bytes `fwrite()` actually accepted. Naming them for their direction
keeps each call site's intent obvious at the point of use, at the cost of a
small amount of duplication between two ~40-line classes.

## The idle-timeout and expiration-sweep timers share one interval parameter

`RedisServer`'s `idleTimeoutSeconds` doubles as both the threshold *and*
the check cadence - a connection is swept for idleness on the same timer
whose period equals the timeout itself, rather than on a separate, tighter
polling interval. This means an idle connection is closed within one
timeout period of crossing the threshold, not the instant it crosses it.
Accepted for this project's scope: a dedicated, faster check interval would
buy tighter precision at the cost of one more constructor parameter and one
more thing to explain, for a project whose point is the mechanism ("time
becomes a first-class event loop event"), not exact SLA-grade timing.

## `docker-compose.yml`'s stale `container_name`

The compose file's `container_name` still read `php-worker-pool` - copied,
along with the rest of the early scaffolding (`Makefile`, `Dockerfile`,
`composer.json`), from the sibling `php-worker-pool` project this
repository's own `README.md` describes as part of the same series.
Corrected to `php-mini-redis` once noticed; `docker compose exec php ...`
was unaffected throughout, since compose addresses a service by its
`services:` key (`php`), not by `container_name`.

## `fread()`'s default chunk size, found writing the backpressure test

Testing Phase 26's resume path needs a client that reads back several
hundred KB to free enough of the kernel's own send buffer for the server's
socket to report writable again (small reads leave it saturated
indefinitely - see the phase's own test for why). The first attempt at
that test looped calling `fread($client, 1024 * 1024)`, expecting each
call to return up to a megabyte - it returned exactly 8192 bytes every
time, needing roughly 150 iterations instead of 3 to free enough space,
which is exactly the kind of slow, many-iteration drain this project's own
Phase 13 test had already gone out of its way to avoid.

The cause: a PHP stream has its own internal chunk size (8192 bytes by
default) that caps how much of a single `fread()` request is actually
satisfied, independent of the length asked for. `stream_set_chunk_size($client,
1024 * 1024)`, called once up front, removes that ceiling; the same drain
then converges in 2-3 iterations. Worth remembering anywhere a test (or
real client) needs to read back a large amount of data in bounded reads
rather than one line at a time.
