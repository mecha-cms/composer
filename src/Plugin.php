<?php

namespace Mecha\Composer;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_END_HEREDOC;
use const T_INLINE_HTML;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;
use const T_START_HEREDOC;
use const T_WHITESPACE;

use function addcslashes;
use function array_shift;
use function basename;
use function constant;
use function count;
use function defined;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function preg_split;
use function rename;
use function rmdir;
use function rtrim;
use function strpos;
use function strtr;
use function substr;
use function token_get_all;
use function trim;
use function unlink;

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
    private function minifyPHP(string $content, int $comment = 2, int $quote = 1) {
        $out = "";
        $t = [];
        // White-space(s) around these token(s) can be removed :)
        foreach ([
            'AND_EQUAL',
            'ARRAY_CAST',
            'BOOLEAN_AND',
            'BOOLEAN_OR',
            'BOOL_CAST',
            'COALESCE',
            'CONCAT_EQUAL',
            'DEC',
            'DIV_EQUAL',
            'DOLLAR_OPEN_CURLY_BRACES',
            'DOUBLE_ARROW',
            'DOUBLE_CAST',
            'DOUBLE_COLON',
            'INC',
            'INT_CAST',
            'IS_EQUAL',
            'IS_GREATER_OR_EQUAL',
            'IS_IDENTICAL',
            'IS_NOT_EQUAL',
            'IS_NOT_IDENTICAL',
            'IS_SMALLER_OR_EQUAL',
            'MINUS_EQUAL',
            'MOD_EQUAL',
            'MUL_EQUAL',
            'OBJECT_OPERATOR',
            'OR_EQUAL',
            'PAAMAYIM_NEKUDOTAYIM',
            'PLUS_EQUAL',
            'POW',
            'POW_EQUAL',
            'SL',
            'SL_EQUAL',
            'SPACESHIP',
            'SR',
            'SR_EQUAL',
            'STRING_CAST',
            'XOR_EQUAL'
        ] as $v) {
            if (defined($v = 'T_' . $v)) {
                $t[constant($v)] = 1;
            }
        }
        $c = count($toks = token_get_all($content));
        $doc = $skip = false;
        $start = $end = null;
        for ($i = 0; $i < $c; ++$i) {
            $tok = $toks[$i];
            if (is_array($tok)) {
                $id = $tok[0];
                $token = $tok[1];
                if (T_INLINE_HTML === $id) {
                    $out .= $token;
                    $skip = false;
                } else {
                    if (T_OPEN_TAG === $id) {
                        $out .= rtrim($token) . ' ';
                        $start = T_OPEN_TAG;
                        $skip = true;
                    } else if (T_OPEN_TAG_WITH_ECHO === $id) {
                        $out .= $token;
                        $start = T_OPEN_TAG_WITH_ECHO;
                        $skip = true;
                    } else if (T_CLOSE_TAG === $id) {
                        if (T_OPEN_TAG_WITH_ECHO === $start) {
                            $out = rtrim($out, '; ');
                        } else {
                            $token = ' ' . $token;
                        }
                        $out .= trim($token);
                        $start = null;
                        $skip = false;
                    } else if (isset($t[$id])) {
                        $out .= $token;
                        $skip = true;
                    } else if (T_ENCAPSED_AND_WHITESPACE === $id || T_CONSTANT_ENCAPSED_STRING === $id) {
                        if ('"' === $token[0]) {
                            $token = addcslashes($token, "\n\r\t");
                        }
                        $out .= $token;
                        $skip = true;
                    } else if (T_WHITESPACE === $id) {
                        $n = $toks[$i + 1] ?? null;
                        if (!$skip && (!is_string($n) || '$' === $n) && !isset($t[$n[0]])) {
                            $out .= ' ';
                        }
                        $skip = false;
                    } else if (T_START_HEREDOC === $id) {
                        $out .= "<<<" . ("'" === $token[3] ? "'S'" : 'S') . "\n";
                        $skip = false;
                        $doc = true; // Enter (HERE/NOW)DOC
                    } else if (T_END_HEREDOC === $id) {
                        $out .= "S\n";
                        $skip = true;
                        $doc = false; // Exit (HERE/NOW)DOC
                        for ($j = $i + 1; $j < $c; ++$j) {
                            if (is_string($v = $toks[$j])) {
                                $out .= $v;
                                if (';' === $v || ',' === $v) {
                                    if ("\nS\n" . $v === substr($out, -4)) {
                                        $out = rtrim($out, "\n" . $v) . $v;
                                        // Prior to PHP 7.3.0, it is very important to note that the line with the closing
                                        // identifier must contain no other characters, except a semicolon (`;`). That means
                                        // especially that the identifier may not be indented, and there may not be any
                                        // spaces or tabs before or after the semicolon. It's also important to realize that
                                        // the first character before the closing identifier must be a newline as defined by
                                        // the local operating system. This is `\n` on UNIX systems, including macOS. The
                                        // closing delimiter must also be followed by a newline.
                                        //
                                        // <https://www.php.net/manual/en/language.types.string.php#language.types.string.syntax.heredoc>
                                        if (';' === $v) {
                                            $out .= "\n";
                                        }
                                    }
                                    $i = $j;
                                    break;
                                }
                            } else if (T_CLOSE_TAG === $v[0]) {
                                break;
                            } else {
                                $out .= trim($v[1]);
                            }
                        }
                    } else if (T_COMMENT === $id || T_DOC_COMMENT === $id) {
                        if (
                            1 === $comment || (
                                2 === $comment && (
                                    // Detect special comment(s) from the third character
                                    // It should be a `!` or `*` → `/*! keep */` or `/** keep */`
                                    !empty($token[2]) && false !== strpos('!*', $token[2]) ||
                                    // Detect license comment(s) from the content
                                    // It should contains character(s) like `@license`
                                    false !== strpos($token, '@licence') || // noun
                                    false !== strpos($token, '@license') || // verb
                                    false !== strpos($token, '@preserve')
                                )
                            )
                        ) {
                            $token = ltrim(substr($token, 2, -2), '!*');
                            $out .= '/*' . trim(strtr($token, ['@preserve' => ""])) . '*/';
                        }
                        $skip = true;
                    } else {
                        $out .= $token;
                        $skip = false;
                    }
                }
                $end = "";
            } else {
                if (false === strpos(';:', $tok) || $end !== $tok) {
                    $out .= $tok;
                    $end = $tok;
                }
                $skip = true;
            }
        }
        $out = $this->tokens([
            '\s*(?:"(?:[^"\\\]|\\\.)*"|\'(?:[^\'\\\]|\\\.)*\')\s*',
            '\s*<\?php echo ',
            ';\?>\s*'
        ], static function($token, $chop) {
            if (!$token || ('"' === $token[0] && '"' === substr($token, -1) || "'" === $token[0] && "'" === substr($token, -1))) {
                return $chop;
            }
            return strtr($chop, [
                '<?php echo ' => '<?=',
                ';?>' => '?>'
            ]);
        }, $out);
        return $out;
    }
    private function tokens(array $tokens, callable $fn = null, string $in = null, string $flag = 'i') {
        if ("" === ($in = trim($in))) {
            return "";
        }
        $pattern = strtr('(?:' . implode(')|(?:', $tokens) . ')', ['/' => "\\/"]);
        $chops = preg_split('/(' . $pattern . ')/' . $flag, $in, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$fn) {
            return $chops;
        }
        $out = "";
        while ($chops) {
            $chop = array_shift($chops);
            if ("" === ($token = trim($chop))) {
                continue;
            }
            $out .= $fn($token, $chop);
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
            }
            if ($v->isFile()) {
                if (isset($files_to_delete[$n = $v->getFilename()])) {
                    unlink($path);
                }
                // Minify `composer.json` and `composer.lock`
                if ('composer.json' === $n || 'composer.lock' === $n) {
                    file_put_contents($path, $this->minifyJSON(file_get_contents($path)));
                // Minify `*.php` file(s)
                } else if ('php' === $v->getExtension()) {
                    file_put_contents($path, $this->minifyPHP(file_get_contents($path)));
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