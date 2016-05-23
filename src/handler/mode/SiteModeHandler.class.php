<?php
namespace handler\mode;

use data\AbstractData;
use data\file\File;
use data\file\ForbiddenException;
use data\file\UnexpectedTypeException;
use data\InvalidDataException;
use data\net\MAPUrl;
use data\oop\ClassObject;
use data\oop\InstanceException;
use data\peer\http\FormStatusEnum;
use data\peer\http\StatusEnum;
use extension\AbstractSitePage;
use util\Bucket;
use util\Logger;
use util\MAPException;
use xml\Tree;
use xml\XSLProcessor;

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
		$namespace  = $pageClass->get();
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

		/* @var $page AbstractSitePage */
		$page = new $namespace($this->config);

		if (!$page->access()) {
			$this->outputFailurePage(new StatusEnum(StatusEnum::FORBIDDEN));
			Logger::debug(
					'FORBIDDEN because: AbstractSitePage::access returns FALSE',
					array(
							'classObject' => $pageClass,
							'page'        => $page
					)
			);
			return;
		}

		if ($this->isStatusInit()) {
			$formStatus = new FormStatusEnum(FormStatusEnum::INIT);
		}
		elseif ($this->isStatusRepeated()) {
			$formStatus = new FormStatusEnum(FormStatusEnum::REPEATED);
		}
		elseif ($this->isStatusRestored()) {
			$formStatus = new FormStatusEnum(FormStatusEnum::RESTORED);

			foreach ($this->getForm()['data'] as $formDataName => $formDataValue) {
				$page->setFormData($formDataName, $formDataValue);
			}
		}

		if (isset($formStatus)) {
			$page->setFormData('formId', self::generateFormId());
			$page->view();
		}
		else {
			if ($this->fillPageObject($page, $_POST) && $page->check()) {
				$formStatus = new FormStatusEnum(FormStatusEnum::ACCEPTED);
				$this->closeForm($page->formId);
			}
			else {
				$formStatus = new FormStatusEnum(FormStatusEnum::REJECTED);
			}
		}

		$this->handleResponse($page->getResponse(), $formStatus, $stylesheet);
	}

	/**
	 * @throws MAPException
	 * @throws UnexpectedTypeException
	 */
	public function handleResponse(Tree $response, FormStatusEnum $formStatus, File $stylesheet) {
		$response->getRootNode()->getChildList('form')[0]->setAttribute(
				'status',
				$formStatus->get()
		);

		echo (new XSLProcessor())
				->setStylesheetFile($stylesheet)
				->setDocument($response->toDomDoc())
				->transform();

		# debug XML-File
		if ($this->request->getMode()->getSetting($this->config, 'debugResponseFile', false) === true) {
			(new File(sys_get_temp_dir()))
					->attach(self::TEMP_SUB_DIR)
					->makeDir()
					->attach(self::TEMP_RESPONSE_FILE)
					->putContents($response->toSource(), false);
		}
	}

	/**
	 * @throws InvalidDataException
	 */
	protected function fillPageObject(AbstractSitePage $page, array $dataList):bool {
		foreach ((new ClassObject(AbstractSitePage::class))->getPropertyList() as $property) {
			foreach ($property->getAnnotationList() as $annotation) {
				if ($annotation->isName('formData')) {
					$annotation->assertIsBool('optional');

					if ($annotation->hasParam('pattern')) {
						$annotation->assertIsString('pattern');

						$pattern = $annotation->getParam('pattern');
					}
					$value = $dataList[$property->getName()] ?? null;

					if (is_string($value) && AbstractData::isMatching($pattern ?? '^.+$', $value)) {
						$property->setValue($page, $dataList);
					}
					elseif ($annotation->getParam('optional') === false) {
						Logger::debug(
								'REJECTED because: expected property',
								array(
										'page'           => $page,
										'propertyObject' => $property,
										'value'          => $value
								)
						);
						return false;
					}
				}
			}
		}
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
			if ($formId === null || (self::isFormId($formId) && $form['data']['formId'] === $formId)) {
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
		if (self::isFormId($formId)) {
			$this->setForm(['formId' => $formId], true);
		}
	}

	protected function isFormClose(string $formId = null) {
		$form = $this->getForm($formId);
		return count($form) && $form['close'] === true;
	}

	protected function isStatusInit():bool {
		return !count($_POST) && !$this->isStatusRestored();
	}

	protected function isStatusRestored():bool {
		return !count($_POST) && count($this->getForm()) && !$this->isFormClose();
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
