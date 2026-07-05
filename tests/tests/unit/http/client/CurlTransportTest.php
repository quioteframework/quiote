<?php

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Client\CurlTransport;
use Quiote\Http\Client\Exception\NetworkException;

/**
 * Exercises the real curl transport against a genuine one-shot TCP server run
 * in a child PHP process — the one thing the in-memory RecordingTransport can't
 * prove: that CurlTransport actually performs HTTP over a socket and maps the
 * response/failures per PSR-18. Skips where curl isn't available.
 */
class CurlTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('curl extension not available');
        }
    }

    public function testPerformsRealHttpGetAndParsesResponse(): void
    {
        // Spawn an isolated PHP process as the one-shot HTTP server.
        // Using proc_open instead of pcntl_fork avoids inheriting PHPUnit's
        // shutdown handlers, output buffers, and signal masks — all of which
        // can deadlock when exit() is called in a forked child.
        //
        // The server script binds to an OS-assigned port, prints that port on
        // stdout, then blocks in stream_socket_accept(). Reading the port line
        // here (fgets) blocks until the server is ready — no sleep/poll needed.
        $serverScript = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) { fwrite(STDERR, "bind: $errstr\n"); exit(1); }
$addr = stream_socket_get_name($server, false);
echo substr($addr, strrpos($addr, ':') + 1) . "\n";
$conn = @stream_socket_accept($server, 5);
if ($conn !== false) {
    $body = 'hello-from-server';
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nX-Test: yes\r\n\r\n" . $body);
    fclose($conn);
}
fclose($server);
PHP;

        $proc = proc_open(
            [PHP_BINARY, '-r', $serverScript],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'Failed to start one-shot server process');
        fclose($pipes[0]);

        $portLine = fgets($pipes[1]);
        $this->assertNotFalse($portLine, 'Server process did not report its port');
        $port = (int) trim($portLine);
        $this->assertGreaterThan(0, $port, 'Server reported an invalid port');

        $transport = new CurlTransport(connectTimeoutSeconds: 5.0);
        $response = $transport->sendRequest(new Request('GET', "http://127.0.0.1:$port/"));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello-from-server', (string) $response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertSame('yes', $response->getHeaderLine('X-Test'));
    }

    public function testConnectionRefusedMapsToNetworkException(): void
    {
        // Bind then immediately close to obtain a port nothing is listening on.
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $transport = new CurlTransport(connectTimeoutSeconds: 2.0);

        $this->expectException(NetworkException::class);
        $transport->sendRequest(new Request('GET', "http://127.0.0.1:$port/"));
    }

    public function testEmptyUriThrowsRequestException(): void
    {
        $transport = new CurlTransport();
        $this->expectException(\Quiote\Http\Client\Exception\RequestException::class);
        $transport->sendRequest(new Request('GET', ''));
    }
}
