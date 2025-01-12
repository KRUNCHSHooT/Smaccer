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

namespace aiptu\smaccer\entity;

use aiptu\libsounds\SoundInstance;
use aiptu\smaccer\entity\command\CommandHandler;
use aiptu\smaccer\entity\emote\EmoteTypes;
use aiptu\smaccer\entity\utils\EntityTag;
use aiptu\smaccer\entity\utils\EntityVisibility;
use aiptu\smaccer\Smaccer;
use aiptu\smaccer\utils\Permissions;
use aiptu\smaccer\utils\Queue;
use pocketmine\color\Color;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\particle\DustParticle;
use function array_map;
use function cos;
use function deg2rad;
use function microtime;
use function mt_rand;
use function round;
use function sin;
use function str_replace;
use function strtolower;

class HumanSmaccer extends Human {
	protected string $creator;
	protected EntityVisibility $visibility = EntityVisibility::VISIBLE_TO_EVERYONE;
	protected CommandHandler $commandHandler;
	protected bool $rotateToPlayers = true;
	protected bool $slapBack = true;
	protected ?EmoteTypes $actionEmoteId = null;
	protected ?EmoteTypes $emoteId = null;

	protected array $emoteCooldowns = [];
	protected array $actionEmoteCooldowns = [];
	protected array $commandCooldowns = [];

	private const RADIUS = 0.8;
	private const ANGLE_INCREMENT = 0.09;
	private const PARTICLE_Y_MULTIPLIER = 2.0;

	public function __construct(Location $location, Skin $skin, ?CompoundTag $nbt = null) {
		if ($nbt instanceof CompoundTag) {
			$this->creator = $nbt->getString(EntityTag::CREATOR);

			$this->commandHandler = new CommandHandler($nbt);
		}

		parent::__construct($location, $skin, $nbt);
	}

	protected function initEntity(CompoundTag $nbt) : void {
		parent::initEntity($nbt);

		$this->setScale($nbt->getFloat(EntityTag::SCALE, 1.0));
		$this->setRotateToPlayers((bool) $nbt->getByte(EntityTag::ROTATE_TO_PLAYERS, 1));
		$this->setNameTagAlwaysVisible((bool) $nbt->getByte(EntityTag::NAMETAG_VISIBLE, 1));
		$this->setNameTagVisible((bool) $nbt->getByte(EntityTag::NAMETAG_VISIBLE, 1));
		$this->setVisibility(EntityVisibility::fromInt($nbt->getInt(EntityTag::VISIBILITY, EntityVisibility::VISIBLE_TO_EVERYONE->value)));

		$this->setSlapBack((bool) $nbt->getByte(EntityTag::SLAP_BACK, 1));

		$this->actionEmoteId = $nbt->getTag(EntityTag::ACTION_EMOTE) instanceof StringTag ? EmoteTypes::fromUUID($nbt->getString(EntityTag::ACTION_EMOTE)) : null;
		$this->emoteId = $nbt->getTag(EntityTag::EMOTE) instanceof StringTag ? EmoteTypes::fromUUID($nbt->getString(EntityTag::EMOTE)) : null;
	}

	public function saveNBT() : CompoundTag {
		$nbt = parent::saveNBT();

		$nbt->setString(EntityTag::CREATOR, $this->creator);
		$nbt->setFloat(EntityTag::SCALE, $this->scale);
		$nbt->setByte(EntityTag::ROTATE_TO_PLAYERS, (int) $this->rotateToPlayers);
		$nbt->setByte(EntityTag::NAMETAG_VISIBLE, (int) $this->isNameTagVisible());
		$nbt->setInt(EntityTag::VISIBILITY, $this->visibility->value);

		$nbt->setByte(EntityTag::SLAP_BACK, (int) $this->slapBack);

		if ($this->actionEmoteId !== null) {
			$nbt->setString(EntityTag::ACTION_EMOTE, $this->actionEmoteId->value);
		}

		if ($this->emoteId !== null) {
			$nbt->setString(EntityTag::EMOTE, $this->emoteId->value);
		}

		$commands = array_map(function ($commandData) {
			$commandTag = CompoundTag::create();
			$commandTag->setString(CommandHandler::KEY_COMMAND, $commandData[CommandHandler::KEY_COMMAND]);
			$commandTag->setString(CommandHandler::KEY_TYPE, $commandData[CommandHandler::KEY_TYPE]);
			return $commandTag;
		}, $this->commandHandler->getAll());

		$listTag = new ListTag($commands, NBT::TAG_Compound);
		$nbt->setTag(EntityTag::COMMANDS, $listTag);

		return $nbt;
	}

	public function getName() : string {
		return $this->nameTag !== '' ? $this->nameTag : 'Human';
	}

	public function getVisibility() : EntityVisibility {
		return $this->visibility;
	}

	public function setVisibility(EntityVisibility $visibility) : void {
		$this->visibility = $visibility;

		switch ($visibility) {
			case EntityVisibility::VISIBLE_TO_EVERYONE:
				$this->spawnToAll();
				break;
			case EntityVisibility::VISIBLE_TO_CREATOR:
				$creator = $this->getCreator();
				if ($creator !== null) {
					$this->despawnFromAll();
					$this->spawnTo($creator);
				}

				break;
			case EntityVisibility::INVISIBLE_TO_EVERYONE:
				$this->despawnFromAll();
				break;
		}
	}

	public function setActionEmoteId(?EmoteTypes $actionEmoteId) : void {
		$this->actionEmoteId = $actionEmoteId;
	}

	public function getActionEmoteId() : ?EmoteTypes {
		return $this->actionEmoteId;
	}

	public function setEmoteId(?EmoteTypes $emoteId) : void {
		$this->emoteId = $emoteId;
	}

	public function getEmoteId() : ?EmoteTypes {
		return $this->emoteId;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool {
		$hasUpdate = parent::entityBaseTick($tickDiff);

		$entityPos = $this->getPosition();
		$entityWorld = $entityPos->getWorld();
		$entityScale = $this->getScale();

		$angle = $this->ticksLived / self::ANGLE_INCREMENT;
		$offsetX = cos(deg2rad($angle)) * self::RADIUS;
		$offsetZ = sin(deg2rad($angle)) * self::RADIUS;
		$offsetY = self::PARTICLE_Y_MULTIPLIER * $entityScale;

		$particle1Pos = $entityPos->add(-$offsetX, $offsetY, -$offsetZ);
		$particle2Pos = $entityPos->add(-$offsetZ, $offsetY, -$offsetX);

		$particle1 = new DustParticle($this->getRandomColor());
		$particle2 = new DustParticle($this->getRandomColor());

		$entityWorld->addParticle($particle1Pos, $particle1);
		$entityWorld->addParticle($particle2Pos, $particle2);

		if ($this->emoteId !== null && Smaccer::getInstance()->getDefaultSettings()->isEmoteCooldownEnabled()) {
			if ($this->handleEmoteCooldown($this->emoteId->value)) {
				$this->broadcastEmote($this->emoteId->value);
				$hasUpdate = true;
			}
		} elseif ($this->emoteId !== null) {
			$this->broadcastEmote($this->emoteId->value);
			$hasUpdate = true;
		}

		return $hasUpdate;
	}

	private function getRandomColor() : Color {
		return new Color(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
	}

	public function spawnTo(Player $player) : void {
		if ($this->visibility === EntityVisibility::INVISIBLE_TO_EVERYONE) {
			return;
		}

		parent::spawnTo($player);
	}

	public function attack(EntityDamageEvent $source) : void {
		if ($this->visibility === EntityVisibility::INVISIBLE_TO_EVERYONE || !($source instanceof EntityDamageByEntityEvent)) {
			return;
		}

		$damager = $source->getDamager();
		if ($damager instanceof Player) {
			$npcId = $this->getId();
			$playerName = $damager->getName();
			if (Queue::isInQueue($playerName, Queue::ACTION_RETRIEVE)) {
				$damager->sendMessage(TextFormat::GREEN . 'NPC Entity ID: ' . $npcId);
				Queue::removeFromQueue($playerName, Queue::ACTION_RETRIEVE);
			} elseif (Queue::isInQueue($playerName, Queue::ACTION_DELETE)) {
				if (!$this->isOwnedBy($damager) && !$damager->hasPermission(Permissions::COMMAND_DELETE_OTHERS)) {
					$damager->sendMessage(TextFormat::RED . "You don't have permission to delete this entity!");
					return;
				}

				SmaccerHandler::getInstance()->despawnNPC($damager, $this);
				Queue::removeFromQueue($playerName, Queue::ACTION_DELETE);
			}
		}

		$source->cancel();
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool {
		if ($this->visibility === EntityVisibility::INVISIBLE_TO_EVERYONE) {
			return false;
		}

		if ($this->canExecuteCommands($player)) {
			$this->executeCommands($player);
		}

		if ($this->canSlapBack()) {
			$this->broadcastAnimation(new ArmSwingAnimation($this));
		}

		if (Smaccer::getInstance()->getDefaultSettings()->isActionEmoteCooldownEnabled()) {
			if ($this->actionEmoteId !== null && $this->handleActionEmoteCooldown($this->actionEmoteId->value)) {
				$this->broadcastEmote($this->actionEmoteId->value, [$player]);
			}
		} else {
			if ($this->actionEmoteId !== null) {
				$this->broadcastEmote($this->actionEmoteId->value, [$player]);
			}
		}

		return true;
	}

	private function canExecuteCommands(Player $player) : bool {
		$plugin = Smaccer::getInstance();
		$settings = $plugin->getDefaultSettings();
		$cooldownEnabled = $settings->isCommandCooldownEnabled();
		$cooldown = $settings->getCommandCooldownValue();

		if ($player->hasPermission(Permissions::BYPASS_COOLDOWN)) {
			return true;
		}

		if ($cooldownEnabled && $cooldown > 0) {
			$playerName = strtolower($player->getName());
			$npcId = $this->getId();
			$lastHitTime = $this->commandCooldowns[$playerName][$npcId] ?? 0.0;
			$currentTime = microtime(true);
			$remainingCooldown = ($cooldown + $lastHitTime) - $currentTime;

			if ($remainingCooldown > 0) {
				$player->sendMessage(TextFormat::RED . 'Please wait ' . round($remainingCooldown, 1) . ' seconds before interacting again.');
				return false;
			}

			$this->commandCooldowns[$playerName][$npcId] = $currentTime;
		}

		return true;
	}

	private function executeCommands(Player $player) : void {
		$commands = $this->commandHandler->getAll();
		$playerName = $player->getName();

		foreach ($commands as $commandData) {
			$command = str_replace('{player}', '"' . $playerName . '"', $commandData[CommandHandler::KEY_COMMAND]);
			$this->dispatchCommand($player, $command, $commandData[CommandHandler::KEY_TYPE]);
		}
	}

	private function dispatchCommand(Player $player, string $command, string $type) : void {
		$plugin = Smaccer::getInstance();
		$server = $plugin->getServer();
		$commandMap = $server->getCommandMap();

		match ($type) {
			EntityTag::COMMAND_TYPE_SERVER => $commandMap->dispatch(new ConsoleCommandSender($server, $server->getLanguage()), $command),
			EntityTag::COMMAND_TYPE_PLAYER => $commandMap->dispatch($player, $command),
			default => throw new \InvalidArgumentException("Invalid command type: {$type}")
		};
	}

	private function handleEmoteCooldown(string $emoteId) : bool {
		$currentTime = microtime(true);
		if (isset($this->emoteCooldowns[$emoteId]) && ($currentTime - $this->emoteCooldowns[$emoteId]) < Smaccer::getInstance()->getDefaultSettings()->getEmoteCooldownValue()) {
			return false;
		}

		$this->emoteCooldowns[$emoteId] = $currentTime;
		return true;
	}

	private function handleActionEmoteCooldown(string $actionEmoteId) : bool {
		$currentTime = microtime(true);
		if (isset($this->actionEmoteCooldowns[$actionEmoteId]) && ($currentTime - $this->actionEmoteCooldowns[$actionEmoteId]) < Smaccer::getInstance()->getDefaultSettings()->getActionEmoteCooldownValue()) {
			return false;
		}

		$this->actionEmoteCooldowns[$actionEmoteId] = $currentTime;
		return true;
	}

	public function broadcastEmote(string $emoteId, ?array $targets = null) : void {
		NetworkBroadcastUtils::broadcastPackets($targets ?? $this->getViewers(), [
			EmotePacket::create($this->getId(), $emoteId, '', '', EmotePacket::FLAG_MUTE_ANNOUNCEMENT),
		]);
	}

	public function addSound(SoundInstance $soundInstance) : void {
		$this->broadcastSound($soundInstance);
	}

	public function getCreatorId() : string {
		return $this->creator;
	}

	public function getCreator() : ?Player {
		return Server::getInstance()->getPlayerByRawUUID($this->creator);
	}

	public function isOwnedBy(Player $player) : bool {
		return $player->getUniqueId()->getBytes() === $this->creator;
	}

	public function getCommandHandler() : CommandHandler {
		return $this->commandHandler;
	}

	public function setRotateToPlayers(bool $value = true) : void {
		$this->rotateToPlayers = $value;
	}

	public function canRotateToPlayers() : bool {
		return $this->rotateToPlayers;
	}

	public function setSlapBack(bool $value = true) : void {
		$this->slapBack = $value;
	}

	public function canSlapBack() : bool {
		return $this->slapBack;
	}

	public function setArmor(Player $source) : void {
		$armorContents = $source->getArmorInventory()->getContents();
		$this->getArmorInventory()->setContents($armorContents);
	}

	public function setHelmet(Item|Player $source) : void {
		$item = $source instanceof Player ? $source->getArmorInventory()->getHelmet() : $source;
		$this->getArmorInventory()->setHelmet($item);
	}

	public function setChestplate(Item|Player $source) : void {
		$item = $source instanceof Player ? $source->getArmorInventory()->getChestplate() : $source;
		$this->getArmorInventory()->setChestplate($item);
	}

	public function setLeggings(Item|Player $source) : void {
		$item = $source instanceof Player ? $source->getArmorInventory()->getLeggings() : $source;
		$this->getArmorInventory()->setLeggings($item);
	}

	public function setBoots(Item|Player $source) : void {
		$item = $source instanceof Player ? $source->getArmorInventory()->getBoots() : $source;
		$this->getArmorInventory()->setBoots($item);
	}

	public function setOffHandItem(Item|Player $source) : void {
		$offHandItem = $source instanceof Player ? $source->getOffHandInventory()->getItem(0) : $source;
		$this->getOffHandInventory()->setItem(0, $offHandItem);
	}

	public function setItemInHand(Item|Player $source) : void {
		$itemInHand = $source instanceof Player ? $source->getInventory()->getItemInHand() : $source;
		$this->getInventory()->setItemInHand($itemInHand);
	}

	public function getArmor() : array {
		return $this->getArmorInventory()->getContents();
	}

	public function getHelmet() : Item {
		return $this->getArmorInventory()->getHelmet();
	}

	public function getChestplate() : Item {
		return $this->getArmorInventory()->getChestplate();
	}

	public function getLeggings() : Item {
		return $this->getArmorInventory()->getLeggings();
	}

	public function getBoots() : Item {
		return $this->getArmorInventory()->getBoots();
	}

	public function getOffHandItem() : Item {
		return $this->getOffHandInventory()->getItem(0);
	}

	public function getItemInHand() : Item {
		return $this->getInventory()->getItemInHand();
	}
}
