<?php

declare(strict_types=1);

namespace App\Tests\Sdk;

use App\Protocol\RespType;
use App\Sdk\CommandFailedException;
use App\Sdk\ConnectionFailedException;
use App\Sdk\RedisClient;
use App\Server\RedisServer;
use App\Server\ServerConfig;
use PHPUnit\Framework\TestCase;

/**
 * Drives a real server over a real socket: the client blocks waiting for
 * replies, so the server cannot share this process's single thread with it -
 * it runs in a forked child, started fresh for each test and stopped again
 * in tearDown().
 */
final class RedisClientTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private int $port = 0;
    private int $serverPid = 0;
    private RedisClient $client;

    protected function setUp(): void
    {
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed');

        if ($pid === 0) {
            // PHPUnit captures a test's output by keeping an output buffer
            // open, and fork() hands the child a copy of it - which it would
            // flush on the way out, printing the run's own progress a second
            // time. It is the parent's buffer; the child gives it up.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Last-resort backstop, for a child that somehow outlives the
            // test: SIGALRM's default action ends the process, and nothing
            // here installs a handler for it.
            pcntl_alarm(15);

            $server->run();

            exit(0);
        }

        $this->serverPid = $pid;
        $server->stop(); // the parent's own copy of the listening socket

        $this->client = $this->newClient();
    }

    protected function tearDown(): void
    {
        $this->client->close();

        // SIGTERM is the graceful path the server implements, and the wait
        // below is bounded rather than a plain waitpid(): a child that
        // never gets there must cost this test two seconds, not the whole
        // suite.
        posix_kill($this->serverPid, SIGTERM);

        for ($i = 0; $i < 40; $i++) {
            if (pcntl_waitpid($this->serverPid, $status, WNOHANG) !== 0) {
                return;
            }

            usleep(50_000);
        }

        posix_kill($this->serverPid, SIGKILL);
        pcntl_waitpid($this->serverPid, $status);
    }

    public function testPingSetAndGetRoundTrip(): void
    {
        self::assertSame('PONG', $this->client->ping());
        self::assertSame('hello', $this->client->ping('hello'));

        $this->client->set('name', 'Tanat');

        self::assertSame('Tanat', $this->client->get('name'));
    }

    public function testGetReportsAMissingKeyAsNull(): void
    {
        self::assertNull($this->client->get('never-written'));
    }

    public function testSetWithATtlIsReadableWhileItLasts(): void
    {
        $this->client->set('session', 'abc', ttlSeconds: 60);

        self::assertSame('abc', $this->client->get('session'));
    }

    public function testDeleteAndExistsCountKeys(): void
    {
        $this->client->set('a', '1');
        $this->client->set('b', '2');

        self::assertSame(2, $this->client->exists('a', 'b', 'missing'));
        self::assertSame(2, $this->client->delete('a', 'b', 'missing'));
        self::assertSame(0, $this->client->exists('a', 'b'));
    }

    public function testIncrementCountsUpFromAMissingKey(): void
    {
        self::assertSame(1, $this->client->increment('hits'));
        self::assertSame(2, $this->client->increment('hits'));
    }

    public function testInfoComesBackAsTheServersReport(): void
    {
        self::assertStringContainsString('total_commands_processed:', $this->client->info());
    }

    public function testAnErrorReplyIsRaisedRatherThanReturnedAsAValue(): void
    {
        $this->client->set('name', 'Tanat');

        try {
            $this->client->increment('name'); // not an integer
            self::fail('Incrementing a non-integer should have raised.');
        } catch (CommandFailedException $exception) {
            self::assertStringContainsString('not an integer', $exception->error);
        }

        // The connection is still usable: a command-level error is the
        // server answering, not the connection breaking.
        self::assertSame('PONG', $this->client->ping());
    }

    public function testPipelineAnswersEveryCommandInOrder(): void
    {
        $replies = $this->client->pipeline([
            ['SET', 'counter', '41'],
            ['INCR', 'counter'],
            ['GET', 'counter'],
        ]);

        self::assertCount(3, $replies);
        self::assertSame('OK', $replies[0]->value);
        self::assertSame(42, $replies[1]->value);
        self::assertSame('42', $replies[2]->value);
    }

    public function testAFailedCommandInAPipelineDoesNotCostTheOthersTheirReplies(): void
    {
        $replies = $this->client->pipeline([
            ['SET', 'name', 'Tanat'],
            ['INCR', 'name'],
            ['GET', 'name'],
        ]);

        self::assertSame('OK', $replies[0]->value);
        self::assertSame(RespType::Error, $replies[1]->type);
        self::assertSame('Tanat', $replies[2]->value);
    }

    public function testATransactionQueuesCommandsAndRunsThemOnExec(): void
    {
        $this->client->set('counter', '1');

        $this->client->multi();
        self::assertTrue($this->client->inTransaction());

        $this->client->queue('INCR', 'counter');
        $this->client->queue('GET', 'counter');

        // Nothing ran yet - the queue is held by the server.
        self::assertSame('1', $this->newClient()->get('counter'));

        $results = $this->client->exec();

        self::assertFalse($this->client->inTransaction());
        self::assertCount(2, $results);
        self::assertSame(2, $results[0]->value);
        self::assertSame('2', $results[1]->value);
    }

    public function testDiscardThrowsTheQueueAway(): void
    {
        $this->client->set('counter', '1');

        $this->client->multi();
        $this->client->queue('INCR', 'counter');
        $this->client->discard();

        self::assertFalse($this->client->inTransaction());
        self::assertSame('1', $this->client->get('counter'));
    }

    public function testAPublishedMessageReachesASubscribedClient(): void
    {
        $subscriber = $this->newClient();

        try {
            self::assertSame(1, $subscriber->subscribe('news'));
            self::assertSame(1, $this->client->publish('news', 'hello'));

            $message = $subscriber->nextMessage();

            self::assertNotNull($message);
            self::assertSame('news', $message->channel);
            self::assertSame('hello', $message->payload);
        } finally {
            $subscriber->close();
        }
    }

    public function testNextMessageGivesUpQuietlyWhenNothingIsPublished(): void
    {
        $subscriber = $this->newClient();

        try {
            $subscriber->subscribe('news');

            // Silence is an answer here, not a failure - so it is a null
            // rather than the exception every other read would raise.
            self::assertNull($subscriber->nextMessage(timeoutSeconds: 0.05));
        } finally {
            $subscriber->close();
        }
    }

    public function testConnectingToAPortNothingListensOnFails(): void
    {
        $client = new RedisClient(self::HOST, 1, timeoutSeconds: 0.5);

        $this->expectException(ConnectionFailedException::class);

        $client->ping();
    }

    public function testAClosedClientReconnectsOnItsNextCommand(): void
    {
        $this->client->set('name', 'Tanat');
        $this->client->close();

        self::assertSame('Tanat', $this->client->get('name'));
    }

    private function newClient(): RedisClient
    {
        return new RedisClient(self::HOST, $this->port, timeoutSeconds: 2.0);
    }
}
