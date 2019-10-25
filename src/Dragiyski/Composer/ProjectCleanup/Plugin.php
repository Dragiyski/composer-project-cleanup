<?php

namespace Dragiyski\Composer\ProjectCleanup;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory as ComposerFactory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class Plugin implements PluginInterface, EventSubscriberInterface {

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    protected $classHandler = array();

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents() {
        return array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array('onNewCodeEvent', 1000)
            ),
            ScriptEvents::POST_UPDATE_CMD => array(
                array('onNewCodeEvent', 1000)
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io) {
        $this->io = $io;
        $this->composer = $composer;
        $this->filesystem = new Filesystem();
    }

    private function tryEnvironmentConfig() {
        $rootPath = realpath(ComposerFactory::getComposerFile());
        if ($rootPath === false) {
            return null;
        }
        $rootPath = dirname($rootPath) . DIRECTORY_SEPARATOR . '.dragiyski-project-cleanup.json';
        if (!is_readable($rootPath)) {
            return null;
        }
        $config = trim(file_get_contents($rootPath));
        if (empty($config)) {
            return null;
        }
        $config = @json_decode($config, true, 10, JSON_OBJECT_AS_ARRAY);
        if (empty($config) || !is_array($config)) {
            return null;
        }
        return $config;
    }

    private function tryComposerConfig() {
        $rootPackage = $this->composer->getPackage();
        $extra = $rootPackage->getExtra();
        if (empty($extra)) {
            return null;
        }
        if (isset($extra['dragiyski-project-cleanup']) && is_array($extra['dragiyski-project-cleanup'])) {
            return $extra['dragiyski-project-cleanup'];
        }
        return null;
    }

    private function countPatterns($config, $key) {
        return !empty($config[$key]) ? count((array)$config[$key]) : 0;
    }

    private function getPatternMatcherForKey($key) {
        $key = explode('-', $key);
        $result = array();
        if($key[0] === 'fnmatch') {
            $result[0] = 'fnmatch';
        } elseif($key[0] === 'regexp') {
            $result[0] = 'preg_match';
        }
        if(!isset($key[1])) {
            $result[1] = array($this, 'matchAlways');
        } elseif($key[1] === 'file') {
            $result[1] = array($this, 'matchIfFile');
        } elseif($key[2] === 'directory') {
            $result[1] = array($this, 'matchIfDirectory');
        }
        return $result;
    }

    private function addForRemovalIfMatch(&$result, $relative, $pathName, $config, $key) {
        if(!empty($config[$key])) {
            $fn = $this->getPatternMatcherForKey($key);
            foreach((array)$config[$key] as $pattern) {
                $params = array($pattern, $relative);
                if($fn[0] === 'fnmatch') {
                    $params[] = FNM_PATHNAME | FNM_PERIOD;
                }
                if(call_user_func_array($fn[0], $params) && call_user_func($fn[1], $pathName)) {
                    $result[] = $pathName;
                    return true;
                }
            }
        }
        return false;
    }

    private function matchAlways($pathName) {
        return file_exists($pathName);
    }

    private function matchIfFile($pathName) {
        return file_exists($pathName) && is_file($pathName);
    }

    private function matchIfDirectory($pathName) {
        return file_exists($pathName) && is_dir($pathName);
    }

    private function doCleanUp($basePath, $config) {
        if (!empty($config['file'])) {
            foreach ((array)$config['file'] as $path) {
                $path = @realpath($basePath . DIRECTORY_SEPARATOR . $path);
                if (!$path || strpos($path, $basePath) !== 0) {
                    // We do not allow deleting files outside the package
                    continue;
                }
                if (file_exists($path) && is_file($path)) {
                    try {
                        $this->filesystem->unlink($path);
                    } catch (\Exception $e) {
                        $this->io->writeError("Unable to remove file: $path", true, IOInterface::VERY_VERBOSE);
                    }
                    $this->io->writeError("Removed $path", true, IOInterface::DEBUG);
                }
            }
        }
        if (!empty($config['directory'])) {
            foreach ((array)$config['directory'] as $path) {
                $path = @realpath($basePath . DIRECTORY_SEPARATOR . $path);
                if (!$path) {
                    continue;
                }
                if (file_exists($path) && is_dir($path)) {
                    try {
                        $this->filesystem->removeDirectory($path);
                    } catch (\Exception $e) {
                        $this->io->writeError("Unable to remove directory: $path", true, IOInterface::VERY_VERBOSE);
                    }
                    $this->io->writeError("Removed $path", true, IOInterface::DEBUG);
                }
            }
        }
        if (!empty($config['path'])) {
            foreach ((array)$config['path'] as $path) {
                $path = @realpath($basePath . DIRECTORY_SEPARATOR . $path);
                if (!$path) {
                    continue;
                }
                if (file_exists($path)) {
                    try {
                        $this->filesystem->remove($path);
                    } catch (\Exception $e) {
                        $this->io->writeError("Unable to remove directory: $path", true, IOInterface::VERY_VERBOSE);
                    }
                    $this->io->writeError("Removed $path", true, IOInterface::DEBUG);
                }
            }
        }
        $patternCount = 0;
        $patternCount += $this->countPatterns($config, 'fnmatch');
        $patternCount += $this->countPatterns($config, 'fnmatch-file');
        $patternCount += $this->countPatterns($config, 'fnmatch-directory');
        $patternCount += $this->countPatterns($config, 'regexp');
        $patternCount += $this->countPatterns($config, 'regexp-file');
        $patternCount += $this->countPatterns($config, 'regexp-directory');
        if ($patternCount > 0) {
            $basePathLength = strlen($basePath);
            $pathToRemove = array();
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $basePath,
                        \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CURRENT_AS_FILEINFO | \RecursiveDirectoryIterator::KEY_AS_PATHNAME
                    ), \RecursiveIteratorIterator::CHILD_FIRST
                ) as $pathName => $fileInfo
            ) {
                /* @var $fileInfo \SplFileInfo */
                if (strpos($pathName, $basePath) !== 0) {
                    continue;
                }
                if(!$fileInfo->isDir() && !$fileInfo->isFile()) {
                    continue;
                }
                if(!$fileInfo->isWritable()) {
                    continue;
                }
                $relative = $pathName;
                if (DIRECTORY_SEPARATOR !== '/') {
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                }
                $relative = ltrim(substr($pathName, $basePathLength), '/');
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'fnmatch') ||
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'fnmatch-file') ||
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'fnmatch-directory') ||
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'regexp') ||
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'regexp-file') ||
                $this->addForRemovalIfMatch($pathToRemove, $relative, $pathName, $config, 'regexp-directory');
            }
            $pathToRemove = array_unique($pathToRemove);
            foreach($pathToRemove as $path) {
                try {
                    $this->filesystem->remove($path);
                } catch (\Exception $e) {
                    $this->io->writeError("Unable to remove path: $path", true, IOInterface::VERY_VERBOSE);
                }
            }
        }
        $j = 0;
    }

    private function cleanUpPackage(PackageInterface $package, $config) {
        if (isset($config['exclude']) && is_array($config['exclude']) && in_array($package->getName(), $config['exclude'], true)) {
            return;
        }
        if (isset($config['override'][$package->getName()])) {
            $config = $config['override'][$package->getName()];
            if (!is_array($config)) {
                return;
            }
        }
        if($package->getType() === 'metapackage') {
            return;
        }
        $basePath = @realpath($this->composer->getInstallationManager()->getInstallPath($package));
        if (!$basePath) {
            // Just in case something goes wrong. Removing nothing is better.
            $this->io->writeError("Unable to get the base path for package: {$package->getName()}", true, IOInterface::VERBOSE);
            return;
        }
        $this->doCleanUp($basePath, $config);
    }

    public function onNewCodeEvent(ScriptEvent $event) {
        $removeConfig = $this->tryEnvironmentConfig();
        if (!is_array($removeConfig)) {
            $removeConfig = $this->tryComposerConfig();
            if (!is_array($removeConfig)) {
                // Nothing to cleanup by default
                return;
            }
        }

        if (isset($removeConfig['packages'])) {
            $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
            foreach ($packages as $package) {
                $this->cleanUpPackage($package, $removeConfig['packages']);
            }
        }

        $notImplemented = true;
    }
}
