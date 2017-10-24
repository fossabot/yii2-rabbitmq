<?php

namespace mikemadisonweb\rabbitmq\components;

use mikemadisonweb\rabbitmq\events\RabbitMQConsumerEvent;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use yii\helpers\Console;

/**
 * Service that receives AMQP Messages
 * @package mikemadisonweb\rabbitmq\components
 */
class Consumer extends BaseRabbitMQ
{
    protected $idleTimeout;
    protected $idleTimeoutExitCode;
    protected $queues = [];
    protected $memoryLimit = 0;

    private $id;
    private $target;
    private $consumed = 0;
    private $forceStop = false;
    private $name = 'unnamed';

    /**
     * Set the memory limit
     * @param int $memoryLimit
     */
    public function setMemoryLimit($memoryLimit)
    {
        $this->memoryLimit = $memoryLimit;
    }

    /**
     * Get the memory limit
     *
     * @return int
     */
    public function getMemoryLimit() : int
    {
        return $this->memoryLimit;
    }

    /**
     * @param array $queues
     */
    public function setQueues(array $queues)
    {
        $this->queues = $queues;
    }

    /**
     * @return array
     */
    public function getQueues() : array
    {
        return $this->queues;
    }

    /**
     * @param $idleTimeout
     */
    public function setIdleTimeout($idleTimeout)
    {
        $this->idleTimeout = $idleTimeout;
    }

    public function getIdleTimeout()
    {
        return $this->idleTimeout;
    }

    /**
     * Set exit code to be returned when there is a timeout exception
     * @param int|null $idleTimeoutExitCode
     */
    public function setIdleTimeoutExitCode($idleTimeoutExitCode)
    {
        $this->idleTimeoutExitCode = $idleTimeoutExitCode;
    }

    /**
     * Get exit code to be returned when there is a timeout exception
     * @return int|null
     */
    public function getIdleTimeoutExitCode()
    {
        return $this->idleTimeoutExitCode;
    }

    /**
     * Resets the consumed property.
     * Use when you want to call start() or consume() multiple times.
     */
    public function resetConsumed()
    {
        $this->consumed = 0;
    }

    /**
     * Sets the qos settings for the current channel
     * Consider that prefetchSize and global do not work with rabbitMQ version <= 8.0
     * @param int $prefetchSize
     * @param int $prefetchCount
     * @param bool $global
     */
    public function setQosOptions($prefetchSize, $prefetchCount, $global)
    {
        $this->getChannel()->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    /**
     * Consume designated number of messages (0 means infinite)
     * @param int $msgAmount
     * @return int
     * @throws \BadFunctionCallException
     * @throws \RuntimeException
     * @throws AMQPTimeoutException
     */
    public function consume($msgAmount) : int
    {
        $this->target = $msgAmount;
        if ($this->autoDeclare) {
            $this->routing->declareAll($this->conn);
        }
        $this->startConsuming();
        // At the end of the callback execution
        while (count($this->getChannel()->callbacks)) {
            $this->maybeStopConsumer();
            if (!$this->forceStop) {
                try {
                    $this->getChannel()->wait(null, false, $this->getIdleTimeout());
                } catch (AMQPTimeoutException $e) {
                    if (null !== $this->getIdleTimeoutExitCode()) {
                        return $this->getIdleTimeoutExitCode();
                    }

                    throw $e;
                }
            }
        }

        return 0;
    }

    /**
     * Start consuming messages
     * @throws \RuntimeException
     * @throws \Throwable
     */
    protected function startConsuming()
    {
        $this->id = $this->generateUniqueId();
        if ($this->autoDeclare) {
            $this->routing->declareAll($this->conn);
        }
        foreach ($this->queues as $queue => $callback) {
            $that = $this;
            $this->getChannel()->basic_consume(
                $queue,
                $this->getConsumerTag($queue),
                null,
                null,
                null,
                null,
                function (AMQPMessage $msg) use ($that, $queue, $callback) {
                    // Execute user-defined callback
                    $that->onReceive($msg, $queue, $callback);
                }
            );
        }
    }

    /**
     * Stop consuming messages
     */
    public function stopConsuming()
    {
        foreach ($this->queues as $name => $options) {
            $this->getChannel()->basic_cancel($this->getConsumerTag($name), false, true);
        }
    }

    /**
     * Decide whether it's time to stop consuming
     * @throws \BadFunctionCallException
     */
    protected function maybeStopConsumer()
    {
        if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true)) {
            if (!function_exists('pcntl_signal_dispatch')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
            }
            pcntl_signal_dispatch();
        }
        if ($this->forceStop || ($this->consumed === $this->target && $this->target > 0)) {
            $this->stopConsuming();
        } else {
            return;
        }
    }

    public function forceStopConsumer()
    {
        $this->forceStop = true;
    }

    /**
     * @param AMQPMessage $msg
     * @param $queueName
     * @param $callback
     * @throws \Throwable
     */
    protected function onReceive(AMQPMessage $msg, string $queueName, callable $callback)
    {
        \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::BEFORE_CONSUME, new RabbitMQConsumerEvent([
            'message' => $msg,
            'consumer' => $this,
        ]));
        $timeStart = microtime(true);
        try {
            $processFlag = $callback($msg);
            $this->handleResultCode($msg, $processFlag);
            \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::AFTER_CONSUME, new RabbitMQConsumerEvent([
                'message' => $msg,
                'consumer' => $this,
            ]));
            if ($this->logger['print_console']) {
                $this->printToConsole($queueName, $timeStart, $processFlag);
            }
            if ($this->logger['enable']) {
                \Yii::info([
                    'info' => 'Queue message processed.',
                    'amqp' => [
                        'queue' => $queueName,
                        'message' => $msg->getBody(),
                        'return_code' => $processFlag,
                        'execution_time' => $this->getExecutionTime($timeStart),
                        'memory' => $this->getMemory(),
                    ],
                ], $this->logger['category']);
            }

            $this->consumed++;
            $this->maybeStopConsumer();
            if (0 !== $this->getMemoryLimit() && $this->isRamAlmostOverloaded()) {
                $this->stopConsuming();
            }
        } catch (\Throwable $e) {
            if ($this->logger['enable']) {
                $this->logError($e, $queueName, $msg, $timeStart);
            }

            throw $e;
        }
    }

    /**
     * Mark message status based on return code from callback
     * @param AMQPMessage $msg
     * @param $processFlag
     */
    protected function handleResultCode(AMQPMessage $msg, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            // Reject and requeue message to RabbitMQ
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], true);
        } elseif ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            // NACK and requeue message to RabbitMQ
            $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, true);
        } elseif ($processFlag === ConsumerInterface::MSG_REJECT) {
            // Reject and drop
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], false);
        } else {
            // Remove message from queue only if callback return not false
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        }
    }

    /**
     * Checks if memory in use is greater or equal than memory allowed for this process
     *
     * @return boolean
     */
    protected function isRamAlmostOverloaded() : bool
    {
        return memory_get_usage(true) >= ($this->getMemoryLimit() * 1024 * 1024);
    }

    /**
     * @param string $name
     */
    public function tagName(string $name)
    {
        $this->name = $name;
    }

    /**
     * @param string $queueName
     * @return string
     */
    protected function getConsumerTag(string $queueName) : string
    {
        return sprintf('%s-%s-%s', $queueName, $this->name, $this->id);
    }

    /**
     * @return string
     */
    protected function generateUniqueId() : string
    {
        return uniqid();
    }
}
