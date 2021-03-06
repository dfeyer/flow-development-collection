<?php
namespace TYPO3\Flow\Tests\Persistence\Fixture\Model;

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

/**
 * A model fixture used for testing the persistence manager
 *
 * @Flow\Entity
 */
class Entity2 implements \TYPO3\Flow\Aop\ProxyInterface
{
    /**
     * Just a normal string
     *
     * @var string
     */
    public $someString;

    /**
     * @var integer
     */
    public $someInteger;

    /**
     * @var \TYPO3\Flow\Tests\Persistence\Fixture\Model\Entity3
     */
    public $someReference;

    /**
     * @var array
     */
    public $someReferenceArray = array();

    /**
     * Invokes the joinpoint - calls the target methods.
     *
     * @param \TYPO3\Flow\Aop\JoinPointInterface $joinPoint The join point
     * @return mixed Result of the target (ie. original) method
     */
    public function Flow_Aop_Proxy_invokeJoinPoint(\TYPO3\Flow\Aop\JoinPointInterface $joinPoint)
    {
    }

    /**
     * A stub to satisfy the Flow Proxy Interface
     */
    public function __wakeup()
    {
    }
}
