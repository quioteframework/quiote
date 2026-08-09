<?php

namespace Quiote\Database\Adapter\Doctrine;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Enables the `doctrine` (ORM) and `doctrine_dbal` (DBAL-only) driver aliases.
 * Add this class to the `plugins` config key to use them in `databases.xml`.
 *
 * Extracts to `quioteframework/quiote-doctrine` unchanged.
 */
#[PluginAttribute(name: 'quiote/doctrine')]
final class DoctrinePlugin implements PluginInterface
{
    /**
     * Registers both Doctrine driver aliases.
     *
     * `doctrine` maps to {@see DoctrineDatabase} (full ORM) and
     * `doctrine_dbal` to {@see DoctrineDbalDatabase} (DBAL only).
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar
            ->databaseDriver('doctrine', DoctrineDatabase::class)
            ->databaseDriver('doctrine_dbal', DoctrineDbalDatabase::class);
    }
}
