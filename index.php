<?php

namespace Mecha\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;

class Installer extends LibraryInstaller {
    public function getInstallPath(PackageInterface $package) {
        $name = basename($package->getPrettyName());
        if ('x.' === substr($name, 0, 2)) {
            return 'lot/x/' . substr($name, 2);
        }
        if ('y.' === substr($name, 0, 2)) {
            return 'lot/y/' . substr($name, 2);
        }
        return parent::getInstallPath($package);
    }
}

class InstallerPlugin implements PluginInterface {
    public function activate(Composer $composer, IOInterface $io) {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
    public function deactivate(Composer $composer, IOInterface $io) {}
    public function uninstall(Composer $composer, IOInterface $io) {}
}