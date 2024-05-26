<?php

declare(strict_types=1);

namespace aiptu\smaccer\entity\npc;

use aiptu\smaccer\entity\EntitySmaccer;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class ElderGuardianSmaccer extends EntitySmaccer {
	protected function getInitialSizeInfo() : EntitySizeInfo {
		return new EntitySizeInfo($this->getHeight(), $this->getWidth());
	}

	public function getHeight() : float {
		return 1.99;
	}

	public function getWidth() : float {
		return 1.99;
	}

	public static function getNetworkTypeId() : string {
		return EntityIds::ELDER_GUARDIAN;
	}

	public function getName() : string {
		return 'Elder Guardian';
	}
}