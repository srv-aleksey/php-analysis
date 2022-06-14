<?php

ini_set('memory_limit', '1024M');

const DEBUG = false;
const PHPSTAN_ENABLED = true;
const PHPCS_ENABLED = true;
const MAX_COLUMN_WIDTH = 100;

// Get changed lines
$dir = __DIR__;

$firstBranchCommitCmd = 'git rev-list master..HEAD | tail -1';
$firstBranchCommit = trim(`$firstBranchCommitCmd`);

if (DEBUG) {
	echo "RUN $firstBranchCommitCmd\n";
	echo "First commit of branch: $firstBranchCommit\n";
}

$parentBranchCommit = null;
if (!empty($firstBranchCommit)) {
	$parentBranchCommitCmd = "git log --pretty=%P -n 1 \"$firstBranchCommit\" | tail -1";
	$parentBranchCommit = trim(`$parentBranchCommitCmd`);

	if (DEBUG) {
		echo "RUN $parentBranchCommitCmd";
		echo "Branch parent commit: $firstBranchCommit\n";
	}
}

$committedChangesRes = null;
if (!empty($parentBranchCommit)) {
	$committedChangesCmd = "$dir/git-diff-changed-lines.sh $parentBranchCommit..@";
	$committedChangesRes = `$committedChangesCmd`;

	if (DEBUG) {
		echo "RUN $committedChangesCmd\n";
		echo "RESULT: $committedChangesRes\n";
	}
}

$notCommittedChangesCmd = "$dir/git-diff-changed-lines.sh @";
$notCommittedChangesRes = `$notCommittedChangesCmd`;

if (DEBUG) {
	echo "RUN $notCommittedChangesCmd\n";
	echo "RESULT: $notCommittedChangesRes\n";
}

function prepareGitChangesResults($changes): array
{
	if (empty($changes)) {
		return [];
	}
	$tmp = preg_replace_callback('/\d(\n)\d/ium', function ($item) {
		return str_replace("\n", ',', $item[0]);
	}, $changes);
	$filesLines = explode("\n", $tmp);

	$linesByFile = [];
	foreach ($filesLines as $filesLine) {
		if (empty($filesLine)) {
			continue;
		}
		$data = explode(':', $filesLine);
		if (isset($data[1])) {
			if (preg_match('/.php$/iu', $data[0])) {
				$linesByFile[$data[0]] = explode(',', $data[1]);
			}
		}
	}

	return $linesByFile;
}

$committedChangedLinesByFile = prepareGitChangesResults($committedChangesRes);
$notCommittedChangedLinesByFile = prepareGitChangesResults($notCommittedChangesRes);
$changedLinesByFile = $committedChangedLinesByFile;
foreach ($notCommittedChangedLinesByFile as $file => $lines) {
	$changedLinesByFile[$file] = array_unique(array_merge(isset($changedLinesByFile[$file]) ?: [], $lines));
}

if (DEBUG) {
	echo "Found changed lines:\n";
	foreach ($changedLinesByFile as $file => $lines) {
		echo "$file:\n" . implode(',', $lines) . "\n";
	}
	echo "\n";
}
// END Get changed lines

if (count($changedLinesByFile) === 0) {
	echo "Changed lines not found.\n";
	echo "All Ok =)\n";
	die();
}

$changedFiles = array_keys($changedLinesByFile);
$filesPaths = [];
foreach ($changedFiles as $file) {
	$filesPaths[] = './' . $file;
}

// Get phpstan errors
$phpstanErrors = [];
if (PHPSTAN_ENABLED) {
	$phpstanCmdParams = [
		'analyse',
		'--error-format=prettyJson',
	];
	foreach ($filesPaths as $path) {
		$phpstanCmdParams[] = $path;
	}
	$phpstanCmdParamsString = implode(' ', $phpstanCmdParams);
	$phpstanCmd = "~/.config/composer/vendor/bin/phpstan $phpstanCmdParamsString";
	$phpstanCmdRes = `$phpstanCmd`;
	$phpstanCmdResArr = json_decode($phpstanCmdRes, true);

	if (DEBUG) {
		echo "RUN $phpstanCmd\n";
		echo "RESULT:\n";
		echo var_export($phpstanCmdResArr, true);
		echo "\n\n";
	}

	foreach ($phpstanCmdResArr['files'] as $filePath => $fileData) {
		$rootFilePath = str_replace(dirname(__DIR__) . '/', '', $filePath);

		if (isset($changedLinesByFile[$rootFilePath])) {
			$phpstanErrors[$rootFilePath] = [];

			$diffLines = $changedLinesByFile[$rootFilePath];
			$fileRes = [];
			foreach ($fileData['messages'] as $lineData) {
				if (in_array($lineData['line'], $diffLines)) {
					$phpstanErrors[$rootFilePath][] = $lineData;
				}
			}
		}
	}
}
// END Get phpstan errors

// Get phpcs errors
$phpcsErrors = [];
if (PHPCS_ENABLED) {
	$phpcsCmdParams = [
		'--standard=./phpcs.xml',
		'--encoding=utf-8',
		'--report=json',
	];
	foreach ($filesPaths as $path) {
		$phpcsCmdParams[] = $path;
	}
	$phpcsCmdParamsString = implode(' ', $phpcsCmdParams);
	$phpcsCmd = "~/.config/composer/vendor/bin/phpcs $phpcsCmdParamsString";
	$phpcsCmdRes = `$phpcsCmd`;
	$phpcsCmdResArr = json_decode($phpcsCmdRes, true);

	if (DEBUG) {
		echo "RUN $phpcsCmd\n";
		echo "RESULT:\n";
		echo var_export($phpcsCmdResArr, true);
		echo "\n\n";
	}

	foreach ($phpcsCmdResArr['files'] as $filePath => $fileData) {
		$rootFilePath = str_replace(dirname(__DIR__) . '/', '', $filePath);

		if (isset($changedLinesByFile[$rootFilePath])) {
			$phpcsErrors[$rootFilePath] = [];

			$diffLines = $changedLinesByFile[$rootFilePath];
			$fileRes = [];
			foreach ($fileData['messages'] as $lineData) {
				if (in_array($lineData['line'], $diffLines)) {
					$phpcsErrors[$rootFilePath][] = $lineData;
				}
			}
		}
	}
}
// END Get phpcs errors

// Render
$hasErrors = false;
foreach ($changedLinesByFile as $file => $lines) {
	$hasFileErrors = false;
	$res = '';
	if (PHPCS_ENABLED && isset($phpcsErrors[$file]) && count($phpcsErrors[$file]) > 0) {
		$hasErrors = true;
		$hasFileErrors = true;
		$data = [];
		foreach ($phpcsErrors[$file] as $error) {
			$data[] = [
				'line' => $error['line'] . ':' . $error['column'],
				'message' => $error['message']
			];
		}
		ob_start();
		renderTable('phpcs:', $data, ['line', 'message']);
		$res .= ob_get_clean();
	}

	if (PHPSTAN_ENABLED && isset($phpstanErrors[$file]) && count($phpstanErrors[$file]) > 0) {
		$hasErrors = true;
		$hasFileErrors = true;
		ob_start();
		renderTable('phpstan:', $phpstanErrors[$file], ['line', 'message']);
		$res .= ob_get_clean();
	}

	if ($hasFileErrors) {
		echo "\n$file:\n";
		echo str_repeat('-', strlen($file) + 1) . "\n";
		echo $res;
		echo "\n";
	}
}
if (!$hasErrors) {
	echo "All Ok =)\n";
}

function renderTable(string $title, array $data, array $columns, string $columnSeparator = '    '): void
{
	$rows = [];
	$columnsLengths = [];
	foreach ($columns as $column) {
		$columnsLengths[$column] = 0;
	}

	foreach ($data as $item) {
		$row = [];
		$subRows = [];
		foreach ($columns as $column) {
			$columnVal = $item[$column];
			$maxLen = strlen($columnVal);
			if (strlen($columnVal) > MAX_COLUMN_WIDTH) {
				$columnValArr = explode("\n", wordwrap($columnVal, MAX_COLUMN_WIDTH));
				$columnVal = array_shift($columnValArr);
				$maxLen = strlen($columnVal);
				foreach ($columnValArr as $subRowKey => $subRow) {
					$subRows[$subRowKey][$column] = $subRow;
					$maxLen = max($maxLen, strlen($subRow));
				}
			}

			$columnVal = ' ' . $columnVal . ' ';
			$columnsLengths[$column] = max($columnsLengths[$column], $maxLen + 2);

			$row[$column] = $columnVal;
		}
		$rows[] = $row;

		foreach ($subRows as $subRowData) {
			$row = [];
			foreach ($columns as $column) {
				if (!isset($subRowData[$column])) {
					$row[$column] = str_repeat(' ', $columnsLengths[$column]);
					continue;
				}

				$row[$column] = ' ' . $subRowData[$column] . ' ';
			}
			$rows[] = $row;
		}
	}

	$rowsRes = [];
	foreach ($rows as $row) {
		$rowRes = [];
		foreach ($row as $column => $item) {
			$rowRes[] = str_pad($item, $columnsLengths[$column]);
		}
		$rowsRes[] = implode($columnSeparator, $rowRes);
	}
	$rows = $rowsRes;

	$borderArr = [];
	foreach ($columnsLengths as $length) {
		$borderArr[] = str_repeat('-', $length);
	}
	$border = implode($columnSeparator, $borderArr);

	echo "$title\n";
	echo "$border\n";
	echo implode("\n", $rows) . "\n";
	echo "$border\n";
}
