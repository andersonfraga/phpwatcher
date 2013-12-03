<?php

function phpwatcher(array $path, $pattern, \Closure $func)
{
    $watch = new Phpwatcher($path, $pattern);
    $numFiles = 0;

    while($file = $watch->hasUpdate()) {
        $func($file->getPathname(), $file);
    }
}

class Phpwatcher
{
    private $_pattern;
    private $_observer;

    public function __construct(array $path, $pattern)
    {
        $this->_pattern = $pattern;
        $this->_observer = new BruteForceWatcher($path, "/{$this->_pattern}/is");

    }

    public function hasUpdate()
    {
        if ($file = $this->_observer->watch()) {
            return $file;
        }
    }
};

class BruteForceWatcher
{
    private $paths = array(), $watch = array(), $identifier = array(), $regex;

    function __construct(array $path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;
    }

    public function watch()
    {
        $this->createList();

        echo 'Watching ' . count($this->watch) . ' files in: '
            . PHP_EOL . ' - ' . implode("\n - ", $this->paths) . PHP_EOL;

        arsort($this->watch);

        do {
            foreach ($this->watch as $_file => $mtime) {
                if (filemtime($_file) != $mtime) {
                    $this->watch[$_file] = filemtime($_file);

                    $file = new FileChangedWatcher($_file);
                    $file->setIsModified();
                    $file->setPathIdentifier($this->identifier[$_file] . DIRECTORY_SEPARATOR);

                    return $file;
                }
            }
        }
        while(sleep(1) == 0);
    }


    private function createList()
    {
        $this->watch = $this->identifier = array();

        foreach ($this->paths as $value) {
            $listPaths = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($value, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($listPaths as $_path) {
                if ($_path->isFile()) {
                    $file = $_path->getPathname();

                    if (preg_match($this->regex, $file)) {
                        $this->watch[$file]      = filemtime($file);
                        $this->identifier[$file] = $value;
                    }
                }
            }
        }
    }
};

class FileChangedWatcher extends SplFileInfo
{
    private $_isCreated = false, $_isUpdated = false, $_isDeleted = false, $_identifier;

    public function __call($method, array $value)
    {
        if (preg_match('/setIs([A-Z])([A-Z]+)/', $method, $match)) {
            $var = "_is{$match[1]}{$match[1]}";
            return $this->$var = true;
        }

        if (preg_match('/get([A-Z])([A-Z]+)/', $method, $match)) {
            $var = "_is{$match[1]}{$match[1]}";
            return $this->$var;
        }
    }

    public function setPathIdentifier($identifier)
    {
        $this->_identifier = $identifier;
    }

    public function getPathIdentifier()
    {
        return $this->_identifier;
    }
};
