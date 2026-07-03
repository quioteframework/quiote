<?php

namespace Quiote\Database\Adapter\Doctrine;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;

/**
 * Enables the `doctrine` (ORM) and `doctrine_dbal` (DBAL-only) driver aliases.
 * Add this class to the `plugins` config key to use them in `databases.xml`.
 *
 * Extracts to `quioteframework/quiote-doctrine` unchanged.
 */
final class DoctrinePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'quiote/doctrine';
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar
            ->databaseDriver('doctrine', DoctrineDatabase::class)
            ->databaseDriver('doctrine_dbal', DoctrineDbalDatabase::class);
    }
}
