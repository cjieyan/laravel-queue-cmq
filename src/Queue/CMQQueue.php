<?php

namespace Freyo\LaravelQueueCMQ\Queue;

use Freyo\LaravelQueueCMQ\Queue\Driver\Account;
use Freyo\LaravelQueueCMQ\Queue\Driver\Message;
use Freyo\LaravelQueueCMQ\Queue\Driver\Topic;
use Freyo\LaravelQueueCMQ\Queue\Jobs\CMQJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;

class CMQQueue extends Queue implements QueueContract
{

    /**
     * @var array
     */
    protected $queueOptions;
    protected $topicOptions;

    /**
     * @var Account
     */
    private $queueAccount;
    private $topicAccount;

    public function __construct(Account $queueAccount, Account $topicAccount, array $config)
    {
        $this->queueAccount = $queueAccount;
        $this->topicAccount = $topicAccount;

        $this->queueOptions = $config['options']['queue'];
        $this->topicOptions = $config['options']['topic'];
    }

    /**
     * Get the size of the queue.
     *
     * @param  string $queue
     *
     * @return int
     */
    public function size($queue = null)
    {
        $attributes = $this->getQueue($queue)->get_attributes();

        return (int)$attributes->activeMsgNum;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object $job
     * @param  mixed         $data
     * @param  string        $queue
     *
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload
     * @param  string $queue
     * @param  array  $options
     *
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $message = new Message($payload);

        $driver = $this->parseQueue($queue);

        if ($driver instanceof Topic) {

            $vTagList = [];
            if ($this->topicOptions['filter'] === 'msgtag') {
                $vTagList = explode(',', $queue);
            }

            $routingKey = null;
            if ($this->topicOptions['filter'] === 'routing') {
                $routingKey = $queue;
            }

            return $driver->publish_message($message, $vTagList, $routingKey);
        }

        return $driver->send_message($message, array_get($options, 'delay', 0));
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int $delay
     * @param  string|object                        $job
     * @param  mixed                                $data
     * @param  string                               $queue
     *
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string $queue
     *
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $message = $this->getQueue($queue)->receive_message(0);

        return new CMQJob($this->container, $this, $message);
    }

    /**
     * Get the queue
     *
     * @param string $queue
     *
     * @return Driver\Queue
     */
    public function getQueue($queue = null)
    {
        return $this->queueAccount->get_queue($queue ?: $this->queueOptions['name']);
    }

    /**
     * Get the topic
     *
     * @param string $topic
     *
     * @return Driver\Topic
     */
    public function getTopic($topic = null)
    {
        return $this->topicAccount->get_topic($topic ?: $this->topicOptions['name']);
    }

    /**
     * Parse name to topic or queue
     *
     * @param string $queue
     *
     * @return Driver\Queue|Driver\Topic
     */
    public function parseQueue($queue = null)
    {
        if ($this->topicOptions['enable']) {
            $exchangeName = $this->topicOptions['name'] ?: $queue;
            return $this->getTopic($exchangeName);
        }

        $queueName = $queue ?: $this->queueOptions['name'];
        return $this->getQueue($queueName);
    }
}