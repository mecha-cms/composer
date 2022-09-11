<?php

namespace Mecha\Composer;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use const PHP_VERSION;
use const T_CASE;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DNUMBER;
use const T_DOC_COMMENT;
use const T_ECHO;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_END_HEREDOC;
use const T_IF;
use const T_OPEN_TAG;
use const T_RETURN;
use const T_START_HEREDOC;
use const T_WHITESPACE;

use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function preg_replace;
use function rename;
use function rmdir;
use function rtrim;
use function strpos;
use function strtr;
use function substr;
use function token_get_all;
use function token_name;
use function trim;
use function unlink;
use function version_compare;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface {
    private $installer;
    private function minifyJSON(string $content) {
        return json_encode(json_decode($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    // Based on <https://github.com/mecha-cms/x.minify>
    private function minifyPHP(string $in, int $comment = 2, int $quote = 1) {
        $out = "";
        $tokens = token_get_all($in);
        foreach ($tokens as $k => $v) {
            // Peek previous token
            if (is_array($prev = $tokens[$k - 1] ?? "")) {
                $prev = $prev[1];
            }
            // Peek next token
            if (is_array($next = $tokens[$k + 1] ?? "")) {
                $next = $next[1];
            }
            if (is_array($v)) {
                if (T_COMMENT === $v[0] || T_DOC_COMMENT === $v[0]) {
                    if (
                        // Keep comment
                        1 === $comment || (
                            // Keep comment with condition(s)
                            2 === $comment && (
                                // Detect special comment from the third character
                                // It should be a `!` or `*` → `/*! keep */` or `/** keep */`
                                !empty($v[1][2]) && false !== strpos('!*', $v[1][2]) ||
                                // Detect license comment from the content
                                // It should contains character(s) like `@license`
                                false !== strpos($v[1], '@licence') || // noun
                                false !== strpos($v[1], '@license') || // verb
                                false !== strpos($v[1], '@preserve')
                            )
                        )
                    ) {
                        $v[1] = ltrim(substr($v[1], 2, -2), '!*');
                        $out .= '/*' . trim(strtr($v[1], ['@preserve' => ""])) . '*/';
                        continue;
                    }
                    // Remove comment
                    continue;
                }
                if (T_CLOSE_TAG === $v[0]) {
                    // <https://www.php.net/manual/en/language.basic-syntax.instruction-separation.php>
                    if ("" === $next) {
                        continue; // Remove the last PHP closing tag
                    }
                    if (';' === substr($out, -1)) {
                        $out = substr($out, 0, -1); // Remove the last semi-colon before PHP closing tag
                    }
                }
                if (T_OPEN_TAG === $v[0]) {
                    $out .= rtrim($v[1]) . ' ';
                    continue;
                }
                if (T_ECHO === $v[0]) {
                    if ('<?php ' === substr($out, -6)) {
                        $out = substr($out, 0, -4) . '='; // Replace `<?php echo` with `<?=`
                        continue;
                    }
                }
                if (T_CASE === $v[0] || T_RETURN === $v[0]) {
                    $out .= $v[1] . ' ';
                    continue;
                }
                if (T_IF === $v[0]) {
                    if ('else ' === substr($out, -5)) {
                        $out = substr($out, 0, -1) . 'if'; // Replace `else if` with `elseif`
                        continue;
                    }
                }
                if (T_DNUMBER === $v[0]) {
                    if (0 === strpos($v[1], '0.')) {
                        $v[1] = substr($v[1], 1); // Replace `0.` prefix with `.` from float
                    }
                    $v[1] = rtrim(rtrim($v[1], '0'), '.'); // Remove trailing `.0` from float
                    $out .= $v[1];
                    continue;
                }
                if (T_START_HEREDOC === $v[0]) {
                    $out .= '<<<' . ("'" === $v[1][3] ? "'S'" : 'S') . "\n";
                    continue;
                }
                if (T_END_HEREDOC === $v[0]) {
                    $out .= 'S';
                    // Prior to PHP 7.3.0, it is very important to note that the line with the closing identifier must
                    // contain no other character(s), except a semicolon (`;`). That means especially that the identifier
                    // may not be indented, and there may not be any space(s) or tab(s) before or after the semicolon.
                    // It’s also important to realize that the first character before the closing identifier must be a
                    // new-line as defined by the local operating system. This is `\n` on UNIX system(s), including macOS.
                    // The closing delimiter must also be followed by a new-line.
                    //
                    // <https://www.php.net/manual/en/language.types.string.php#language.types.string.syntax.heredoc>
                    // if (version_compare(PHP_VERSION, '7.3.0') < 0) {
                        if (';' === $next) {
                            if (is_array($tokens[$k + 1])) {
                                $tokens[$k + 1][1] .= "\n";
                            } else {
                                $tokens[$k + 1] .= "\n";
                            }
                            continue;
                        }
                        if (',' !== $next) {
                            $out .= "\n";
                            continue;
                        }
                    // }
                    continue;
                }
                if (T_CONSTANT_ENCAPSED_STRING === $v[0] || T_ENCAPSED_AND_WHITESPACE === $v[0]) {
                    $out .= $v[1];
                    continue;
                }
                // Any type cast
                if (0 === strpos($v[1], '(') && ')' === substr($v[1], -1) && '_CAST' === substr(token_name($v[0]), -5)) {
                    $out .= '(' . trim(substr($v[1], 1, -1)) . ')'; // Remove white-space after `(` and before `)`
                    continue;
                }
                if (T_WHITESPACE === $v[0]) {
                    if ("" === $next || "" === $prev) {
                        continue;
                    }
                    if (' ' === substr($out, -1)) {
                        continue; // Has been followed by single space, skip!
                    }
                    // Check if previous or next token contains only punctuation mark(s). White-space around this
                    // token usually safe to be removed. They must be PHP operator(s) like `&&` and `||`.
                    // Of course, they can also be present in comment and string, but we already filtered them.
                    if (
                        (function_exists("\\ctype_punct") && \ctype_punct($next) || preg_match('/^\p{P}$/', $next)) ||
                        (function_exists("\\ctype_punct") && \ctype_punct($prev) || preg_match('/^\p{P}$/', $prev))
                    ) {
                        // `_` is a punctuation but it can be used to name a valid constant, function and property
                        if ('_' === $next) {
                            $out .= ' ';
                            continue;
                        }
                        continue;
                    }
                    // Check if previous or next token is a comment, then remove white-space around it!
                    if (
                        0 === strpos($next, '#') ||
                        0 === strpos($prev, '#') ||
                        0 === strpos($next, '//') ||
                        0 === strpos($prev, '//') ||
                        '/*' === substr($next, 0, 2) && '*/' === substr($next, -2) ||
                        '/*' === substr($prev, 0, 2) && '*/' === substr($prev, -2)
                    ) {
                        continue;
                    }
                    // Remove white-space after type cast
                    if (0 === strpos($prev, '(') && ')' === substr($prev, -1) && preg_match('/^\(\s*[^()\s]+\s*\)$/', $prev)) {
                        continue;
                    }
                    // Remove white-space after short echo
                    if ('<?=' === substr($out, -3)) {
                        continue;
                    }
                    // Convert multiple white-space to single space
                    $out .= ' ';
                }
                $out .= ("" === trim($v[1]) ? "" : $v[1]);
                continue;
            }
            // Remove trailing `,`
            if (',' === substr($out, -1) && false !== strpos(')]}', $v)) {
                $out = substr($out, 0, -1);
            }
            if ('case ' === substr($out, -5) || 'return ' === substr($out, -7)) {
                if ($v && false !== strpos('([', $v[0])) {
                    $out = substr($out, 0, -1);
                }
            }
            $out .= ("" === trim($v) ? "" : $v);
        }
        return $out;
    }
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
                continue;
            }
            if ($v->isFile()) {
                if (isset($files_to_delete[$n = $v->getFilename()])) {
                    unlink($path);
                    continue;
                }
                // Minify `composer.json` and `composer.lock`
                if ('composer.json' === $n || 'composer.lock' === $n) {
                    file_put_contents($path, $this->minifyJSON(file_get_contents($path)));
                    continue;
                }
                // Minify `*.php` file(s)
                if ('php' === $v->getExtension()) {
                    $content = $this->minifyPHP(file_get_contents($path));
                    if ('state.php' === $n && (false !== strpos($content, '=>function (') || false !== strpos($content, '=>fn('))) {
                        // Need to add a line-break here because <https://github.com/mecha-cms/mecha/blob/650fcccc13a5c6a2591d523d8f76411a6bdae8fb/engine/f.php#L1268-L1270>
                        $content = preg_replace('/("(?:[^"\\\]|\\\.)*"|\'(?:[^\'\\\]|\\\.)*\')=>(fn|function)\(/', PHP_EOL . '$1=>$2(', $content);
                    }
                    file_put_contents($path, $content);
                }
            }
        }
    }
    public function onPostPackageInstall(PackageEvent $event) {
        $name = basename(($package = $event->getOperation()->getPackage())->getName());
        $r = dirname($event->getComposer()->getConfig()->get('vendor-dir'), 2);
        // Automatically disable other layout after installing this layout
        if ('y.' === substr($name, 0, 2)) {
            $name = substr($name, 2);
            $folder = strtr(dirname($r . '/' . $this->installer->getInstallPath($package)), ['/' => DIRECTORY_SEPARATOR]);
            foreach (glob($folder . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'index.php', GLOB_NOSORT) as $v) {
                if ($name === basename(dirname($v))) {
                    continue;
                }
                // $event->getIO()->write('Disabling ' . basename(dirname($v)) . ' layout...');
                rename($v, dirname($v) . DIRECTORY_SEPARATOR . '.index.php');
            }
        }
    }
    public function onPostUpdate(Event $event) {
        return $this->onPostCreateProject($event);
    }
    public function uninstall(Composer $composer, IOInterface $io) {}
    public static function getSubscribedEvents() {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate'
        ];
    }
}