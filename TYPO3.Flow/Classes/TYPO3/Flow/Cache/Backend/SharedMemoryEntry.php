<?php
namespace TYPO3\Flow\Cache\Backend;

/*
 * This file is part of the TYPO3.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Cache\Exception;
use TYPO3\Flow\Annotations as Flow;

/**
 * @api
 * @Flow\Proxy(false)
 */
class SharedMemoryEntry
{
    /**
     * Holds the system id for the shared memory block
     *
     * @var int
     */
    protected $id;

    /**
     * Holds the shared memory block id returned by shmop_open
     *
     * @var int
     */
    protected $shmid;

    /**
     * Holds the default permission (octal) that will be used in created memory blocks
     *
     * @var integer
     */
    protected $perms = 0666;

    /**
     * @var mixed
     */
    protected $data;

    /**
     * SharedMemoryEntry constructor.
     * @param integer $entryKey
     */
    public function __construct($entryKey)
    {
        $this->id = (integer)$entryKey;
        if ($this->exists($this->id)) {
            $this->shmid = shmop_open($this->id, "w", 0, 0);
        }
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return integer
     */
    public function exists()
    {
        $status = @shmop_open($this->id, 'a', 0, 0);
        return $status;
    }

    /**
     * @param mixed $data
     */
    public function write($data)
    {
        $data = serialize($data);
        $size = mb_strlen($data, 'UTF-8');
        if ($this->exists($this->id)) {
            shmop_delete($this->shmid);
            shmop_close($this->shmid);
            $this->shmid = shmop_open($this->id, 'c', $this->perms, $size);
            shmop_write($this->shmid, $data, 0);
        } else {
            $this->shmid = shmop_open($this->id, 'c', $this->perms, $size);
            shmop_write($this->shmid, $data, 0);
        }
    }

    /**
     * @return string
     */
    public function read()
    {
        $size = shmop_size($this->shmid);
        $data = shmop_read($this->shmid, 0, $size);
        return unserialize($data);
    }

    /**
     * @return void
     */
    public function delete()
    {
        if ($this->exists()) {
            shmop_delete($this->shmid);
        }
    }
}
