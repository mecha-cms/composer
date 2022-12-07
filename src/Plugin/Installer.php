<?php

namespace Mecha\Composer\Plugin;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;

class Installer extends LibraryInstaller {
    private function d(string $path) {
        // Normalize file/folder path
        return \strtr($path, ['/' => \DIRECTORY_SEPARATOR]);
    }
    public function getInstallPath(PackageInterface $package) {
        $name = \basename($package->getPrettyName());
        if ('x.' === \substr($name, 0, 2)) {
            return $this->d('lot/x/' . \substr($name, 2));
        }
        if ('y.' === \substr($name, 0, 2)) {
            return $this->d('lot/y/' . \substr($name, 2));
        }
        return parent::getInstallPath($package);
    }
}