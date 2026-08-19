<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Logging\Log;
use Quiote\Middleware\Attribute\Middleware as MiddlewareAttribute;
use Quiote\Replay\Attribute\NoRecord;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Session\SessionBagInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\CorrelationId;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Captures the request/response/resolved/session/exception detail described
 * by `docs/RECORD_REPLAY_PLAN.md` §5, and writes a {@see Cassette} for
 * whichever requests {@see SamplingPolicy} keeps.
 *
 * Registered `phase: 'bootstrap', priority: 1100` -- between `StealthMiddleware`
 * (1200) and `ErrorHandlingMiddleware` (1000) -- so it observes the *rendered*
 * error response and also catches an exception that escapes error handling
 * entirely. Being outermost means the PSR-7 request this middleware receives
 * back never reflects attributes inner middleware attached (`withAttribute()`
 * clones don't propagate outward), so resolved routing/validation state is
 * read from {@see RequestState::current()} instead -- see the two small
 * `RequestState::publish()` additions in `RoutingMiddleware`/`DispatchMiddleware`
 * this depends on.
 *
 * Effects (§6's DB/HTTP/cache/queue/env recorders) are not populated by this
 * middleware: that requires the app's live PDO/cache/queue/HTTP-client
 * instances to be swapped for their `Recording*` counterparts for the
 * request's duration, a distinct integration task. Every cassette this
 * middleware writes has `effects: []`. `response.stray_output` is likewise
 * always empty: `OutputCapture` is owned by `Quiote\Runtime\Kernel` and not
 * reachable from a PSR-15 middleware.
 */
#[MiddlewareAttribute(phase: 'bootstrap', priority: 1100)]
final class RecorderMiddleware implements MiddlewareInterface, ResetInterface
{
    /** @var list<string> */
    private const SERVER_ALLOWLIST = [
        'REQUEST_METHOD', 'REQUEST_URI', 'SERVER_PROTOCOL', 'HTTP_HOST',
        'REMOTE_ADDR', 'SERVER_NAME', 'SERVER_PORT', 'REQUEST_TIME_FLOAT',
    ];

    public function __construct(
        private readonly Context $context,
        private readonly CassetteStoreInterface $store,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly RandomnessInterface $randomness = new SystemRandomness(),
    ) {
    }

    /**
     * Stateless: every capture below lives in a local variable inside
     * process(), never on $this, so two sequential requests through one
     * worker-reused instance already produce two independent cassettes.
     * Implements ResetInterface anyway, matching {@see WebRequest}'s own
     * precedent, so a future stateful addition is forced to wire its own
     * reset rather than being missed.
     */
    public function reset(): void
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = Config::getBool('replay.enabled', false)
            ? SamplingPolicy::fromConfigValue(Config::getString('replay.record', 'never'))
            : SamplingPolicy::Never;
        if ($policy === SamplingPolicy::Never) {
            // One enum comparison, no buffer, no allocation -- the overhead budget in
            // docs/RECORD_REPLAY_PLAN.md §5.4 for the default configuration.
            return $handler->handle($request);
        }

        $session = new RecordingSession(Config::getInt('replay.max_bytes', 2_097_152), Config::getInt('replay.max_effects', 2000));
        $redactor = $this->makeRedactor();

        if (Config::getBool('replay.capture_body', true)) {
            $session->setRequest($this->captureRequest($request, $redactor));
        }

        $sampleRate = Config::getFloat('replay.sample_rate', 0.0);
        $headerPresent = $this->triggerHeaderPresent($request);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            if ($policy->shouldKeep(500, true, $sampleRate, $this->randomness, $headerPresent)) {
                $this->finishRecording($session, $request, null, $e, $redactor, $policy);
            }
            throw $e;
        }

        if ($policy->shouldKeep($response->getStatusCode(), false, $sampleRate, $this->randomness, $headerPresent)) {
            $this->finishRecording($session, $request, $response, null, $redactor, $policy);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function captureRequest(ServerRequestInterface $request, Redactor $redactor): array
    {
        return [
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'protocol' => $request->getProtocolVersion(),
            'headers' => $redactor->redactHeaders($request->getHeaders()),
            'cookies' => $redactor->redactCookies($request->getCookieParams()),
            'body' => self::encodeBody((string)$request->getBody()),
            'uploads' => $this->captureUploads($request),
            'server' => self::allowlistedServerParams($request->getServerParams()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function captureUploads(ServerRequestInterface $request): array
    {
        $uploads = [];
        $this->flattenUploads($request->getUploadedFiles(), '', $uploads);

        return $uploads;
    }

    /**
     * @param array<array-key, mixed> $files
     * @param list<array<string, mixed>> $into
     */
    private function flattenUploads(array $files, string $prefix, array &$into): void
    {
        foreach ($files as $key => $value) {
            $field = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if ($value instanceof UploadedFileInterface) {
                $into[] = [
                    'field' => $field,
                    'name' => $value->getClientFilename(),
                    'type' => $value->getClientMediaType(),
                    'size' => $value->getSize(),
                    'sha256' => self::hashUploadStream($value),
                    'content' => null,
                ];
            } elseif (is_array($value)) {
                $this->flattenUploads($value, $field, $into);
            }
        }
    }

    private static function hashUploadStream(UploadedFileInterface $file): ?string
    {
        try {
            return hash('sha256', (string)$file->getStream());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private static function allowlistedServerParams(array $server): array
    {
        $result = [];
        foreach (self::SERVER_ALLOWLIST as $key) {
            if (array_key_exists($key, $server)) {
                $result[$key] = $server[$key];
            }
        }

        return $result;
    }

    /** @return array{encoding: string, content: string, truncated: bool} */
    private static function encodeBody(string $raw): array
    {
        if ($raw === '' || mb_check_encoding($raw, 'UTF-8')) {
            return ['encoding' => 'utf8', 'content' => $raw, 'truncated' => false];
        }

        return ['encoding' => 'base64', 'content' => base64_encode($raw), 'truncated' => false];
    }

    private function triggerHeaderPresent(ServerRequestInterface $request): bool
    {
        $headerName = Config::getString('replay.trigger_header', 'X-Quiote-Record');

        return $request->getHeaderLine($headerName) !== '';
    }

    private function makeRedactor(): Redactor
    {
        return new Redactor(
            self::lowercasedList(Config::getStringList('replay.redact.headers', [
                'authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key',
            ])),
            self::lowercasedList(Config::getStringList('replay.redact.params', [
                'password', 'password_confirm', 'token', 'secret', 'card', 'cvv', 'ssn',
            ])),
            self::lowercasedList(Config::getStringList('replay.redact.session', ['_csrf', 'auth.token'])),
            RedactionMode::fromConfigValue(Config::getString('replay.redact.mode', 'drop')),
        );
    }

    /**
     * @param array<int, string> $values
     * @return list<string>
     */
    private static function lowercasedList(array $values): array
    {
        return array_values(array_map(strtolower(...), $values));
    }

    private function currentRequest(ServerRequestInterface $fallback): ServerRequestInterface
    {
        $requestState = $this->context->getContainer()->tryGet(RequestState::class);

        return $requestState instanceof RequestState ? $requestState->current() : $fallback;
    }

    private function finishRecording(
        RecordingSession $session,
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
        Redactor $redactor,
        SamplingPolicy $policy,
    ): void {
        $current = $this->currentRequest($request);
        $descriptorAttr = $current->getAttribute(ActionDescriptor::class);
        $descriptor = $descriptorAttr instanceof ActionDescriptor ? $descriptorAttr : null;
        $execStateAttr = $current->getAttribute(ExecutionState::class);
        $execState = $execStateAttr instanceof ExecutionState ? $execStateAttr : null;

        $noRecord = $descriptor !== null && $this->isNoRecord($descriptor);

        $session->setResolved($noRecord
            ? ['module' => $descriptor->module, 'action' => $descriptor->action, 'no_record' => true]
            : $this->captureResolved($current, $descriptor, $execState, $redactor));

        if (!$noRecord && Config::getBool('replay.capture_session', true)) {
            $snapshot = $this->captureSessionSnapshot($redactor);
            $session->setSessionBefore($snapshot);
            $session->setSessionAfter($snapshot);
        }

        if ($response !== null) {
            $session->setResponse($noRecord ? self::skeletonResponse($response->getStatusCode()) : $this->captureResponse($response));
        } else {
            $session->setResponse(self::skeletonResponse(500));
        }

        if ($exception !== null && !$noRecord) {
            $session->setException([
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ]);
        }

        $rawId = CorrelationId::fromRequest($request) ?? CorrelationId::generate();
        $id = CassetteId::fromCorrelationId($rawId);
        $cassette = $this->buildCassette($id, $rawId, $session, $policy, $noRecord);

        try {
            $this->store->put($id, $cassette);
        } catch (Throwable $e) {
            // The cassette is dropped rather than retried forever, and the loss is stated --
            // never the cassette body itself, which is exactly the payload a log sink must not
            // carry (docs/RECORD_REPLAY_PLAN.md §12.6).
            Log::for($this)->error(sprintf(
                'Failed to store cassette "%s": %s',
                $id->slug,
                $e->getMessage(),
            ));
        }
    }

    private function buildCassette(CassetteId $id, string $rawId, RecordingSession $session, SamplingPolicy $policy, bool $noRecord): Cassette
    {
        $request = $session->request() ?? [];
        if ($noRecord) {
            // Request capture happens at entry (§5.3), before the action -- and therefore
            // #[NoRecord] -- is knowable. The metadata skeleton the plan promises for a
            // non-recordable action is enforced here instead: method/uri only, no
            // headers/cookies/body/uploads/server, regardless of what was already buffered.
            $request = ['method' => $request['method'] ?? null, 'uri' => $request['uri'] ?? null];
        }

        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: [
                'id' => $rawId,
                'recorded_at' => $this->clock->now()->format(DATE_ATOM),
                // No runtime-readable framework version constant exists to fill quiote_version;
                // left null rather than fabricated. Likewise source_hash/runtime/trace_id/span_id
                // -- AppIntrospectionCompiler hashing and OTel span correlation are out of scope
                // for this step.
                'quiote_version' => null,
                'php_version' => PHP_VERSION,
                'context' => $this->context->getName(),
                'source_hash' => null,
                'runtime' => null,
                'trace_id' => null,
                'span_id' => null,
                'trigger' => $policy->value,
            ],
            request: $request,
            resolved: $session->resolved(),
            session: $session->sessionBefore() !== null || $session->sessionAfter() !== null
                ? ['before' => $session->sessionBefore(), 'after' => $session->sessionAfter(), 'id_rotated' => false]
                : null,
            user: null,
            effects: $session->boundedEffects(),
            response: $session->response() ?? [],
            exception: $session->exception(),
            log: null,
        );
    }

    /** @return array<string, mixed> */
    private static function skeletonResponse(int $status): array
    {
        return [
            'status' => $status,
            'headers' => [],
            'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
            'stray_output' => '',
        ];
    }

    /**
     * @param ActionDescriptor|null $descriptor
     * @param ExecutionState|null $execState
     * @return array<string, mixed>
     */
    private function captureResolved(ServerRequestInterface $current, ?ActionDescriptor $descriptor, ?ExecutionState $execState, Redactor $redactor): array
    {
        $routeName = $current->getAttribute('route_name');
        $routeParams = $current->getAttribute('route_params');

        $validatedParams = $current instanceof WebRequest
            ? $redactor->redactParams($current->getParameters('runtime'))
            : [];

        $validationReport = null;
        if ($execState?->validationDecision?->isFailed() === true) {
            $validationReport = $execState->validationDecision->errors;
        }

        return [
            'route' => is_string($routeName) ? $routeName : null,
            'module' => $descriptor !== null ? $descriptor->module : $execState?->module,
            'action' => $descriptor !== null ? $descriptor->action : $execState?->action,
            'route_params' => is_array($routeParams) ? $routeParams : [],
            'output_type' => $descriptor !== null ? $descriptor->outputType : $execState?->outputType,
            'validated_params' => $validatedParams,
            'validation_report' => $validationReport,
        ];
    }

    /** @return array<string, mixed> */
    private function captureResponse(ResponseInterface $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => self::encodeBody((string)$response->getBody()),
            'stray_output' => '',
        ];
    }

    /**
     * `SessionBagInterface` has no key enumeration method (deliberately narrow, per its own
     * docblock), so this cannot dump the session's full contents -- only its id and whether it
     * exists. Both `session.before` and `session.after` in the resulting cassette therefore
     * carry the same end-of-request snapshot rather than a genuine before/after diff, since
     * nothing in this step captures the bag at request entry either (that would need a
     * ledger-triggered lazy read per §5.3, which has nothing to trigger it while no live
     * DB/HTTP/cache/queue/env observer is wired into the request).
     *
     * @return array<string, mixed>|null
     */
    private function captureSessionSnapshot(Redactor $redactor): ?array
    {
        $bag = $this->context->getContainer()->tryGet(SessionBagInterface::class);
        if (!$bag instanceof SessionBagInterface) {
            return null;
        }

        return $redactor->redactSession([
            'id' => $bag->getId(),
            'exists' => $bag->exists(),
        ]);
    }

    private function isNoRecord(ActionDescriptor $descriptor): bool
    {
        try {
            $controller = $this->context->getContainer()->get(Controller::class);
            $action = $controller->createActionInstance($descriptor->module, $descriptor->action);
        } catch (Throwable) {
            return false;
        }

        return (new ReflectionClass($action))->getAttributes(NoRecord::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }
}
