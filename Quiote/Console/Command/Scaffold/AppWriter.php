<?php
namespace Quiote\Console\Command\Scaffold;

use Quiote\Exception\ConfigurationException;

/**
 * Writes the actual files for `quiote new`. Kept separate from NewCommand so
 * the (fairly mechanical) file templates don't crowd the command's
 * option-parsing/error-reporting concerns.
 *
 * The generated app deliberately mixes config formats to exercise all three
 * FormatDrivers: settings in whichever
 * format was requested (default php), factories in YAML, databases/output_types
 * in XML. Routing is a plain PHP `Routing` subclass rather than a config file
 * at all -- `Quiote\Config\RoutingConfigHandler` (the class routing.xml
 * would need) doesn't exist, so routing.xml is not a working option today. The generated AppRouting
 * also demonstrates that file-based and #[Route]-attribute routing coexist:
 * Index/About/Boom are declared by hand, Contact is declared via a #[Route]
 * attribute on ContactAction and pulled in with AttributeRoutes::mergeInto().
 * @since      1.0.0
 */
final class AppWriter
{
	public function __construct(
		private readonly string $path,
		private readonly string $namespace,
		private readonly string $format,
		private readonly ?string $activeAutoloadPath = null,
	) {}

	public function write(): void
	{
		$this->mkdir($this->path);
		$this->mkdir("$this->path/Config");
		$this->mkdir("$this->path/Modules/Default/Actions");
		$this->mkdir("$this->path/Modules/Default/Views");
		$this->mkdir("$this->path/Modules/Default/Templates");
		$this->mkdir("$this->path/Routing");
		$this->mkdir("$this->path/cache");
		$this->mkdir("$this->path/log");
		$this->mkdir("$this->path/pub");

		$this->put("cache/.gitkeep", '');
		$this->put("log/.gitkeep", '');
		$this->put('.gitignore', $this->gitignore());

		$this->put('Config/settings.' . $this->settingsExtension(), $this->settingsContent());
		$this->put('Config/factories.yaml', $this->factoriesYaml());
		$this->put('Config/databases.xml', $this->databasesXml());
		$this->put('Config/output_types.xml', $this->outputTypesXml());

		$this->put('Routing/AppRouting.php', $this->routingPhp());

		$this->put('Modules/Default/Actions/IndexAction.php', $this->actionPhp('Index', 'Success'));
		$this->put('Modules/Default/Actions/AboutAction.php', $this->actionPhp('About', 'Success'));
		$this->put('Modules/Default/Actions/BoomAction.php', $this->boomActionPhp());
		$this->put('Modules/Default/Actions/ContactAction.php', $this->contactActionPhp());

		$this->put('Modules/Default/Views/IndexSuccessView.php', $this->viewPhp('IndexSuccess', 'Home'));
		$this->put('Modules/Default/Views/AboutSuccessView.php', $this->viewPhp('AboutSuccess', 'About'));
		$this->put('Modules/Default/Views/ContactSuccessView.php', $this->viewPhp('ContactSuccess', 'Contact'));

		$this->put('Modules/Default/Templates/IndexSuccess.php', $this->templatePhp('Home', $this->indexBody()));
		$this->put('Modules/Default/Templates/AboutSuccess.php', $this->templatePhp('About', $this->aboutBody()));
		$this->put('Modules/Default/Templates/ContactSuccess.php', $this->templatePhp('Contact', $this->contactBody()));

		$this->put('pub/index.php', $this->frontControllerPhp());

		$this->put('phpstan-bootstrap.php', $this->phpstanBootstrapPhp());
		$this->put('phpstan.neon', $this->phpstanNeon());
	}

	/**
	 * Skip baking in the absolute autoload path (dev-machine-specific, ugly
	 * to commit) when the target app already lives inside the same
	 * vendor/autoload.php's own directory tree -- the portable relative
	 * `dirname(__DIR__, N)` candidates in pub/index.php already reach it in
	 * that case (e.g. scaffolding samples/app inside this very monorepo).
	 */
	private function needsAbsoluteAutoloadFallback(): bool
	{
		if ($this->activeAutoloadPath === null) {
			return false;
		}
		$pubDir = realpath($this->path) ?: rtrim($this->path, '/');
		$pubDir .= '/pub';
		for ($levels = 1; $levels <= 5; $levels++) {
			$candidate = dirname($pubDir, $levels) . '/vendor/autoload.php';
			if (realpath($candidate) === $this->activeAutoloadPath) {
				return false;
			}
		}
		return true;
	}

	private function gitignore(): string
	{
		return <<<'GITIGNORE'
		/cache/*
		!/cache/.gitkeep
		/log/*
		!/log/.gitkeep

		GITIGNORE;
	}

	private function settingsExtension(): string
	{
		return match ($this->format) {
			'php' => 'php',
			'yaml' => 'yaml',
			'xml' => 'xml',
			default => throw new ConfigurationException('Unknown config format "' . $this->format . '"'),
		};
	}

	private function mkdir(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new ConfigurationException(sprintf('Could not create directory "%s".', $path));
		}
	}

	private function put(string $relative, string $content): void
	{
		$full = "$this->path/$relative";
		if (file_put_contents($full, $content) === false) {
			throw new ConfigurationException(sprintf('Could not write file "%s".', $full));
		}
	}

	private function settingsContent(): string
	{
		$values = [
			'core.app_name' => $this->namespace,
			'core.namespace_prefix' => $this->namespace,
			'core.available' => true,
			'core.debug' => false,
			'core.use_database' => false,
			'core.use_logging' => true,
			'core.use_security' => false,
			'core.use_translation' => false,
			'core.default_context' => 'web',
		];

		return match ($this->format) {
			'php' => "<?php\n\nreturn " . var_export($values, true) . ";\n",
			'yaml' => implode("\n", array_map(
				static fn(string $k, mixed $v) => $k . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : "'" . $v . "'"),
				array_keys($values),
				$values,
			)) . "\n",
			'xml' => $this->settingsXml($values),
			default => throw new ConfigurationException('Unknown config format "' . $this->format . '"'),
		};
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function settingsXml(array $values): string
	{
		$settings = '';
		foreach ($values as $key => $value) {
			$name = substr($key, strlen('core.'));
			$literal = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
			$settings .= "\t\t\t<setting name=\"$name\">$literal</setting>\n";
		}

		return <<<XML
		<?xml version="1.0" encoding="UTF-8"?>
		<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1" xmlns="http://quiote.dev/quiote/config/parts/settings/1.1">
			<ae:configuration>
				<settings>
		$settings\t\t</settings>
			</ae:configuration>
		</ae:configurations>

		XML;
	}

	private function factoriesYaml(): string
	{
		$namespace = $this->namespace;
		return <<<YAML
		# Factories in YAML. Settings are in
		# {$this->settingsExtension()}, this is YAML, databases/output_types are XML: one
		# generated app, all three FormatDrivers.
		controller:
		  class: Quiote\\Controller\\Controller
		  params: []
		response:
		  class: Quiote\\Response\\WebResponse
		  params: []
		database_manager:
		  class: Quiote\\Database\\DatabaseManager
		  params: []
		routing:
		  class: {$namespace}\\Routing\\AppRouting
		  params: []
		request:
		  class: Quiote\\Request\\WebRequest
		  params: []
		storage:
		  class: Quiote\\Storage\\NullStorage
		  params: []
		user:
		  class: Quiote\\User\\User
		  params: []
		validation_manager:
		  class: Quiote\\Validator\\ValidationManager
		  params: []

		YAML;
	}

	private function databasesXml(): string
	{
		return <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1" xmlns="http://quiote.dev/quiote/config/parts/databases/1.1">
			<ae:configuration>
				<databases default="default">
					<!-- core.use_database is false, so this connection is never opened. DatabaseManager
					     is still a required factory regardless (see Quiote\Config\FactoryConfigHandler). -->
					<database name="default" class="Quiote\Database\PdoDatabase">
						<ae:parameter name="dsn">sqlite::memory:</ae:parameter>
					</database>
				</databases>
			</ae:configuration>
		</ae:configurations>

		XML;
	}

	private function outputTypesXml(): string
	{
		return <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<configurations xmlns="http://quiote.org/quiote/1.0/config">
			<configuration>
				<output_types default="html">
					<output_type name="html">
						<renderers default="php">
							<renderer name="php" class="Quiote\Renderer\PhpRenderer" />
						</renderers>
						<layouts default="default">
							<layout name="default">
								<layer name="content" />
							</layout>
						</layouts>
						<parameter name="http_headers">
							<parameter name="Content-Type">text/html; charset=UTF-8</parameter>
						</parameter>
					</output_type>
				</output_types>
			</configuration>
		</configurations>

		XML;
	}

	private function routingPhp(): string
	{
		$namespace = $this->namespace;
		return <<<PHP
		<?php
		namespace {$namespace}\\Routing;

		use Quiote\\Routing\\AttributeRoutes;
		use Quiote\\Routing\\Routing;
		use Symfony\\Component\\Routing\\Route;
		use Symfony\\Component\\Routing\\RouteCollection;

		/**
		 * Plain PHP routing -- routing.xml has no
		 * working config handler today, so a Routing subclass building the
		 * RouteCollection directly is the supported way to declare routes.
		 *
		 * Index/About/Boom are declared here by hand; Contact is declared via a
		 * #[Route] attribute on ContactAction instead and pulled into this same
		 * RouteCollection by AttributeRoutes::mergeInto() -- the two styles are not
		 * mutually exclusive.
		 */
		final class AppRouting extends Routing
		{
			protected function build(): array
			{
				\$routes = new RouteCollection();
				\$meta = [];

				\$routes->add('index', new Route('/', ['_module' => 'Default', '_action' => 'Index']));
				\$meta['index'] = ['gen_path' => '/', 'path' => '/', 'cut' => false];

				\$routes->add('about', new Route('/about', ['_module' => 'Default', '_action' => 'About']));
				\$meta['about'] = ['gen_path' => '/about', 'path' => '/about', 'cut' => false];

				\$routes->add('boom', new Route('/boom', ['_module' => 'Default', '_action' => 'Boom']));
				\$meta['boom'] = ['gen_path' => '/boom', 'path' => '/boom', 'cut' => false];

				AttributeRoutes::mergeInto(\$routes, \$meta);

				return [\$routes, \$meta];
			}

			#[\\Override]
			public function exportRoutes(): array
			{
				return [\$this->getRouteCollection(), \$this->getMeta()];
			}
		}

		PHP;
	}

	private function actionPhp(string $name, string $defaultView): string
	{
		$namespace = $this->namespace;
		return <<<PHP
		<?php
		namespace {$namespace}\\Modules\\Default\\Actions;

		use Quiote\\Action\\Action;
		use Quiote\\Request\\WebRequest;

		class {$name}Action extends Action
		{
			public function executeRead(WebRequest \$rd): string
			{
				return '{$defaultView}';
			}

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

	private function boomActionPhp(): string
	{
		$namespace = $this->namespace;
		return <<<PHP
		<?php
		namespace {$namespace}\\Modules\\Default\\Actions;

		use Quiote\\Action\\Action;
		use Quiote\\Request\\WebRequest;

		/**
		 * Deliberately throws -- hit GET /boom to see how the framework renders an
		 * unhandled exception. With core.developer_exceptions off (the default),
		 * this should never leak this message or a trace to the client.
		 */
		class BoomAction extends Action
		{
			public function executeRead(WebRequest \$rd): never
			{
				throw new \\RuntimeException('Boom! This is a deliberately triggered error.');
			}

			public function getDefaultViewName(): string
			{
				return 'Success';
			}
		}

		PHP;
	}

	private function contactActionPhp(): string
	{
		$namespace = $this->namespace;
		return <<<PHP
		<?php
		namespace {$namespace}\\Modules\\Default\\Actions;

		use Quiote\\Action\\Action;
		use Quiote\\Request\\WebRequest;
		use Quiote\\Routing\\Attribute\\Route;

		/**
		 * Routed via #[Route] instead of a line in AppRouting.
		 * AppRouting::build() pulls this in with
		 * AttributeRoutes::mergeInto() alongside its hand-written routes.
		 */
		#[Route('/contact', name: 'contact', methods: ['GET'])]
		class ContactAction extends Action
		{
			public function executeRead(WebRequest \$rd): string
			{
				return 'Success';
			}

			public function getDefaultViewName(): string
			{
				return 'Success';
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

	private function viewPhp(string $viewClassPrefix, string $title): string
	{
		$namespace = $this->namespace;
		return <<<PHP
		<?php
		namespace {$namespace}\\Modules\\Default\\Views;

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

			public function executeHtml(WebRequest \$rd): void
			{
				// Populates the layers from output_types.xml's <layouts> so the "content"
				// layer's template actually gets rendered -- without this, executeHtml()
				// returning null falls through to an empty body.
				\$this->loadLayout();
				\$this->setAttribute('title', '{$title}');
			}
		}

		PHP;
	}

	private function templatePhp(string $title, string $body): string
	{
		$namespace = $this->namespace;
		return <<<HTML
		<!DOCTYPE html>
		<html lang="en" data-theme="cupcake">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo htmlspecialchars(\$template['title'] ?? '{$title}', ENT_QUOTES, 'UTF-8'); ?></title>
			<!-- Tailwind + daisyUI via CDN -- no build step, just enough to not look like raw HTML.
			     Swap for a real build pipeline once this app grows past a scaffold. -->
			<script src="https://cdn.tailwindcss.com"></script>
			<link href="https://cdn.jsdelivr.net/npm/daisyui@4/dist/full.min.css" rel="stylesheet" type="text/css" />
		</head>
		<body class="min-h-screen bg-base-200">
			<div class="navbar bg-base-100 shadow-sm px-4">
				<div class="flex-1">
					<a href="/" class="btn btn-ghost text-xl">{$namespace}</a>
				</div>
				<div class="flex-none">
					<ul class="menu menu-horizontal px-1 gap-1">
						<li><a href="/">Home</a></li>
						<li><a href="/about">About</a></li>
						<li><a href="/contact">Contact</a></li>
						<li><a href="/boom" class="text-error">/boom</a></li>
					</ul>
				</div>
			</div>
			<main class="max-w-3xl mx-auto px-4 py-12">
		{$body}
			</main>
		</body>
		</html>

		HTML;
	}

	private function indexBody(): string
	{
		return <<<'HTML'
				<div class="hero bg-base-100 rounded-box shadow-xl">
					<div class="hero-content text-center py-16">
						<div class="max-w-md">
							<h1 class="text-5xl font-bold">It works! 🎉</h1>
							<p class="py-6">This is a freshly scaffolded Quiote application, generated by
								<code class="bg-base-200 px-1 rounded">quiote new</code>.</p>
							<div class="flex justify-center gap-3">
								<a href="/about" class="btn btn-primary">About this app</a>
								<a href="/boom" class="btn btn-error btn-outline">Trigger an error</a>
							</div>
						</div>
					</div>
				</div>
				<div role="alert" class="alert alert-info mt-8">
					<span>Try <code>/boom</code> with <code>core.developer_exceptions</code> on and off in
						<code>Config/settings.*</code> to see the difference between the Whoops developer
						page and the safe generic response.</span>
				</div>
		HTML;
	}

	private function aboutBody(): string
	{
		return <<<'HTML'
				<div class="card bg-base-100 shadow-xl">
					<div class="card-body">
						<h1 class="card-title text-3xl">About</h1>
						<p>This app was generated by <code class="bg-base-200 px-1 rounded">quiote new</code> --
							it demonstrates a Default module with a couple of actions, config split across
							PHP/YAML/XML, and a FrankenPHP-ready front controller.</p>
						<div class="card-actions justify-end">
							<a href="/" class="btn btn-primary btn-sm">Back home</a>
						</div>
					</div>
				</div>
		HTML;
	}

	private function contactBody(): string
	{
		return <<<'HTML'
				<div class="card bg-base-100 shadow-xl">
					<div class="card-body">
						<h1 class="card-title text-3xl">Contact</h1>
						<p>Unlike Home/About/Boom, this route isn't declared in
							<code class="bg-base-200 px-1 rounded">AppRouting</code> -- it comes from a
							<code class="bg-base-200 px-1 rounded">#[Route]</code> attribute on
							<code class="bg-base-200 px-1 rounded">ContactAction</code>, pulled in via
							<code class="bg-base-200 px-1 rounded">AttributeRoutes::mergeInto()</code>. Both
							styles coexist in the same RouteCollection.</p>
						<div class="card-actions justify-end">
							<a href="/" class="btn btn-primary btn-sm">Back home</a>
						</div>
					</div>
				</div>
		HTML;
	}

	private function frontControllerPhp(): string
	{
		$namespace = $this->namespace;
		$activeAutoloadLiteral = $this->needsAbsoluteAutoloadFallback()
			? var_export($this->activeAutoloadPath, true) . ",\n\t\t\t"
			: '';
		return <<<PHP
		<?php

		/**
		 * Front controller / FrankenPHP worker entrypoint.
		 *
		 * Self-contained: registers its own PSR-4-ish autoloader for the app's own
		 * namespace so this app doesn't need its own composer.json, then finds
		 * *some* vendor/autoload.php (walking up from here) to pull in Quiote
		 * itself and its dependencies.
		 */

		spl_autoload_register(static function (string \$class): void {
			\$prefix = '{$namespace}\\\\';
			if (!str_starts_with(\$class, \$prefix)) {
				return;
			}
			\$relative = substr(\$class, strlen(\$prefix));
			\$file = dirname(__DIR__) . '/' . str_replace('\\\\', '/', \$relative) . '.php';
			if (is_file(\$file)) {
				require \$file;
			}
		});

		\$autoloadCandidates = [
			{$activeAutoloadLiteral}
			dirname(__DIR__) . '/vendor/autoload.php',
			dirname(__DIR__, 2) . '/vendor/autoload.php',
			dirname(__DIR__, 3) . '/vendor/autoload.php',
			dirname(__DIR__, 4) . '/vendor/autoload.php',
			dirname(__DIR__, 5) . '/vendor/autoload.php',
		];
		foreach (\$autoloadCandidates as \$candidate) {
			if (is_file(\$candidate)) {
				require \$candidate;
				break;
			}
		}
		if (!class_exists(Quiote\\Runtime\\Kernel::class)) {
			error_log('Could not find a vendor/autoload.php with quioteframework/quiote installed.');
			http_response_code(500);
			exit(1);
		}

		Quiote\\Runtime\\Kernel::create([
			'app_dir' => dirname(__DIR__),
			'env' => getenv('QUIOTE_ENV') ?: 'development',
			'context' => 'web',
		])->run();

		PHP;
	}

	/**
	 * PHPStan bootstrap: same autoload dance as pub/index.php (own-namespace
	 * autoloader + finding the framework's vendor/autoload.php), so `phpstan
	 * analyse` can resolve both the app's own classes and Quiote's.
	 */
	private function phpstanBootstrapPhp(): string
	{
		$namespace = $this->namespace;
		$activeAutoloadLiteral = $this->needsAbsoluteAutoloadFallback()
			? var_export($this->activeAutoloadPath, true) . ",\n\t"
			: '';
		return <<<PHP
		<?php

		spl_autoload_register(static function (string \$class): void {
			\$prefix = '{$namespace}\\\\';
			if (!str_starts_with(\$class, \$prefix)) {
				return;
			}
			\$relative = substr(\$class, strlen(\$prefix));
			\$file = __DIR__ . '/' . str_replace('\\\\', '/', \$relative) . '.php';
			if (is_file(\$file)) {
				require \$file;
			}
		});

		\$autoloadCandidates = [
			{$activeAutoloadLiteral}
			__DIR__ . '/vendor/autoload.php',
			dirname(__DIR__) . '/vendor/autoload.php',
			dirname(__DIR__, 2) . '/vendor/autoload.php',
			dirname(__DIR__, 3) . '/vendor/autoload.php',
			dirname(__DIR__, 4) . '/vendor/autoload.php',
		];
		foreach (\$autoloadCandidates as \$candidate) {
			if (is_file(\$candidate)) {
				require \$candidate;
				break;
			}
		}

		PHP;
	}

	/** Level 9 by default -- the generated app's own code is kept clean at that level. */
	private function phpstanNeon(): string
	{
		return <<<NEON
		parameters:
			level: 9
			bootstrapFiles:
				- phpstan-bootstrap.php
			paths:
				- Modules
				- Routing

		NEON;
	}
}
