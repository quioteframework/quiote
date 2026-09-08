<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Asks the Azure Instance Metadata Service (IMDS) for a token on behalf of whichever managed
 * identity is attached to the node/pod, the flow AKS's kubelet-identity and pod-managed-identity
 * both end up using and which .NET's `DefaultAzureCredential`/`ManagedIdentityCredential`
 * exercise out of the box. This is deliberately a different provider than
 * {@see WorkloadIdentityTokenProvider}: workload identity exchanges a projected, webhook-mounted
 * federated token via AAD's OAuth2 endpoint, while managed identity never leaves the node and
 * has no federated token file at all -- a cluster wired for one does not necessarily have the
 * other.
 *
 * No configuration is required for a system-assigned identity; a user-assigned one needs its
 * client ID, since IMDS otherwise cannot tell which of the node's identities to use.
 *
 * @see https://learn.microsoft.com/en-us/azure/active-directory/managed-identities-azure-resources/how-to-use-vm-token#get-a-token-using-http
 */
final class ManagedIdentityTokenProvider implements AzureTokenProvider
{
    use CachesExpiringToken;
    use DecodesBearerTokenJson;

    private const int EXPIRY_SAFETY_MARGIN_SECONDS = 60;
    public const string IMDS_HOST = '169.254.169.254';
    public const int IMDS_PORT = 80;
    private const string DEFAULT_ENDPOINT = 'http://' . self::IMDS_HOST . '/metadata/identity/oauth2/token';
    private const string API_VERSION = '2018-02-01';

    /**
     * A short, hardcoded connect timeout for the reachability probe {@see isReachable()} runs
     * before this provider is trusted with an unbounded request -- IMDS is either answering on
     * the node/pod's link-local address or it plainly is not, and a caller-supplied
     * {@see ClientInterface} cannot be relied on to enforce its own timeout, so this cannot be
     * configurable per call the way {@see AzureTokenProviderFactory} builds one.
     */
    private const float PROBE_TIMEOUT_SECONDS = 0.5;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $resource = self::STORAGE_RESOURCE,
        private readonly ?string $clientId = null,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    /**
     * A cheap, bounded TCP-reachability check against IMDS's link-local address, used by
     * {@see AzureTokenProviderFactory::chainProviders()} to decide whether this provider belongs
     * in a `chain` at all -- the same eager-precondition idea {@see WorkloadIdentityTokenProvider}
     * applies via its environment-variable check, so a `chain`-authorized request on a developer
     * machine (no IMDS reachable) never risks blocking on the caller's HTTP client for however
     * long that client's own timeout (or lack of one) allows.
     *
     * @param ?string $host Overridable only so a test can point this at a controllable local
     *        listener instead of the real IMDS address; a caller resolving an actual identity
     *        always wants the default.
     */
    public static function isReachable(float $timeoutSeconds = self::PROBE_TIMEOUT_SECONDS, ?string $host = null, ?int $port = null): bool
    {
        $socket = @fsockopen($host ?? self::IMDS_HOST, $port ?? self::IMDS_PORT, timeout: $timeoutSeconds);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        if (($cached = $this->cachedTokenOrNull()) !== null) {
            return $cached;
        }

        $query = [
            'api-version' => self::API_VERSION,
            'resource' => $this->resource,
        ];
        if ($this->clientId !== null) {
            $query['client_id'] = $this->clientId;
        }

        $request = $this->psr17->createRequest('GET', $this->endpoint . '?' . http_build_query($query))
            ->withHeader('Metadata', 'true');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AzureStorageException("IMDS managed-identity token request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new AzureStorageException(sprintf(
                'IMDS managed-identity token request failed with status %d: %s',
                $response->getStatusCode(),
                (string) $response->getBody(),
            ));
        }

        $decoded = self::decodeBearerToken((string) $response->getBody(), 'access_token', 'IMDS');

        return $this->cacheToken($decoded['token'], $decoded['expiresIn'] - self::EXPIRY_SAFETY_MARGIN_SECONDS);
    }
}
