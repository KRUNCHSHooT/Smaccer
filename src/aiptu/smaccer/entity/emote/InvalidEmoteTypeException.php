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

namespace aiptu\smaccer\entity\emote;

class InvalidEmoteTypeException extends \Exception {
	public function __construct(string $value) {
		parent::__construct("Invalid emote type: {$value}");
	}
}
