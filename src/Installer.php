<?php

namespace Mecha\Composer;

use const DIRECTORY_SEPARATOR;

use function basename;
use function substr;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;

class Installer extends LibraryInstaller {
    public function getInstallPath(PackageInterface $package) {
        $name = basename($package->getPrettyName());
        if ('x.' === substr($name, 0, 2)) {
            return 'lot' . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . substr($name, 2);
        }
        if ('y.' === substr($name, 0, 2)) {
            return 'lot' . DIRECTORY_SEPARATOR . 'y' . DIRECTORY_SEPARATOR . substr($name, 2);
        }
        return parent::getInstallPath($package);
    }
}