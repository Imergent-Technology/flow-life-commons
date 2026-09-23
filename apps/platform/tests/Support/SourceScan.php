<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scans that read PHP as PHP, through the tokenizer, so a comment or docblock that NAMES a forbidden thing
 * (as this codebase's comments routinely do, to explain why it is forbidden) can never trip a rule, and a rule is about
 * what the code does. Every scan built on this has a positive control fed through the same function.
 */
final class SourceScan
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every PHP file under these directories (relative to apps/platform).
     *
     * @param  list<string>  $dirs
     * @return list<string> absolute paths
     */
    public static function phpFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $path = self::root().'/'.$dir;
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                assert($file instanceof SplFileInfo);
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    public static function relative(string $path): string
    {
        return str_replace(self::root().'/', '', $path);
    }

    /** The source with every comment and docblock removed: what the code actually says. */
    public static function code(string $source): string
    {
        $code = '';
        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }

    /**
     * The string literals in the source, unquoted. Comments are not string literals, so they are never here.
     *
     * @return list<string>
     */
    public static function stringLiterals(string $source): array
    {
        $literals = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $literals[] = substr($token->text, 1, -1);
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                $literals[] = $token->text;
            }
        }

        return $literals;
    }

    public static function read(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
