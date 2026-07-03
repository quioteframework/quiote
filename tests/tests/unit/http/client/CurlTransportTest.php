<?php

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Client\CurlTransport;
use Quiote\Http\Client\Exception\NetworkException;

/**
 * Exercises the real curl transport against a genuine one-shot TCP server run
 * in a forked child — the one thing the in-memory RecordingTransport can't
 * prove: that CurlTransport actually performs HTTP over a socket and maps the
 * response/failures per PSR-18. Skips where pcntl/curl aren't available.
 */
class CurlTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('curl extension not available');
        }
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
    }

    public function testPerformsRealHttpGetAndParsesResponse(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "bind failed: $errstr");
        $port = (int) substr(stream_socket_get_name($server, false), strrpos(stream_socket_get_name($server, false), ':') + 1);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: accept one connection, send a canned response, exit.
            $conn = @stream_socket_accept($server, 5);
            if ($conn !== false) {
                $body = 'hello-from-server';
                $response = "HTTP/1.1 200 OK\r\n"
                    . "Content-Type: text/plain\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\n"
                    . "X-Test: yes\r\n\r\n"
                    . $body;
                fwrite($conn, $response);
                fclose($conn);
            }
            exit(0);
        }

        $transport = new CurlTransport();
        $response = $transport->sendRequest(new Request('GET', "http://127.0.0.1:$port/"));
        pcntl_waitpid($pid, $status);
        fclose($server);

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
