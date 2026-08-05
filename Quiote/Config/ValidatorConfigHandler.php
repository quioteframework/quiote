<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Validator\Compiler\RuntimeDeclarationEmitter;
use Quiote\Validator\Compiler\ValidatorPlanBuilder;

/**
 * Compiles a validators.xml document into a compiled Quiote configuration file: a declaration of the
 * validators to build, which
 * {@see \Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier} registers onto a
 * validation manager. The artifact is data and cannot register anything itself.
 *
 * The XML interpretation lives in ValidatorPlanBuilder, which builds a format-independent
 * ValidatorPlan (see Quiote\Validator\Compiler\Ir). This handler is a thin adapter: parse to IR,
 * emit the declaration from that IR via RuntimeDeclarationEmitter, wrap in the standard compiled-file
 * header. The same ValidatorPlan also feeds a fluent-source emitter for hand-committable,
 * opcacheable validator files, and a non-XML config front-end builds the same IR without touching
 * this class or the emitter.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidatorConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/validators/1.1';

	public function execute(XmlConfigDomDocument $document): mixed
	{
		$builder = new ValidatorPlanBuilder();
		$plan = $builder->build($document, self::XML_NAMESPACE);

		$emitter = new RuntimeDeclarationEmitter();

		return $emitter->emit($plan);
	}
}

// Backwards compatibility: global class name
if (!\class_exists('ValidatorConfigHandler', false)) {
	\class_alias(__NAMESPACE__ . '\\ValidatorConfigHandler', 'ValidatorConfigHandler');
}
?>
