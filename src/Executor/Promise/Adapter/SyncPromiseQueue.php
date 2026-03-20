<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise\Adapter;

/**
 * Queue for deferred execution of SyncPromise tasks.
 *
 * Owns the shared queue and provides the run loop for processing promises.
 *
 * @api
 *
 * @phpstan-type Task callable(): void
 */
class Sync_Promise_Queue
{
    /**
     * Adds a task to the queue.
     *
     * @param Task $task
     *
     * @api
     */
    public static function enqueue(callable $task): void
    {
        self::queue()->enqueue($task);
    }
    /**
     * Process all queued promises until the queue is empty.
     *
     * @api
     */
    public static function run(): void
    {
        $queue = self::queue();
        while (!$queue->is_empty()) {
            $task = $queue->dequeue();
            $task();
        }
    }
    /**
     * Check if the queue is empty.
     *
     * @api
     */
    public static function is_empty(): bool
    {
        return self::queue()->is_empty();
    }
    /**
     * Return the number of tasks in the queue.
     *
     * @api
     */
    public static function count(): int
    {
        return self::queue()->count();
    }
    /**
     * TODO change to protected in next major version.
     *
     * @return \SplQueue<Task>
     */
    public static function queue(): \SplQueue
    {
        /** @var \SplQueue<Task>|null $queue */
        static $queue;
        return $queue ??= new \SplQueue();
    }
}