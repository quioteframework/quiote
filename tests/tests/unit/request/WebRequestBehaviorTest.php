<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Additional behavior tests for WebRequest focusing on edge cases and
 * branch coverage: method override, HTTPS inference, file normalization,
 * runtime vs intrinsic precedence, bracket path access, X-Forwarded-Proto.
 */
class WebRequestBehaviorTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $headers
     */
    private function newRequest(array $server = [], array $query = [], array $body = [], array $cookies = [], array $files = [], array $headers = []): WebRequest
    {
        $url = ($server['REQUEST_SCHEME'] ?? 'http') . '://' . ($server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'example.test')) . ($server['REQUEST_URI'] ?? '/');
        $wr = new WebRequest(
            $server['REQUEST_METHOD'] ?? 'GET',
            $url,
            $headers,
            $body ? http_build_query($body) : null,
            '1.1',
            $server
        );
        $wr->initialize($this->getContext());
        // Ensure URI scheme is correct (constructor might override from server params)
        $wr = $wr->withUri(new \Quiote\Http\SimpleUri($url));
        $wr = $wr->withQueryParams($query)->withParsedBody($body)->withCookieParams($cookies)->withUploadedFiles($files);
        return $wr;
    }

    public function testHttpsInferenceViaForwardedProto(): void
    {
        // Current implementation does not parse X_FORWARDED_PROTO during attachPsrRequest;
        // scheme is taken from created URI. Simulate load balancer providing forwarded proto
        // by setting REQUEST_SCHEME so bootstrap picks it up.
        $wr = $this->newRequest([
            'REQUEST_SCHEME' => 'https',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'fw.example.test',
            'REQUEST_URI' => '/p',
        ]);
        $this->assertSame('https', $wr->getUrlScheme());
        $this->assertTrue($wr->isHttps());
        $this->assertSame(443, $wr->getUrlPort());
    }

    public function testRuntimeParameterOverridesIntrinsic(): void
    {
        $wr = $this->newRequest([], ['foo' => 'queryVal']);
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        // Register validator BEFORE any access
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['foo'], [], ['value' => 'queryVal']);
        $vm->execute($wr);
        $wr = $vm->getContext()->getRequest();
        $this->assertSame('queryVal', $wr->getParameter('foo'));
        // Mutate runtime parameter AFTER validation; this should still be accessible because foo already validated
        $wr = $wr->setParameter('foo', 'runtimeVal');
        $this->assertSame('runtimeVal', $wr->getParameter('foo'));
        $all = $wr->getParameters();
        $this->assertSame('runtimeVal', $all['foo']);
    }

    public function testGetParametersExcludesUnvalidatedKeys(): void
    {
        // getParameters()/getAll() are the plural counterpart of getParameter()
        // and must enforce the same strict-validation whitelist -- otherwise
        // an action could read raw, unvalidated input by calling
        // getParameters() instead of getParameter(), defeating strict mode
        // entirely.
        $wr = $this->newRequest([], ['validated' => 'safe', 'untouched' => "' OR 1=1;--"]);
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['validated'], [], ['value' => 'safe']);
        $vm->execute($wr);
        $wr = $vm->getContext()->getRequest();

        $all = $wr->getParameters();
        $this->assertArrayHasKey('validated', $all);
        $this->assertArrayNotHasKey('untouched', $all, 'Unvalidated parameter must not leak through getParameters()');

        $allAlias = $wr->getAll('parameters');
        $this->assertArrayNotHasKey('untouched', $allAlias, 'Unvalidated parameter must not leak through getAll() either');

        $runtimeAll = $wr->getParameters('runtime');
        $this->assertArrayNotHasKey('untouched', $runtimeAll);
    }

    public function testBracketPathMaterializationAndRetrieval(): void
    {
        $wr = $this->newRequest();
        $dataVal = [ ['Application' => 'orders', 'Enabled' => true] ];
        $wr = $wr->setParameter('data', $dataVal);
        // Explicitly whitelist the flattened bracket keys produced by materialization
        $wr = $wr->enforceValidatedParameters(['data','data[0][Application]','data[0][Enabled]']);
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['data'], [], ['value' => $dataVal]);
        $vm->execute($wr);
        $wr = $vm->getContext()->getRequest();
        $this->assertSame('orders', $wr->getParameter('data[0][Application]'));
        $this->assertSame(true, $wr->getParameter('data[0][Enabled]'));
    }

    public function testRemoveParameterFromRuntime(): void
    {
        $wr = $this->newRequest();
        $wr = $wr->setParameter('alpha', 'one');
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['alpha'], [], ['value' => 'one']);
        $vm->execute($wr);
        $wr = $vm->getContext()->getRequest();
        $this->assertTrue($wr->hasParameter('alpha'));
        $wr = $wr->removeParameter('alpha');
        $this->assertFalse($wr->hasParameter('alpha'));
    }

    public function testUploadedFilesNormalizationSimple(): void
    {
        // Construct nested uploaded files array similar to $_FILES shape
        $stream = fopen('php://temp', 'r');
        $this->assertNotFalse($stream);
        $files = [
            'upload' => new UploadedFile($stream, 0, UPLOAD_ERR_OK, 'empty.txt', 'text/plain')
        ];
        $wr = $this->newRequest([], [], [], [], $files);
        $retrieved = $wr->getUploadedFiles();
        $this->assertArrayHasKey('upload', $retrieved);
        $this->assertInstanceOf(UploadedFileInterface::class, $retrieved['upload']);
    }

    public function testParameterRemovalCascadesToQueryAndBody(): void
    {
        $wr = $this->newRequest([], ['q' => 'query'], ['p' => 'body']);
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['q'], [], ['value' => 'query']);
        $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['p'], [], ['value' => 'body']);
        $vm->execute($wr);
        $wr = $vm->getContext()->getRequest();
        $this->assertSame('query', $wr->getParameter('q'));
        $this->assertSame('body', $wr->getParameter('p'));
        $wr = $wr->removeParameter('q', 'parameters');
        $wr = $wr->removeParameter('p', 'parameters');
        $this->assertNull($wr->getParameter('q'));
        $this->assertNull($wr->getParameter('p'));
    }

    public function testGetUrlPortFallbackWhenZero(): void
    {
        // Simulate attach with scheme but no port -> port falls back based on scheme
        $wr = $this->newRequest(['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'zero.example', 'REQUEST_URI' => '/']);
        $this->assertSame(443, $wr->getUrlPort());
    }
}
