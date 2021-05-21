<?php

declare(strict_types=1);

namespace Soap\WsdlReader\Reader;

use Soap\WsdlReader\Metadata\Collection\MethodCollection;
use Soap\WsdlReader\Metadata\Collection\ParameterCollection;
use Soap\WsdlReader\Metadata\Model\Method;
use Soap\WsdlReader\Metadata\Model\Parameter;
use Soap\WsdlReader\Metadata\Model\XsdType;
use Soap\WsdlReader\Parser\QnameParser;
use VeeWee\Xml\Dom\Document;

/**
 * TODO: in current implementation we assume everything exists; Remove naivity by adding error handling.
 */
class MethodsReader
{
    private ServiceReader $serviceReader;

    public function __construct(ServiceReader $serviceDetector)
    {
        $this->serviceReader = $serviceDetector;
    }

    public function read(Document $wsdl): MethodCollection
    {
        $service = $this->serviceReader->read($wsdl);

        return new MethodCollection(...array_values(array_map(
            fn (array $operation) => $this->parseMethod($service, $operation['name']),
            $service['binding']['operations']
        )));
    }

    private function parseMethod(array $service, string $operationName): Method
    {
        $portInfo = $service['port']['operations'][$operationName] ?? [];
        $inputInfo = $portInfo['input'];
        $outputInfo = $portInfo['output'];

        $filterMessageName = fn (string $namespaced): string => (new QnameParser())($namespaced)[1];
        $inputMessage = $filterMessageName($inputInfo['message']);
        $outputMessage = $filterMessageName($outputInfo['message']);

        $messages = [
            $inputMessage => $service['messages'][$inputMessage] ?? [],
            $outputMessage => $service['messages'][$outputMessage] ?? [],
        ];

        return new Method(
            $operationName,
            new ParameterCollection(...$this->parseXsdTypesFromMessage($service, $messages[$inputMessage])),
            current($this->parseXsdTypesFromMessage($service, $messages[$outputMessage]))->getType()
        );
    }

    /**
     * @return array|Parameter[]
     */
    private function parseXsdTypesFromMessage(array $service, array $message): array
    {
        $lookupNsUri = fn (string $prefix): string => $service['namespaceMap'][$prefix] ?? '';

        return array_values(array_map(
            static function (array $param) use ($lookupNsUri): Parameter {
                [$elementNamespaceAlias, $elementName] = (new QnameParser())($param['element']);

                return new Parameter(
                    $elementName,
                    XsdType::guess($elementName)
                        ->withXmlNamespaceName($elementNamespaceAlias)
                        ->withXmlNamespace($lookupNsUri($elementNamespaceAlias))
                );
            },
            $message['parts']
        ));
    }
}
