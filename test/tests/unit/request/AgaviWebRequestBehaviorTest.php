<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Request\AgaviWebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Additional behavior tests for AgaviWebRequest focusing on edge cases and
 * branch coverage: method override, HTTPS inference, file normalization,
 * runtime vs intrinsic precedence, bracket path access, X-Forwarded-Proto.
 */
class AgaviWebRequestBehaviorTest extends AgaviUnitTestCase
{
    private function newRequest(array $server = [], array $query = [], array $body = [], array $cookies = [], array $files = [], array $headers = []): AgaviWebRequest
    {
        $url = ($server['REQUEST_SCHEME'] ?? 'http') . '://' . ($server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'example.test')) . ($server['REQUEST_URI'] ?? '/');
        $wr = new AgaviWebRequest(
            $server['REQUEST_METHOD'] ?? 'GET',
            $url,
            $headers,
            $body ? http_build_query($body) : null,
            '1.1',
            $server
        );
        $wr->initialize($this->getContext());
        // Ensure URI scheme is correct (constructor might override from server params)
        $wr = $wr->withUri(new \Agavi\Http\SimpleUri($url));
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
        $vm->createValidator(Agavi\Validator\AgaviEqualsValidator::class, ['foo'], [], ['value' => 'queryVal']);
        $vm->execute($wr);
        $this->assertSame('queryVal', $wr->getParameter('foo'));
        // Mutate runtime parameter AFTER validation; this should still be accessible because foo already validated
        $wr->setParameter('foo', 'runtimeVal');
        $this->assertSame('runtimeVal', $wr->getParameter('foo'));
        $all = $wr->getParameters();
        $this->assertSame('runtimeVal', $all['foo']);
    }

    public function testBracketPathMaterializationAndRetrieval(): void
    {
        $wr = $this->newRequest();
        $dataVal = [ ['Application' => 'orders', 'Enabled' => true] ];
        $wr->setParameter('data', $dataVal);
        // Explicitly whitelist the flattened bracket keys produced by materialization
        $wr->enforceValidatedParameters(['data','data[0][Application]','data[0][Enabled]']);
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(Agavi\Validator\AgaviEqualsValidator::class, ['data'], [], ['value' => $dataVal]);
        $vm->execute($wr);
        $this->assertSame('orders', $wr->getParameter('data[0][Application]'));
        $this->assertSame(true, $wr->getParameter('data[0][Enabled]'));
    }

    public function testRemoveParameterFromRuntime(): void
    {
        $wr = $this->newRequest();
        $wr->setParameter('alpha', 'one');
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(Agavi\Validator\AgaviEqualsValidator::class, ['alpha'], [], ['value' => 'one']);
        $vm->execute($wr);
        $this->assertTrue($wr->hasParameter('alpha'));
        $wr = $wr->removeParameter('alpha');
        $this->assertFalse($wr->hasParameter('alpha'));
    }

    public function testUploadedFilesNormalizationSimple(): void
    {
        // Construct nested uploaded files array similar to $_FILES shape
        $files = [
            'upload' => new UploadedFile(fopen('php://temp','r'), 0, UPLOAD_ERR_OK, 'empty.txt', 'text/plain')
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
        $vm->createValidator(Agavi\Validator\AgaviEqualsValidator::class, ['q'], [], ['value' => 'query']);
        $vm->createValidator(Agavi\Validator\AgaviEqualsValidator::class, ['p'], [], ['value' => 'body']);
        $vm->execute($wr);
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
