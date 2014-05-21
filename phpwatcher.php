<?php

function phpwatcher(array $path, $pattern, \Closure $func)
{
    $watch = new Phpwatcher($path, $pattern);
    $numFiles = 0;

    while($file = $watch->hasUpdate()) {
        $func($file->getPathname(), $file, $watch->listObservablesFiles());
    }
}

class Phpwatcher
{
    private $_pattern;
    private $_observer;

    public function __construct(array $path, $pattern)
    {
        $this->_pattern  = $pattern;
        $this->_observer = new BruteForceWatcher($path, "/{$this->_pattern}/is");
    }

    public function hasUpdate()
    {
        list($paths, $files) = $this->_observer->header();

        echo self::createMsg('Watching %s files in', count($files), $paths);

        do {
            if ($added = $this->_observer->addedFiles()) {
                echo self::createMsg('Added %s new file' . (count($added) > 1 ? 's' : ''), count($added), $added);
            }

            if ($deleted = $this->_observer->deletedFiles()) {
                echo self::createMsg('Deleted %s file' . (count($deleted) > 1 ? 's' : ''), count($deleted), $deleted);
            }

            if ($files = $this->_observer->updatedFiles()) {
                return array_shift($files);
            }
        }
        while (1);
    }

    static private function createMsg($msg, $counter, $elements)
    {
        $suffix = PHP_EOL . ' - ';
        return sprintf("{$msg}:{$suffix}%s\n", $counter, implode($suffix , $elements));
    }

    public function listObservablesFiles()
    {
        return $this->_observer->getFiles();
    }
};

class BruteForceWatcher
{
    private $paths = array(), $watch = array(), $identifier = array(), $regex, $lastUpdated;

    function __construct(array $path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;
    }

    public function getFiles()
    {
        return array_keys($this->watch);
    }

    public function header()
    {
        list($this->watch, $this->identifier) = $this->createList();
        arsort($this->watch, SORT_NUMERIC);
        $this->lastUpdated = time();

        return array($this->paths, $this->watch);
    }

    private function checkChanges()
    {
        $actualWatch = $this->watch;

        if (time() - $this->lastUpdated > 10) {
            $this->lastUpdated = time();
            list($this->watch, $this->identifier) = $this->createList();
        }

        $actualWatch = array_keys($actualWatch);
        $newWatch    = array_keys($this->watch);

        return array($actualWatch, $newWatch);
    }

    public function addedFiles()
    {
        list($actualWatch, $newWatch) = $this->checkChanges();
        return array_diff($newWatch, $actualWatch);
    }

    public function deletedFiles()
    {
        list($actualWatch, $newWatch) = $this->checkChanges();
        return array_diff($actualWatch, $newWatch);
    }

    public function updatedFiles()
    {
        $updated = array();

        foreach ($this->watch as $_file => $mtime) {
            $newmtime = (is_file($_file)) ? filemtime($_file) : null;

            if ($newmtime and $newmtime != $mtime) {
                $this->watch[$_file] = $newmtime;

                $file = new FileChangedWatcher($_file);
                $file->setIsModified();
                $file->setPathIdentifier($this->identifier[$_file] . DIRECTORY_SEPARATOR);

                $updated[] = $file;
            }
        }

        return $updated;
    }


    private function createList()
    {
        $watch = $identifier = array();

        foreach ($this->paths as $value) {
            $listPaths = new RegexIterator(
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($value),
                    RecursiveIteratorIterator::SELF_FIRST
                ),
                $this->regex
            );

            foreach ($listPaths as $file => $_path) {
                $watch[$file]      = $_path->getMTime();
                $identifier[$file] = $value;
            }
        }

        return array($watch, $identifier);
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
