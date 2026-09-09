# Failure Model

What breaks, what survives it, and what this server does **not** guarantee.
Read this before trusting it with anything beyond learning how it works -
it is an educational implementation (see the README's Final Principle), not
a Redis replacement.

---

## Single process, single point of failure

Everything - the listening socket, every client connection, the store, the
event loop - lives in one PHP process. There is no replication, no
clustering, no secondary that takes over if the process dies.

```text
RedisServer process dies
         │
         ▼
Every connected client is dropped
         │
         ▼
Every key in the Store is gone (see "In-memory only" below)
```

There is currently no supervisor around `bin/server.php` restarting it on
crash - that is an operational concern deliberately left to whoever runs
it (`systemd`, Docker's own restart policy, or a process manager), the same
way `php-worker-pool`'s Master has no supervisor of its own either.

## In-memory only, unless a snapshot path is configured

`InMemoryStore` holds every key in a PHP array. A process restart - crash,
deploy, `SIGKILL` - loses every key, unless `RedisServer` was constructed
with a `snapshotPath` (see [PHASES.md](PHASES.md#phase-22--persistence)).
Even then, a snapshot only covers whatever was last written to it: without
`snapshotIntervalSeconds` configured too, that means whatever was on disk
the last time `saveSnapshot()` was called. This is a point-in-time
snapshot, not a write-ahead log: there is no way to recover writes newer
than the snapshot itself.

A snapshot that cannot be written says so rather than passing for one
that was: the forked child prints the reason and exits non-zero, and the
synchronous paths (no `ext-pcntl`, a failed `fork()`, and the final
snapshot on shutdown) raise the failure to the caller.

A shutdown the server gets to see - `stop()`, or `SIGTERM`/`SIGINT`
through `requestShutdown()` - writes one final snapshot on the way out, so
a planned restart keeps what was written since the last scheduled one. A
`SIGKILL`, a segfault, or the machine losing power gets no such chance:
everything after the last snapshot is gone.

## At-most-once delivery, not at-least-once

A response is written once. If a client's socket is gone by the time the
server tries to write (`fwrite()` returns `false`), the response is
dropped and the connection is closed - the server does not retry, buffer
past the connection's lifetime, or notify anyone. A client that
disconnects between sending a command and receiving its reply simply never
sees that reply, whether or not the command's effect (e.g. a `SET`)
already happened. Command handlers are not required to be idempotent
because nothing in this server retries a command on their behalf - but a
client-side retry after a dropped connection can still double-apply a
non-idempotent command (`INCR`, for instance) for reasons entirely outside
this server's control.

## A malformed protocol stream still ends the connection

A byte stream that stops looking like RESP mid-parse (`ProtocolException`)
gets a `-ERR Protocol error: ...` reply and then the connection closes -
see
[DECISIONS.md](DECISIONS.md#malformed-input-still-disconnects-but-with-a-resp-error-first)
for why the connection still has to end (there is no reliable way to
resynchronize a desynced stream) even though the client is now told why.

Command-level errors (wrong argument count, non-integer `INCR` target, an
unregistered command name) reply with a proper `-ERR ...` RESP error and
do **not** disconnect - only a genuinely malformed protocol stream does.

## Backpressure pauses reading, not the connection itself

A connection whose `WriteBuffer` grows past `maxWriteBufferBytes` stops
being read from until it drains back below `lowWriteBufferBytes` (a
quarter of the pause level by default; see
[PHASES.md](PHASES.md#phase-26--backpressure) and
[PHASES.md](PHASES.md#phase-32--highlow-watermarks)) - but it is not
disconnected, and whatever it already queued keeps trying to flush
regardless. A client that both never reads its responses *and* never
stops sending new commands has its own commands ignored (paused) but its
socket stays open indefinitely; nothing currently times out a connection
stuck in the paused state specifically (Phase 19's idle timeout is based
on last activity, and a socket mid-flush still counts as active).

## Resource limits are capped, but coarse

`RedisServer` caps read buffer size, arguments per command, and total
connection count (see
[PHASES.md](PHASES.md#phase-25--limits)) - but the buffer-size limit only
catches a value that can *never* complete; a client sending many small,
individually-valid commands in a slow drip is not rate-limited by it at
all, and there is no per-client accounting (one client's oversized buffer
disconnects only that client, but a moderate one from each of many
connections is still bounded only by `maxConnections`, if set).

## Timers are best-effort, not exact

Both the active-expiration sweep (Phase 17) and the idle-connection check
(Phase 19) run on `SelectLoop` timers whose accuracy is bounded by how
often the loop actually wakes - a tick that is busy processing socket
events can run a scheduled callback slightly late, never early. Neither
timer is meant to provide millisecond-precision SLAs; both are, by design,
"within about one interval of the deadline," not "at the deadline."

## No authentication, no ACLs

Anything that can open a TCP connection to the listening port can issue any
command, including `PUBLISH`/`SUBSCRIBE` to any channel and `EXEC` of
anyone's own transaction (transactions are per-connection, so this is at
least not cross-client). There is no password, no user model, no
per-command permission. Treat the listening port the way you would treat
an unauthenticated command channel - because that is exactly what it is.

## `EXEC` runs the queue, but does not roll back

If one queued command in a transaction fails (a RESP error result -
wrong argument count, for instance), `EXEC` still executes every command
after it in the queue. Real Redis behaves the same way for runtime errors
inside `EXEC` (as opposed to a queue-time error, which aborts the whole
transaction before `EXEC` is even reached) - there is no partial rollback
in either implementation. This project does not currently distinguish the
two cases at queue time; every syntactically valid command is queued and
only fails, if it fails, once `EXEC` actually runs it.

## Pub/Sub messages are not durable

A `PUBLISH` while nobody is subscribed to that channel delivers to zero
recipients and is gone - there is no channel history, no replay for a
client that subscribes a moment later. `PublishCommand`'s return value (how
many subscribers it delivered to) is the only record that the publish
happened at all.
