<?php
namespace extension;

use handler\mode\SiteModeHandler;
use util\Bucket;
use xml\Node;
use xml\Tree;

/**
 * This file is part of the MAP-Framework.
 *
 * @author    Michael Piontkowski <mail@mpiontkowski.de>
 * @copyright Copyright 2016 Michael Piontkowski
 * @license   https://raw.githubusercontent.com/map-framework/map/master/LICENSE.txt Apache License 2.0
 *
 * @see       SiteModeHandler
 *
 * Declare public class properties with formData-Annotation. These will filled with $_POST data.
 *            required parameters:
 *            - (bool)   optional => The check-method is only calling if all properties where optional is TRUE are
 *                                   filled.
 *            optional parameters:
 *            - (string) pattern  => Property will only fill if pattern is matching the value.
 */
abstract class AbstractSitePage {

	/**
	 * @var Bucket
	 */
	protected $config;

	/**
	 * @var Tree
	 */
	protected $response;

	/**
	 * @var Node
	 */
	protected $responseForm;

	/**
	 * @var      string
	 * @formData optional=FALSE pattern='^[0-9a-fA-F]{16}$'
	 */
	public $formId;

	/**
	 * Check in this method the user authorization.
	 *
	 * @return bool control the access on AbstractSitePage::view and AbstractSitePage::check.
	 *              FALSE => FORBIDDEN
	 *              TRUE  => granted access for this request
	 */
	abstract public function access():bool;

	/**
	 * This method is used to provide the page.
	 */
	abstract public function view();

	/**
	 * In this method you can handle the submitted form data.
	 *
	 * @return bool indicate the request:
	 *              FALSE => @see FormStatusEnum::$REJECTED
	 *              TRUE  => @see FormStatusEnum::$ACCEPTED
	 */
	abstract public function check():bool;

	public function __construct(Bucket $config) {
		$this->config       = $config;
		$this->response     = new Tree('document');
		$this->responseForm = $this->response->getRootNode()->addChild(new Node('form'));
	}

	final public function setFormData(string $dataName, string $dataValue):AbstractSitePage {
		$this->responseForm->addChild(
				(new Node($dataName))->setContent($dataValue)
		);
		return $this;
	}

	final public function getResponse():Tree {
		return clone $this->response;
	}

}
