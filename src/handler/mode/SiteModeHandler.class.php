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
use handler\mode\site\RejectException;
use util\Bucket;
use util\Logger;
use util\MAPException;
use xml\Node;
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
		$page = new $namespace($this->config, $this->request);

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
			$page->view();
		}
		else {
			if ($this->fillPageObject($page, $_POST)) {
				try {
					if ($page->check()) {
						$formStatus = new FormStatusEnum(FormStatusEnum::ACCEPTED);
						$this->closeForm($page->formId);
					}
				}
				catch (RejectException $e) {
					$page->reject($e->getReason());
				}
			}
			else {
				$page->reject('FORM_DATA');
			}

			if (!isset($formStatus)) {
				$formStatus = new FormStatusEnum(FormStatusEnum::REJECTED);
			}
		}

		$this->handleResponse($page, $formStatus, $stylesheet);
	}

	/**
	 * @throws MAPException
	 * @throws UnexpectedTypeException
	 */
	public function handleResponse(AbstractSitePage $page, FormStatusEnum $formStatus, File $stylesheet) {
		if ($formStatus->get() === FormStatusEnum::REJECTED) {
			foreach ((new ClassObject(get_class($page)))->getPropertyList() as $property) {
				if ($property->hasAnnotation('formData')) {
					$property->assertIsPublic();

					if ($property->getValue($page) !== null) {
						$page->setFormData($property->getName(), $property->getValue($page));
						$formData[$property->getName()] = $property->getValue($page);
					}
				}
			}
			$this->setForm($formData ?? array());
		}

		if ($page->getFormData('formId') === null) {
			$page->setFormData('formId', self::generateFormId());
		}

		$response = $page->getResponse();
		$response->getRootNode()->getChildList('form')[0]->setAttribute(
				'status',
				$formStatus->get()
		);

		# request into response
		$requestNode = $response->getRootNode()->addChild(
				(new Node('request'))
						->withChild(
								(new Node('mode'))->setContent($this->request->getMode()->get())
						)
						->withChild(
								(new Node('area'))->setContent($this->request->getArea()->get())
						)
						->withChild(
								(new Node('page'))->setContent($this->request->getPage())
						)
		);
		$inputNode   = $requestNode->addChild(new Node('inputs'));
		foreach ($this->request->getInputList() as $input) {
			$inputNode->addChild(
					(new Node('input'))->setContent($input)
			);
		}

		# session into response
		$group = $this->request->getMode()->getConfigGroup();
		if (!$this->config->isNull($group, 'sessionIntoResponse')) {
			$this->config->assertIsArray($group, 'sessionIntoResponse');

			$sessionNode = $response->getRootNode()->addChild(new Node('session'));
			foreach ($this->config->get($group, 'sessionIntoResponse') as $sessionGroup) {
				$sessionNode->addChild(
						(new Node($sessionGroup))->fromArray($_SESSION[$sessionGroup] ?? array())
				);
			}
		}

		echo (new XSLProcessor())
				->setStylesheetFile($stylesheet)
				->setDocument($response->toDomDoc())
				->transform();

		# debug XML-File
		if ($this->config->isTrue($group, 'debugResponseFile')) {
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
		foreach ((new ClassObject(get_class($page)))->getPropertyList() as $property) {
			if ($property->hasAnnotation('formData')) {
				$annotation = $property->getAnnotation('formData');
				$annotation->assertIsBool('optional');

				if ($annotation->hasParam('pattern')) {
					$annotation->assertIsString('pattern');
				}

				$value   = $dataList[$property->getName()] ?? null;
				$pattern = $annotation->getParam('pattern', '^.+$');

				if (is_string($value) && AbstractData::isMatching($pattern, $value)) {
					$property->setValue($page, $value);
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
			if ($formId === null || (self::isFormId($formId) && ($form['data']['formId'] ?? null) === $formId)) {
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
