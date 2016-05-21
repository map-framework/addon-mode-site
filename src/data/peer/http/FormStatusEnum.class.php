<?php
namespace data\peer\http;

use data\AbstractEnum;
use extension\AbstractSitePage;
use handler\mode\SiteModeHandler;

/**
 * This file is part of the MAP-Framework.
 *
 * @author    Michael Piontkowski <mail@mpiontkowski.de>
 * @copyright Copyright 2016 Michael Piontkowski
 * @license   https://raw.githubusercontent.com/map-framework/map/master/LICENSE.txt Apache License 2.0
 *
 * @see       SiteModeHandler
 * @see       AbstractSitePage
 */
class FormStatusEnum extends AbstractEnum {

	const INIT     = 'INIT';
	const RESTORED = 'RESTORED';
	const ACCEPTED = 'ACCEPTED';
	const REJECTED = 'REJECTED';
	const REPEATED = 'REPEATED';

}
