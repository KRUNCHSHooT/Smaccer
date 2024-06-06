<?php

declare(strict_types=1);

namespace aiptu\smaccer\entity\npc;

use aiptu\smaccer\entity\EntitySmaccer;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class IronGolemSmaccer extends EntitySmaccer {
	protected function getInitialSizeInfo() : EntitySizeInfo {
		return new EntitySizeInfo($this->getHeight(), $this->getWidth());
	}

	public function getHeight() : float {
		return 2.9;
	}

	public function getWidth() : float {
		return 1.4;
	}

	public static function getNetworkTypeId() : string {
		return EntityIds::IRON_GOLEM;
	}

	public function getName() : string {
		return 'Iron Golem';
	}
}
