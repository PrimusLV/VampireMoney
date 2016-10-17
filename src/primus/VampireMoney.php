<?php
namespace primus;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class VampireMoney extends PluginBase implements Listener {

	const SAFE 	= "vmoney.safe";
	const STEAL = "vmoney.steal";

	/** @var EconomyManager */
	private $economy;

	public function onEnable() {
		$this->economy = new EconomyManager($this);
		if (!$this->economy->isValid()) {
			$this->getLogger()->critical("Please install one of following Economy plugins: EconomyAPI, PocketMoney, GoldStd, MassiveEconomy.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
		$this->getLogger()->info(TextFormat::GREEN . "Enabled.");
	}

	public function onDisable() {
		$this->getServer()->getLogger()->info(TextFormat::RED . "Disabled.");
	}

	//
	//
	// API FUNCTIONS
	//
	//

	/**
	 * @param Player $player
	 * @return bool
	 */
	public function canStealFrom(Player $player) : bool {
		return !$player->hasPermission(self::SAFE);
	}

	/**
	 * @param Player $player
	 * @return bool
	 */
	public function canSteal(Player $player) : bool {
		return $player->hasPermission(self::STEAL);
	}

	public function processDamage(Player $attacker, Player $victim, int $damage) {
		if (!$this->canSteal($attacker)) return;
		if (!$this->canStealFrom($victim)) return;
		$sum = $damage * (int) $this->getConfig()->get("amplifier", 1);
		if ($sum <= 0 or !is_int($sum)) {
			$this->getLogger()->warning("Config key `amplifier` is configured incorrectly! Make sure it's value is 32 bit signed integer. (1 - 2147483647)");
			return;
		}
		// If victim doesn't have enough money and server doesn't want to leave him with negative value...
		if ( (($vicMoney = $this->economy->getMoney($victim)) < $sum) && !((bool) $this->getConfig()->get("force-steal", false)) ) {
			// Take from victim all he have to left him with zero
			$sum = $vicMoney;
		}
		$attMoney = $this->getMoney($attacker);
		// Process safe money giving and taking
		$this->economy->takeMoney($victim, $sum);
		$this->economy->giveMoney($attacker, $sum);

		if ((bool) $this->getConfig()->get("safe-mode", true)) {
			if ( (($this->economy->getMoney($victim) + $sum) !== $vicMoney) or (($this->economy->getMoney($attacker) - $sum !== $attMoney))) {
				// Something wen't terrible wrong :/
				$this->economy->setMoney($victim, $vicMoney);
				$this->economy->setMoney($attacker, $attMoney);
				$this->getLogger()->warning("Safe mode detected disfunction in money transfer process, make sure no other plugin is causing this before reporting bug.");
				return;
			}
		}
		$attacker->sendTip(TextFormat::GREEN . "+" . $this->economy->formatMoney($sum));
		$victim->sendTip(TextFormat::RED . "-" . $this->economy->formatMoney($sum));
	}

	//
	//
	// LISTENER
	//
	//

	/**
	 * @param EntityDamageEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerCombat(EntityDamageEvent $event) {
		if (!($event instanceof EntityDamageByEntityEvent)) return;
		// OMG TV IS TAKING MY ATTENTION AWAY...
		$victim = $event->getEntity();
		if (!($victim instanceof Player)) return;
		$attacker = $event->getDamager();
		if (!($victim instanceof Player)) return;
		$this->processDamage($victim, $attacker, (int) $event->getDamage());
	}

}