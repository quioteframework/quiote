<?php

declare(strict_types=1);

use Quiote\Action\Action;
use Quiote\Execution\ValidationService;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;

/** Captures debug and above, so the validation diagnostics can be read back. */
final class ValidationDiagnosticsCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * The debug diagnostics ValidationService writes when a request is being
 * traced: a summary of the manager's configuration and one line per incident.
 *
 * They only run with debug logging on, which is exactly why they are worth a
 * test -- a diagnostic that throws would take the request down with it, and
 * the only thing standing between the traversals below and that outcome is
 * CategoryLogger::debugWith() never being called when debug is off.
 */
final class ValidationServiceDiagnosticsTest extends UnitTestCase
{
    private ValidationDiagnosticsCapturingSink $sink;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new ValidationDiagnosticsCapturingSink();
        Log::setLevel('Quiote', Level::Debug);
        Log::addSink($this->sink);
    }

    #[\Override]
    protected function tearDown(): void
    {
        LogRegistry::reset();
    }

    /**
     * An action that registers one validator from within
     * register{Method}Validators(), which is where validateDeclaredOnly() expects
     * them -- it clears the manager first, so anything pre-seeded is gone.
     */
    private function newAction(?bool $validatorPasses, string $validatorName = 'diagnosticsValidator'): Action
    {
        $context = $this->getContext();
        $request = $this->newWebRequest(['alpha' => 'A']);
        $context->getContainer()->get(\Quiote\Request\RequestState::class)->publish($request);

        $initContext = new \Quiote\Execution\LightweightActionInitContext(
            $context,
            'app',
            'dummy',
            'write',
            'html',
            $request,
            $context->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse(),
        );

        return new class ($context, $initContext, $validatorPasses, $validatorName) extends Action {
            public function __construct(
                \Quiote\Context $context,
                \Quiote\Execution\ActionInitContext $initContext,
                private readonly ?bool $validatorPasses,
                private readonly string $validatorName,
            ) {
                $this->context = $context;
                $this->initContext = $initContext;
            }

            public function getDefaultViewName(): string
            {
                return 'Success';
            }

            public function executeWrite(WebRequest $req): string
            {
                return 'Success';
            }

            public function handleError(WebRequest $req): string
            {
                return 'Error';
            }

            public function isSecure(): bool
            {
                return false;
            }

            public function registerWriteValidators(): void
            {
                if ($this->validatorPasses === null) {
                    return;
                }

                $manager = $this->getInitContext()?->getValidationManager();
                if (!$manager instanceof ValidationManager) {
                    throw new \RuntimeException('no ValidationManager on the init context');
                }

                $validator = $manager->createValidator(
                    'DummyValidator',
                    ['alpha'],
                    [],
                    ['name' => $this->validatorName, 'severity' => 'error'],
                );
                $validator->val_result = $this->validatorPasses;
            }
        };
    }

    private function validate(Action $action): \Quiote\Execution\ValidationResult
    {
        $manager = $this->getContext()->getContainer()->get(ValidationManager::class);

        return (new ValidationService($manager))
            ->validateDeclaredOnly($action, $this->newWebRequest(['alpha' => 'A']), 'app', 'dummy', 'write');
    }

    private function loggedText(): string
    {
        return implode("\n", array_map(static fn(LogEvent $e): string => $e->renderMessage(), $this->sink->captured));
    }

    /**
     * The summary is the single line that says whether validation passed and
     * what it was configured with, so a trace that has it needs no second
     * source to explain the outcome.
     */
    public function testThePassingSummaryReportsTheOutcomeAndValidatorCount(): void
    {
        $this->validate($this->newAction(true));

        $logged = $this->loggedText();
        $this->assertStringContainsString('[ValidationService] summary ok=1', $logged);
        $this->assertStringContainsString('childValidators=1', $logged);
    }

    public function testTheFailingSummaryReportsTheOutcomeAsNotOk(): void
    {
        $this->validate($this->newAction(false));

        $this->assertStringContainsString('[ValidationService] summary ok=0', $this->loggedText());
    }

    /**
     * A failed validator becomes an incident, and the per-incident line is
     * what names which validator rejected which argument -- the thing you
     * actually want when a form keeps coming back invalid.
     */
    public function testAFailingValidatorIsReportedAsAnIncidentNamingItAndItsArgument(): void
    {
        $this->validate($this->newAction(false, 'rejectingValidator'));

        $logged = $this->loggedText();
        $this->assertStringContainsString('[ValidationService] incident#0', $logged);
        $this->assertStringContainsString('validator=rejectingValidator', $logged);
        $this->assertStringContainsString('args=alpha', $logged);
    }

    /** Each configured validator is listed with the settings it was built from. */
    public function testEachConfiguredValidatorIsReportedWithItsSettings(): void
    {
        $this->validate($this->newAction(true, 'diagnosticsCfg'));

        $logged = $this->loggedText();
        $this->assertStringContainsString('[ValidationService] validator cfg name=diagnosticsCfg', $logged);
        $this->assertStringContainsString('required=', $logged);
    }

    /** With nothing registered the summary still reports, saying there was nothing to run. */
    public function testTheSummaryReportsAnEmptyValidatorSetAsSuch(): void
    {
        $this->validate($this->newAction(null));

        $this->assertStringContainsString('childValidators=0', $this->loggedText());
    }

    /**
     * validate() runs the same validators, so it emits the same snapshot. A
     * slot dispatch or a test that goes through validate() would otherwise
     * diagnose a failure with strictly less than a request does.
     */
    public function testValidateEmitsTheSameSnapshotAsValidateDeclaredOnly(): void
    {
        $manager = $this->getContext()->getContainer()->get(ValidationManager::class);
        $service = new ValidationService($manager);

        $service->validate($this->newAction(true), $this->newWebRequest(['alpha' => 'A']), 'app', 'dummy', 'write');

        $this->assertStringContainsString('[ValidationService] summary ok=', $this->loggedText());
    }

    /** Diagnostics are diagnostics: producing them must not change the outcome. */
    public function testTurningDebugLoggingOnDoesNotChangeTheValidationResult(): void
    {
        $result = $this->validate($this->newAction(true));

        $this->assertTrue($result->ok);
        $this->assertNotSame('', $this->loggedText(), 'the diagnostics did run');
    }

    // --- the stringification rule the snapshot relies on --------------------

    /**
     * Config parameters are untyped, and the snapshot interpolates them. The
     * rule is PHP's own string cast for anything that has one, and an empty
     * string for anything that does not -- rather than a type error inside a
     * diagnostic.
     *
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stringifiableValues')]
    public function testAConfigValueIsStringifiedForTheSnapshot(mixed $value, string $expected): void
    {
        $service = new ValidationService();
        $method = new ReflectionMethod(ValidationService::class, 'scalarToString');

        $this->assertSame($expected, $method->invoke($service, $value));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function stringifiableValues(): array
    {
        return [
            'string' => ['strict', 'strict'],
            'int' => [42, '42'],
            'float' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'null' => [null, ''],
            'array' => [['a', 'b'], ''],
            'plain object' => [new stdClass(), ''],
            'stringable' => [new ValidationDiagnosticsStringable(), 'stringable-value'],
        ];
    }

    /**
     * An unnamed validator is dropped from the trace rather than contributing
     * a null: the trace is a debugging aid, and a null name in the list would
     * be less useful than a shorter list.
     */
    public function testUnnamedValidatorsAreLeftOutOfTheTracesNameList(): void
    {
        $manager = $this->getContext()->getContainer()->get(ValidationManager::class);
        $named = $manager->createValidator('DummyValidator', ['alpha'], [], ['name' => 'namedOne']);
        $unnamed = $manager->createValidator('DummyValidator', ['alpha'], [], []);

        $service = new ValidationService();
        $method = new ReflectionMethod(ValidationService::class, 'validatorNames');
        $names = $method->invoke($service, [$named, $unnamed]);

        $this->assertIsArray($names);
        $this->assertContains('namedOne', $names);
        $this->assertNotContains(null, $names);
    }
}

/** A value whose string form comes from Stringable rather than a scalar cast. */
final class ValidationDiagnosticsStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable-value';
    }
}
