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
the last time `saveSnapshot()` was called - a crash between the last save
and the crash still loses everything written since. This is a point-in-time
snapshot, not a write-ahead log: there is no way to recover writes newer
than the snapshot itself.

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

## Protocol errors currently disconnect (interim, see DECISIONS.md)

A byte stream that stops looking like RESP mid-parse
(`ProtocolException`) closes that connection rather than replying with a
RESP error. This is Phase 12's interim behavior, not Phase 24's finished
one - see
[DECISIONS.md](DECISIONS.md#malformed-input-disconnects-the-client-for-now)
for why disconnecting was chosen over attempting to resynchronize.

Command-level errors (wrong argument count, non-integer `INCR` target, an
unregistered command name) already reply with a proper `-ERR ...` RESP
error and do **not** disconnect - only a genuinely malformed protocol
stream does.

## No backpressure yet (Phase 26)

`WriteBuffer` queues an unbounded amount of data if a client reads slower
than the server produces responses (see Phase 13). Nothing currently caps
its size or pauses reading from a slow client - a single very large
response, or a client that never reads at all, grows that connection's
`WriteBuffer` without limit until Phase 26 adds a cap. Until then, a
pathological client is a real, if narrow, memory-growth vector.

## No resource limits yet (Phase 25)

There is currently no cap on: read buffer size before parsing, arguments
per command, or total connection count. A client sending an enormous bulk
string, or opening far more connections than intended, is not rejected.

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
