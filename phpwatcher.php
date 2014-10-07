<?php

function phpwatcher($path, $pattern, Closure $func, $checkTo = PhpWatcher::ALL)
{
    if (!empty($path) and is_string($path)) {
        $path = [$path];
    }

    $path  = array_map('realpath', $path);
    $watch = new PhpWatcher($path, $pattern, $checkTo);

    while($file = $watch->hasChanges()) {
        $func($file->getFile(), $file, $watch->listObservableFiles());
    }
}

if (!class_exists('Thread')) {
    class Thread {}
}

if (!class_exists('Stackable')) {
    class Stackable {}
}

class PhpWatcher
{
    private $_pattern, $_observer, $_flagsCheck, $_changedFiles = [];

    const UPDATE = 1;
    const CREATE = 2;
    const DELETE = 4;
    const ALL    = 7;

    public function __construct(array $path, $pattern, $flagsCheck)
    {
        $this->_pattern    = $pattern;
        $this->_observer   = new ChangeableCheckFiles($path, "/{$this->_pattern}/i");
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

class ChangeableCheckFiles
{
    private $paths = [], $currentListWatch = [], $identifierPaths = [], $regex, $lastUpdated, $worker;

    function __construct(array $path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;

        if (class_exists('Thread') and false) {
            $this->worker = new WorkerFilesThreaded($this->paths, $this->regex);
        }
        else {
            $this->worker = new WorkerFilesForce($this->paths, $this->regex);
        }
    }

    public function getFiles()
    {
        return array_keys($this->currentListWatch);
    }

    public function header()
    {
        list($this->currentListWatch, $this->identifierPaths) = $this->createList();
        $this->lastUpdated = time();

        return [$this->paths, $this->currentListWatch];
    }

    public function checkChanges($flags)
    {
        list($newWatchFiles, $newIdentifiers) = $this->createList();
        $changedFiles = [];

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

        if ($changedFiles) {
            $this->lastUpdated      = time();
            $this->identifierPaths  = $newIdentifiers;
            $this->currentListWatch = $newWatchFiles;
        }

        return $changedFiles;
    }

    private function createList()
    {
        $storage = $this->worker->get();
        //arsort($watch, SORT_NUMERIC);
        return [$storage->getList(), $storage->getIdentifiers()];
    }
};

class WorkerFilesForce
{
    private $paths, $regex;

    function __construct($path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;
    }

    public function get()
    {
        $storage = new StorageFilesForced();

        foreach ($this->paths as $path) {
            $item = new GlobFilesForced($path, $this->regex, $storage);
            $item->run();
        }

        return $storage;
    }
};

class WorkerFilesThreaded
{
    private $paths, $regex;

    function __construct($path, $regex)
    {
        $this->paths = $path;
        $this->regex = $regex;
    }

    public function get()
    {
        $glob    = [];
        $storage = new StorageFilesThreaded();

        foreach ($this->paths as $path) {
            $glob[$path] = new GlobFilesThreaded($path, $this->regex, $storage);
        }

        foreach ($glob as $item) {
            $item->start();
        }

        while ($item = array_shift($glob)) {
            if ($item->isRunning()) {
                array_push($glob, $item);
            }
        }

        return $storage;
    }
};

class GlobFilesForced
{
    use GlobFilesTrait;
};

class StorageFilesForced
{
    use StorageFilesTrait;
};

class GlobFilesThreaded extends Thread
{
    use GlobFilesTrait;
};

class StorageFilesThreaded extends Stackable
{
    use StorageFilesTrait;

    public function run() {}
};

trait StorageFilesTrait
{
    private $listFiles = [], $listIdentifiers = [];

    public function getList()
    {
        return $this->listFiles;
    }

    public function getIdentifiers()
    {
        return $this->listIdentifiers;
    }

    public function addInList($list)
    {
        $this->listFiles = array_merge($this->listFiles, $list);
    }

    public function addInIdentifiers($list)
    {
        $this->listIdentifiers = array_merge($this->listIdentifiers, $list);
    }
};

trait GlobFilesTrait
{
    private $path, $regex, $storage;

    function __construct($path, $regex, $storage)
    {
        $this->path    = $path;
        $this->regex   = $regex;
        $this->storage = $storage;
    }

    public function run()
    {
        $listPaths = new RegexIterator(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->path),
                RecursiveIteratorIterator::SELF_FIRST
            ),
            $this->regex
        );

        $watching = $identifiers = [];

        foreach ($listPaths as $file => $_path) {
            $watching[$file]    = $_path->getMTime();
            $identifiers[$file] = $this->path;
        }

        $this->storage->addInList($watching);
        $this->storage->addInIdentifiers($identifiers);
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