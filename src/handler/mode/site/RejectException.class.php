<?php
namespace handler\mode\site;

use util\MAPException;

/**
 * This file is part of the MAP-Framework.
 *
 * @author    Michael Piontkowski <mail@mpiontkowski.de>
 * @copyright Copyright 2016 Michael Piontkowski
 * @license   https://raw.githubusercontent.com/map-framework/map/master/LICENSE.txt Apache License 2.0
 *
 * This exception is an alternative way to @see AbstractSitePage::reject a request.
 */
class RejectException extends MAPException {

	public function __construct(string $reason, string $reference = null) {
		parent::__construct('The request is rejected.');
		$this->setData('reason', $reason);
		$this->setData('reference', $reference);
	}

	public function getReason():string {
		return $this->getData('reason');
	}

	/**
	 * @return string|null
	 */
	public function getReference() {
		return $this->getData('reference');
	}

}
