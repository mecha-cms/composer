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
        if ('extension' === $this->type) {
            return $this->d('lot/x/' . $name);
        }
        if ('layout' === $this->type) {
            return $this->d('lot/y/' . $name);
        }
        if ('patch' === $this->type) {
            // TODO
        }
        if ('x.' === \substr($name, 0, 2)) {
            return $this->d('lot/x/' . \substr($name, 2));
        }
        if ('y.' === \substr($name, 0, 2)) {
            return $this->d('lot/y/' . \substr($name, 2));
        }
        return parent::getInstallPath($package);
    }
}