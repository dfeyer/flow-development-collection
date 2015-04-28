<?php
namespace TYPO3\Eel\FlowQuery\Operations;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.Eel".             *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\Flow\Annotations as Flow;

/**
 * Remove another $flowQuery object from the current one.
 */
class RemoveOperation extends AbstractOperation {

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	static protected $shortName = 'remove';

	/**
	 * {@inheritdoc}
	 *
	 * @param FlowQuery $flowQuery the FlowQuery object
	 * @param array $arguments the elements to add (as array in index 0)
	 * @return void
	 */
	public function evaluate(FlowQuery $flowQuery, array $arguments) {
		$output = array();
		foreach ($flowQuery->getContext() as $element) {
			$output[] = $element;
		}
		if (isset($arguments[0])) {
			if (is_array($arguments[0]) || $arguments[0] instanceof \Traversable) {
				foreach ($arguments[0] as $element) {
					$output = $this->removeCurrentElement($output, $element);
				}
			} else {
				$output = $this->removeCurrentElement($output, $arguments[0]);
			}
		}
		$flowQuery->setContext($output);
	}

	/**
	 * @param array $output
	 * @param mixed $element
	 * @return array
	 */
	protected function removeCurrentElement(array $output, $element) {
		foreach ($output as $key => $currentElement) {
			if ($currentElement === $element) {
				unset($output[$key]);
			}
		}
		return $output;
	}
}
