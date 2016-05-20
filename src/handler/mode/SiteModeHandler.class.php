<?php
namespace handler\mode;

use data\file\ForbiddenException;
use data\file\UnexpectedTypeException;
use data\oop\ClassObject;
use data\oop\InstanceException;
use data\peer\http\StatusEnum;
use extension\AbstractSitePage;
use util\Bucket;
use data\file\File;
use data\net\MAPUrl;
use util\Logger;
use util\MAPException;

/**
 * This file is part of the MAP-Framework.
 *
 * @author    Michael Piontkowski <mail@mpiontkowski.de>
 * @copyright Copyright 2016 Michael Piontkowski
 * @license   https://raw.githubusercontent.com/map-framework/map/master/LICENSE.txt Apache License 2.0
 */
class SiteModeHandler extends AbstractModeHandler {

	const FORM_ID_LENGTH = 16;

	const TEMP_SUB_DIR       = 'map';
	const TEMP_RESPONSE_FILE = 'lastSiteResponse.xml';

	const FORMAT_NAMESPACE  = 'area\%s\logic\site\%sPage';
	const FORMAT_STYLESHEET = 'private/src/area/%s/app/view/site/%s.xsl';

	/**
	 * @var Bucket
	 */
	protected $forms;

	public function __construct(Bucket $config, MAPUrl $request) {
		parent::__construct($config, $request);

		$this->forms = new Bucket($_SESSION['form'] ?? array());
	}

	public function __destruct() {
		$_SESSION['form'] = $this->forms->toArray();
	}

	/**
	 * @throws MAPException
	 * @throws ForbiddenException
	 * @throws UnexpectedTypeException
	 * @throws InstanceException
	 */
	public function handle() {
		$pageClass  = $this->getPageClass();
		$stylesheet = $this->getStylesheet();

		if (!$pageClass->exists()) {
			$this->outputFailurePage(new StatusEnum(StatusEnum::NOT_FOUND));
			Logger::debug('NOT_FOUND because: Page-Class not found', ['classObject' => $pageClass]);
			return;
		}

		$pageClass->assertIsNotAbstract();
		$pageClass->assertIsNotInterface();
		$pageClass->assertIsChildOf(new ClassObject(AbstractSitePage::class));

		if (!$stylesheet->exists()) {
			$this->outputFailurePage(new StatusEnum(StatusEnum::NOT_FOUND));
			Logger::debug('NOT_FOUND because: Stylesheet-File not found', ['file' => $stylesheet]);
			return;
		}

		$stylesheet->assertIsFile();
		$stylesheet->assertIsReadable();

		$namespace = $pageClass->get();
		$page      = new $namespace($this->config);

		// TODO continue implement method
	}

	protected function fillPageObject(ClassObject $pageClass, $page, array $formData):bool {
		// TODO fill properties
		// TODO return false if required property not exists
		return true;
	}

	protected function getPageClass():ClassObject {
		return new ClassObject(
				sprintf(
						self::FORMAT_NAMESPACE,
						$this->request->getArea(),
						ucfirst($this->request->getPage())
				)
		);
	}

	protected function getStylesheet():File {
		return new File(
				sprintf(
						self::FORMAT_STYLESHEET,
						$this->request->getArea(),
						$this->request->getPage()
				)
		);
	}

	protected function getForm(string $formId = null):array {
		if ($this->forms->isArray($this->request->getArea(), $this->request->getPage())) {
			$form = $this->forms->get($this->request->getArea(), $this->request->getPage());
			if ($formId === null || $form['data']['formId'] === $formId) {
				return $form;
			}
		}
		return array();
	}

	protected function setForm(array $data, bool $close = false) {
		$this->forms->set(
				$this->request->getArea(),
				$this->request->getPage(),
				array(
						'data'  => $data,
						'close' => $close
				)
		);
	}

	protected function closeForm(string $formId) {
		$this->setForm(['formId' => $formId], true);
	}

	protected function isFormClose(string $formId = null) {
		$form = $this->getForm($formId);
		return count($form) && $form['close'] === true;
	}

	protected function isStatusInit():bool {
		return !count($_POST) && !$this->isStatusRestored();
	}

	protected function isStatusRestored():bool {
		return !count($_POST) && !$this->isFormClose();
	}

	protected function isStatusRepeated():bool {
		return isset($_POST['formId']) && self::isFormId($_POST['formId']) && $this->isFormClose($_POST['formId']);
	}

	final public static function generateFormId():string {
		return bin2hex(random_bytes(self::FORM_ID_LENGTH / 2));
	}

	final public static function isFormId(string $formId):bool {
		return strlen($formId) === self::FORM_ID_LENGTH && ctype_xdigit($formId);
	}

}

