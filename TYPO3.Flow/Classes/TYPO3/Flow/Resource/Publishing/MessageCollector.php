<?php
namespace TYPO3\Flow\Resource\Publishing;

/*
 * This file is part of the TYPO3.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Error\Error;
use TYPO3\Flow\Error\Message;
use TYPO3\Flow\Error\Notice;
use TYPO3\Flow\Error\Warning;
use TYPO3\Flow\Exception;
use TYPO3\Flow\Log\SystemLoggerInterface;

/**
 * Notification Collector
 *
 * @Flow\Scope("singleton")
 */
class MessageCollector
{
    /**
     * @var \SplObjectStorage
     */
    protected $backends;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * Notification Collector constructor
     */
    public function __construct()
    {
        $this->backends = new \SplObjectStorage();
    }

    /**
     * @param string $message The message to log
     * @param string $severity An integer value, one of the Error::SEVERITY_* constants
     * @param integer $code A unique error code
     * @throws Exception
     */
    public function append($message, $severity = Error::SEVERITY_ERROR, $code = null)
    {
        switch ($severity) {
            case Error::SEVERITY_ERROR:
                $notification = new Error($message, $code);
                break;
            case Error::SEVERITY_WARNING:
                $notification = new Warning($message, $code);
                break;
            case Error::SEVERITY_NOTICE:
                $notification = new Notice($message, $code);
                break;
            case Error::SEVERITY_OK:
                $notification = new Message($message, $code);
                break;
            default:
                throw new Exception('Invalid severity', 1455819761);
        }
        $this->backends->attach($notification);
    }

    /**
     * @return boolean
     */
    public function hasNotification()
    {
        return $this->backends->count() > 0;
    }

    /**
     * @param callable $callback a callback function to process every notification
     * @return \Generator
     */
    public function flush(callable $callback = null)
    {
        foreach ($this->backends as $message) {
            /** @var Message $message */
            $this->backends->detach($message);
            $this->systemLogger->log('ResourcePublishingMessage: ' . $message->getMessage(), $message->getSeverity());
            if ($callback !== null) {
                $callback($message);
            }
        }
    }

    /**
     * Flush all notification during the object lifecycle
     */
    public function __destruct()
    {
        $this->flush();
    }
}
