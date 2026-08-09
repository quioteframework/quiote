<?php

namespace Quiote\Database\Adapter\Propulsion;

use Quiote\Database\Database;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;
use Quiote\Util\Toolkit;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;

/**
 * First-class adapter for Propulsion (the quioteframework/propulsion fork of
 * Propel 1). The adapter bootstraps Propulsion from its runtime config and
 * returns a datasource PDO connection from {@see getConnection()}.
 *
 * Configuration parameters (in `databases.xml`):
 *  - `config`                  : path to the Propulsion runtime config file
 *  - `datasource`              : datasource to use (default = config default)
 *  - `overrides`               : key/value overrides applied after init
 *  - `init_queries`            : extra connection init queries to append
 *  - `enable_instance_pooling` : true/false to force pooling behavior
 */
class PropulsionDatabase extends Database
{
    private string $datasource = 'default';

    /**
     * Bootstraps Propulsion from the configured runtime config file.
     *
     * Requires the `quioteframework/propulsion` package and a `config`
     * parameter pointing at a readable PHP file that returns an array;
     * directives in the path are expanded first. Propulsion is initialized
     * from that file if it has not been initialized yet, otherwise the
     * already-running instance is reconfigured with this array. The datasource
     * is then resolved, `overrides` are applied to the configuration,
     * `init_queries` are appended to the datasource's connection queries, and
     * instance pooling is switched on or off if `enable_instance_pooling` was
     * given. No connection is opened here.
     *
     * @param array<string, mixed> $parameters
     * @throws DatabaseException If Propulsion is not installed, the `config`
     *                           parameter is missing or unreadable, the config
     *                           file does not return an array, the
     *                           configuration object cannot be resolved, or no
     *                           datasource can be determined.
     */
    #[\Override]
    public function initialize(DatabaseManager $databaseManager, array $parameters = [])
    {
        parent::initialize($databaseManager, $parameters);
        $this->requirePropulsionLibrary();

        $configParam = $this->getParameter('config');
        if (!is_string($configParam) || $configParam === '') {
            throw new DatabaseException(sprintf(
                'PropulsionDatabase "%s" requires a non-empty string "config" parameter.',
                $this->getName()
            ));
        }

        $configPath = Toolkit::expandDirectives($configParam);
        if (!$configPath || !is_file($configPath)) {
            throw new DatabaseException(sprintf(
                'PropulsionDatabase "%s" requires a readable "config" file path; got %s.',
                $this->getName(),
                var_export($configParam, true)
            ));
        }

        $rawConfig = require $configPath;
        if (!is_array($rawConfig)) {
            throw new DatabaseException(sprintf(
                'PropulsionDatabase "%s" expected "%s" to return an array, got %s.',
                $this->getName(),
                $configPath,
                get_debug_type($rawConfig)
            ));
        }

        if (!Propulsion::isInit()) {
            Propulsion::init($configPath);
        } else {
            Propulsion::setConfiguration($rawConfig);
            Propulsion::initialize();
        }

        $config = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
        if (!$config instanceof PropulsionConfiguration) {
            throw new DatabaseException(sprintf(
                'PropulsionDatabase "%s" could not resolve a PropulsionConfiguration instance.',
                $this->getName()
            ));
        }

        $this->datasource = $this->resolveDatasource($rawConfig);
        foreach ((array) $this->getParameter('overrides', []) as $key => $value) {
            $config->setParameter((string) $key, $value);
        }

        $queryPath = sprintf('datasources.%s.connection.settings.queries.query', $this->datasource);
        $queries = (array) $config->getParameter($queryPath, []);
        $queries = array_merge($queries, (array) $this->getParameter('init_queries', []));
        $config->setParameter($queryPath, $queries);

        $enablePooling = $this->getParameter('enable_instance_pooling');
        if ($enablePooling === true) {
            Propulsion::enableInstancePooling();
        } elseif ($enablePooling === false) {
            Propulsion::disableInstancePooling();
        }
    }

    protected function connect()
    {
        $this->connection = $this->resource = Propulsion::getConnection($this->datasource);
    }

    /**
     * Returns the `config` parameter as configured.
     *
     * This is the raw parameter value, not the directive-expanded path that
     * initialize() actually loaded.
     *
     * @throws DatabaseException If the `config` parameter is absent or not a
     *                           non-empty string.
     */
    public function getConfigPath(): string
    {
        $configPath = $this->getParameter('config');
        if (is_string($configPath) && $configPath !== '') {
            return $configPath;
        }

        throw new DatabaseException(sprintf(
            'PropulsionDatabase "%s" has no usable "config" parameter.',
            $this->getName()
        ));
    }

    /**
     * Returns the Propulsion datasource this database connects through.
     *
     * Resolved during initialize() from the `datasource` parameter or from the
     * config file's default datasource; `default` until then.
     */
    public function getDatasource(): string
    {
        return $this->datasource;
    }

    /**
     * Returns the connection narrowed to Propulsion's own PDO subclass.
     *
     * Connects lazily on first call. Use this over getConnection() when the
     * caller needs Propulsion-specific PDO behaviour.
     *
     * @throws DatabaseException If a connection could not be created, or the
     *                           datasource handed back something that is not a
     *                           PropulsionPDO.
     */
    public function getPropulsionConnection(): PropulsionPDO
    {
        $connection = $this->getConnection();
        if ($connection instanceof PropulsionPDO) {
            return $connection;
        }

        throw new DatabaseException(sprintf(
            'PropulsionDatabase "%s" expected a %s connection, got %s.',
            $this->getName(),
            PropulsionPDO::class,
            get_debug_type($connection)
        ));
    }

    /**
     * Returns the datasource connection as a plain PDO handle.
     *
     * PropulsionPDO is an interface, and each concrete implementation extends
     * the driver-specific PDO subclass rather than PDO itself, so the PDO
     * instance is checked for here instead of being taken on trust.
     *
     * @throws DatabaseException If the connection could not be created, is not
     *                           a PropulsionPDO, or is a PropulsionPDO that
     *                           does not extend PDO.
     */
    #[\Override]
    public function getPdo(): \PDO
    {
        $connection = $this->getPropulsionConnection();
        if ($connection instanceof \PDO) {
            return $connection;
        }

        throw new DatabaseException(sprintf(
            'PropulsionDatabase "%s" connection is a %s that does not extend PDO, so it cannot be used as a raw PDO handle.',
            $this->getName(),
            get_debug_type($connection)
        ));
    }

    /**
     * Probes the connection with `SELECT 1`.
     *
     * Returns true when no connection has been opened yet, since lazy connect
     * will create a fresh one on first use. On any failure — including the
     * connection not being a PDO instance — the connection and resource are
     * cleared so the next getConnection() reconnects, and false is returned.
     */
    #[\Override]
    public function ping(): bool
    {
        if ($this->connection === null) {
            return true;
        }

        try {
            if (!$this->connection instanceof \PDO) {
                throw new DatabaseException(sprintf(
                    'PropulsionDatabase "%s" connection is not a PDO instance (got %s).',
                    $this->getName(),
                    get_debug_type($this->connection)
                ));
            }

            $this->connection->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            $this->connection = $this->resource = null;
            return false;
        }
    }

    /**
     * Reset request-scoped Propulsion state while preserving process-scoped
     * resources (connection pool, maps, adapters).
     */
    #[\Override]
    public function reset(): void
    {
        if (Propulsion::isInit()) {
            Propulsion::getSession()->reset();
        }
    }

    /**
     * Closes Propulsion and drops the connection.
     *
     * Propulsion::close() is only called when Propulsion was initialized; the
     * connection and resource are cleared either way, so a later
     * getConnection() opens a new one.
     */
    #[\Override]
    public function shutdown()
    {
        if (Propulsion::isInit()) {
            Propulsion::close();
        }
        $this->connection = $this->resource = null;
    }

    /**
     * @param array<mixed, mixed> $rawConfig
     */
    private function resolveDatasource(array $rawConfig): string
    {
        $datasource = $this->getParameter('datasource');
        if (is_string($datasource) && $datasource !== '' && $datasource !== 'default') {
            return $datasource;
        }

        $fromRoot = null;
        $datasources = $rawConfig['datasources'] ?? null;
        if (is_array($datasources)) {
            $fromRoot = $datasources['default'] ?? null;
        }
        if (is_string($fromRoot) && $fromRoot !== '') {
            return $fromRoot;
        }

        $fromPropel = null;
        $propel = $rawConfig['propel'] ?? null;
        if (is_array($propel)) {
            $propelDatasources = $propel['datasources'] ?? null;
            if (is_array($propelDatasources)) {
                $fromPropel = $propelDatasources['default'] ?? null;
            }
        }
        if (is_string($fromPropel) && $fromPropel !== '') {
            return $fromPropel;
        }

        throw new DatabaseException(sprintf(
            'PropulsionDatabase "%s" has no datasource: set the "datasource" parameter or define datasources.default in the runtime config.',
            $this->getName()
        ));
    }

    private function requirePropulsionLibrary(): void
    {
        if (class_exists(Propulsion::class) && class_exists(PropulsionConfiguration::class)) {
            return;
        }

        throw new DatabaseException(sprintf(
            'PropulsionDatabase "%s" requires the "quioteframework/propulsion" package. Install it with: composer require quioteframework/propulsion',
            $this->getName()
        ));
    }
}
