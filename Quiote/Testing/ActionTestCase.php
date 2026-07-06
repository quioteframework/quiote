<?php
namespace Quiote\Testing;

use Quiote\Execution\ValidationService;
use Quiote\Testing\PHPUnit\Constraint\ConstraintActionHandlesMethod;
use Quiote\Validator\ValidationArgument;

/**
 * ActionTestCase is the base class for all action testcases and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class ActionTestCase extends FragmentTestCase
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
	 * @return     void
	 * @since      1.0.0
	 */
	protected function runAction()
	{
		// Container removed in modernized pipeline: execute action manually.
		$action = $this->createActionInstance();
		$methodLogical = strtolower($this->requestMethod);
		$method = ucfirst($methodLogical);
		$execMethod = 'execute' . $method;
		$hasSpecific = is_callable([$action, $execMethod]);
		if (!$hasSpecific) {
			$execMethod = 'execute';
		}
		$request = $this->getContext()->getRequest();
		$resultView = null;

		// If validation already determined to have failed, emulate framework behavior by invoking
		// handleError<Method>() or handleError() without executing the core action logic.
		if ($this->validationSuccess === false) {
			// Correct Quiote semantics: prefer handle<Method>Error, then generic handleError, else default '<Action>Error'
			$errorHandler = 'handle' . $method . 'Error';
			try {
				if (is_callable([$action, $errorHandler])) {
					$resultView = $action->$errorHandler($request);
				} else {
					$resultView = $action->handleError($request);
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
				if (getenv('DEBUG_TESTS')) {
					error_log('[runAction] EXCEPTION in ' . $execMethod . ': ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
				}
				$logger = \Quiote\Logging\Log::for($this);
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug) || getenv('DEBUG_TESTS')) {
					try {
						$logger->debug('[TestDebug][runAction][Exception] ' . $e::class . ': ' . $e->getMessage());
					} catch (\Throwable) {
					}
				}
				$resultView = 'Error';
			}
		}
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug) || getenv('DEBUG_TESTS')) {
			try {
				$logger->debug('[TestDebug][runAction] rawResult=' . var_export($resultView, true) . ' method=' . $execMethod . ' validationSuccess=1');
			} catch (\Throwable) {
			}
		}
		$this->viewModuleName = $this->moduleName;
		// Store raw result (short view name as returned by action). If null assume Success.
		$raw = $resultView ?? 'Success';
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug) || getenv('DEBUG_TESTS')) {
			try {
				$logger->debug('[TestDebug][runAction] preNormalizeRaw=' . $raw);
			} catch (\Throwable) {
			}
		}
		// Normalize using shared logic (applies module directive + canonicalization) so that
		// legacy semantics <ActionName><ShortViewName> are preserved without ad-hoc prefixing.
		$this->viewName = $this->normalizeViewName($raw);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug) || getenv('DEBUG_TESTS')) {
			try {
				$logger->debug('[TestDebug][runAction] normalizedView=' . $this->viewName);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * register the validators for this testcase
	 * @return     void
	 * @since      1.0.0
	 */
	protected function performValidation()
	{
		// Real validation pipeline: use ValidationService to mirror production middleware behavior.
		$action = $this->createActionInstance();
		// IMPORTANT: validation XML <validator method="write"> expects lowercase tokens.
		// We still need Ucfirst variant inside ValidationService when constructing validate* methods.
		$methodToken = strtolower($this->requestMethod);
		// Acquire canonical WebRequest (it already holds parameters injected via helpers)
		$request = $this->getContext()->getRequest();
		$logger = \Quiote\Logging\Log::for($this);
		$dbg = ($logger->isEnabled(\Quiote\Logging\Level::Debug) || getenv('DEBUG_TESTS'));
		if ($dbg) {
			try {
				$logger->debug('[TestDebug][performValidation] methodToken=' . $methodToken . ' reqId=' . spl_object_id($request));
			} catch (\Throwable) {
			}
		}
		// Controlled debug: only emit pre-validation parameter dump when explicitly enabled
		if ($dbg) {
			try {
				$rawParams = $request->getParameters('parameters');
				$flat = [];
				if (class_exists(\Quiote\Util\ArrayPathDefinition::class)) {
					$flat = \Quiote\Util\ArrayPathDefinition::getFlatKeyNames($rawParams);
				}
				$logger->debug('[TestDebug][PreValidation] action=' . $this->actionName . ' method=' . $methodToken . ' keys=' . implode(',', $flat) . ' raw=' . json_encode($rawParams));
			} catch (\Throwable $e) {
				try {
					$logger->debug('[TestDebug][PreValidation] exception dumping params: ' . $e->getMessage());
				} catch (\Throwable) {
				}
			}
		}
		$module = $this->moduleName;
		$actionName = $this->actionName;
		try {
			$vm = $this->getContext()->createInstanceFor('validation_manager');
			if ($this->container && method_exists($this->container, 'setValidationManager')) {
				if (method_exists($this->container, 'setArguments')) {
					try {
						$this->container->setArguments($request->getParameters('parameters'));
					} catch (\Throwable) {
					}
				}
				$this->container->setValidationManager($vm);
			}
			if ($dbg) {
				try {
					$rp = $request->getParameters('runtime');
					$logger->debug('[TestDebug][RuntimeBeforeValidation] keys=' . implode(',', array_keys($rp)));
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
				$logger->debug('[TestDebug][BeforeValidate] module=' . $module . ' primaryName=' . $primaryName . ' alternativeName=' . ($alternativeName ?? 'null') . ' method=' . $methodToken);
			}
			try {
				$result = $service->validate($action, $request, $module, $primaryName, $methodToken);
				if ($dbg) {
					$logger->debug('[TestDebug][AfterValidate] result->ok=' . ($result->ok ? '1' : '0'));
				}
			} catch (\Throwable $e) {
				if ($dbg) {
					$logger->debug('[TestDebug][ValidateException] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
				}
				// Create a failed result
				$result = (object)['ok' => false, 'data' => []];
			}
			$this->validationSuccess = (bool)$result->ok;
			$trace = $result->data['trace'] ?? null;
			if ($trace) {
				$loaded = $trace->validatorsLoaded ?? [];
			}
			if (empty($loaded) && $alternativeName) {
				try {
					if ($dbg) {
						$logger->debug('[TestDebug][ValidationFallback] retry dotted=' . $alternativeName);
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
					$logger->debug('[TestDebug][PostValidation] success=' . ($this->validationSuccess ? '1' : '0') . ' loadedValidators=' . implode(',', $loaded));
					if (!$this->validationSuccess) {
						// EXTRA DEBUG: dump validator names + argument results
						try {
							$childs = $vm->getChilds();
							$names = [];
							foreach ($childs as $cv) {
								$names[] = $cv->getName();
							}
							$logger->debug('[TestDebug][ValidatorsRegistered] ' . implode(',', $names));
							$report = $vm->getReport();
							if ($report) {
								$argsFailed = [];
								try {
									foreach ($report->getFailedArguments() as $fa) {
										$argsFailed[] = $fa->getName();
									}
								} catch (\Throwable) {
								}
								$logger->debug('[TestDebug][FailedArguments] ' . (empty($argsFailed) ? 'none' : implode(',', $argsFailed)));
								$errs = $report->getErrorMessages();
								if (!empty($errs)) {
									$logger->debug('[TestDebug][ErrorMessages] ' . json_encode($errs));
								}
							}
						} catch (\Throwable $ie) {
							$logger->debug('[TestDebug][ValidatorDumpException] ' . $ie->getMessage());
						}
					}
					if (!$this->validationSuccess && $vm->getReport()) {
						$errs = $vm->getReport()->getErrors();
						$lines = [];
						foreach ($errs as $err) {
							try {
								$lines[] = ($err->getName() ? $err->getName() . ': ' : '') . $err->getMessage();
							} catch (\Throwable) {
							}
						}
						if (!empty($lines)) {
							$logger->debug('[TestDebug][ValidationErrors] ' . implode(' | ', $lines));
						}
					}
				} catch (\Throwable) {
				}
			}
		} catch (\Throwable) {
			$this->validationSuccess = false;
		}
	}

	/**
	 * asserts that the viewName is the expected value after runAction was called
	 * @param      string $expected the expected viewname in short form ('Success' etc)
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertViewNameEquals($expected, $message = 'Failed asserting that the view\'s name is <%1$s>.')
	{
		$expected = $this->normalizeViewName($expected);
		$this->assertEquals($expected, $this->viewName, sprintf($message, $expected));
	}

	/**
	 * asserts that the view's modulename is the expected value after runAction was called
	 * @param      string $expected the expected moduleName 
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertViewModuleNameEquals($expected, $message = 'Failed asserting that the view\'s module name is <%1$s>.')
	{
		$this->assertEquals($expected, $this->viewModuleName, sprintf($message, $expected));
	}

	/**
	 * asserts that the DefaultView is the expected 
	 * @param     mixed $expected A string containing the view name associated with the
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @see        Action::getDefaultViewName()
	 * @since      1.0.0
	 */
	protected function assertDefaultView($expected, $message = 'Failed asserting that the defaultView is the expected value.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertEquals($expected, $actionInstance->getDefaultViewName(), $message);
	}

	/**
	 * assert that the action handles the given request method
	 * @param      string $method the method name
	 * @param      boolean $acceptGeneric true if the generic 'execute' method should be accepted as handled
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertHandlesMethod($method, $acceptGeneric = true, $message = '')
	{
		$actionInstance = $this->createActionInstance();
		$constraint = new ConstraintActionHandlesMethod($actionInstance, $acceptGeneric);

		self::assertThat($method, $constraint, $message);
	}

	/**
	 * assert that the action does not handle the given request method
	 * @param      string $method the method name
	 * @param      boolean $acceptGeneric true if the generic 'execute' method should be accepted as handled
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertNotHandlesMethod($method, $acceptGeneric = true, $message = '')
	{
		$actionInstance = $this->createActionInstance();
		$constraint = self::logicalNot(new ConstraintActionHandlesMethod($actionInstance, $acceptGeneric));

		self::assertThat($method, $constraint, $message);
	}

	/**
	 * assert that the action is simple
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertIsSimple($message = 'Failed asserting that the action is simple.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertTrue($actionInstance->isSimple(), $message);
	}

	/**
	 * assert that the action is not simple
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertIsNotSimple($message = 'Failed asserting that the action is not simple.')
	{
		$actionInstance = $this->createActionInstance();
		$this->assertFalse($actionInstance->isSimple(), $message);
	}

	/**
	 * asserts that the given argument has been touched by a validator
	 * This does not imply that the validation failed or succeeded, just
	 * that a validator attempted to validate the given argument
	 * @param      string $argumentName the name of the argument
	 * @param      string $source the source of the argument
	 * @param      string $message an optional message
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertValidatedArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is validated.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$this->assertTrue($report->isArgumentValidated(new ValidationArgument($argumentName, $source)), sprintf($message, $argumentName));
	}

	/**
	 * asserts that the given argument has failed the validation
	 * @param      string $argumentName the name of the argument
	 * @param      string $source the source of the argument
	 * @param      string $message an optional message
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertFailedArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is failed.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$this->assertTrue($report->isArgumentFailed(new ValidationArgument($argumentName, $source)), sprintf($message, $argumentName));
	}

	/**
	 * asserts that the given argument has succeeded the validation
	 * @param      string $argumentName the name of the argument
	 * @param      string $source the source of the argument
	 * @param      string $message an optional message
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertSucceededArgument($argumentName, $source = 'parameters', $message = 'Failed asserting that the argument <%1$s> is succeeded.')
	{
		$report = $this->container->getValidationManager()->getReport();
		$success = $report->isArgumentValidated(new ValidationArgument($argumentName, $source)) && ! $report->isArgumentFailed(new ValidationArgument($argumentName, $source));
		$this->assertTrue($success, sprintf($message, $argumentName));
	}
}
