<?php

namespace Quiote\Database\Adapter\Doctrine;

use Quiote\Config\Config;
use Quiote\Database\AbstractOrmDatabase;
use Quiote\Exception\DatabaseException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;

/**
 * Modern first-class adapter for Doctrine ORM 3 / DBAL 4. {@see getConnection()}
 * returns the {@see EntityManagerInterface}. Supersedes the legacy in-tree
 * `Doctrine2*` adapters.
 *
 * Configuration parameters (in `databases.xml`):
 *  - `connection`      : the name (string) of a configured DoctrineDbalDatabase to
 *                        reuse, OR an inline DBAL params array. Omit to build from
 *                        flat params (see {@see DoctrineDbalParams}). NB: DBAL 4
 *                        cannot wrap a raw PDO, so referencing a plain PdoDatabase
 *                        is not supported — reference a DoctrineDbalDatabase.
 *  - `entity_paths`    : array of directories/files holding mapping metadata
 *  - `metadata`        : "attribute" (default) | "xml"
 *  - `dev_mode`        : bool (default = core.debug) — proxy auto-generation etc.
 *  - `proxy_dir`       : directory for generated proxies (default: system temp)
 *  - `proxy_namespace` : namespace for generated proxy classes
 *
 * Cache bridging (metadata/query caches to Quiote's PSR-6 pool) is a follow-up;
 * for now ORMSetup's in-memory default is used unless a subclass overrides
 * {@see metadataCache()}.
 */
class DoctrineDatabase extends AbstractOrmDatabase
{
    use DoctrineDbalParams;

    protected function connect()
    {
        $this->requireLibrary(EntityManager::class, 'doctrine/orm');

        $config = $this->buildOrmConfiguration();
        $dbal = $this->resolveDbalConnection($config);

        // ORM 3 removed EntityManager::create(); construct directly.
        $this->connection = new EntityManager($dbal, $config);
        $this->resource = $dbal;
    }

    protected function buildOrmConfiguration(): OrmConfiguration
    {
        $paths = $this->entityPaths();
        $isDevMode = (bool) $this->getParameter('dev_mode', Config::getBool('core.debug', false));
        $proxyDir = $this->proxyDir();
        $cache = $this->metadataCache();

        $metadataParam = $this->getParameter('metadata', 'attribute');
        if (!is_string($metadataParam)) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s": "metadata" parameter must be a string ("attribute" or "xml"), got %s.',
                $this->getName(),
                get_debug_type($metadataParam)
            ));
        }

        $config = match (strtolower($metadataParam)) {
            'xml'   => ORMSetup::createXMLMetadataConfiguration($paths, $isDevMode, $proxyDir, $cache),
            default => ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, $proxyDir, $cache),
        };

        $proxyNamespace = $this->getParameter('proxy_namespace');
        if (is_string($proxyNamespace) && $proxyNamespace !== '') {
            $config->setProxyNamespace($proxyNamespace);
        }

        // Doctrine ORM 3.x needs a lazy-proxy backend; Quiote targets PHP 8.5+,
        // where native lazy objects (no symfony/var-exporter dependency) are
        // always available. Opt out with the "native_lazy_objects" parameter.
        if ($this->getParameter('native_lazy_objects', true)) {
            $config->enableNativeLazyObjects(true);
        }

        return $config;
    }

    /**
     * @return array<string>
     */
    private function entityPaths(): array
    {
        $pathsParam = $this->getParameter('entity_paths') ?? [];
        if (!is_array($pathsParam)) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s": "entity_paths" parameter must be an array of strings, got %s.',
                $this->getName(),
                get_debug_type($pathsParam)
            ));
        }

        $paths = [];
        foreach ($pathsParam as $path) {
            if (!is_string($path)) {
                throw new DatabaseException(sprintf(
                    'DoctrineDatabase "%s": "entity_paths" must contain only strings, got %s.',
                    $this->getName(),
                    get_debug_type($path)
                ));
            }
            $paths[] = $path;
        }

        return $paths;
    }

    private function proxyDir(): ?string
    {
        $proxyDir = $this->getParameter('proxy_dir');
        if ($proxyDir !== null && !is_string($proxyDir)) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s": "proxy_dir" parameter must be a string or null, got %s.',
                $this->getName(),
                get_debug_type($proxyDir)
            ));
        }

        return $proxyDir;
    }

    protected function resolveDbalConnection(OrmConfiguration $config): DbalConnection
    {
        $connection = $this->getParameter('connection');

        if (is_string($connection)) {
            $resolved = $this->resolveUnderlyingConnection();
            if ($resolved instanceof DbalConnection) {
                return $resolved;
            }
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s" references "%s", which did not resolve to a '
                . 'Doctrine\DBAL\Connection (got %s). Reference a DoctrineDbalDatabase, '
                . 'or provide inline/flat connection params — DBAL 4 cannot wrap a raw PDO.',
                $this->getName(),
                $connection,
                get_debug_type($resolved)
            ));
        }

        $params = is_array($connection) ? $this->normalizeInlineDbalParams($connection) : $this->dbalParams();

        if (!$params) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s" needs connection details: reference a '
                . 'DoctrineDbalDatabase by name, or give an inline "connection" '
                . 'array / "url" / flat driver params.',
                $this->getName()
            ));
        }

        try {
            return DriverManager::getConnection($params, $config);
        } catch (\Throwable $e) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s" could not create a DBAL connection: %s',
                $this->getName(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * Override in a subclass to bridge Quiote's PSR-6 cache into Doctrine's
     * metadata/query caches. Returns null to use ORMSetup's default.
     */
    protected function metadataCache(): ?\Psr\Cache\CacheItemPoolInterface
    {
        return null;
    }

    // --- typed accessors ----------------------------------------------------

    public function getEntityManager(): EntityManagerInterface
    {
        $connection = $this->getConnection();
        if ($connection instanceof EntityManagerInterface) {
            return $connection;
        }

        throw new DatabaseException(sprintf(
            'DoctrineDatabase "%s" connection is not an EntityManagerInterface (got %s).',
            $this->getName(),
            get_debug_type($connection)
        ));
    }

    public function getDbalConnection(): DbalConnection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * Only available when the configured `driver` is a `pdo_*` one (`pdo_mysql`,
     * `pdo_pgsql`, `pdo_sqlite`, ...) — DBAL 4 also supports native drivers
     * (`mysqli`, `pgsql`) that never construct a \PDO instance at all.
     */
    #[\Override]
    public function getPdo(): \PDO
    {
        $native = $this->getDbalConnection()->getNativeConnection();
        if (!$native instanceof \PDO) {
            throw new DatabaseException(sprintf(
                'DoctrineDatabase "%s" is configured with a native (non-PDO) DBAL '
                . 'driver (got %s). Use a "pdo_*" driver (pdo_mysql, pdo_pgsql, '
                . 'pdo_sqlite, ...) to get a raw PDO connection, or write custom SQL via '
                . 'getDbalConnection()->executeQuery()/executeStatement().',
                $this->getName(),
                get_debug_type($native)
            ));
        }

        return $native;
    }

    /**
     * @template T of object
     * @param class-string<T> $entity
     * @return \Doctrine\ORM\EntityRepository<T>
     */
    public function getRepository(string $entity): \Doctrine\ORM\EntityRepository
    {
        return $this->getEntityManager()->getRepository($entity);
    }

    // --- worker lifecycle ---------------------------------------------------

    #[\Override]
    public function ping(): bool
    {
        if ($this->connection === null) {
            return true;
        }
        try {
            $this->getDbalConnection()->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            $this->connection = $this->resource = null;
            return false;
        }
    }

    /**
     * Per-request boundary: detach all managed entities (clear the identity map)
     * so nothing bleeds into the next request; keep the connection + metadata.
     */
    #[\Override]
    public function reset(): void
    {
        if ($this->connection instanceof EntityManagerInterface) {
            try {
                $this->connection->clear();
            } catch (\Throwable) {
                // best-effort
            }
        }
        parent::reset();
    }

    #[\Override]
    public function shutdown()
    {
        if ($this->connection instanceof EntityManagerInterface) {
            try {
                $conn = $this->connection->getConnection();
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                $conn->close();
            } catch (\Throwable) {
                // best-effort
            }
        }
        $this->connection = $this->resource = null;
    }
}
