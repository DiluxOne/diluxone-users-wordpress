<?php
/**
 * The coverage report the Make targets print: line, function and class
 * percentages per file of includes/ and templates/, the totals, the files
 * covered least, and every function no test ever ran.
 *
 * PHPUnit's own text report lists classes only, and this plugin is functions
 * in files, so it would print the totals and nothing under them. This reads
 * the serialized coverage (`--coverage-php`, or what `phpcov merge` writes)
 * and walks php-code-coverage's own tree instead.
 *
 * Usage: php tests/coverage/report.php <coverage.cov> [--top=20] [--out=<file>] [--min-lines=<percent>] [--uncovered=<path>]
 *
 * --uncovered=<path> (repeatable, e.g. --uncovered=includes/sso.php) prints the
 * lines of that file no test ran, with their code, instead of the table.
 *
 * --min-lines makes it a gate: the exit code is 1 when the lines of includes/
 * are covered less than that.
 *
 * @package DiluxOneUsers\Tests
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File;

$args = array_slice($argv, 1);
$input = '';
$top = 20;
$out = '';
$min_lines = null;
$uncovered = [];
foreach ($args as $arg) {
    if (strpos($arg, '--uncovered=') === 0) {
        $uncovered[] = substr($arg, 12);
    } elseif (strpos($arg, '--top=') === 0) {
        $top = (int) substr($arg, 6);
    } elseif (strpos($arg, '--out=') === 0) {
        $out = substr($arg, 6);
    } elseif (strpos($arg, '--min-lines=') === 0) {
        $min_lines = (float) substr($arg, 12);
    } else {
        $input = $arg;
    }
}
if ($input === '' || !is_readable($input)) {
    fwrite(STDERR, "usage: php tests/coverage/report.php <coverage.cov> [--top=20] [--out=<file>] [--min-lines=<percent>]\n");
    exit(2);
}

/** @var CodeCoverage $coverage */
$coverage = include $input;
$tree = $coverage->getReport();

if ($uncovered !== []) {
    foreach ($tree as $node) {
        if (!$node instanceof File) {
            continue;
        }
        $relative = preg_replace('#^.*?/((includes|templates)/)#', '$1', $node->pathAsString());
        if (!in_array($relative, $uncovered, true)) {
            continue;
        }
        $code = file($node->pathAsString());
        echo "== {$relative}\n";
        foreach ($node->lineCoverageData() as $number => $tests) {
            if (is_array($tests) && $tests === []) {
                printf("%5d  %s", $number, $code[$number - 1] ?? "\n");
            }
        }
    }
    exit(0);
}

$rows = [];
foreach ($tree as $node) {
    if (!$node instanceof File) {
        continue;
    }
    $path = $node->pathAsString();
    $relative = ltrim(substr($path, strlen($root)), '/');
    if (strpos($path, $root . '/') !== 0) {
        // Merged on another machine: keep what follows the plugin's folder.
        $relative = preg_replace('#^.*?/(includes|templates)/#', '$1/', $path);
    }
    if (strpos($relative, 'includes/') !== 0 && strpos($relative, 'templates/') !== 0) {
        continue;
    }
    $never = [];
    $run = 0;
    foreach ($node->functions() as $name => $function) {
        if ($function['executableLines'] === 0) {
            continue;
        }
        if ($function['executedLines'] > 0) {
            ++$run;
        } else {
            $never[] = $name;
        }
    }
    foreach ($node->classes() as $class_name => $class) {
        foreach ($class['methods'] as $method_name => $method) {
            if ($method['executableLines'] === 0) {
                continue;
            }
            if ($method['executedLines'] > 0) {
                ++$run;
            } else {
                $never[] = $class_name . '::' . $method_name;
            }
        }
    }
    $rows[$relative] = [
        'lines'            => $node->numberOfExecutableLines(),
        'covered'          => $node->numberOfExecutedLines(),
        'functions'        => $run + count($never),
        'functions_run'    => $run,
        'functions_full'   => $node->numberOfTestedFunctions() + $node->numberOfTestedMethods(),
        'classes'          => $node->numberOfClassesAndTraits(),
        'classes_full'     => $node->numberOfTestedClassesAndTraits(),
        'never'            => $never,
    ];
}
// A file no test loaded is in no part at all (the runs leave uncovered files
// out, see phpunit-integration.xml), so it is added here, every line and
// function never run, counted the way php-code-coverage counts a file it was
// never handed.
$analyser = new \SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser(true, false);
foreach (['includes', 'templates'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = $dir . substr($file->getPathname(), strlen($root . '/' . $dir));
        if ($file->getExtension() !== 'php' || isset($rows[$relative])) {
            continue;
        }
        $functions = array_keys($analyser->functionsIn($file->getPathname()));
        $rows[$relative] = [
            'lines'          => count($analyser->executableLinesIn($file->getPathname())),
            'covered'        => 0,
            'functions'      => count($functions),
            'functions_run'  => 0,
            'functions_full' => 0,
            'classes'        => count($analyser->classesIn($file->getPathname())),
            'classes_full'   => 0,
            'never'          => array_map(static function (string $name): string {
                return $name . ' (file never loaded)';
            }, $functions),
        ];
    }
}
ksort($rows);

$pct = static function (int $part, int $whole): string {
    return $whole === 0 ? '   n/a' : sprintf('%6.2f', 100 * $part / $whole);
};
$line = static function (string $text) use (&$output): void {
    $output .= $text . "\n";
};
$output = '';

$totals = static function (array $rows, string $prefix): array {
    $sum = ['lines' => 0, 'covered' => 0, 'functions' => 0, 'functions_run' => 0, 'functions_full' => 0, 'classes' => 0, 'classes_full' => 0, 'files' => 0];
    foreach ($rows as $path => $row) {
        if ($prefix !== '' && strpos($path, $prefix) !== 0) {
            continue;
        }
        ++$sum['files'];
        foreach (['lines', 'covered', 'functions', 'functions_run', 'functions_full', 'classes', 'classes_full'] as $key) {
            $sum[$key] += $row[$key];
        }
    }
    return $sum;
};

$line(sprintf('%-46s %8s %7s %11s %7s %9s', 'File', 'Lines', '%', 'Functions', 'run %', 'Classes'));
$line(str_repeat('-', 94));
foreach ($rows as $path => $row) {
    $line(sprintf(
        '%-46s %8s %7s %11s %7s %9s',
        $path,
        $row['covered'] . '/' . $row['lines'],
        $pct($row['covered'], $row['lines']),
        $row['functions_run'] . '/' . $row['functions'],
        $pct($row['functions_run'], $row['functions']),
        $row['classes'] === 0 ? '-' : $row['classes_full'] . '/' . $row['classes']
    ));
}
$line(str_repeat('-', 94));
foreach (['includes/' => 'includes/', 'templates/' => 'templates/', '' => 'Total'] as $prefix => $label) {
    $sum = $totals($rows, $prefix);
    $line(sprintf(
        '%-46s %8s %7s %11s %7s   (%d files; functions fully covered %s%%; classes %s)',
        $label,
        $sum['covered'] . '/' . $sum['lines'],
        $pct($sum['covered'], $sum['lines']),
        $sum['functions_run'] . '/' . $sum['functions'],
        $pct($sum['functions_run'], $sum['functions']),
        $sum['files'],
        trim($pct($sum['functions_full'], $sum['functions'])),
        $sum['classes'] === 0 ? 'none' : $sum['classes_full'] . '/' . $sum['classes']
    ));
}

$ranked = array_filter($rows, static function (array $row): bool {
    return $row['lines'] > 0;
});
uasort($ranked, static function (array $a, array $b): int {
    return ($a['covered'] / $a['lines']) <=> ($b['covered'] / $b['lines']) ?: $b['lines'] <=> $a['lines'];
});
$line('');
$line(sprintf('The %d files covered least:', $top));
foreach (array_slice($ranked, 0, $top, true) as $path => $row) {
    $line(sprintf('  %7s%%  %-46s %d lines never run', trim($pct($row['covered'], $row['lines'])), $path, $row['lines'] - $row['covered']));
}

$never = [];
foreach ($rows as $path => $row) {
    foreach ($row['never'] as $name) {
        $never[] = $path . '  ' . $name . '()';
    }
}
$line('');
$line(sprintf('Functions no test ran: %d', count($never)));
foreach ($never as $entry) {
    $line('  ' . $entry);
}

echo $output;
if ($out !== '') {
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0777, true);
    }
    file_put_contents($out, $output);
}

if ($min_lines !== null) {
    $sum = $totals($rows, 'includes/');
    $have = $sum['lines'] === 0 ? 0.0 : 100 * $sum['covered'] / $sum['lines'];
    if ($have < $min_lines) {
        fwrite(STDERR, sprintf("✗ includes/ lines covered: %.2f%%, below %.2f%%\n", $have, $min_lines));
        exit(1);
    }
}
