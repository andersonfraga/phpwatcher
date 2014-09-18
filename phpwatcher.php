<?php

function phpwatcher($path, $pattern, Closure $func, $checkTo = PhpWatcher::ALL)
{
    if (!empty($path) and is_string($path)) {
        $path = array($path);
    }

    $path  = array_map('realpath', $path);
    $watch = new PhpWatcher($path, $pattern, $checkTo);

    while($file = $watch->hasChanges()) {
        $func($file->getFile(), $file, $watch->listObservableFiles());
    }
}

class PhpWatcher
{
    private $_pattern, $_observer, $_flagsCheck, $_changedFiles = array();

    const UPDATE = 1;
    const CREATE = 2;
    const DELETE = 4;
    const ALL    = 7;

    public function __construct(array $path, $pattern, $flagsCheck)
    {
        $this->_pattern    = $pattern;
        $this->_observer   = new BruteForceWatcher($path, "/{$this->_pattern}/i");
        $this->_flagsCheck = $flagsCheck;
    }

    public function listObservableFiles()
    {
        return $this->_observer->getFiles();
    }

    public function hasChanges()
    {
        if (count($this->_changedFiles)) {
            return self::shiftFile($this->_changedFiles);
        }

        list($paths, $files) = $this->_observer->header();

        echo sprintf("Watching %s files in:\n - ", count($files)) . implode(PHP_EOL . ' - ', $paths) . PHP_EOL . PHP_EOL;

        do {
            $changedFileList     = $this->_observer->checkChanges($this->_flagsCheck);
            $this->_changedFiles = array_merge($this->_changedFiles, $changedFileList);

            // if ($this->_flagsCheck & self::UPDATE) {
            //    $this->_changedFiles = array_merge(
            //        $this->_changedFiles,
            //        array_filter($changedFileList, function ($item) {
            //            return $item->hasUpdated();
            //        })
            //    );
            // }

            // if ($this->_flagsCheck & self::CREATE) {
            //      $this->_changedFiles = array_merge(
            //         $this->_changedFiles,
            //         array_filter($changedFileList, function ($item) {
            //             return $item->hasCreated();
            //         })
            //     );
            // }

            // if ($this->_flagsCheck & self::DELETE) {
            //      $this->_changedFiles = array_merge(
            //         $this->_changedFiles,
            //         array_filter($changedFileList, function ($item) {
            //             return $item->hasDeleted();
            //         })
            //     );
            // }

            if (count($this->_changedFiles)) {
                return self::shiftFile($this->_changedFiles);
            }
        }
        while (1);
    }

    static private function shiftFile(&$list)
    {
        $item = array_shift($list);

        if ($item->hasUpdated()) {
            $act = 'Updated';
        }
        else if ($item->hasCreated()) {
            $act = 'Created';
        }
        else if ($item->hasDeleted()) {
            $act = 'Deleted';
        }

        echo "\n{$act} 1 file" . (($count = count($list)) > 0 ? " (remaining {$count} files)" : "") . ":\n{$item->getFile()}\n";

        return $item;
    }
};

class BruteForceWatcher
{
    private $paths = array(), $currentListWatch = array(), $identifierPaths = array(), $regex, $lastUpdated;

    function __construct(array $path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;
    }

    public function getFiles()
    {
        return array_keys($this->currentListWatch);
    }

    public function header()
    {
        list($this->currentListWatch, $this->identifierPaths) = $this->createList();
        $this->lastUpdated = time();

        return array($this->paths, $this->currentListWatch);
    }

    public function checkChanges($flags)
    {
        $changedFiles = array();

        // if (time() - $this->lastUpdated > 60*5) {
        //     $this->header();
        // }

        list($newWatchFiles, $newIdentifiers) = $this->createList();

        // Não existe na lista atual, somente na nova
        if ($flags & PhpWatcher::CREATE) {
            foreach (array_diff(array_keys($newWatchFiles), array_keys($this->currentListWatch)) as $file) {
                $fileWatcher = new FileChangedWatcher($file);
                $fileWatcher->setPathIdentifier($newIdentifiers[$file] . DIRECTORY_SEPARATOR);
                $fileWatcher->setIsCreated();
                $changedFiles[] = $fileWatcher;
            }
        }

        // Não existe na nova, somente na lista atual
        if ($flags & PhpWatcher::DELETE) {
            foreach (array_diff(array_keys($this->currentListWatch), array_keys($newWatchFiles)) as $file) {
                $fileWatcher = new FileChangedWatcher($file);
                $fileWatcher->setPathIdentifier($this->identifierPaths[$file] . DIRECTORY_SEPARATOR);
                $fileWatcher->setIsDeleted();
                $changedFiles[] = $fileWatcher;
            }
        }

        // Tem diferença de tempo entre os dois arrays
        if ($flags & PhpWatcher::UPDATE) {
            $diffValues = array_udiff_assoc(
                array_intersect_key($this->currentListWatch, $newWatchFiles),
                $newWatchFiles,
                function ($actual, $new) {
                    if ($new == $actual) {
                        return 0;
                    }

                    return $actual > $new ? 1 : -1;
                }
            );

            foreach (array_keys($diffValues) as $file) {
                $fileWatcher = new FileChangedWatcher($file);
                $fileWatcher->setPathIdentifier($this->identifierPaths[$file] . DIRECTORY_SEPARATOR);
                $fileWatcher->setIsUpdated();
                $changedFiles[] = $fileWatcher;
            }
        }

        // foreach (array_merge(array_keys($this->currentListWatch), array_keys($newWatchFiles)) as $file) {
        //     $fileWatcher = new FileChangedWatcher($file);

        //     if (isset($this->identifierPaths[$file])) {
        //         $fileWatcher->setPathIdentifier($this->identifierPaths[$file] . DIRECTORY_SEPARATOR);
        //     }
        //     else if (isset($newIdentifiers[$file])) {
        //         $fileWatcher->setPathIdentifier($newIdentifiers[$file] . DIRECTORY_SEPARATOR);
        //     }

        //     // Não existe na lista atual, somente na nova
        //     if (!isset($this->currentListWatch[$file])) {
        //         $fileWatcher->setIsCreated();
        //         $changedFiles[] = $fileWatcher;
        //     }
        //     // Não existe na nova, somente na lista atual
        //     else if (!isset($newWatchFiles[$file])) {
        //         $fileWatcher->setIsDeleted();
        //         $changedFiles[] = $fileWatcher;
        //     }
        //     // Tem diferença entre uma e outra :)
        //     else if ($this->currentListWatch[$file] != $newWatchFiles[$file]) {
        //         $fileWatcher->setIsUpdated();
        //         $this->currentListWatch[$file] = $newWatchFiles[$file];
        //         $changedFiles[] = $fileWatcher;
        //     }
        // }

        if ($changedFiles) {
            $this->lastUpdated      = time();
            $this->identifierPaths  = $newIdentifiers;
            $this->currentListWatch = $newWatchFiles;
        }

        return $changedFiles;
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

        //arsort($watch, SORT_NUMERIC);
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