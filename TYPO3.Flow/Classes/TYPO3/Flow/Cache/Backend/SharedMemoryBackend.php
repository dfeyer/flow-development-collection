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

use TYPO3\Flow\Cache\CacheManager;
use TYPO3\Flow\Cache\Exception;
use TYPO3\Flow\Cache\Frontend\PhpFrontend;
use TYPO3\Flow\Cache\Frontend\FrontendInterface;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\Lock\Lock;
use TYPO3\Flow\Utility\OpcodeCacheHelper;

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
     * @var resource
     */
    protected $mutex;

    /**
     * @var integer
     */
    protected $mutexKey;

    /**
     * @var resource
     */
    protected $resource;

    /**
     * @var integer
     */
    protected $resourceKey;

    /**
     * @var integer
     */
    protected $cacheKey;

    /**
     * @var resource
     */
    protected $cacheResource;

    /**
     * @var integer
     */
    protected $writers = 0;

    /**
     * @var integer
     */
    protected $readers = 0;

    /**
     * Sets a reference to the cache frontend which uses this backend and
     * initializes the default cache directory.
     *
     * @param \TYPO3\Flow\Cache\Frontend\FrontendInterface $cache The cache frontend
     * @return void
     */
    public function setCache(FrontendInterface $cache)
    {
        parent::setCache($cache);

        $cacheIdentifier = FLOW_PATH_ROOT . '::' . $this->cacheIdentifier;

        $this->mutexKey = crc32($cacheIdentifier. '::mutex');
        $this->resourceKey = crc32($cacheIdentifier . '::ressource');

        $this->mutex = sem_get($this->mutexKey);
        $this->resource = sem_get($this->resourceKey);

        $this->cacheKey = crc32($cacheIdentifier);

        $this->cacheResource = shm_attach($this->resourceKey, 1024000);
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
        if (shm_has_var($this->cacheResource, $this->cacheKey)) {
            $cacheContent = $this->getRawCacheContent();
        } else {
            $cacheContent = [];
        }
        $cacheContent[$entryIdentifier] = $data;
        $cacheContent = gzencode(serialize($cacheContent));
//        $dataLength = (ceil((strlen(serialize($cacheContent)) + 13 - 1) / 4) * 4) + 4;
        shm_put_var($this->cacheResource, $this->cacheKey, $cacheContent);
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
        if (!shm_has_var($this->cacheResource, $this->cacheKey)) {
            $this->release(self::READ_ACCESS);
            return null;
        }
        $cacheContent = $this->getRawCacheContent();
        $this->release(self::READ_ACCESS);
        if (!isset($cacheContent[$entryIdentifier])) {
            return null;
        }
        return $cacheContent[$entryIdentifier];
    }

    /**
     * @return mixed
     */
    protected function getRawCacheContent()
    {
        $data = shm_get_var($this->cacheResource, $this->cacheKey);
        return $data ? unserialize(gzdecode($data)) : null;
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
        if (!shm_has_var($this->cacheResource, $this->cacheKey)) {
            $this->release(self::READ_ACCESS);
            return false;
        }
        $cacheContent = $this->getRawCacheContent();
        $this->release(self::READ_ACCESS);
        if (!isset($cacheContent[$entryIdentifier])) {
            return false;
        }
        return true;
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
        if (shm_has_var($this->cacheResource, $this->cacheKey)) {
            $cacheContent = $this->getRawCacheContent();
        } else {
            $cacheContent = [];
        }
        unset($cacheContent[$entryIdentifier]);
        shm_put_var($this->cacheResource, $this->cacheKey, $cacheContent);
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
        shm_put_var($this->cacheResource, $this->cacheKey, null);
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

    }

    /**
     * Acquire the resource
     *
     * @param integer $accessType
     * @return void
     */
    protected function acquire($accessType = self::READ_ACCESS)
    {
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
     * Release the resource
     *
     * @param integer $accessType
     * @return void
     */
    protected function release($accessType = self::READ_ACCESS)
    {
        if ($accessType == self::WRITE_ACCESS) {
            sem_acquire($this->mutex);
            $this->writers--;
            sem_release($this->mutex);
            @sem_release($this->resource);
        } else {
            sem_acquire($this->mutex);
            $this->readers--;
            if ($this->readers == 0) {
                @sem_release($this->resource);
            }
            sem_release($this->mutex);
        }
    }
}
