<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;
use Quiote\Validator\ValidationArgument;
use Quiote\Validator\ValidationIncident;
use Quiote\Validator\ValidationError;

/**
 * Behavior-focused tests for ValidationManager: dependency token propagation,
 * short-circuit on CRITICAL, strict mode parameter pruning, and manual incident aggregation.
 */
class ValidationManagerBehaviorTest extends UnitTestCase
{
    private function newVm(array $params = []): ValidationManager
    {
        /** @var ValidationManager $vm */
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        // override mode if provided
        if ($params) {
            foreach ($params as $k => $v) { $vm->setParameter($k, $v); }
        }
        return $vm;
    }

    public function testProvidesDependencyTokensAdded(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_RELAXED]);
        $v1 = $vm->createValidator('DummyValidator', [] , [], ['name' => 'alpha', 'provides' => 'tokenA']);
        $v2 = $vm->createValidator('DummyValidator', [] , [], ['name' => 'beta', 'provides' => 'tokenB']);
        $request = $this->newWebRequest();
        $this->assertTrue($vm->execute($request));
        $tokens = $vm->getReport()->getDependTokens();
        $this->assertArrayHasKey('tokenA', $tokens);
        $this->assertArrayHasKey('tokenB', $tokens);
    }

    public function testCriticalShortCircuitsLaterValidators(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_RELAXED]);
        /** @var DummyValidator $crit */
        $crit = $vm->createValidator('DummyValidator', [], [], ['name' => 'crit', 'severity' => 'critical']);
        /** @var DummyValidator $after */
        $after = $vm->createValidator('DummyValidator', [], [], ['name' => 'after', 'severity' => 'error']);
        $crit->val_result = false; // make it fail at CRITICAL level
        $request = $this->newWebRequest();
        $this->assertFalse($vm->execute($request));
        $this->assertTrue($crit->validated, 'Critical validator executed');
        $this->assertFalse($after->validated, 'Subsequent validator should not execute');
    }

    public function testStrictModeEmptyValidatorSetClearsAllParameters(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_STRICT]);
        $req = $this->newWebRequest();
        $req = $req->setParameter('unvalidated', 'val');
        // No validators registered -> strict mode should clear parameters
        $this->assertTrue($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        $this->assertFalse($req->hasParameter('unvalidated'));
    }

    public function testFailedArgumentsRemovedInStrictMode(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_STRICT]);
        /** @var DummyValidator $v */
        $v = $vm->createValidator('DummyValidator', ['field'], [], ['name' => 'fval', 'severity' => 'error']);
        $v->val_result = false; // make it fail
        // Provide intrinsic (query) parameters so removal logic operates on underlying PSR-7 sources
        $req = $this->newWebRequest(['field' => 'abc', 'other' => 'keep?']);
        $this->assertFalse($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        // After pruning logic: failed argument 'field' removed, unvalidated 'other' also pruned in strict mode.
        $this->assertFalse($req->hasParameter('field'), 'Failed argument should be pruned');
        $this->assertFalse($req->hasParameter('other'), 'Unvalidated argument should be pruned');
    }

    public function testManualIncidentAggregation(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_RELAXED]);
        $incident = new ValidationIncident(null, Validator::ERROR);
        $arg = new ValidationArgument('foo');
        $incident->addError(new ValidationError('msg1', null, [$arg]));
        $vm->addIncident($incident);
        $this->assertTrue($vm->getReport()->isArgumentFailed($arg));
        $errors = $vm->getReport()->getIncidents();
        $this->assertCount(1, $errors);
    }

    public function testDependencyTokensAbsentWhenValidatorSkipped(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_RELAXED]);
        /** @var DummyValidator $crit */
        $crit = $vm->createValidator('DummyValidator', [], [], ['name' => 'crit', 'severity' => 'critical']);
        /** @var DummyValidator $provider */
        $provider = $vm->createValidator('DummyValidator', [], [], ['name' => 'provider', 'provides' => 'willNotExecute']);
        $crit->val_result = false; // cause critical failure
        $req = $this->newWebRequest();
        $this->assertFalse($vm->execute($req));
        $tokens = $vm->getReport()->getDependTokens();
        $this->assertArrayNotHasKey('willNotExecute', $tokens, 'Depend token should not be present because provider validator never executed');
        $this->assertTrue($crit->validated);
        $this->assertFalse($provider->validated);
    }

    public function testInfoSeverityDoesNotShortCircuitErrorValidator(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_RELAXED]);
        /** @var DummyValidator $info */
        $info = $vm->createValidator('DummyValidator', [], [], ['name' => 'infoFirst', 'severity' => 'info']);
        /** @var DummyValidator $error */
        $error = $vm->createValidator('DummyValidator', [], [], ['name' => 'errorSecond', 'severity' => 'error']);
        $info->val_result = false; // produce INFO incident but should not stop chain
        $error->val_result = false; // produce ERROR incident
        $req = $this->newWebRequest();
        $this->assertFalse($vm->execute($req)); // overall fails due to ERROR
        $this->assertTrue($info->validated, 'INFO validator executed');
        $this->assertTrue($error->validated, 'ERROR validator executed after INFO');
        $incidents = $vm->getReport()->getIncidents();
        $this->assertGreaterThanOrEqual(1, count($incidents));
    }

    public function testStrictModeSuccessKeepsParameters(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_STRICT]);
        /** @var DummyValidator $v */
        $v = $vm->createValidator('DummyValidator', ['alpha'], [], ['name' => 'ok', 'severity' => 'error']);
        $v->val_result = true; // success
        $req = $this->newWebRequest(['alpha' => 'A', 'beta' => 'B']);
        // Only 'alpha' is validated; since validation overall succeeds, 'beta' (unvalidated) should be pruned in strict mode.
        $this->assertTrue($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        $this->assertTrue($req->hasParameter('alpha'));
        $this->assertFalse($req->hasParameter('beta'), 'Unvalidated parameter pruned on success in strict mode');
    }

    public function testStrictModePrunesUnvalidatedHeadersCookiesFiles(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_STRICT]);
        // Validate only header X-Auth and cookie sessionid
        /** @var DummyValidator $vh */
    // Use lowercase header argument because pruning maps headers to lowercase keys
    $vh = $vm->createValidator('DummyValidator', ['x-auth'], [], ['name' => 'head', 'severity' => 'error', 'source' => 'headers']);
        /** @var DummyValidator $vc */
        $vc = $vm->createValidator('DummyValidator', ['sessionid'], [], ['name' => 'cook', 'severity' => 'error', 'source' => 'cookies']);
        $vh->val_result = true; $vc->val_result = true;
        // Build request with extra unvalidated header/cookie/file
        $req = $this->newWebRequest();
        // Simulate intrinsic sources by attaching a PSR-7 request with headers/cookies/files via helper
    $psrReq = new \Nyholm\Psr7\ServerRequest('GET', 'http://example.test/');
    $psrReq = $psrReq->withHeader('x-auth', 'ok')->withHeader('x-unvalidated', 'remove-me');
    $psrReq = $psrReq->withCookieParams(['sessionid' => 'abc', 'junk' => 'zzz']);
    $stream = new \Nyholm\Psr7\Stream(fopen('php://temp', 'r+'));
    $stream->write('content');
    $file = new \Nyholm\Psr7\UploadedFile($stream, $stream->getSize() ?? 7, UPLOAD_ERR_OK, 'x.txt', 'text/plain');
    $psrReq = $psrReq->withUploadedFiles(['keptFile' => $file, 'tmpFile' => $file]);
        $req = $req->withHeader('x-auth', 'ok')->withHeader('x-unvalidated', 'remove-me');
        $req = $req->withCookieParams(['sessionid' => 'abc', 'junk' => 'zzz']);
        $req = $req->withUploadedFiles(['keptFile' => $file, 'tmpFile' => $file]);
        $this->assertTrue($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        // Validated header retained; unvalidated header removed.
        $this->assertTrue($req->hasHeader('x-auth'));
        $this->assertFalse($req->hasHeader('x-unvalidated'));
        $this->assertSame(['sessionid' => 'abc'], $req->getCookieParams());
        $files = $req->getUploadedFiles();
        // No files validated, so both should be pruned
        $this->assertCount(0, $files, 'Unvalidated files pruned');
    }

    public function testZeroValidatorsStillPurgesAllHeaders(): void
    {
        // Headers are just as attacker-controlled as query/body parameters
        // (Content-Type, Authorization, X-Forwarded-*, etc.). An action with
        // NO validators registered at all must still get every header
        // stripped before execute*() runs -- the same deny-by-default
        // guarantee zero-validator params already get via clearParameters(),
        // which previously did not extend to headers at all.
        $vm = $this->newVm();
        $req = $this->newWebRequest();
        $req = $req->withHeader('authorization', 'Bearer secret')->withHeader('x-my-special-header', 'attacker-value');
        $this->assertTrue($vm->execute($req));
        $finalReq = $this->getContext()->getRequest();
        $this->assertFalse($finalReq->hasHeader('authorization'), 'Authorization must be purged when no validator ran');
        $this->assertFalse($finalReq->hasHeader('x-my-special-header'), 'Arbitrary unvalidated header must be purged when no validator ran');
    }

    public function testHeaderValidatorSurvivesEvenWithZeroOtherValidators(): void
    {
        $vm = $this->newVm();
        /** @var DummyValidator $vh */
        $vh = $vm->createValidator('DummyValidator', ['content-type'], [], ['name' => 'ct', 'severity' => 'error', 'source' => 'headers']);
        $vh->val_result = true;
        $req = $this->newWebRequest();
        $req = $req->withHeader('content-type', 'application/json')->withHeader('authorization', 'Bearer secret');
        $this->assertTrue($vm->execute($req));
        $finalReq = $this->getContext()->getRequest();
        $this->assertTrue($finalReq->hasHeader('content-type'), 'Validated header must survive');
        $this->assertFalse($finalReq->hasHeader('authorization'), 'Unvalidated header must still be purged');
    }

    public function testCriticalFailurePrunesAllUnvalidatedSources(): void
    {
        $vm = $this->newVm(['mode' => ValidationManager::MODE_STRICT]);
        /** @var DummyValidator $vh */
    $vh = $vm->createValidator('DummyValidator', ['x-auth'], [], ['name' => 'head', 'severity' => 'critical', 'source' => 'headers']);
        $vh->val_result = false; // critical failure
        $req = $this->newWebRequest();
    $psrReq = new \Nyholm\Psr7\ServerRequest('POST', 'https://ex/test');
    $psrReq = $psrReq->withHeader('x-auth', 'should-remove')->withHeader('another', 'remove');
    $psrReq = $psrReq->withCookieParams(['keep' => 'n/a', 'lose' => 'x']);
    $stream2 = new \Nyholm\Psr7\Stream(fopen('php://temp', 'r+'));
    $stream2->write('data');
    $file = new \Nyholm\Psr7\UploadedFile($stream2, $stream2->getSize() ?? 4, UPLOAD_ERR_OK, 'f.bin', 'application/octet-stream');
    $psrReq = $psrReq->withUploadedFiles(['f1' => $file]);
        $req = $req->withHeader('x-auth', 'should-remove')->withHeader('another', 'remove');
        $req = $req->withCookieParams(['keep' => 'n/a', 'lose' => 'x']);
        $req = $req->withUploadedFiles(['f1' => $file]);
        $this->assertFalse($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        $this->assertFalse($req->hasHeader('x-auth'));
        $this->assertFalse($req->hasHeader('another'));
        $this->assertSame([], $req->getCookieParams());
        $this->assertSame([], $req->getUploadedFiles());
    }
}
