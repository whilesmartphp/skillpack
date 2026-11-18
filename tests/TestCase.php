<?php

namespace Whilesmart\Skillpack\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Whilesmart\Skillpack\Problem;

abstract class TestCase extends BaseTestCase
{
    protected const FIXTURES = __DIR__.'/Fixtures';

    protected const LAYOUT_SKILL = "---\nname: page-layout\ndescription: Lay out a page.\n---\n\nSee [grids](references/grids.md).\n";

    protected const REVIEW_SKILL = "---\nname: code-review\ndescription: Review code for bugs.\n---\n\n# Code review\n";

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $dir) {
            $this->remove($dir);
        }
        $this->temporary = [];
        parent::tearDown();
    }

    /**
     * @param  list<Problem>  $problems
     * @return list<string>
     */
    protected function codes(array $problems): array
    {
        $codes = array_map(fn (Problem $problem) => $problem->code, $problems);
        sort($codes, SORT_STRING);

        return $codes;
    }

    protected function temporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'skillpack-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $real = (string) realpath($dir);
        $this->temporary[] = $real;

        return $real;
    }

    protected function write(string $path, string $content): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $content);
    }

    private function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
