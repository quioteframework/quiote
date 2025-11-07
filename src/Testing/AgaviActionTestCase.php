<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+

namespace Agavi\Testing;

use Agavi\Request\AgaviWebRequest;
use Agavi\Execution\ValidationService;
use Agavi\Testing\PHPUnit\Constraint\AgaviConstraintActionHandlesMethod;
use Agavi\Validator\AgaviValidationArgument;
use Agavi\Logging\AgaviDebugLogger;

/**
 * AgaviActionTestCase is the base class for all action testcases and provides
 * the necessary assertions
 * 
 * 
 * @package    agavi
 * @subpackage testing
 *
 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
 * @copyright  The Agavi Project
 *
 * @since      1.0.0
 *
 * @version    $Id$
 */
abstract class AgaviActionTestCase extends AgaviFragmentTestCase
{
	/**
	 * @var        string the name of the resulting view
	 */
	protected $viewName;

	/**
	 * @var        string the name of the resulting view's module
	 */
	protected $viewModuleName;

	/**
	 * run the action for this testcase
	 *  
	 * @return     void
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function runAction()
	{
		// Container removed in modernized pipeline: execute action manually.
		$action = $this->createActionInstance();
		$methodLogical = strtolower($this->requestMethod ?? 'read');
		$method = ucfirst($methodLogical);
		$execMethod = 'execute' . $method;
		$hasSpecific = is_callable([$action, $execMethod]);
		if (!$hasSpecific) {
			$execMethod = 'execute';
		}
		$request = $this->getContext()->getRequest();
		/** @var AgaviWebRequest $request */
		// Ensure we have concrete AgaviWebRequest (context should supply it). If not, attempt adapt.
		if (!($request instanceof AgaviWebRequest)) {
			try {
				$request = $this->getContext()->getRequest();
			} catch (\Throwable) {
			}
		}
		$resultView = null;

		// If validation already determined to have failed, emulate framework behavior by invoking
		// handleError<Method>() or handleError() without executing the core action logic.
		if ($this->validationSuccess === false) {
			// Correct Agavi semantics: prefer handle<Method>Error, then generic handleError, else default '<Action>Error'
			$errorHandler = 'handle' . $method . 'Error';
			try {
				if (is_callable([$action, $errorHandler])) {
					$resultView = $action->$errorHandler($request);
				} elseif (is_callable([$action, 'handleError'])) {
					$resultView = $action->handleError($request);
				} else {
					$resultView = 'Error';
				}
			} catch (\Throwable) {
				$resultView = 'Error';
			}
			$this->viewModuleName = $this->moduleName;
			$raw = $resultView ?? 'Error';
			$this->viewName = $this->normalizeViewName($raw);
			return; // Skip normal execution path
		}
		// If action is simple and neither specific nor generic execute* exists, use default view.
		if ($action->isSimple() && !$hasSpecific && !is_callable([$action, $execMethod])) {
			$resultView = $action->getDefaultViewName();
		} else {
			try {
				if (is_callable([$action, $execMethod])) {
					$resultView = $action->$execMethod($request);
				} elseif ($action->isSimple()) {
					$resultView = $action->getDefaultViewName();
				}
			} catch (\Throwable $e) {
				if (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS')) {
					try {
						AgaviDebugLogger::debug('[TestDebug][runAction][Exception] ' . get_class($e) . ': ' . $e->getMessage(), $this->getContext());
					} catch (\Throwable) {
					}
				}
				$resultView = 'Error';
			}
		}
		if (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS')) {
			try {
				AgaviDebugLogger::debug('[TestDebug][runAction] rawResult=' . var_export($resultView, true) . ' method=' . $execMethod . ' validationSuccess=' . ($this->validationSuccess ? '1' : '0'), $this->getContext());
			} catch (\Throwable) {
			}
		}
		$this->viewModuleName = $this->moduleName;
		// Store raw result (short view name as returned by action). If null assume Success.
		$raw = $resultView ?? 'Success';
		if (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS')) {
			try {
				AgaviDebugLogger::debug('[TestDebug][runAction] preNormalizeRaw=' . $raw, $this->getContext());
			} catch (\Throwable) {
			}
		}
		// Normalize using shared logic (applies module directive + canonicalization) so that
		// legacy semantics <ActionName><ShortViewName> are preserved without ad-hoc prefixing.
		$this->viewName = $this->normalizeViewName($raw);
		if (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS')) {
			try {
				AgaviDebugLogger::debug('[TestDebug][runAction] normalizedView=' . $this->viewName, $this->getContext());
			} catch (\Throwable) {
			}
		}


		// Credential normalization now occurs in JakamoBaseAction::getCredentials().
	}

	/**
	 * register the validators for this testcase
	 *  
	 * @return     void
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function performValidation()
	{
		// Real validation pipeline: use ValidationService to mirror production middleware behavior.
		$action = $this->createActionInstance();
		// IMPORTANT: validation XML <validator method="write"> expects lowercase tokens.
		// We still need Ucfirst variant inside ValidationService when constructing validate* methods.
		$methodToken = strtolower($this->requestMethod ?? 'read');
		// Acquire canonical AgaviWebRequest (it already holds parameters injected via helpers)
		$request = $this->getContext()->getRequest();
		$dbg = (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS'));
		if ($dbg) {
			try {
				AgaviDebugLogger::debug('[TestDebug][performValidation] methodToken=' . $methodToken . ' reqId=' . spl_object_id($request), $this->getContext());
			} catch (\Throwable) {
			}
		}
		// Controlled debug: only emit pre-validation parameter dump when explicitly enabled
		if (getenv('AGAVI_DEBUG_VALIDATION') || getenv('DEBUG_TESTS')) {
			try {
				$rawParams = method_exists($request, 'getParameters') ? $request->getParameters('parameters') : [];
				$flat = [];
				if (class_exists('Agavi\\Util\\AgaviArrayPathDefinition')) {
					$flat = \Agavi\Util\AgaviArrayPathDefinition::getFlatKeyNames($rawParams);
				}
				AgaviDebugLogger::debug('[TestDebug][PreValidation] action=' . ($this->actionName ?? '') . ' method=' . $methodToken . ' keys=' . implode(',', $flat) . ' raw=' . json_encode($rawParams), $this->getContext());
			} catch (\Throwable $e) {
				try {
					AgaviDebugLogger::debug('[TestDebug][PreValidation] exception dumping params: ' . $e->getMessage(), $this->getContext());
				} catch (\Throwable) {
				}
			}
		}
		$module = $this->moduleName;
		$actionName = $this->actionName;
		try {
			$vm = $this->getContext()->createInstanceFor('validation_manager');
			if ($this->container && method_exists($this->container, 'setValidationManager')) {
				if (method_exists($this->container, 'setArguments') && method_exists($request, 'getParameters')) {
					try {
						$this->container->setArguments($request->getParameters('parameters') ?? []);
					} catch (\Throwable) {
					}
				}
				$this->container->setValidationManager($vm);
			}
			if ($dbg) {
				try {
					$rp = method_exists($request, 'getParameters') ? $request->getParameters('runtime') : [];
					AgaviDebugLogger::debug('[TestDebug][RuntimeBeforeValidation] keys=' . implode(',', array_keys($rp)), $this->getContext());
				} catch (\Throwable) {
				}
			}
			$service = new ValidationService($vm);
			$loaded = [];
			$primaryName = $actionName;
			$alternativeName = null;
			if (str_contains($actionName, '.')) {
				// Prefer slash form first for modern loader
				$slashFirst = str_replace('.', '/', $actionName);
				$primaryName = $slashFirst;
				$alternativeName = $actionName; // dotted fallback
			}
			if ($dbg) {
				AgaviDebugLogger::debug('[TestDebug][BeforeValidate] module=' . $module . ' primaryName=' . $primaryName . ' alternativeName=' . ($alternativeName ?? 'null') . ' method=' . $methodToken, $this->getContext());
			}
			try {
				$result = $service->validate($action, $request, $module, $primaryName, $methodToken);
				if ($dbg) {
					AgaviDebugLogger::debug('[TestDebug][AfterValidate] result->ok=' . ($result->ok ? '1' : '0'), $this->getContext());
				}
			} catch (\Throwable $e) {
				if ($dbg) {
					AgaviDebugLogger::debug('[TestDebug][ValidateException] ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), $this->getContext());
				}
				// Create a failed result
				$result = (object)['ok' => false, 'data' => []];
			}
			$this->validationSuccess = (bool)$result->ok;
			try {
				$trace = $result->data['trace'] ?? null;
				if ($trace) {
					$loaded = $trace->validatorsLoaded ?? [];
				}
			} catch (\Throwable) {
			}
			if (empty($loaded) && $alternativeName) {
				try {
					if ($dbg) {
						AgaviDebugLogger::debug('[TestDebug][ValidationFallback] retry dotted=' . $alternativeName, $this->getContext());
					}
					$result = $service->validate($action, $request, $module, $alternativeName, $methodToken);
					$this->validationSuccess = (bool)$result->ok;
					try {
						$trace = $result->data['trace'] ?? null;
						if ($trace) {
							$loaded = $trace->validatorsLoaded ?? [];
						}
					} catch (\Throwable) {
					}
				} catch (\Throwable) {
				}
			}
			if ($dbg) {
				try {
					AgaviDebugLogger::debug('[TestDebug][PostValidation] success=' . ($this->validationSuccess ? '1' : '0') . ' loadedValidators=' . implode(',', $loaded), $this->getContext());
					if (!$this->validationSuccess) {
						// EXTRA DEBUG: dump validator names + argument results
						try {
							$childs = $vm->getChilds();
							$names = [];
							foreach ($childs as $cv) {
								$names[] = method_exists($cv, 'getName') ? $cv->getName() : 'unknown';
							}
							AgaviDebugLogger::debug('[TestDebug][ValidatorsRegistered] ' . implode(',', $names), $this->getContext());
							$report = $vm->getReport();
							if ($report) {
								$argsFailed = [];
								try {
									foreach ($report->getFailedArguments() as $fa) {
										$argsFailed[] = $fa->getName();
									}
								} catch (\Throwable) {
								}
								AgaviDebugLogger::debug('[TestDebug][FailedArguments] ' . (empty($argsFailed) ? 'none' : implode(',', $argsFailed)), $this->getContext());
								$errs = $report->getErrorMessages();
								if (!empty($errs)) {
									AgaviDebugLogger::debug('[TestDebug][ErrorMessages] ' . json_encode($errs), $this->getContext());
								}
							}
						} catch (\Throwable $ie) {
							AgaviDebugLogger::debug('[TestDebug][ValidatorDumpException] ' . $ie->getMessage(), $this->getContext());
						}
					}
					if (!$this->validationSuccess && method_exists($vm, 'getReport') && $vm->getReport()) {
						$errs = $vm->getReport()->getErrors();
						$lines = [];
						foreach ($errs as $err) {
							try {
								$lines[] = ($err->getName() ? $err->getName() . ': ' : '') . $err->getMessage();
							} catch (\Throwable) {
							}
						}
						if (!empty($lines)) {
							AgaviDebugLogger::debug('[TestDebug][ValidationErrors] ' . implode(' | ', $lines), $this->getContext());
						}
					}
				} catch (\Throwable) {
				}
			}
		} catch (\Throwable $e) {
			$this->validationSuccess = false;
		}
	}

	/**
	 * asserts that the viewName is the expected value after runAction was called
	 * 
	 * @param      string the expected viewname in short form ('Success' etc)
	 * @param      string an optional message to display if the test fails
	 * 
	 * @return     void
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0 
	 */
	protected function assertViewNameEquals($expected, $message = 'Failed asserting that the view\'s name is <%1$s>.')
	{
		$expected = $this->normalizeViewName($expected);
		$this->assertEquals($expected, $this->viewName, sprintf($message, $expected));
	}

	/**
	 * asserts that the view's modulename is the expected value after runAction was called
	 * 
	 * @param      string the expected moduleName 
	 * @param      string an optional message to display if the test fails
	 * 
	 * @return     void
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0 
	 */
	protected function assertViewModuleNameEquals($expected, $message = 'Failed asserting that the view\'s module name is <%1$s>.')
	{
		$this->assertEquals($expected, $this->viewModuleName, sprintf($message, $expected));
	}

	/**
	 * asserts that the DefaultView is the expected 
	 * 
	 * @param     mixed A string containing the view name associated with the
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 *
	 * @param      string an optional message to display if the test fails
	 * 
	 * @return     void
	 * 
	 * @see        AgaviAction::getDefaultViewName()
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0 
	 */
	protected function assertDefaultView($expected, $message = 'Failed asserting that the defaultView is the expected value.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertEquals($expected, $actionInstance->getDefaultViewName(), $message);
	}

	/**
	 * assert that the action handles the given request method
	 * 
	 * @param      string  the method name
	 * @param      boolean true if the generic 'execute' method should be accepted as handled
	 * @param      string  an optional message to display if the test fails
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertHandlesMethod($method, $acceptGeneric = true, $message = '')
	{
		$actionInstance = $this->createActionInstance();
		$constraint = new AgaviConstraintActionHandlesMethod($actionInstance, $acceptGeneric);

		self::assertThat($method, $constraint, $message);
	}

	/**
	 * assert that the action does not handle the given request method
	 * 
	 * @param      string  the method name
	 * @param      boolean true if the generic 'execute' method should be accepted as handled
	 * @param      string  an optional message to display if the test fails
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertNotHandlesMethod($method, $acceptGeneric = true, $message = '')
	{
		$actionInstance = $this->createActionInstance();
		$constraint = self::logicalNot(new AgaviConstraintActionHandlesMethod($actionInstance, $acceptGeneric));

		self::assertThat($method, $constraint, $message);
	}

	/**
	 * assert that the action is simple
	 * 
	 * @param      string  an optional message to display if the test fails
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertIsSimple($message = 'Failed asserting that the action is simple.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertTrue($actionInstance->isSimple(), $message);
	}

	/**
	 * assert that the action is not simple
	 * 
	 * @param      string  an optional message to display if the test fails
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertIsNotSimple($message = 'Failed asserting that the action is not simple.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertFalse($actionInstance->isSimple(), $message);
	}

	/**
	 * asserts that the given argument has been touched by a validator
	 * 
	 * This does not imply that the validation failed or succeeded, just
	 * that a validator attempted to validate the given argument
	 * 
	 * @param      string the name of the argument
	 * @param      string the source of the argument
	 * @param      string an optional message 
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertValidatedArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is validated.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$this->assertTrue($report->isArgumentValidated(new AgaviValidationArgument($argumentName, $source)), sprintf($message, $argumentName));
	}

	/**
	 * asserts that the given argument has failed the validation
	 * 
	 * @param      string the name of the argument
	 * @param      string the source of the argument
	 * @param      string an optional message 
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertFailedArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is failed.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$this->assertTrue($report->isArgumentFailed(new AgaviValidationArgument($argumentName, $source)), sprintf($message, $argumentName));
	}

	/**
	 * asserts that the given argument has succeeded the validation
	 * 
	 * @param      string the name of the argument
	 * @param      string the source of the argument
	 * @param      string an optional message 
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function assertSucceededArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is succeeded.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$success = $report->isArgumentValidated(new AgaviValidationArgument($argumentName, $source)) && ! $report->isArgumentFailed(new AgaviValidationArgument($argumentName, $source));
		$this->assertTrue($success, sprintf($message, $argumentName));
	}
}
