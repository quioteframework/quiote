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
 *
 * @see docs/DATABASE_ADAPTERS_PLAN.md §4.2
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
        $paths = (array) ($this->getParameter('entity_paths') ?? []);
        $isDevMode = (bool) $this->getParameter('dev_mode', (bool) Config::get('core.debug', false));
        $proxyDir = $this->getParameter('proxy_dir'); // null → system temp
        $cache = $this->metadataCache();

        $metadata = strtolower((string) $this->getParameter('metadata', 'attribute'));
        $config = match ($metadata) {
            'xml'   => ORMSetup::createXMLMetadataConfiguration($paths, $isDevMode, $proxyDir, $cache),
            default => ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, $proxyDir, $cache),
        };

        if ($ns = $this->getParameter('proxy_namespace')) {
            $config->setProxyNamespace($ns);
        }

        // Doctrine ORM 3.x needs a lazy-proxy backend. On PHP 8.4+ prefer native
        // lazy objects (no symfony/var-exporter dependency); Quiote targets 8.5,
        // so default it on. Opt out with the "native_lazy_objects" parameter.
        if (
            method_exists($config, 'enableNativeLazyObjects')
            && $this->getParameter('native_lazy_objects', PHP_VERSION_ID >= 80400)
        ) {
            $config->enableNativeLazyObjects(true);
        }

        return $config;
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

        $params = is_array($connection) ? $connection : $this->dbalParams();

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
        return $this->getConnection();
    }

    public function getDbalConnection(): DbalConnection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * @param class-string $entity
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
