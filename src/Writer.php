<?php

namespace Scraper;

use Riimu\Kit\PHPEncoder\PHPEncoder;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Reflection\ClassReflection;

class Writer
{
    private $path;
    private $namespace;
    private $arrayEncoder;
    private $operations = [];

    public function __construct($path, $namespace, array $operations)
    {
        $this->path = $path;
        $this->namespace = $namespace;
        $this->operations = $operations;

        $this->arrayEncoder = new PHPEncoder();
    }

    public function write()
    {
        $this->writeApiFile();
        $this->writeParamFile();
    }

    private function writeApiFile()
    {
        $file = $this->path . DIRECTORY_SEPARATOR . 'Api.php';

        if (file_exists($file) && class_exists($this->namespace . '\\Api')) {
            $class = new ClassReflection($this->namespace . '\\Api');
            $apiClass = ClassGenerator::fromReflection($class);
        } else {
            $apiClass = new ClassGenerator('Api', $this->namespace, null, 'OpenStack\\Common\\Api\\AbstractApi');
        }

        if (!$apiClass->hasMethod('__construct')) {
            $apiClass->addProperty('params', null, PropertyGenerator::FLAG_PRIVATE);
            $apiClass->addMethod('__construct', [], MethodGenerator::FLAG_PUBLIC, '$this->params = new Params;');
        }

        foreach ($this->operations as $operation) {
            $path = str_replace(['{', '}'], '', $operation['path']);
            $name = strtolower($operation['method']) . ucfirst(substr($path, strrpos($path, DIRECTORY_SEPARATOR) + 1));

            if ($apiClass->hasMethod($name)) {
                continue;
            }

            foreach ($operation['params'] as $kName => $arr) {
                $operation['params'][$kName] = '$this->params->' . $kName . ucfirst($arr['location']) . '()';
            }

            $body = sprintf("return %s;", $this->arrayEncoder->encode($operation, ['array.align' => true]));
            $body = str_replace("'$", '$', $body);
            $body = str_replace("()'", '()', $body);

            $docblock = new DocBlockGenerator(
                sprintf("Returns information about %s %s HTTP operation", $operation['method'], $operation['path']),
                null,
                [new ReturnTag(['array'])]
            );

            $apiClass->addMethod($name, [], MethodGenerator::FLAG_PUBLIC, $body, $docblock);
        }

        $output = sprintf("<?php\n\n%s", $apiClass->generate());

        file_put_contents($file, $output);
    }

    private function writeParamFile()
    {
        $file = $this->path . DIRECTORY_SEPARATOR . 'Params.php';

        if (file_exists($file) && class_exists($this->namespace . '\\Params')) {
            $class = new ClassReflection($this->namespace . '\\Params');
            $paramsClass = ClassGenerator::fromReflection($class);
        } else {
            $paramsClass = new ClassGenerator('Params', $this->namespace, null, 'OpenStack\\Common\\Api\\AbstractParams');
        }

        foreach ($this->operations as $operation) {
            $params = $operation['params'];

            if (empty($params)) {
                continue;
            }

            foreach ($params as $paramName => $paramVal) {
                $name = $paramName . ucfirst($paramVal['location']);

                if ($paramsClass->hasMethod($name)) {
                    continue;
                }

                $body = sprintf("return %s;", $this->arrayEncoder->encode($paramVal, ['array.align' => true]));
                $body = str_replace("'$", '$', $body);
                $body = str_replace("()'", '()', $body);

                $docblock = new DocBlockGenerator(
                    sprintf("Returns information about %s parameter", $paramName),
                    null,
                    [new ReturnTag(['array'])]
                );

                $paramsClass->addMethod($name, [], MethodGenerator::FLAG_PUBLIC, $body, $docblock);
            }
        }

        $output = sprintf("<?php\n\n%s", $paramsClass->generate());

        file_put_contents($file, $output);
    }
}