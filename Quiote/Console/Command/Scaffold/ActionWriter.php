<?php
declare(strict_types=1);

namespace Quiote\Console\Command\Scaffold;

use Quiote\Exception\ConfigurationException;

/**
 * Writes the files for `make:action`: the Action class itself, and
 * (optionally) a matching View + Template. Follows the same "heredoc per
 * file" convention as `AppWriter` -- no template-file/engine mechanism.
 *
 * HTTP-verb -> execute{X}() naming mirrors
 * {@see \Quiote\Execution\HttpMethodMapper}'s canonical map (the single
 * source of truth ActionResolver dispatches against): GET/HEAD/OPTIONS/TRACE
 * -> executeRead, POST -> executeWrite, PUT/PATCH -> executeUpdate,
 * DELETE -> executeRemove. Output-type -> execute{X}() naming mirrors the
 * `ucfirst(strtolower($name))` convention already documented on
 * {@see \Quiote\View\View} and used by `AppWriter::viewPhp()`.
 */
final class ActionWriter
{
    /** Mirrors Quiote\Execution\HttpMethodMapper::DEFAULT_MAP; kept in sync manually since that map is private. */
    private const VERB_TOKENS = [
        'GET' => 'Read',
        'HEAD' => 'Read',
        'OPTIONS' => 'Read',
        'TRACE' => 'Read',
        'POST' => 'Write',
        'PUT' => 'Update',
        'PATCH' => 'Update',
        'DELETE' => 'Remove',
    ];

    /** Output types this generator knows how to also provision in output_types.xml. */
    private const KNOWN_OUTPUT_TYPE_CONTENT_TYPES = [
        'json' => 'application/json; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'text' => 'text/plain; charset=UTF-8',
    ];

    public function __construct(
        private readonly string $appDir,
        private readonly string $namespace,
        private readonly string $moduleDir,
    ) {
    }

    /**
     * @param list<string> $methods HTTP verbs, e.g. ['GET', 'POST']
     * @param list<string> $outputTypes output type names, e.g. ['html', 'json']
     * @return list<string> warnings (e.g. output types left unconfigured)
     */
    public function write(string $name, array $methods, bool $withView, array $outputTypes, bool $force): array
    {
        $warnings = [];

        $actionPath = "{$this->appDir}/Modules/{$this->moduleDir}/Actions/{$name}Action.php";
        GeneratorSupport::guardOverwrite($actionPath, $force);
        GeneratorSupport::writeFile($actionPath, $this->actionPhp($name, $methods, $withView));

        if ($withView) {
            $viewPrefix = "{$name}Success";
            $viewPath = "{$this->appDir}/Modules/{$this->moduleDir}/Views/{$viewPrefix}View.php";
            GeneratorSupport::guardOverwrite($viewPath, $force);
            GeneratorSupport::writeFile($viewPath, $this->viewPhp($viewPrefix, $outputTypes));

            if (in_array('html', $outputTypes, true)) {
                $templatePath = "{$this->appDir}/Modules/{$this->moduleDir}/Templates/{$viewPrefix}.php";
                GeneratorSupport::guardOverwrite($templatePath, $force);
                GeneratorSupport::writeFile($templatePath, $this->templatePhp($name));
            }

            $warnings = $this->ensureOutputTypesConfigured($outputTypes);
        }

        return $warnings;
    }

    /**
     * @param list<string> $outputTypes
     * @return list<string> warnings for output types this method could not auto-provision
     */
    private function ensureOutputTypesConfigured(array $outputTypes): array
    {
        $warnings = [];
        $configPath = "{$this->appDir}/Config/output_types.xml";
        if (!is_file($configPath)) {
            return $outputTypes === ['html'] ? [] : ['Config/output_types.xml not found -- add <output_type> entries for: ' . implode(', ', array_diff($outputTypes, ['html']))];
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!$dom->load($configPath)) {
            throw new ConfigurationException(sprintf('Could not parse "%s" as XML.', $configPath));
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ot', 'http://quiote.dev/quiote/config/parts/output_types/1.1');
        $xpath->registerNamespace('ae', 'http://quiote.dev/quiote/config/global/envelope/1.1');

        $outputTypesNodes = $xpath->query('//ot:output_types');
        $outputTypesNode = $outputTypesNodes !== false ? $outputTypesNodes->item(0) : null;
        if (!$outputTypesNode instanceof \DOMElement) {
            return $outputTypes === ['html'] ? [] : ['Could not locate <output_types> in Config/output_types.xml -- add entries for: ' . implode(', ', array_diff($outputTypes, ['html']))];
        }

        $changed = false;
        foreach (array_diff($outputTypes, ['html']) as $type) {
            $existingNodes = $xpath->query('ot:output_type[@name="' . $type . '"]', $outputTypesNode);
            $existing = $existingNodes !== false ? $existingNodes->item(0) : null;
            if ($existing !== null) {
                continue;
            }
            $contentType = self::KNOWN_OUTPUT_TYPE_CONTENT_TYPES[$type] ?? null;
            if ($contentType === null) {
                $warnings[] = sprintf(
                    'Output type "%s" is not one Quiote provisions automatically -- add a matching <output_type name="%s"> entry to Config/output_types.xml yourself.',
                    $type,
                    $type
                );
                continue;
            }
            $outputTypesNode->appendChild($this->buildOutputTypeNode($dom, $type, $contentType));
            $changed = true;
        }

        if ($changed) {
            if ($dom->save($configPath) === false) {
                throw new ConfigurationException(sprintf('Could not write "%s".', $configPath));
            }
        }

        return $warnings;
    }

    private function buildOutputTypeNode(\DOMDocument $dom, string $type, string $contentType): \DOMElement
    {
        $outputType = $dom->createElement('output_type');
        $outputType->setAttribute('name', $type);

        $renderers = $dom->createElement('renderers');
        $renderers->setAttribute('default', 'php');
        $renderer = $dom->createElement('renderer');
        $renderer->setAttribute('name', 'php');
        $renderer->setAttribute('class', 'Quiote\\Renderer\\PhpRenderer');
        $renderers->appendChild($renderer);
        $outputType->appendChild($renderers);

        $httpHeaders = $dom->createElementNS('http://quiote.dev/quiote/config/global/envelope/1.1', 'ae:parameter');
        $httpHeaders->setAttribute('name', 'http_headers');
        $contentTypeParam = $dom->createElementNS('http://quiote.dev/quiote/config/global/envelope/1.1', 'ae:parameter');
        $contentTypeParam->setAttribute('name', 'Content-Type');
        $contentTypeParam->appendChild($dom->createTextNode($contentType));
        $httpHeaders->appendChild($contentTypeParam);
        $outputType->appendChild($httpHeaders);

        return $outputType;
    }

    /**
     * @param list<string> $methods
     */
    private function actionPhp(string $name, array $methods, bool $withView): string
    {
        $namespace = $this->namespace;
        $module = $this->moduleDir;
        $defaultView = $withView ? "{$name}Success" : 'Success';

        $tokens = [];
        foreach ($methods as $verb) {
            $tokens[self::VERB_TOKENS[$verb]] = true;
        }

        $methodStubs = '';
        foreach (array_keys($tokens) as $token) {
            $methodStubs .= <<<PHP

	public function execute{$token}(WebRequest \$rd): string
	{
		return '{$defaultView}';
	}

PHP;
        }

        return <<<PHP
<?php
namespace {$namespace}\\Modules\\{$module}\\Actions;

use Quiote\\Action\\Action;
use Quiote\\Request\\WebRequest;

class {$name}Action extends Action
{
{$methodStubs}
	public function getDefaultViewName(): string
	{
		return '{$defaultView}';
	}

	// No validators configured for this scaffolded action -- skip the
	// validation pipeline's XML-config lookup entirely.
	public function isSimple(): bool
	{
		return true;
	}
}

PHP;
    }

    /**
     * @param list<string> $outputTypes
     */
    private function viewPhp(string $viewClassPrefix, array $outputTypes): string
    {
        $namespace = $this->namespace;
        $module = $this->moduleDir;

        $methodStubs = '';
        foreach ($outputTypes as $type) {
            $token = ucfirst(strtolower($type));
            $methodStubs .= $type === 'html'
                ? <<<PHP

	public function executeHtml(WebRequest \$rd): void
	{
		\$this->loadLayout();
		\$this->setAttribute('title', '{$viewClassPrefix}');
	}

PHP
                : <<<PHP

	public function execute{$token}(WebRequest \$rd): string
	{
		return json_encode(['message' => '{$viewClassPrefix}'], JSON_THROW_ON_ERROR);
	}

PHP;
        }

        return <<<PHP
<?php
namespace {$namespace}\\Modules\\{$module}\\Views;

use Quiote\\Exception\\ViewException;
use Quiote\\Request\\WebRequest;
use Quiote\\View\\View;

class {$viewClassPrefix}View extends View
{
	public function execute(WebRequest \$rd): never
	{
		throw new ViewException(sprintf(
			'The view "%1\$s" does not implement an "execute%2\$s()" method for this output type.',
			static::class,
			ucfirst(strtolower(\$this->getCurrentOutputType()->getName()))
		));
	}
{$methodStubs}}

PHP;
    }

    private function templatePhp(string $name): string
    {
        return <<<HTML
<p><?php echo htmlspecialchars(\$template['title'] ?? '{$name}', ENT_QUOTES, 'UTF-8'); ?></p>

HTML;
    }
}
