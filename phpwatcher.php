<?php

function phpwatcher($path, $pattern, Closure $func)
{
    if (!empty($path) and is_string($path)) {
        $path = array($path);
    }

    foreach ($path as $_ => $value) {
        $path[$_] = realpath($value);
    }

    $watch = new Phpwatcher($path, $pattern);
    $numFiles = 0;

    while($file = $watch->hasUpdate()) {
        $func($file->getFile(), $file, $watch->listObservableFiles());
    }
}

class Phpwatcher
{
    private $_pattern, $_observer;

    public function __construct(array $path, $pattern)
    {
        $this->_pattern  = $pattern;
        $this->_observer = new BruteForceWatcher($path, "/{$this->_pattern}/i");
    }

    public function hasUpdate()
    {
        list($paths, $files) = $this->_observer->header();

        echo self::createMsg('Watching %s files in', count($files), $paths);

        do {
            if ($updated = $this->_observer->updatedFiles()) {
                echo self::createMsg('Updated %s file' . (count($updated) > 1 ? 's' : ''), count($updated), $updated);
                return array_shift($updated);
            }

            if ($added = $this->_observer->addedFiles()) {
                echo self::createMsg('Added %s new file' . (count($added) > 1 ? 's' : ''), count($added), $added);
                return array_shift($added);
            }

            if ($deleted = $this->_observer->deletedFiles()) {
                echo self::createMsg('Deleted %s file' . (count($deleted) > 1 ? 's' : ''), count($deleted), $deleted);
                return array_shift($deleted);
            }
        }
        while (1);
    }

    static private function createMsg($msg, $counter, $elements)
    {
        $suffix = PHP_EOL . ' - ';
        return sprintf("{$msg}:{$suffix}%s\n", $counter, implode($suffix , $elements));
    }

    public function listObservableFiles()
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
        $this->lastUpdated = time();

        return array($this->paths, $this->watch);
    }

    private function checkChanges()
    {
        $actualWatch = $this->watch;

        $this->lastUpdated = time();
        list($newWatch, $this->identifier) = $this->createList();

        $actualWatch = array_keys($actualWatch);
        $newWatch    = array_keys($newWatch);

        return array($actualWatch, $newWatch);
    }

    public function addedFiles()
    {
        list($actualWatch, $newWatch) = $this->checkChanges();

        $updated = array();

        foreach (array_diff($newWatch, $actualWatch) as $_file) {
            $file = new FileChangedWatcher($_file);
            $file->setIsCreated();
            $file->setPathIdentifier($this->identifier[$_file] . DIRECTORY_SEPARATOR);
            $updated[] = $file;
        }

        return $updated;
    }

    public function deletedFiles()
    {
        $updated = array();

        foreach ($this->watch as $_file => $mtime) {
            if (!is_file($_file)) {
                $file = new FileChangedWatcher($_file);
                $file->setIsDeleted();
                $file->setPathIdentifier($this->identifier[$_file] . DIRECTORY_SEPARATOR);
                $updated[] = $file;
            }
        }

        return $updated;
    }

    public function updatedFiles()
    {
        $updated = array();

        foreach ($this->watch as $_file => $mtime) {
            $newmtime = (is_file($_file)) ? filemtime($_file) : null;

            if ($newmtime and $newmtime != $mtime) {
                $this->watch[$_file] = $newmtime;

                $file = new FileChangedWatcher($_file);
                $file->setIsUpdated();
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

        arsort($watch, SORT_NUMERIC);
        return array($watch, $identifier);
    }
};

class FileChangedWatcher
{
    private $_file, $_isCreated = false, $_isUpdated = false, $_isDeleted = false, $_identifier;

    public function __construct($file)
    {
        $this->_file = $file;
    }

    public function getFile()
    {
        return $this->_file;
    }

    public function __toString()
    {
        return $this->getFile();
    }

    public function setIsDeleted()
    {
        $this->_isDeleted = true;
        $this->_isCreated = false;
        $this->_isUpdated = false;
    }

    public function setIsCreated()
    {
        $this->_isDeleted = false;
        $this->_isCreated = true;
        $this->_isUpdated = false;
    }

    public function setIsUpdated()
    {
        $this->_isDeleted = false;
        $this->_isCreated = false;
        $this->_isUpdated = true;
    }

    public function hasDeleted()
    {
        return $this->_isDeleted;
    }

    public function hasCreated()
    {
        return $this->_isCreated;
    }

    public function hasUpdated()
    {
        return $this->_isUpdated;
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
