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
    private $filesToDelete = [
        '.editorconfig' => 1,
        '.gitattributes' => 1,
        '.gitignore' => 1,
        '.gitmodules' => 1,
        '.keep' => 1,
        'README.md' => 1,
        'package-lock.json' => 1,
        'package.json' => 1,
        'test' => 1,
        'test.css' => 1,
        'test.html' => 1,
        'test.js' => 1,
        'test.json' => 1,
        'test.php' => 1,
        'test.txt' => 1
    ];
    private $foldersToDelete = [
        '.factory' => 1,
        '.git' => 1,
        '.github' => 1,
        '.node_modules' => 1,
        'test' => 1
    ];
    private $installer;
    private function d(string $path) {
        // Normalize file/folder path
        return \strtr($path, ['/' => \DIRECTORY_SEPARATOR]);
    }
    private function minify(Event $event) {
        $extra = $event->getComposer()->getPackage()->getExtra();
        $minify = !empty($extra['minify']);
        $delete = empty($extra['no-delete']);
        $r = $this->d(\dirname($vendor = $event->getComposer()->getConfig()->get('vendor-dir'), 2));
        $dir = new \RecursiveDirectoryIterator($r, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::CHILD_FIRST);
        print('Running Mecha-cms composer plugin');
        if (!($delete || $minify)){
            print('nothing to minify or delete!' . PHP_EOL);
            return;
        }
        foreach ($files as $v) {
            $path = $this->d($v->getRealPath());
            // Skip optimization in `mecha-cms/composer` folder just to be safe
            if (0 === \strpos($this->d($path . '/'), $this->d($vendor . '/mecha-cms/composer/'))) {
                continue;
            }
            if ($v->isFile()) {
                if ($delete && !empty($this->filesToDelete[$n = $v->getFilename()])) {
                    print('deleting file ' . $path . PHP_EOL);
                    \unlink($path);
                    continue;
                }
                if ($minify) {
                    // Minify `composer.json` and `composer.lock`
                    if ('composer.json' === $n || 'composer.lock' === $n) {
                        \file_put_contents($path, $this->minifyJSON(\file_get_contents($path)));
                        continue;
                    }
                    // Minify `*.php` file(s)
                    if ('php' === $v->getExtension()) {
                        $content = $this->minifyPHP(\file_get_contents($path));
                        // Attribute syntax is available since PHP 8.0.0. All PHP versions prior to that will treat them
                        // as normal comment token. Therefore, if there is no line-break after the comment token, it
                        // will cause a syntax error because all PHP syntax that comes after the comment token will be
                        // treated as part of the comment token.
                        if (\version_compare(\PHP_VERSION, '8.0.0', '<') && false !== \strpos($content, '#[')) {
                            $content = \preg_replace('/#\[(?:"(?:[^"\\\]|\\\.)*"|\'(?:[^\'\\\]|\\\.)*\'|[^\[\]]|(?R))*\]/', '$0' . \PHP_EOL, $content);
                        }
                        if ('state.php' === $n && (false !== \strpos($content, '=>function(') || false !== \strpos($content, '=>fn('))) {
                            // Need to add a line-break here because <https://github.com/mecha-cms/mecha/blob/650fcccc13a5c6a2591d523d8f76411a6bdae8fb/engine/f.php#L1268-L1270>
                            $content = \preg_replace('/("(?:[^"\\\]|\\\.)*"|\'(?:[^\'\\\]|\\\.)*\')=>(fn|function)\(/', \PHP_EOL . '$1=>$2(', $content);
                        }
                        \file_put_contents($path, $content);
                    }
                }
            }
            if (!$delete){
                return;
            }
            foreach (\array_filter($this->foldersToDelete) as $kk => $vv) {
                if (false !== \strpos($this->d($path . '/'), $this->d('/' . $kk . '/'))) {
                    print('deleting folder ' . $path . PHP_EOL);
                    $v->isDir() ? \rmdir($path) : \unlink($path);
                    continue;
                }
            }
        }
    }
    private function minifyJSON(string $in) {
        return \json_encode(\json_decode($in), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
    // Based on <https://github.com/mecha-cms/x.minify>
    private function minifyPHP(string $in, int $comment = 2, int $quote = 1) {
        if ("" === ($in = \trim($in))) {
            return "";
        }
        $out = "";
        $tokens = \token_get_all($in);
        foreach ($tokens as $k => $v) {
            // Peek previous token
            if (\is_array($prev = $tokens[$k - 1] ?? "")) {
                $prev = $prev[1];
            }
            // Peek next token
            if (\is_array($next = $tokens[$k + 1] ?? "")) {
                $next = $next[1];
            }
            if (\is_array($v)) {
                if (\T_COMMENT === $v[0] || \T_DOC_COMMENT === $v[0]) {
                    if (
                        // Keep comment
                        1 === $comment || (
                            // Keep comment with condition(s)
                            2 === $comment && (
                                // Detect special comment from the third character
                                // It should be a `!` or `*` → `/*! keep */` or `/** keep */`
                                !empty($v[1][2]) && false !== \strpos('!*', $v[1][2]) ||
                                // Detect license comment from the content
                                // It should contains character(s) like `@license`
                                false !== \strpos($v[1], '@licence') || // noun
                                false !== \strpos($v[1], '@license') || // verb
                                false !== \strpos($v[1], '@preserve')
                            )
                        )
                    ) {
                        $v[1] = \ltrim(\substr($v[1], 2, -2), '!*');
                        $out .= '/*' . \trim(\strtr($v[1], ['@preserve' => ""])) . '*/';
                        continue;
                    }
                    // Remove comment
                    continue;
                }
                if (\T_CLOSE_TAG === $v[0]) {
                    // <https://www.php.net/manual/en/language.basic-syntax.instruction-separation.php>
                    if ("" === $next) {
                        continue; // Remove the last PHP closing tag
                    }
                    if (';' === \substr($out, -1)) {
                        $out = \substr($out, 0, -1); // Remove the last semi-colon before PHP closing tag
                    }
                }
                if (\T_OPEN_TAG === $v[0]) {
                    $out .= \rtrim($v[1]) . ' ';
                    continue;
                }
                if (\T_ECHO === $v[0] || \T_PRINT === $v[0]) {
                    if ('<?php ' === \substr($out, -6)) {
                        $out = \substr($out, 0, -4) . '='; // Replace `<?php echo` with `<?=`
                        continue;
                    }
                    $out .= 'echo '; // Replace `print` with `echo`
                    continue;
                }
                if (\T_CASE === $v[0] || \T_RETURN === $v[0] || \T_YIELD === $v[0]) {
                    $out .= $v[1] . ' ';
                    continue;
                }
                if (\T_IF === $v[0]) {
                    if ('else ' === \substr($out, -5)) {
                        $out = \substr($out, 0, -1) . 'if'; // Replace `else if` with `elseif`
                        continue;
                    }
                }
                if (\T_DNUMBER === $v[0]) {
                    if (0 === \strpos($v[1], '0.')) {
                        $v[1] = \substr($v[1], 1); // Replace `0.` prefix with `.` from float
                    }
                    $v[1] = \rtrim(\rtrim($v[1], '0'), '.'); // Remove trailing `.0` from float
                    $out .= $v[1];
                    continue;
                }
                if (\T_START_HEREDOC === $v[0]) {
                    $out .= '<<<' . ("'" === $v[1][3] ? "'S'" : 'S') . "\n";
                    continue;
                }
                if (\T_END_HEREDOC === $v[0]) {
                    $out .= 'S';
                    continue;
                }
                if (\T_CONSTANT_ENCAPSED_STRING === $v[0] || \T_ENCAPSED_AND_WHITESPACE === $v[0]) {
                    $out .= $v[1];
                    continue;
                }
                // Any type cast
                if (0 === \strpos($v[1], '(') && ')' === \substr($v[1], -1) && '_CAST' === \substr(\token_name($v[0]), -5)) {
                    $out = \rtrim($out) . '(' . \trim(\substr($v[1], 1, -1)) . ')'; // Remove white-space after `(` and before `)`
                    continue;
                }
                if (\T_WHITESPACE === $v[0]) {
                    if ("" === $next || "" === $prev) {
                        continue;
                    }
                    if (' ' === \substr($out, -1)) {
                        continue; // Has been followed by single space, skip!
                    }
                    // Check if previous or next token contains only punctuation mark(s). White-space around this
                    // token usually safe to be removed. They must be PHP operator(s) like `&&` and `||`.
                    // Of course, they can also be present in comment and string, but we already filtered them.
                    if (
                        (\function_exists("\\ctype_punct") && \ctype_punct($next) || \preg_match('/^\p{P}$/', $next)) ||
                        (\function_exists("\\ctype_punct") && \ctype_punct($prev) || \preg_match('/^\p{P}$/', $prev))
                    ) {
                        // `$_` variable is all punctuation but it needs to be preceded by a space to
                        // ensure that we don’t experience a result like `static$_=1` in the output.
                        if ('$' === $next[0] && (\function_exists("\\ctype_alnum") && \ctype_alnum(\strtr($prev, ['_' => ""])) || \preg_match('/^\w+$/', $prev))) {
                            $out .= ' ';
                            continue;
                        }
                        // `_` is a punctuation but it needs to be preceded by a space to ensure that we
                        // don’t experience a result like `function_(){}` or `const_=1` in the output.
                        if ('_' === $next[0]) {
                            $out .= ' ';
                            continue;
                        }
                        continue;
                    }
                    // Check if previous or next token is a comment, then remove white-space around it!
                    if (
                        0 === \strpos($next, '#') ||
                        0 === \strpos($prev, '#') ||
                        0 === \strpos($next, '//') ||
                        0 === \strpos($prev, '//') ||
                        '/*' === \substr($next, 0, 2) && '*/' === \substr($next, -2) ||
                        '/*' === \substr($prev, 0, 2) && '*/' === \substr($prev, -2)
                    ) {
                        continue;
                    }
                    // Remove white-space after type cast
                    if (0 === \strpos($prev, '(') && ')' === \substr($prev, -1) && \preg_match('/^\(\s*[^()\s]+\s*\)$/', $prev)) {
                        continue;
                    }
                    // Remove white-space after short echo
                    if ('<?=' === \substr($out, -3)) {
                        continue;
                    }
                    // Convert multiple white-space to single space
                    $out .= ' ';
                }
                $out .= ("" === \trim($v[1]) ? "" : $v[1]);
                continue;
            }
            // Replace `-0` with `0`
            if ('-' === $v && '0' === $next) {
                continue;
            }
            // Remove trailing `,`
            if (',' === \substr($out, -1) && false !== \strpos(')]}', $v)) {
                $out = \substr($out, 0, -1);
            }
            if (
                'case ' === \substr($out, -5) ||
                'echo ' === \substr($out, -5) ||
                'return ' === \substr($out, -7) ||
                'yield ' === \substr($out, -6)
            ) {
                if ($v && false !== \strpos('!([', $v[0])) {
                    $out = \substr($out, 0, -1);
                }
            }
            $out .= ("" === \trim($v) ? "" : $v);
        }
        return $out;
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