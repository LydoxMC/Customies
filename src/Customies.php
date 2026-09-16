<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\BlockPalette;
use customiesdevs\customies\block\CustomiesBlockFactory;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Customies {
	private static bool $initialized = false;

	/**
	 * Initialize Customies as a library within a host plugin.
	 * Call this from your plugin's onEnable() AFTER registering all custom blocks/items.
	 */
	public static function init(PluginBase $plugin): void {
		if(self::$initialized){
			return;
		}
		self::$initialized = true;

		$plugin->getServer()->getPluginManager()->registerEvents(new CustomiesListener(), $plugin);

		$cachePath = $plugin->getDataFolder() . "customies_idcache";
		$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($cachePath): void {
			BlockPalette::getInstance()->sortStates();
			CustomiesBlockFactory::getInstance()->addWorkerInitHook($cachePath);
		}), 0);
	}
}
