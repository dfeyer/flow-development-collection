<?php
namespace TYPO3\Flow\Utility\Lock;

/*
 * This file is part of the Neos.Flow.Lock package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * A Semaphore (IPC) based lock strategy.
 *
 * This lock strategy is based on Semaphore (IPC), only available on System V.
 *
 */
class SemaphoreLockStrategy implements LockStrategyInterface
{
    /**
     * Semaphore ID
     *
     * @var resource
     */
    protected $semaphoreId;

    /**
     * Is this lock aquired
     *
     * @var boolean
     */
    protected $aquiredLock;

    /**
     * File pointer if using flock method
     *
     * @var resource
     */
    protected $filePointer;

    /**
     * @param string $subject
     * @param boolean $exclusiveLock TRUE to, acquire an exclusive (write) lock, FALSE for a shared (read) lock.
     * @return void
     * @throws LockNotAcquiredException
     */
    public function acquire($subject, $exclusiveLock)
    {
        $key = crc32(FLOW_PATH_ROOT . (string)$subject);
        $this->semaphoreId = sem_get($key);
        $this->aquiredLock = sem_acquire($this->semaphoreId);
    }

    /**
     * Releases the lock
     *
     * @return boolean
     */
    public function release()
    {
        return @sem_release($this->semaphoreId);
    }
}
