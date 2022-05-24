<?php

namespace Mecha\Composer;

use Composer\Installer\LibraryInstaller;

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