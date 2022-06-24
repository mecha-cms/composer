<?php

namespace Mecha\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Plugin implements PluginInterface, EventSubscriberInterface {
    public function activate(Composer $composer, IOInterface $io) {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
    public function deactivate(Composer $composer, IOInterface $io) {}
    public function onPostCreateProjectCommand(Event $event) {
        return $this->onPostUpdateCommand($event);
    }
    public function onPostUpdateCommand(Event $event) {
        $dir = new RecursiveDirectoryIterator($r = getcwd(), RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $v) {
            $path = $v->getRealPath();
            if (
                false !== strpos($path . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . '.factory' . DIRECTORY_SEPARATOR) ||
                false !== strpos($path . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) ||
                false !== strpos($path . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR) ||
                false !== strpos($path . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
            ) {
                $v->isDir() ? rmdir($path) : unlink($path);
            }
        }
        is_file($path = $r . DIRECTORY_SEPARATOR . '.gitignore') && unlink($path);
        is_file($path = $r . DIRECTORY_SEPARATOR . '.gitmodules') && unlink($path);
        is_file($path = $r . DIRECTORY_SEPARATOR . 'README.md') && unlink($path);
        // Minify `composer.json` and `composer.lock`
        if (is_file($path = $r . DIRECTORY_SEPARATOR . 'composer.json')) {
            file_put_contents($path, json_encode(json_decode(file_get_contents($path)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if (is_file($path = $r . DIRECTORY_SEPARATOR . 'composer.lock')) {
            file_put_contents($path, json_encode(json_decode(file_get_contents($path)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
    public function uninstall(Composer $composer, IOInterface $io) {}
    public static function getSubscribedEvents() {
        return [
            'post-create-project-cmd' => 'onPostCreateProjectCommand',
            'post-update-cmd' => 'onPostUpdateCommand'
        ];
    }
}