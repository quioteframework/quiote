<?php

use Quiote\Request\RequestParameterStore;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;

/**
 * A value that failed validation must never stay readable, regardless of which
 * source it arrived from.
 *
 * ValidationManager pre-declares the union of every validator's argument names
 * before running any of them, so the strict-validation whitelist always contains
 * the name of an argument that is about to fail. Pruning therefore has to treat
 * an explicit failure as beating the whitelist, or a runtime-staged value (a
 * route param promoted by ValidationMiddleware) survives a failure that the same
 * value arriving as a query param would not.
 */
class ValidationFailurePruningTest extends UnitTestCase
{
    public function testFailureBeatsTheWhitelistInTheStore(): void
    {
        $store = (new RequestParameterStore())
            ->withUnvalidatedParameter('slug', '../../etc/passwd')
            // What ValidationManager::execute() does before any validator runs.
            ->withEnforcedValidatedParameters(['slug']);

        $pruned = $store->pruneTo([], ['slug'], []);

        $this->assertFalse($pruned->has('slug'), 'a failed argument must not survive pruning');
        $this->assertNull($pruned->get('slug'));
    }

    public function testFailureBeatsAnExplicitPreserve(): void
    {
        $store = (new RequestParameterStore())->withParameter('module', 'Admin');

        $pruned = $store->pruneTo([], ['module'], ['module' => true]);

        $this->assertFalse($pruned->has('module'), 'preserve must not resurrect a failed argument');
    }

    public function testSucceededRuntimeExportStillSurvives(): void
    {
        $store = (new RequestParameterStore())->withParameter('exported', 'value');

        $pruned = $store->pruneTo([], [], []);

        $this->assertTrue($pruned->has('exported'), 'a validator export must still survive pruning');
        $this->assertSame('value', $pruned->get('exported'));
    }

    /**
     * severity=notice keeps overall validation successful, so the action really
     * dispatches and really reads the parameter -- the worst reachability for
     * this bug, and the case a failure-path-only test would miss.
     */
    public function testActionCannotReadARouteParamThatFailedValidation(): void
    {
        $context = $this->getContext();
        $manager = $context->getContainer()->get(\Quiote\Validator\ValidationManager::class);
        $manager->initialize($context, ['mode' => ValidationManager::MODE_STRICT]);
        $manager->createValidator(StringValidator::class, ['slug'], [], [
            'max' => 3,
            'required' => true,
            'severity' => 'notice',
        ]);

        $request = new WebRequest();
        $request->initialize($context);
        // Exactly how ValidationMiddleware promotes a route param.
        $request = $request->setUnvalidatedParameter('slug', '../../etc/passwd');

        $succeeded = $manager->execute($request);
        $final = $context->getRequest();

        $this->assertTrue($succeeded, 'notice severity leaves validation successful');
        $this->assertContains(
            'slug',
            array_map(
                static fn($argument): string => (string) $argument->getName(),
                $manager->getReport()->getFailedArguments(),
            ),
            'slug is recorded as a failed argument',
        );
        $this->assertNull($final->getParameter('slug', null), 'the failed value must not be readable');
    }

    public function testFailedQueryParamIsAlsoScrubbed(): void
    {
        $context = $this->getContext();
        $manager = $context->getContainer()->get(\Quiote\Validator\ValidationManager::class);
        $manager->initialize($context, ['mode' => ValidationManager::MODE_STRICT]);
        $manager->createValidator(StringValidator::class, ['slug'], [], ['max' => 3, 'required' => true]);

        $request = new WebRequest();
        $request->initialize($context);
        $request = $request->withQueryParams(['slug' => '../../etc/passwd']);

        $this->assertFalse($manager->execute($request));
        $this->assertNull($context->getRequest()->getParameter('slug', null));
    }

    public function testValidValueStillReachesTheAction(): void
    {
        $context = $this->getContext();
        $manager = $context->getContainer()->get(\Quiote\Validator\ValidationManager::class);
        $manager->initialize($context, ['mode' => ValidationManager::MODE_STRICT]);
        $manager->createValidator(StringValidator::class, ['slug'], [], ['max' => 32, 'required' => true]);

        $request = new WebRequest();
        $request->initialize($context);
        $request = $request->setUnvalidatedParameter('slug', 'ok');

        $this->assertTrue($manager->execute($request));
        $this->assertSame('ok', $context->getRequest()->getParameter('slug'));
    }
}
