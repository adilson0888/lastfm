<?php

declare(strict_types=1);

// Standalone smoke test for the pure logic (no network).

require_once __DIR__ . '/../src/Library.php';
require_once __DIR__ . '/../src/Text.php';

function check(string $label, bool $ok): void
{
    echo ($ok ? "PASS" : "FAIL") . " - $label\n";
}

// Sections partition the library and never overlap or exceed the total.
$sections = Library::sections(1000);
check('sections count', count($sections) === 5);
check('classics window', $sections['classics'] === [1, 50]);
check('insane ends at total', $sections['insane'][1] === 1000);
$prevEnd = 0;
$ok = true;
foreach ($sections as [$start, $end]) {
    if ($start !== $prevEnd + 1 || $end < $start) {
        $ok = false;
    }
    $prevEnd = $end;
}
check('sections are contiguous', $ok);

// Random picks stay in range and are distinct.
$picks = Library::randomRanks(1, 50, 7);
check('random count', count($picks) === 7);
check('random in range', $picks === array_filter($picks, fn ($n) => $n >= 1 && $n <= 50));
check('random distinct', count($picks) === count(array_unique($picks)));

// Requesting more than the window yields the whole window, no duplicates.
$all = Library::randomRanks(1, 3, 10);
check('random clamps to window', count($all) === 3 && count(array_unique($all)) === 3);

// Tiny libraries still produce valid, non-empty windows.
$tiny = Library::sections(1);
check('tiny library classics', $tiny['classics'] === [1, 1]);

// Accent handling for the tasker deep link.
check('strip accents', Text::stripAccents('Björk') === 'Bjork');
$link = Text::artistDeepLink('tasker', 'Sigur Rós');
check('deep link shape', str_starts_with($link, 'tasker://Sigur'));

// Section names match the front-end slider ids.
check('section names', Library::sectionNames() === ['classics', 'known', 'adventurous', 'wild', 'insane']);
