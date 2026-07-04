<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Validator\Compiler\RuntimeArrayEmitter;
use Quiote\Validator\Compiler\ValidatorPlanBuilder;

/**
 * Compiles a validators.xml document into a compiled Quiote configuration
 * file (plain PHP that instantiates validators and addChild()s them onto
 * $validationManager).
 *
 * The XML interpretation itself lives in ValidatorPlanBuilder, which builds
 * a format-independent ValidatorPlan (see Quiote\Validator\Compiler\Ir).
 * This handler is now a thin adapter: parse to IR, emit the runtime array
 * snippets from that IR via RuntimeArrayEmitter, wrap in the standard
 * compiled-file header. The same
 * ValidatorPlan also feeds a fluent-source emitter for hand-committable,
 * opcacheable validator files, and any future non-XML config front-end
 * builds the same IR without touching this class or the emitter.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidatorConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/validators/1.1';

	public function execute(XmlConfigDomDocument $document): string
	{
		$builder = new ValidatorPlanBuilder();
		$plan = $builder->build($document, self::XML_NAMESPACE);

		$emitter = new RuntimeArrayEmitter();
		$final = $emitter->emit($plan);

		return $this->generate($final, $plan->sourceRef);
	}
}

// Backwards compatibility: global class name
if (!\class_exists('ValidatorConfigHandler', false)) {
	\class_alias(__NAMESPACE__ . '\\ValidatorConfigHandler', 'ValidatorConfigHandler');
}
?>
