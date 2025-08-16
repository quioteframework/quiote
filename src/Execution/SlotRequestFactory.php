<?php
namespace Agavi\Execution;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Factory to derive a child PSR-7 request for slot (sub-action) execution.
 * Ensures SlotStack presence and attaches standardized slot metadata attributes.
 */
class SlotRequestFactory
{
    public const ATTR_SLOT_MODULE     = 'agavi.slot.module';
    public const ATTR_SLOT_ACTION     = 'agavi.slot.action';
    public const ATTR_SLOT_PARAMETERS = 'agavi.slot.parameters';
    public const ATTR_SLOT_OUTPUTTYPE = 'agavi.slot.output_type';

    /**
     * Create a child request containing slot metadata.
     * The SlotStack attribute (SlotStack::class) is preserved or created if missing.
     */
    public static function create(ServerRequestInterface $parent, string $module, string $action, array $parameters = [], ?string $outputType = null): ServerRequestInterface
    {
        $child = $parent;
        if(!$child->getAttribute(SlotStack::class)) {
            $child = $child->withAttribute(SlotStack::class, new SlotStack());
        }
        $child = $child
            ->withAttribute(self::ATTR_SLOT_MODULE, $module)
            ->withAttribute(self::ATTR_SLOT_ACTION, $action)
            ->withAttribute(self::ATTR_SLOT_PARAMETERS, $parameters);
        if($outputType !== null) {
            $child = $child->withAttribute(self::ATTR_SLOT_OUTPUTTYPE, $outputType);
        }
        return $child;
    }
}

?>
