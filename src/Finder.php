<?php

namespace Scraper;

class Finder
{
    private $inputDir;
    private $outputDir;
    private $namespace;

    public function __construct($inputDir, $outputDir, $namespace)
    {
        $this->inputDir = $inputDir;
        $this->outputDir = $outputDir;
        $this->namespace = $namespace;
    }

    public function execute()
    {
        $iterator = new \DirectoryIterator($this->inputDir);
        $interpreter = new Interpreter();

        $operations = [];

        foreach ($iterator as $path) {
            if ($path->getExtension() === 'rst') {
                $operations[] = $interpreter->parse(file_get_contents($path->getRealPath()));
            }
        }

        $operations = array_values(array_filter($operations));

        usort ($operations, function ($a, $b) {
            return strlen($a['path']) - strlen($b['path']);
        });

        $writer = new Writer($this->outputDir, $this->namespace, $operations);
        $writer->write();
    }
}