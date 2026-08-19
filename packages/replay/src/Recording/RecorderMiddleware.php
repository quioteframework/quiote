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
 * Captures the request/response/resolved/session/exception detail for a
 * request and writes a {@see Cassette} for whichever requests
 * {@see SamplingPolicy} keeps.
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
 * Effects: this package carries no compile-time dependency on any ORM/DB
 * driver. A driver-specific package (e.g. `quioteframework/replay-propulsion`)
 * registers an {@see EffectSource} via {@see EffectSourceRegistry::register()}
 * from its own plugin, and this middleware activates/deactivates every
 * registered source for the duration of `$handler->handle()` -- see
 * {@see EffectSource}'s own docblock for why a driver needs this seam at all
 * rather than just taking an `EffectLedger` directly (Propulsion's
 * `addQueryObserver()` being process-scoped, not request-scoped, is the
 * motivating case; a per-request decorator around a specific connection, the
 * PDO/Doctrine/Eloquent/Cycle shape, has no need of it). HTTP/cache/queue/env
 * effects are not populated by this middleware either: those still require
 * the app's live client/cache/queue instances to be swapped for their
 * `Recording*` counterparts, a distinct integration task per subsystem.
 * `meta.effects_instrumented` states whether any `EffectSource` is
 * registered at all, so a `cassette:show` reader can tell "nothing happened"
 * apart from "nothing was watched" without reading this source file.
 * `response.stray_output` is likewise always empty: `OutputCapture` is owned
 * by `Quiote\Runtime\Kernel` and not reachable from a PSR-15 middleware.
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
            // One enum comparison, no buffer, no allocation -- the overhead budget for the
            // default configuration.
            return $handler->handle($request);
        }

        // Computed once, up front: needed before the request reaches the database (an
        // EffectSource may need to tag queries by it) as well as for the cassette id built at
        // the end.
        $rawId = CorrelationId::fromRequest($request) ?? CorrelationId::generate();
        $session = new RecordingSession(Config::getInt('replay.max_bytes', 2_097_152), Config::getInt('replay.max_effects', 2000));
        $redactor = Redactor::fromConfig();

        if (Config::getBool('replay.capture_body', true)) {
            $session->setRequest($this->captureRequest($request, $redactor));
        }

        $sampleRate = Config::getFloat('replay.sample_rate', 0.0);
        $headerPresent = $this->triggerHeaderPresent($request);
        $sources = EffectSourceRegistry::all();
        foreach ($sources as $source) {
            $source->activate($rawId, $session->ledger());
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->deactivate($sources, $rawId);
            if ($policy->shouldKeep(500, true, $sampleRate, $this->randomness, $headerPresent)) {
                $this->finishRecording($session, $request, null, $e, $redactor, $policy, $rawId, $sources !== []);
            }
            throw $e;
        }

        $this->deactivate($sources, $rawId);

        if ($policy->shouldKeep($response->getStatusCode(), false, $sampleRate, $this->randomness, $headerPresent)) {
            $this->finishRecording($session, $request, $response, null, $redactor, $policy, $rawId, $sources !== []);
        }

        return $response;
    }

    /**
     * Called as soon as `$handler->handle()` returns or throws: every query this request could
     * make has already happened by then, so nothing further would need an `EffectSource`'s
     * routing entry regardless of when `finishRecording()` itself runs afterward.
     *
     * @param list<EffectSource> $sources
     */
    private function deactivate(array $sources, string $correlationId): void
    {
        foreach ($sources as $source) {
            $source->deactivate($correlationId);
        }
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
        string $rawId,
        bool $effectsInstrumented,
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

        $id = CassetteId::fromCorrelationId($rawId);
        $cassette = $this->buildCassette($id, $rawId, $session, $policy, $noRecord, $effectsInstrumented);

        try {
            $this->store->put($id, $cassette);
        } catch (Throwable $e) {
            // The cassette is dropped rather than retried forever, and the loss is stated --
            // never the cassette body itself, which is exactly the payload a log sink must not
            // carry.
            Log::for($this)->error(sprintf(
                'Failed to store cassette "%s": %s',
                $id->slug,
                $e->getMessage(),
            ));
        }
    }

    private function buildCassette(CassetteId $id, string $rawId, RecordingSession $session, SamplingPolicy $policy, bool $noRecord, bool $effectsInstrumented): Cassette
    {
        $request = $session->request() ?? [];
        if ($noRecord) {
            // Request capture happens at entry, before the action -- and therefore #[NoRecord] --
            // is knowable. A non-recordable action gets only a metadata skeleton: method/uri
            // only, no headers/cookies/body/uploads/server, regardless of what was already
            // buffered.
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
                // A cassette that recorded no DB effects because its adapter is not instrumented
                // says so in meta, rather than looking indistinguishable from "genuinely no DB
                // activity". True when at least one driver-specific EffectSource is registered
                // (e.g. quioteframework/replay-propulsion installed and its plugin booted), false
                // otherwise.
                'effects_instrumented' => $effectsInstrumented,
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
     * ledger-triggered lazy read, which has nothing to trigger it in this middleware alone).
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
