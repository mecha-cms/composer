<?php

namespace Mecha\Composer;

use const DIRECTORY_SEPARATOR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use function basename;
use function dirname;
use function json_decode;
use function json_encode;
use function rmdir;
use function strpos;
use function unlink;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface {
    public function activate(Composer $composer, IOInterface $io) {
        $composer->getInstallationManager()->addInstaller($this->installer = new Installer($io, $composer));
    }
    public function deactivate(Composer $composer, IOInterface $io) {}
    public function onPostCreateProject(Event $event) {
        $r = dirname($event->getComposer()->getConfig()->get('vendor-dir'), 2);
        $dir = new RecursiveDirectoryIterator($r, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::CHILD_FIRST);
        $files_to_delete = [
            '.editorconfig' => 1,
            '.gitattributes' => 1,
            '.gitignore' => 1,
            '.gitmodules' => 1,
            'README.md' => 1
        ];
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
            if ($v->isFile()) {
                if (isset($files_to_delete[$n = basename($path)])) {
                    unlink($path);
                }
                // Minify `composer.json` and `composer.lock`
                if ('composer.json' === $n || 'composer.lock' === $n) {
                    file_put_contents($path, json_encode(json_decode(file_get_contents($path)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
    public function onPostUpdate(Event $event) {
        return $this->onPostCreateProject($event);
    }
    public function uninstall(Composer $composer, IOInterface $io) {}
    public static function getSubscribedEvents() {
        return [
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate'
        ];
    }
}