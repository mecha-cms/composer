<?php

namespace MechaCMS\Composer;

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
        return 'vendor/' . $name;
    }
    public function supports(string $packageType) {
        return 'mecha-cms/x' === $packageType || 'mecha-cms/y' === $packageType;
    }
}

class Plugin implements PluginInterface {
    public function activate(Composer $composer, IOInterface $io) {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}