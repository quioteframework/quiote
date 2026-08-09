<?php

declare(strict_types=1);

namespace Quiote\Docs;

use Quiote\Docs\Emitter\ApiIndexEmitter;
use Quiote\Docs\Emitter\ClassPageEmitter;
use Quiote\Docs\Emitter\Markdown;
use Quiote\Docs\Emitter\NamespaceIndexEmitter;
use Quiote\Docs\Emitter\TypeLinker;
use Quiote\Docs\Ir\ApiIndex;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\EmittedArtifact;

/**
 * Turns the documentation model into the full set of pages, plus the manifest that
 * describes it.
 *
 * Nothing here touches the filesystem: the command decides what to do with the artifacts,
 * so a drift check can compare them against disk without writing, and a test can assert on
 * them without a temporary directory.
 */
final class DocsGenerator
{
    public const MANIFEST_FILE = '.manifest.json';
    private const MANIFEST_SCHEMA = 1;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Markdown $markdown = new Markdown(),
        private readonly string $basePath = '/api',
    ) {
    }

    /**
     * Every page the reference consists of, keyed by its path below the output directory.
     *
     * @return array<string, EmittedArtifact>
     */
    public function generate(ApiIndex $index): array
    {
        $this->diagnostics = [];

        $linker = new TypeLinker($index, $this->basePath);
        $classPages = new ClassPageEmitter($index, $linker, $this->markdown);
        $namespacePages = new NamespaceIndexEmitter($index, $linker, $this->markdown);
        $indexPage = new ApiIndexEmitter($index, $this->markdown, $linker);

        $artifacts = [];

        $root = $indexPage->emit();
        $artifacts[$root->targetHint] = $root;

        foreach ($index->navigableNamespaces() as $namespace) {
            // The framework's root namespace addresses the reference root, which the landing
            // page already occupies; a second page there would be a duplicate route.
            if ($index->slugger()->forNamespace($namespace) === '') {
                continue;
            }

            $artifact = $namespacePages->emit($namespace);
            $artifacts[$artifact->targetHint] = $artifact;
        }

        $routes = [];
        foreach (array_keys($artifacts) as $target) {
            $routes[$this->routeFor($target)] = $target;
        }

        foreach ($index->classes() as $class) {
            $artifact = $classPages->emit($class);
            $route = $this->routeFor($artifact->targetHint);

            if (isset($routes[$route])) {
                // Two pages claiming one path would become a duplicate route, and the site is
                // built in a repository with no PHP to diagnose it -- so it fails here instead.
                $this->diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_ERROR,
                    Diagnostic::CODE_UNRESOLVABLE_CLASS,
                    sprintf(
                        'Two pages serve "/%s": %s would be written to %s, which collides with %s. '
                        . 'Rename one, or the documentation site will refuse to build.',
                        $route,
                        $class->fqcn,
                        $artifact->targetHint,
                        $routes[$route],
                    ),
                    $class->sourcePath,
                    symbol: $class->fqcn,
                );

                continue;
            }

            $routes[$route] = $artifact->targetHint;
            $artifacts[$artifact->targetHint] = $artifact;
        }

        ksort($artifacts, SORT_STRING);

        $manifest = $this->manifest($artifacts, $index);
        $artifacts[$manifest->targetHint] = $manifest;

        return $artifacts;
    }

    /** @return list<Diagnostic> */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * The URL a page file is served at.
     *
     * Two different files can address one URL -- `execution/slot.md` and
     * `execution/slot/index.md` both answer `/api/execution/slot/` -- so a collision has to be
     * looked for here rather than in the file paths, which would not appear to clash at all.
     */
    private function routeFor(string $target): string
    {
        $route = substr($target, -3) === '.md' ? substr($target, 0, -3) : $target;

        if ($route === 'index') {
            return '';
        }

        return str_ends_with($route, '/index') ? substr($route, 0, -6) : $route;
    }

    /**
     * The list of what this run produced.
     *
     * Drift checking compares artifacts one path at a time, so on its own it cannot notice a
     * page whose class no longer exists: nothing regenerates it and nothing reports it. The
     * manifest is what makes a deletion visible, and it is what write mode prunes against.
     *
     * @param array<string, EmittedArtifact> $artifacts
     */
    private function manifest(array $artifacts, ApiIndex $index): EmittedArtifact
    {
        $files = [];
        foreach ($artifacts as $target => $artifact) {
            $files[$target] = $artifact->checksum;
        }
        ksort($files, SORT_STRING);

        // No version and no timestamp: either would rewrite every page on every commit and
        // make a drift check meaningless.
        $payload = [
            'schema' => self::MANIFEST_SCHEMA,
            'namespaces' => $index->topLevelNamespaces(),
            'types' => count($index->classes()),
            'files' => $files,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return EmittedArtifact::fromSource($json . "\n", self::MANIFEST_FILE);
    }

    /**
     * Reads a manifest previously written to $outputDir.
     *
     * @return array<string, string>|null Target path => checksum, or null when there is none.
     */
    public function readManifest(string $outputDir): ?array
    {
        $path = rtrim($outputDir, '/') . '/' . self::MANIFEST_FILE;
        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
            return null;
        }

        $files = [];
        foreach ($decoded['files'] as $target => $checksum) {
            if (is_string($target) && is_string($checksum)) {
                $files[$target] = $checksum;
            }
        }

        return $files;
    }
}
