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
use TYPO3\Flow\Cache\Frontend\FrontendInterface;

/**
 * A caching backend which stores cache entries in files, but does not support or
 * care about expiry times and tags.
 *
 * @api
 * @Flow\Proxy(false)
 */
class SharedMemoryBackend extends AbstractBackend
{
    const READ_ACCESS = 0;
    const WRITE_ACCESS = 1;

    /**
     * @var integer
     */
    protected $cacheRootKey;

    /**
     * @var resource
     */
    protected $mutex;

    /**
     * @var resource
     */
    protected $resource;

    /**
     * @var integer
     */
    protected $metadataKey;

    /**
     * @var integer
     */
    protected $writers = 0;

    /**
     * @var integer
     */
    protected $readers = 0;

    /**
     * @param FrontendInterface $cache
     */
    public function setCache(FrontendInterface $cache)
    {
        parent::setCache($cache);

        $mutexKey = crc32(FLOW_PATH_ROOT . $this->cacheIdentifier . ':mutex');
        $resourceKey = crc32(FLOW_PATH_ROOT . $this->cacheIdentifier . ':resource');

        $this->mutex = sem_get($mutexKey, 1);
        $this->resource = sem_get($resourceKey, 1);

        $this->metadataKey = crc32(FLOW_PATH_ROOT . $this->cacheIdentifier . ':entries');
        $this->cacheRootKey = crc32(FLOW_PATH_ROOT . $this->cacheIdentifier);
    }

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry. If the backend does not support tags, this option can be ignored.
     * @param integer $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
     * @return void
     * @api
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null)
    {
        $this->acquire(self::WRITE_ACCESS);
        $entryKey = $this->buildEntryIdentifierKey($entryIdentifier);
        $entry = new SharedMemoryEntry($entryIdentifier, $entryKey);
        $entry->write($data);
        $this->registerEntryIdentifier($entryIdentifier, $entryKey);
        $this->release(self::WRITE_ACCESS);
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return mixed The cache entry's content as a string or FALSE if the cache entry could not be loaded
     * @api
     */
    public function get($entryIdentifier)
    {
        $this->acquire(self::READ_ACCESS);
        $entryKey = $this->buildEntryIdentifierKey($entryIdentifier);
        $entry = new SharedMemoryEntry($entryIdentifier, $entryKey);
        $data = $entry->read();
        $this->release(self::READ_ACCESS);
        return $data;
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     * @return boolean TRUE if such an entry exists, FALSE if not
     * @api
     */
    public function has($entryIdentifier)
    {
        $this->acquire(self::READ_ACCESS);
        $entryKey = $this->buildEntryIdentifierKey($entryIdentifier);
        $entry = new SharedMemoryEntry($entryIdentifier, $entryKey);
        $status = $entry->exists();
        $this->release(self::READ_ACCESS);
        return $status;
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @return boolean TRUE if (at least) an entry could be removed or FALSE if no entry was found
     * @api
     */
    public function remove($entryIdentifier)
    {
        $this->acquire(self::WRITE_ACCESS);
        $entryKey = $this->buildEntryIdentifierKey($entryIdentifier);
        $entry = new SharedMemoryEntry($entryIdentifier, $entryKey);
        if ($entry->exists()) {
            $entry->delete();
        }
        $this->unregisterEntryIdentifier($entryIdentifier);
        $this->release(self::WRITE_ACCESS);
    }

    /**
     * Removes all cache entries of this cache.
     *
     * @return void
     * @api
     */
    public function flush()
    {
        $this->acquire(self::WRITE_ACCESS);
        foreach ($this->getEntryIdentifiers() as $entryIdentifier => $entryKey) {
            $entry = new SharedMemoryEntry($entryIdentifier, $entryKey);
            $entry->delete();
        }
        $this->flushEntryIdentifiers();
        $this->release(self::WRITE_ACCESS);
    }

    /**
     * Does garbage collection
     *
     * @return void
     * @api
     */
    public function collectGarbage()
    {
        // Todo
    }

    /**
     * @param string $entryIdentifier
     * @return string
     */
    protected function buildEntryIdentifierKey($entryIdentifier)
    {
        return crc32(FLOW_PATH_ROOT . $this->cacheIdentifier . $entryIdentifier);
    }

    /**
     * @param integer $accessType
     */
    protected function acquire($accessType = self::READ_ACCESS) {
        if ($accessType == self::WRITE_ACCESS) {
            sem_acquire($this->mutex);
            $this->writers++;
            sem_release($this->mutex);
            sem_acquire($this->resource);
        } else {
            sem_acquire($this->mutex);
            if ($this->writers > 0 || $this->readers == 0) {
                sem_release($this->mutex);
                sem_acquire($this->resource);
                sem_acquire($this->mutex);
            }
            $this->readers++;
            sem_release($this->mutex);
        }
    }

    /**
     * @param integer $accessType
     */
    protected function release($accessType = self::READ_ACCESS) {
        if ($accessType == self::WRITE_ACCESS) {
            sem_acquire($this->mutex);
            $this->writers--;
            sem_release($this->mutex);
            sem_release($this->resource);
        } else {
            sem_acquire($this->mutex);
            $this->readers--;

            if ($this->readers == 0)
                sem_release($this->resource);

            sem_release($this->mutex);
        }
    }

    /**
     * @param string $entryIdentifier
     * @param integer $entryKey
     */
    protected function registerEntryIdentifier($entryIdentifier, $entryKey)
    {
        $resource = shm_attach($this->metadataKey);
        $data = $this->getVar($resource, $this->metadataKey) ?: [];
        $data[$entryIdentifier] = $entryKey;
        shm_put_var($resource, $this->metadataKey, $data);
        shm_detach($resource);

    }

    /**
     * @param string $entryIdentifier
     */
    protected function unregisterEntryIdentifier($entryIdentifier)
    {
        $resource = shm_attach($this->metadataKey);
        $data = $this->getVar($resource, $this->metadataKey);
        unset($data[$entryIdentifier]);
        shm_put_var($resource, $this->metadataKey, $data);
        shm_detach($resource);
    }

    /**
     * @return void
     */
    protected function flushEntryIdentifiers()
    {
        $resource = shm_attach($this->metadataKey);

        shm_put_var($resource, $this->metadataKey, []);
        shm_detach($resource);
    }

    /**
     * @return array
     */
    protected function getEntryIdentifiers()
    {
        $resource = shm_attach($this->metadataKey);
        $data = $this->getVar($resource, $this->metadataKey) ?: [];
        shm_detach($resource);
        return $data;
    }

    /**
     * @param resource $resource
     * @param integer $key
     * @return mixed
     */
    protected function getVar($resource, $key)
    {
        return shm_has_var($resource, $key) ? shm_get_var($resource, $key) : null;
    }
}
