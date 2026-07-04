<?php

namespace Quiote\Database\Adapter\Doctrine;

/**
 * Shared translation of flat `databases.xml` parameters into a Doctrine DBAL
 * connection-parameters array, used by both {@see DoctrineDatabase} and
 * {@see DoctrineDbalDatabase}.
 *
 * DBAL 4 no longer wraps a pre-existing PDO, so there is no "layer on a raw PDO"
 * mode here — either give inline `connection` details, a DSN `url`, or flat
 * driver params, or (for the ORM) reference a DoctrineDbalDatabase by name.
 */
trait DoctrineDbalParams
{
    /**
     * @return array<string, mixed>
     */
    protected function dbalParams(): array
    {
        if ($url = $this->getParameter('url')) {
            return ['url' => $url];
        }

        return array_filter([
            'driver'   => $this->getParameter('driver'),   // pdo_mysql, pdo_pgsql, pdo_sqlite, ...
            'host'     => $this->getParameter('host'),
            'port'     => $this->getParameter('port'),
            'dbname'   => $this->getParameter('dbname'),
            'user'     => $this->getParameter('user', $this->getParameter('username')),
            'password' => $this->getParameter('password'),
            'path'     => $this->getParameter('path'),     // sqlite file path
            'memory'   => $this->getParameter('memory'),   // sqlite :memory:
            'charset'  => $this->getParameter('charset'),
        ], static fn($v) => $v !== null);
    }
}
