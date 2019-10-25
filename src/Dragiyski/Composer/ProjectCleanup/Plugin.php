<?php

namespace Dragiyski\Composer\ProjectCleanup;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
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

    public function onNewCodeEvent(ScriptEvent $event) {
        $notImplemented = true;
    }
}