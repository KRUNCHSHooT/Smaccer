<?php

declare(strict_types=1);

namespace aiptu\smaccer\entity\npc;

use aiptu\smaccer\entity\EntityAgeable;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class PolarBearSmaccer extends EntityAgeable {
	protected function getInitialSizeInfo() : EntitySizeInfo {
		return new EntitySizeInfo($this->getHeight(), $this->getWidth());
	}

	public function getHeight() : float {
		return $this->isBaby() ? 0.7 : 1.4;
	}

	public function getWidth() : float {
		return $this->isBaby() ? 0.7 : 1.4;
	}

	public static function getNetworkTypeId() : string {
		return EntityIds::POLAR_BEAR;
	}

	public function getName() : string {
		return 'Polar Bear';
	}
}