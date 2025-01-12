<?php

/*
 * Copyright (c) 2024 AIPTU
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/AIPTU/Smaccer
 */

declare(strict_types=1);

namespace aiptu\smaccer\utils;

use InvalidArgumentException;
use function array_filter;
use function array_map;
use function implode;
use function preg_replace;
use function preg_split;
use function str_replace;
use const PREG_SPLIT_NO_EMPTY;

class Utils {
	/**
	 * Extracts the class name and converts it to a namespace format.
	 */
	public static function getClassNamespace(string $className) : array {
		// Extract the class name without the "::class" suffix
		$classNameWithoutSuffix = preg_replace('/(::class)$/', '', $className);
		if ($classNameWithoutSuffix === null) {
			throw new InvalidArgumentException('Invalid class name format');
		}

		// Remove "Smaccer" from the class name
		$classNameWithoutSmaccer = str_replace('Smaccer', '', $classNameWithoutSuffix);

		// Split the class name into parts based on consecutive uppercase letters
		$parts = preg_split('/(?=[A-Z][a-z])/', $classNameWithoutSmaccer, -1, PREG_SPLIT_NO_EMPTY);

		// Check if preg_split was successful
		if ($parts === false) {
			throw new InvalidArgumentException('Invalid class name format');
		}

		// Remove any empty parts
		$parts = array_filter($parts);

		// Convert the parts to lowercase and join them with a colon
		$namespace = implode(':', array_map('strtolower', $parts));

		// Add a "smaccer:" prefix
		$result = 'smaccer:' . $namespace;

		return [$classNameWithoutSuffix, $result];
	}
}
