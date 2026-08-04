<?php

namespace Quiote\Model;

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

/**
 * Turns a model name into the class that implements it.
 *
 * The naming conventions are the only reason this class changes: a fully qualified class name
 * is taken at its word (with an optional `Model` suffix), and a short name is probed against
 * the namespaced convention first, then the underscore-joined legacy one, then a direct
 * `require` of the file the legacy convention would have put it in.
 *
 * Resolution is a pure function of (model name, module name) once the class exists, so the
 * answer -- including the reflection probe that feeds it -- is cached. The cache is per
 * instance rather than per process: the resolver is a container singleton, so a worker resolves
 * each model name once, and a second named context profile keeping its own cache costs a
 * handful of `class_exists()` calls rather than sharing mutable static state.
 *
 * @since      4.0.0
 */
final class ModelClassResolver
{
    /**
     * @var        array<string, ResolvedModel> Keyed by "(moduleName ?? '')|(modelName)".
     */
    private array $cache = [];

    /**
     * Resolve a model name to the class implementing it.
     *
     * @param      string $modelName A model name or fully qualified class name.
     * @param      ?string $moduleName A module name for a module model, null for a global one.
     * @throws     QuioteException When no candidate class exists.
     * @since      4.0.0
     */
    public function resolve(string $modelName, ?string $moduleName = null): ResolvedModel
    {
        $cacheKey = ($moduleName ?? '') . '|' . $modelName;
        $cached = $this->cache[$cacheKey] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $class = str_contains($modelName, '\\')
            ? $this->findQualifiedClass($modelName)
            : $this->findConventionalClass($modelName, $moduleName);

        if ($class === null) {
            throw new QuioteException(
                sprintf("Couldn't find class for Model %s", $modelName),
            );
        }

        $rc = new \ReflectionClass($class);

        return $this->cache[$cacheKey] = new ResolvedModel(
            $class,
            $rc->implementsInterface(ISingletonModel::class),
            $rc->getConstructor() !== null,
        );
    }

    /**
     * Drop the resolution cache. For tests that define model classes between calls, and for
     * anything that changes `core.namespace_prefix` or the model directories mid-process.
     *
     * @since      4.0.0
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * A name containing a namespace separator is already a class name: try it with the `Model`
     * suffix the convention adds, then exactly as given.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    private function findQualifiedClass(string $modelName): ?string
    {
        if (!str_ends_with($modelName, 'Model')) {
            $suffixed = $modelName . 'Model';
            if (class_exists($suffixed)) {
                return $suffixed;
            }
        }

        return class_exists($modelName) ? $modelName : null;
    }

    /**
     * A short name goes through the convention chain: namespaced class, legacy
     * underscore-joined class, then the legacy file location the autoloader does not know
     * about.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    private function findConventionalClass(string $modelName, ?string $moduleName): ?string
    {
        $baseNamespace = Config::getString('core.namespace_prefix', 'App');
        $canonical = Toolkit::canonicalName($modelName);
        $legacyName = str_replace('/', '_', $canonical);
        $namespacedName = str_replace('/', '\\', $canonical);

        if ($moduleName === null) {
            $namespaced = $baseNamespace . '\\Models\\' . $namespacedName . 'Model';
            $legacy = $legacyName . 'Model';
            $file = Config::getString('core.model_dir') . '/' . $canonical . 'Model.php';
        } else {
            $namespaced = $baseNamespace . '\\Modules\\' . $moduleName . '\\Models\\'
                . $namespacedName . 'Model';
            $legacy = $moduleName . '_' . $legacyName . 'Model';
            $file = Config::getString('core.module_dir') . '/' . $moduleName . '/Models/'
                . $canonical . 'Model.php';
        }

        foreach ([$namespaced, $legacy] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // Neither convention resolved through the autoloader. The legacy one also allows the
        // class to sit in a file nothing autoloads, so load it and ask once more.
        if (is_readable($file)) {
            require $file;
        }

        return class_exists($legacy) ? $legacy : null;
    }
}
