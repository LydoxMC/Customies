<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\CustomiesBlockFactory;
use customiesdevs\customies\item\CustomiesItemFactory;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use function count;
use function str_starts_with;

final class CustomiesListener implements Listener {
	/** @var BlockPaletteEntry[][] */
	private array $cachedBlockPalette = [];
	private Experiments $experiments;

	public function __construct() {
		$this->experiments = new Experiments([
			// "data_driven_items" is required for custom blocks to render in-game. With this disabled, they will be
			// shown as the UPDATE texture block.
			"data_driven_items" => true,
		], true);
	}

	public function onDataPacketSend(DataPacketSendEvent $event): void {
		$targets = $event->getTargets();
		if(count($targets) === 0){
			return;
		}
		$protocolId = null;
		foreach($targets as $target){
			if($target !== null){
				$protocolId = $target->getProtocolId();
				break;
			}
		}
		if($protocolId === null){
			return;
		}
		$packets = $event->getPackets();
		$sendLegacyItemRegistry = false;

		foreach($packets as $index => $packet){
			if($packet instanceof StartGamePacket) {
				if(!isset($this->cachedBlockPalette[$protocolId])) {
					// Wait for the data to be needed before it is actually cached. Allows for all blocks and items to be
					// registered before they are cached for the rest of the runtime.
					$this->cachedBlockPalette[$protocolId] = CustomiesBlockFactory::getInstance()->getBlockPaletteEntries($protocolId);
				}
				$packet->levelSettings->experiments = $this->experiments;
				$packet->blockPalette = $this->cachedBlockPalette[$protocolId];
				if($protocolId < ProtocolInfo::PROTOCOL_1_21_60){
					$sendLegacyItemRegistry = true;
				}
			} elseif($packet instanceof ItemRegistryPacket) {
				// >= 1.21.60 clients can consume the packet as-is. For older versions, encode a custom-only component list.
				if($protocolId < ProtocolInfo::PROTOCOL_1_21_60){
					$packets[$index] = ItemRegistryPacket::create($this->getLegacyRegistryEntries($protocolId));
				}
			} elseif($packet instanceof ResourcePackStackPacket) {
				$packet->experiments = $this->experiments;
			}
		}

		if($sendLegacyItemRegistry){
			$legacyEntries = $this->getLegacyRegistryEntries($protocolId);
			if(count($legacyEntries) > 0){
				$packets[] = ItemRegistryPacket::create($legacyEntries);
			}
		}

		$event->setPackets($packets);
	}

	/**
	 * @return ItemTypeEntry[]
	 */
	private function getLegacyRegistryEntries(int $protocolId): array{
		$entries = [];
		foreach(CustomiesItemFactory::getInstance()->getItemTableEntries() as $entry){
			if(!$entry->isComponentBased()){
				continue;
			}
			if(str_starts_with($entry->getStringId(), "minecraft:")){
				continue;
			}
			$entries[] = $this->mapEntryForProtocol($entry, $protocolId);
		}
		return $entries;
	}

	private function mapEntryForProtocol(ItemTypeEntry $entry, int $protocolId): ItemTypeEntry{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
			return $entry;
		}
		$root = clone $entry->getComponentNbt()->getRoot();
		if(!$root instanceof CompoundTag){
			return $entry;
		}

		$components = $root->getCompoundTag("components");
		$itemProperties = $components?->getCompoundTag("item_properties");
		$icon = $itemProperties?->getCompoundTag("minecraft:icon");
		$textures = $icon?->getCompoundTag("textures");
		$defaultTextureTag = $textures?->getTag("default");
		if($defaultTextureTag instanceof StringTag){
			$defaultTexture = $defaultTextureTag->getValue();
			$icon->setTag("texture", new StringTag($defaultTexture));
			$icon->removeTag("textures");
		}

		return new ItemTypeEntry(
			$entry->getStringId(),
			$entry->getNumericId(),
			$entry->isComponentBased(),
			$entry->getVersion(),
			new CacheableNbt($root)
		);
	}
}
