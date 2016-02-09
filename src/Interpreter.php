<?php

namespace Scraper;

use Gregwar\RST\HTML\Nodes\TableNode;
use Gregwar\RST\Parser;

class Interpreter
{
    private function isAssoc($val)
    {
        return is_array($val) && array_keys($val) !== range(0, count($val) - 1);
    }

    private function toCamelCase($str)
    {
        return lcfirst(str_replace('_', '', ucwords($str, '_')));
    }

    private function parseParam($paramName, $paramVal, array &$params)
    {
        $modifiedName = $this->toCamelCase($paramName);

        $arr = [
            'type'     => gettype($paramVal),
            'location' => 'json',
        ];

        if ($modifiedName !== $paramName) {
            $arr['sentAs'] = $paramName;
        }

        if ($arr['type'] == 'array' && isset($paramVal[0])) {
            $arr['itemSchema'] = $this->parseParam($paramName, $paramVal[0], $params);
        } elseif ($arr['type'] == 'object') {
            $props = [];
            foreach ($paramVal as $propN => $propV) {
                $mPropN = $this->toCamelCase($propN);
                $parr = $this->parseParam($propN, $propV, $params);
                $params[$mPropN] = $parr;
                $props = array_merge($props, [$mPropN => '$this->'.$mPropN.ucfirst($parr['location']).'()']);
            }
            $arr['properties'] = $props;
        }

        return $arr;
    }

    private function getNested($data, $key)
    {
        if (!$key) {
            return $data;
        }

        $value = is_object($data) ? $data->{$key} : $data[$key];

        return (is_object($value) || is_array($value)) ? $value : $data;
    }

    private function getKey($data)
    {
        if (is_object($data)) {
            $vars = array_keys(get_object_vars($data));
            return (count($vars) == 1) ? $vars[0] : null;
        } else {
            return (count($data) == 1) ? key($data) : null;
        }
    }

    public function parse($fileContent)
    {
        $jsonBlockPattern = '#request\*\*(?:\n+)\.\. code::(?:[\n|\s]*)({[^\*]*)#';

        $matches = [];
        preg_match_all($jsonBlockPattern, $fileContent, $matches);

        $jsonKey = '';
        $params = [];

        if (!empty($matches[0])) {
            foreach ($matches[0] as $match) {
                $decoded = json_decode(trim(str_replace(['.. code::', 'request**'], '', $match)));

                if (!is_object($decoded) && !is_array($decoded)) {
                    continue;
                }

                $jsonKey = $this->getKey($decoded);
                $nested = $this->getNested($decoded, $jsonKey);

                if (is_object($nested)) {
                    foreach ($nested as $paramName => $paramVal) {
                        $modifiedName = $this->toCamelCase($paramName);
                        $params[$modifiedName] = $this->parseParam($paramName, $paramVal, $params);
                    }
                } elseif (is_array($nested)) {
                    $params[$jsonKey] = $this->parseParam('', $nested[0], $params);
                }
            }

            $rstString = $fileContent;

            // strip code blocks
            $dirPatterns = ['#\.\. code::#', '#\.\. table::#'];
            $rstString = preg_replace($dirPatterns, '', $rstString);

            // strip refs
            $refPattern = '#:ref:`([\w\s<>-]+)`#';
            $rstString = preg_replace($refPattern, '$1' . str_repeat(' ', 7), $rstString);

            // align indent
            $rstString = str_replace("\n    +", "\n+", str_replace("\n    |", "\n|", $rstString));

            $d = (new Parser())->parse($rstString);

            foreach ($d->getNodes() as $node) {
                if ($node instanceof TableNode) {
                    foreach ($this->getProtectedProperty($node, 'data') as $row) {
                        if (isset($row[2])) {
                            $name = str_replace(['{','}'], '', trim($this->getProtectedProperty($row[0], 'span')));
                            if (preg_match('#(?:[\d-=])#', $name)) {
                                continue;
                            }

                            $type = $this->getProtectedProperty($row[1], 'span');
                            $required = stripos($type, 'required') !== false;
                            $desc = $this->getProtectedProperty($row[2], 'span');

                            $modifiedName = trim($this->toCamelCase($name));

                            if (isset($params[$modifiedName])) {
                                $params[$modifiedName]['required'] = $required;
                                $params[$modifiedName]['description'] = $desc;
                            } else {
                                $arr = [
                                    'type'        => $type,
                                    'required'    => $required,
                                    'location'    => 'json',
                                    'description' => $desc,
                                ];

                                if ($modifiedName != $name) {
                                    $arr['sentAs'] = $name;
                                }

                                $params[$modifiedName] = $arr;
                            }
                        }
                    }
                }
            }
        }

        $pathMethodPattern = '#\.\. code::(?:[\n|\s]*)([a-zA-Z 0-9\.\/{}]+)\n\n#';

        $matches = [];

        preg_match_all($pathMethodPattern, $fileContent, $matches);

        if (empty($matches[1])) {
            return;
        }

        list ($method, $path) = explode(' ', trim(str_replace('.. code::', '', $matches[1][0])));

        $matches = $urlParams = [];
        preg_match_all('#{(\w+)}#', $path, $matches);
        if (!empty($matches[0])) {
            foreach ($matches[0] as $match) {
                $sentAs = str_replace(['{', '}'], '', $match);
                $name   = $this->toCamelCase($sentAs);

                $arr = [
                    'type'     => 'string',
                    'location' => 'url',
                ];

                if ($sentAs != $name) {
                    $arr['sentAs'] = $sentAs;
                }

                $urlParams[$name] = $arr;

                if (isset($params[$name])) {
                    unset($params[$name]);
                }
            }
        }

        ksort($params);

        $params = array_merge($urlParams, $params);

        $arr = [
            'method'  => $method,
            'path'    => $path,
        ];

        if ($jsonKey) {
            $arr['jsonKey'] = $jsonKey;
        }

        $arr['params'] = $params;

        return $arr;
    }

    private function getProtectedProperty($object, $propertyName)
    {
        $p = (new \ReflectionObject($object))->getProperty($propertyName);
        $p->setAccessible(true);
        return $p->getValue($object);
    }
}