<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Exchanges the projected service account token AKS's workload identity webhook mounts into the
 * pod for a Storage-scoped Azure AD access token, via the OAuth2 JWT-bearer client-assertion
 * flow. Needs no secret: the assertion is the federated token file, not a client secret.
 *
 * {@see fromEnvironment()} reads the four variables the webhook injects
 * (`AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_FEDERATED_TOKEN_FILE`, `AZURE_AUTHORITY_HOST`),
 * the same ones the official Azure SDKs' `WorkloadIdentityCredential` reads, so a pod annotated
 * for workload identity needs no Quiote-specific configuration at all.
 *
 * @see https://azure.github.io/azure-workload-identity/docs/topics/language-specific-examples.html
 */
final class WorkloadIdentityTokenProvider implements AzureTokenProvider
{
    private const int EXPIRY_SAFETY_MARGIN_SECONDS = 60;

    private ?string $cachedToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $federatedTokenFile,
        private readonly string $authorityHost = 'https://login.microsoftonline.com/',
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
        private readonly string $scope = self::STORAGE_RESOURCE . '.default',
    ) {
    }

    /**
     * @throws AzureStorageException If any of the four AKS workload identity variables is
     *         missing from the environment.
     */
    public static function fromEnvironment(ClientInterface $httpClient, Psr17Factory $psr17 = new Psr17Factory(), string $scope = self::STORAGE_RESOURCE . '.default'): self
    {
        $tenantId = getenv('AZURE_TENANT_ID');
        $clientId = getenv('AZURE_CLIENT_ID');
        $tokenFile = getenv('AZURE_FEDERATED_TOKEN_FILE');
        if ($tenantId === false || $clientId === false || $tokenFile === false) {
            throw new AzureStorageException(
                'Workload identity requires AZURE_TENANT_ID, AZURE_CLIENT_ID and '
                . 'AZURE_FEDERATED_TOKEN_FILE in the environment: the AKS workload identity '
                . 'webhook injects these into an annotated pod; they are absent outside one.',
            );
        }
        $authorityHost = getenv('AZURE_AUTHORITY_HOST');

        return new self($httpClient, $tenantId, $clientId, $tokenFile, $authorityHost !== false ? $authorityHost : 'https://login.microsoftonline.com/', $psr17, $scope);
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->expiresAt) {
            return $this->cachedToken;
        }

        $assertion = @file_get_contents($this->federatedTokenFile);
        if ($assertion === false) {
            throw new AzureStorageException("Could not read the federated token file at \"{$this->federatedTokenFile}\".");
        }

        $body = http_build_query([
            'client_id' => $this->clientId,
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => trim($assertion),
            'scope' => $this->scope,
        ]);

        $tokenEndpoint = rtrim($this->authorityHost, '/') . "/{$this->tenantId}/oauth2/v2.0/token";
        $request = $this->psr17->createRequest('POST', $tokenEndpoint)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->psr17->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AzureStorageException("Azure AD token request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new AzureStorageException(sprintf(
                'Azure AD token request failed with status %d: %s',
                $response->getStatusCode(),
                (string) $response->getBody(),
            ));
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AzureStorageException("Azure AD returned a token response that was not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($payload) || !isset($payload['access_token']) || !is_string($payload['access_token'])) {
            throw new AzureStorageException('Azure AD token response had no "access_token".');
        }

        $expiresIn = is_int($payload['expires_in'] ?? null) ? $payload['expires_in'] : 0;
        $this->cachedToken = $payload['access_token'];
        $this->expiresAt = time() + max(0, $expiresIn - self::EXPIRY_SAFETY_MARGIN_SECONDS);

        return $this->cachedToken;
    }
}
