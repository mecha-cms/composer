<?php

namespace Mecha\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

use Mecha\Composer\Plugin\Installer;

class Plugin implements PluginInterface, EventSubscriberInterface {
    private $installer;
    private function d(string $path) {
        // Normalize file/folder path
        return \strtr($path, ['/' => \DIRECTORY_SEPARATOR]);
    }
    private function minify(Event $event) {
        $minify_on_install = !empty($event->getComposer()->getPackage()->getExtra()['minify-on-install']);
        $remove_on_install = (array) ($event->getComposer()->getPackage()->getExtra()['remove-on-install'] ?? []);
        $d = \DIRECTORY_SEPARATOR;
        $r = $this->d(\dirname($vendor = $event->getComposer()->getConfig()->get('vendor-dir'), 2));
        $dir = new \RecursiveDirectoryIterator($r, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $path = $this->d($file->getRealPath());
            // Skip optimization in `mecha-cms/composer` folder just to be safe
            if (0 === \strpos($path . $d, $this->d($vendor . '/mecha-cms/composer/'))) {
                continue;
            }
            if ($file->isFile()) {
                // Relative to the project folder
                if (!empty($remove_on_install[$n = '/' . \strtr($path, [$r . $d => "", $d => '/'])])) {
                    if (\unlink($path)) {
                        unset($remove_on_install[$n]);
                    }
                    continue;
                }
                // Relative to the library folder
                if (!empty($remove_on_install[$n = $file->getFilename()])) {
                    if (\unlink($path)) {
                        unset($remove_on_install[$n]);
                    }
                    continue;
                }
                if ($minify_on_install) {
                    // Minify `composer.json` and `composer.lock`
                    if ('composer.json' === $n || 'composer.lock' === $n) {
                        \file_put_contents($path, (string) $this->minifyJSON(\file_get_contents($path)));
                        continue;
                    }
                    // Minify `*.php` file(s)
                    if ('php' === $file->getExtension()) {
                        $content = (string) $this->minifyPHP(\file_get_contents($path));
                        // Attribute syntax is available since PHP 8.0.0. All PHP versions prior to that will treat them
                        // as normal comment token. Therefore, if there is no line-break after the comment token, it
                        // will cause a syntax error because all PHP syntax that comes after the comment token will be
                        // treated as part of the comment token.
                        if (\version_compare(\PHP_VERSION, '8.0.0', '<') && false !== \strpos($content, '#[')) {
                            $content = \preg_replace('/#\[(?:"(?:[^"\\\]|\\\.)*"|\'(?:[^\'\\\]|\\\.)*\'|[^\[\]]|(?R))*\]/', '$0' . \PHP_EOL, $content);
                        }
                        \file_put_contents($path, $content);
                    }
                }
            }
        }
        if (!empty($remove_on_install)) {
            foreach (\array_filter($remove_on_install) as $k => $v) {
                $k = \strtr($k, [$d => '/']);
                if ('/*' === \substr($k, -2)) {
                    $folder = \substr($k, 0, -1);
                    $folder_remove = false;
                } else if ('/' === \substr($k, -1)) {
                    $folder = $k;
                    $folder_remove = true;
                } else {
                    $folder = $k . '/';
                    $folder_remove = true;
                }
                $folder = $this->d($folder);
                foreach ($files as $file) {
                    $path = $this->d($file->getRealPath());
                    if (0 === \strpos($path . $d, $r . $folder)) {
                        if ($file->isDir() && $folder_remove ? (!(new \FilesystemIterator($path))->valid() && \rmdir($path)) : \unlink($path)) {
                            unset($remove_on_install[$k]);
                        }
                        continue;
                    }
                    if (0 === \strpos($file->getFilename() . $d, $folder)) {
                        if ($file->isDir() && $folder_remove ? (!(new \FilesystemIterator($path))->valid() && \rmdir($path)) : \unlink($path)) {
                            unset($remove_on_install[$k]);
                        }
                        continue;
                    }
                }
            }
        }
        if (!empty($remove_on_install)) {
            foreach (\array_filter($remove_on_install) as $k => $v) {
                // $event->getIO()->write('  - Could not find file/folder matching pattern <info>' . $k . '</info>');
            }
        }
    }
    // Based on <https://github.com/taufik-nurrohman/minify>
    private function minifyJSON(?string $from): ?string {
        if ("" === ($from = \trim($from ?? ""))) {
            return null;
        }
        if ('""' === $from || '[]' === $from || 'false' === $from || 'null' === $from || 'true' === $from || '{}' === $from || \is_numeric($from)) {
            return $from;
        }
        $c1 = ',:[]{}';
        $to = "";
        while (false !== ($chop = \strpbrk($from, '"' . $c1))) {
            if ("" !== ($v = \strstr($from, $c = $chop[0], true))) {
                $from = $chop;
                $to .= \trim($v);
            }
            if (false !== \strpos($c1, $c)) {
                $from = \substr($from, 1);
                $to .= $chop[0];
                continue;
            }
            if ('""' === \substr($chop, 0, 2)) {
                $from = \substr($from, 2);
                $to .= '""';
                continue;
            }
            if ('"' === $c && \preg_match('/^"[^"\\\\]*(?>\\\\.[^"\\\\]*)*"/', $chop, $m)) {
                $from = \substr($from, \strlen($m[0]));
                $to .= $m[0];
                continue;
            }
            $from = "";
            $to .= \trim($chop); // `false`, `null`, `true`, `1`, `1.0`
        }
        if ("" !== $from) {
            $to .= \trim($from);
        }
        return "" !== ($to = \trim($to)) ? $to : null;
    }
    // Based on <https://github.com/taufik-nurrohman/minify>
    private function minifyPHP(?string $from): ?string {
        if ("" === ($from = \trim($from ?? ""))) {
            return null;
        }
        $count = \count($lot = \token_get_all($from));
        $in_array = $is_array = 0;
        $to = "";
        foreach ($lot as $k => $v) {
            if ('stdclass' === \strtolower(\substr($to, -8)) && \preg_match('/\bnew \\\\?stdclass$/i', $to, $m)) {
                $to = \trim(\substr($to, 0, -\strlen($m[0]))) . '(object)[]';
            }
            if (\is_array($v)) {
                // Can be `array $asdf` or `array (`
                if (\T_ARRAY === $v[0]) {
                    $i = $k + 1;
                    while (isset($lot[$i])) {
                        if (\is_array($lot[$i]) && \T_WHITESPACE !== $lot[$i][0]) {
                            break;
                        }
                        if (\is_string($lot[$i])) {
                            if ('(' === $lot[$i]) {
                                $is_array += 1;
                            }
                            break;
                        }
                        ++$i;
                    }
                    if (!$is_array) {
                        $to .= $v[1];
                    }
                    continue;
                }
                if ('_CAST' === \substr(\token_name($v[0]), -5)) {
                    $cast = \trim(\substr($v[1], 1, -1));
                    if ('boolean' === $cast) {
                        $cast = 'bool';
                    } else if ('double' === $cast || 'real' === $cast) {
                        $cast = 'float';
                    } else if ('integer' === $cast) {
                        $cast = 'int';
                    }
                    $to .= '(' . $cast . ')';
                    continue;
                }
                if (\T_CLOSE_TAG === $v[0]) {
                    if ($k === $count - 1) {
                        $to = \trim($to, ';') . ';';
                        continue;
                    }
                    // <https://www.php.net/language.basic-syntax.instruction-separation>
                    $to = \trim(\trim($to, ';')) . $v[1];
                    continue;
                }
                if (\T_COMMENT === $v[0] || \T_DOC_COMMENT === $v[0]) {
                    if (0 === \strpos($v[1], '/*') && false !== \strpos('!*', $v[1][2])) {
                        if (false !== \strpos($v[1], "\n")) {
                            $to .= '/*' . \substr($v[1], 3);
                        } else {
                            $to .= '/*' . \trim(\substr($v[1], 3, -2)) . '*/';
                        }
                    }
                    continue;
                }
                if (\T_CONSTANT_ENCAPSED_STRING === $v[0]) {
                    if ('(binary)' === \substr($to, -8)) {
                        $to = \substr($to, 0, -8) . 'b';
                    }
                    $to = \trim($to) . $v[1];
                    continue;
                }
                if (\T_DNUMBER === $v[0]) {
                    $test = \strtolower(\rtrim(\trim(\strtr($v[1], ['_' => ""]), '0'), '.'));
                    if (false === \strpos($test = "" !== $test ? $test : '0', '.')) {
                        if (false === \strpos($test, 'e')) {
                            $test .= '.0';
                        }
                    }
                    if ('(int)' === \substr($to, -5)) {
                        $to = \substr($to, 0, -5) . \var_export((int) $test, true);
                        continue;
                    }
                    if ('(string)' === \substr($to, -8)) {
                        $to = \substr($to, 0, -8) . "'" . $test . "'";
                        continue;
                    }
                    $to .= $test;
                    continue;
                }
                if (\T_ECHO === $v[0] || \T_PRINT === $v[0]) {
                    if ('<?php ' === \substr($to, -6)) {
                        // Replace `<?php echo` with `<?=`
                        $to = \substr($to, 0, -4) . '=';
                        continue;
                    }
                    // Replace `print` with `echo`
                    $to .= 'echo ';
                    continue;
                }
                if (\T_ENCAPSED_AND_WHITESPACE === $v[0]) {
                    $v[1] = \strtr($v[1], ["S\n" => "\\x53\n"]);
                    // `asdf { $asdf } asdf`
                    if ('}' === (\trim($v[1])[0] ?? 0) && false !== ($test = \strrchr($to, '{'))) {
                        $to = \substr($to, 0, -\strlen($test)) . '{' . \trim(\substr($test, 1)) . \trim($v[1]);
                        continue;
                    }
                    $to .= $v[1] . (false !== \strpos(" \n\r\t", \substr($v[1], -1)) ? "\x1a" : "");
                    continue;
                }
                if (\T_END_HEREDOC === $v[0]) {
                    $to .= 'S';
                    continue;
                }
                if (\T_INLINE_HTML === $v[0]) {
                    $to .= $v[1];
                    continue;
                }
                if (\T_LNUMBER === $v[0]) {
                    $test = \strtolower(\ltrim(\strtr($v[1], ['_' => ""]), '0'));
                    if ('(float)' === \substr($to, -7)) {
                        $to = \substr($to, 0, -7) . \var_export((float) $test, true);
                        continue;
                    }
                    $test = "" !== $test ? $test : '0';
                    if ('(string)' === \substr($to, -8)) {
                        $to = \substr($to, 0, -8) . "'" . $test . "'";
                        continue;
                    }
                    $to .= $test;
                    continue;
                }
                if (\T_OPEN_TAG === $v[0]) {
                    $to .= \trim($v[1]) . ' ';
                    continue;
                }
                if (\T_OPEN_TAG_WITH_ECHO === $v[0]) {
                    $to .= $v[1];
                    continue;
                }
                if (\T_START_HEREDOC === $v[0]) {
                    if ("'" === $v[1][3]) {
                        $to .= "<<<'S'\n";
                        continue;
                    }
                    $to .= "<<<S\n";
                    continue;
                }
                if (\T_STRING === $v[0]) {
                    $test = \strtolower($v[1]);
                    if ('false' === $test) {
                        $to = \trim($to) . '!1';
                    } else if ('null' === $test) {
                        $to .= $test;
                    } else if ('true' === $test) {
                        $to = \trim($to) . '!0';
                    } else {
                        $to .= $v[1];
                    }
                    continue;
                }
                // <https://stackoverflow.com/a/16606419/1163000>
                if (\T_VARIABLE === $v[0]) {
                    if ('(bool)' === \substr($to, -6)) {
                        $to = \substr($to, 0, -6) . '!!' . $v[1];
                    } else if ('(float)' === \substr($to, -7)) {
                        $to = \substr($to, 0, -7) . $v[1] . '+0';
                    } else if ('(int)' === \substr($to, -5)) {
                        $to = \substr($to, 0, -5) . $v[1] . '+0';
                    } else if ('(string)' === \substr($to, -8)) {
                        $to = \substr($to, 0, -8) . $v[1] . '.""';
                    } else if ("\x1a" === \substr($to, -1)) {
                        $to = \substr($to, 0, -1) . $v[1];
                    } else {
                        if ('<?php ' === \substr($to, -6)) {
                            $to .= $v[1];
                        } else {
                            $to = \trim($to) . $v[1];
                        }
                    }
                    continue;
                }
                if (\T_WHITESPACE === $v[0]) {
                    $to .= false !== \strpos(' "/!#%&()*+,-.:;<=>?@[\]^`{|}~' . "'", \substr($to, -1)) ? "" : ' ';
                    continue;
                }
                // Math operator(s)
                if (false !== \strpos('!%&*+-./<=>?|~', $v[1][0])) {
                    $to = \trim($to) . $v[1];
                    continue;
                }
                $to .= $v[1];
                continue;
            }
            if ($is_array && '(' === $v) {
                $in_array += 1;
                $to = \trim($to) . '[';
                continue;
            }
            if ($is_array && ')' === $v) {
                if ($in_array === $is_array) {
                    $in_array -= 1;
                    $is_array -= 1;
                    $to = \trim(\trim($to, ',')) . ']';
                    continue;
                }
            }
            if (false !== \strpos('([', $v)) {
                $to = \trim($to) . $v;
                continue;
            }
            if (false !== \strpos(')]', $v)) {
                // `new stdclass()` to `(object)[]()` to `(object)[]`
                if ('(object)[](' === \substr($to, -11)) {
                    $to = \substr($to, 0, -1);
                    continue;
                }
                $to = \trim(\trim($to, ',')) . $v;
                continue;
            }
            $to = \trim($to) . $v;
        }
        return "" !== ($to = \trim(\strtr($to, ["\x1a" => ""]))) ? $to : null;
    }
    public function activate(Composer $composer, IOInterface $io) {
        $composer->getInstallationManager()->addInstaller($this->installer = new Installer($io, $composer));
    }
    public function deactivate(Composer $composer, IOInterface $io) {}
    public function onPostCreateProject(Event $event) {
        $this->minify($event);
    }
    public function onPostInstall(Event $event) {
        $this->minify($event);
    }
    public function onPostPackageInstall(PackageEvent $event) {
        $name = \basename(($package = $event->getOperation()->getPackage())->getName());
        $r = \dirname($event->getComposer()->getConfig()->get('vendor-dir'), 2);
        // Automatically disable other layout(s) after installing this layout
        if ('y.' === \substr($name, 0, 2)) {
            $name = \substr($name, 2);
            $folder = $this->d(\dirname($r . '/' . $this->installer->getInstallPath($package)));
            foreach (\glob($this->d($folder . '/*/index.php'), \GLOB_NOSORT) as $v) {
                if ($name === \basename(\dirname($v))) {
                    continue;
                }
                \rename($v, $this->d(\dirname($v) . '/.index.php'));
                $event->getIO()->write('  - Layout <info>' . \basename(\dirname($v)) . '</info> is now disabled.');
            }
        }
    }
    public function onPostUpdate(Event $event) {
        $this->minify($event);
    }
    public function uninstall(Composer $composer, IOInterface $io) {}
    public static function getSubscribedEvents() {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate'
        ];
    }
}