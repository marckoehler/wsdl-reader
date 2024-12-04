<?php
declare(strict_types=1);

namespace Soap\WsdlReader\Metadata\Converter\Types\Visitor;

use GoetasWebservices\XML\XSDReader\Schema\Element\Choice;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementContainer;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementItem;
use GoetasWebservices\XML\XSDReader\Schema\Element\ElementSingle;
use GoetasWebservices\XML\XSDReader\Schema\Element\Group;
use GoetasWebservices\XML\XSDReader\Schema\Element\Sequence;
use GoetasWebservices\XML\XSDReader\Schema\Type\ComplexType;
use Soap\Engine\Metadata\Collection\PropertyCollection;
use Soap\Engine\Metadata\Model\Property;
use Soap\Engine\Metadata\Model\XsdType as EngineType;
use Soap\WsdlReader\Metadata\Converter\Types\Configurator;
use Soap\WsdlReader\Metadata\Converter\Types\TypesConverterContext;
use function Psl\Fun\pipe;
use function Psl\Vec\flat_map;

final class ElementContainerVisitor
{
    public function __invoke(ElementContainer $container, TypesConverterContext $context): PropertyCollection
    {
        return new PropertyCollection(
            ...flat_map(
                $container->getElements(),
                fn (ElementItem $element): PropertyCollection => $this->parseElementItem($element, $context, $container)
            )
        );
    }

    private function parseElementItem(ElementItem $element, TypesConverterContext $context, ComplexType $parent): PropertyCollection
    {
        if ($element instanceof Group || $element instanceof Choice || $element instanceof Sequence) {
            return new PropertyCollection(
                ...flat_map(
                    $element->getElements(),
                    fn (ElementItem $child): PropertyCollection => $this->parseElementItem($child, $context, $parent)
                )
            );
        }

        $typeName = $this->parseTypeName($element, $parent);

        $configure = pipe(
            static fn (EngineType $engineType): EngineType => (new Configurator\ElementConfigurator())($engineType, $element, $context),
        );

        return new PropertyCollection(
            new Property(
                $element->getName(),
                $configure(EngineType::guess($typeName))
            )
        );
    }

    private function parseTypeName(ElementItem $element, ComplexType $parent): string
    {
        // If ElementItem is not ElementSingle (e.g. Any), we guess the type from the element name later on.
        if (!$element instanceof ElementSingle) {
            return $element->getName();
        }

        // If ElementSingle is local, the type will be the element name prefixed with the parent name
        // to avoid name conflicts with other (inline) elements of same name.
        if ($element->isLocal()) {
            return $parent->getName() . (ucfirst($element->getName()));
        }

        // If ElementSingle is global, the type will be its type name.
        $typeName = $element->getType()->getName();

        // However, if this type name is null, we have a global element with an anonymous complex type.
        // The element name will be the type name instead.
        if ($typeName === null) {
            $typeName = $element->getName();
        }

        return $typeName;
    }
}
