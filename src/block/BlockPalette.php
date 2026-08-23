<?php
declare(strict_types=1);

namespace customiesdevs\customies\block;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use ReflectionProperty;
use RuntimeException;
use function array_keys;
use function array_merge;
use function count;
use function hash;
use function hexdec;
use function ksort;
use function property_exists;
use function strcmp;
use function usort;

final class BlockPalette {
	use SingletonTrait;

	/** @var BlockStateDictionaryEntry[][] */
	private array $states = [];
	/** @var BlockStateDictionaryEntry[] */
	private array $customStates = [];
	/** @var BlockStateDictionaryEntry[][] */
	private array $pendingInsert = [];

	/** @var BlockTranslator[] */
	private array $translator = [];
	/** @var ReflectionProperty[] */
	private array $bedrockKnownStates = [];
	/** @var ReflectionProperty[] */
	private array $stateDataToStateIdLookup = [];
	/** @var ReflectionProperty[] */
	private array $idMetaToStateIdLookupCache = [];
	/** @var ReflectionProperty[] */
	private array $fallbackStateId = [];
	/** @var ReflectionProperty[] */
	private array $networkIdCache = [];
	/** @var bool[] */
	private array $networkIdsAreHashes = [];

	/**
	 * Protocols whose dictionary already has the custom states merged in.
	 * @var bool[]
	 */
	private array $merged = [];

	/**
	 * Each TypeConverter owns a full block state dictionary (~15k entries), so touching every accepted
	 * protocol up front costs that much memory on every thread that has a palette - including each async
	 * pool worker. Only the converters that actually get built are tracked, and any built later is caught
	 * by the creation listener and merged on the spot.
	 */
	public function __construct() {
		foreach(TypeConverter::getAll() as $converter){
			$this->track($converter);
		}
		TypeConverter::addCreationListener(function(TypeConverter $converter): void {
			if($this->track($converter) && $this->customStates !== []){
				$this->merge($converter->getProtocolId());
			}
		});
	}

	private function track(TypeConverter $converter): bool {
		$protocolId = $converter->getProtocolId();
		if(isset($this->states[$protocolId])){
			return false;
		}
		try{
			$blockTranslator = $converter->getBlockTranslator();
		}catch(\Throwable){
			// Some protocol IDs in forks may not have converter assets loaded; skip those safely.
			return false;
		}
		$this->translator[$protocolId] = $blockTranslator;
		$dictionary = $blockTranslator->getBlockStateDictionary();
		$this->states[$protocolId] = $dictionary->getStates();
		$this->bedrockKnownStates[$protocolId] = new ReflectionProperty($dictionary, "states");
		$this->stateDataToStateIdLookup[$protocolId] = new ReflectionProperty($dictionary, "stateDataToStateIdLookup");
		$this->idMetaToStateIdLookupCache[$protocolId] = new ReflectionProperty($dictionary, "idMetaToStateIdLookupCache");
		$this->fallbackStateId[$protocolId] = new ReflectionProperty($blockTranslator, "fallbackStateId");
		$this->networkIdCache[$protocolId] = new ReflectionProperty($blockTranslator, "networkIdCache");
		$this->networkIdsAreHashes[$protocolId] = $dictionary->networkIdsAreHashes();
		return true;
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getStates(int $mappingProtocol): array {
		return $this->states[$mappingProtocol];
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getCustomStates(): array {
		return $this->customStates;
	}

	/**
	 * Inserts the provided state in to the correct position of the palette.
	 * Sorting is deferred to sortStates() which must be called after all blocks are registered.
	 */
	public function insertState(CompoundTag $state, int $meta = 0): void {
		if(($name = $state->getString(BlockStateData::TAG_NAME, "")) === "") {
			throw new RuntimeException("Block state must contain a StringTag called 'name'");
		}
		if(($properties = $state->getCompoundTag(BlockStateData::TAG_STATES)) === null) {
			throw new RuntimeException("Block state must contain a CompoundTag called 'states'");
		}
		$entry = new BlockStateDictionaryEntry($name, $properties->getValue(), $meta, null);
		$this->pendingInsert[$name][] = $entry;
		$this->customStates[] = $entry;
	}

	/**
	 * Sorts the palette's block states in the correct order for all protocols.
	 * Must be called after all blocks have been registered.
	 */
	public function sortStates(): void {
		CustomiesBlockFactory::getInstance()->sort();
		if(count($this->pendingInsert) === 0){
			// No custom blocks registered — nothing to merge, skip dictionary rewrite.
			return;
		}
		foreach(array_keys($this->states) as $protocol){
			$this->merge($protocol);
		}
	}

	private function merge(int $protocol): void {
		if(isset($this->merged[$protocol])){
			return;
		}
		$this->merged[$protocol] = true;
		// Anything translated before the merge memoised the info_update fallback for that state, and the
		// cache is never invalidated on its own.
		$this->networkIdCache[$protocol]->setValue($this->translator[$protocol], []);
		if($this->networkIdsAreHashes[$protocol] ?? false){
			$this->insertHashedStates($protocol);
			return;
		}
		// To sort the block palette we first have to split the palette up in to groups of states. We only want to sort
		// using the name of the block, and keeping the order of the existing states.
		/** @var BlockStateDictionaryEntry[][] $states */
		$states = [];
		foreach($this->states[$protocol] as $state){
			$states[(property_exists($state, "oldBlockStateData") ? (new ReflectionProperty($state, "oldBlockStateData"))->getValue($state)?->getName() : null) ?? $state->getStateName()][] = $state;
		}
		// Append the new state we are sorting with at the end to preserve existing order.
		$states = array_merge($states, $this->pendingInsert);

		$names = array_keys($states);
		// As of 1.18.30, blocks are sorted using a fnv164 hash of their names.
		usort($names, static fn(string $a, string $b) => strcmp(hash("fnv164", $a), hash("fnv164", $b)));
		$sortedStates = [];
		$stateId = 0;
		$stateDataToStateIdLookup = [];
		foreach($names as $name){
			// With the sorted list of names, we can now go back and add all the states for each block in the correct order.
			foreach($states[$name] as $state){
				$sortedStates[$stateId] = $state;
				if(count($states[$name]) === 1){
					$stateDataToStateIdLookup[$state->getStateName()] = $stateId;
				}else{
					$stateDataToStateIdLookup[$state->getStateName()][$state->getRawStateProperties()] = $stateId;
				}
				$stateId++;
			}
		}

		// To work around the BedrockBlockUpgradeSchema not being able to automatically map all skull ids,
		// we manually set the unmapped skulls to "minecraft:skeleton_skull", which would later be changed by downgraders.
		$standardSkull = $stateDataToStateIdLookup[BlockTypeNames::SKELETON_SKULL] ?? null;
		if($standardSkull !== null){
			foreach([
				BlockTypeNames::WITHER_SKELETON_SKULL,
				BlockTypeNames::ZOMBIE_HEAD,
				BlockTypeNames::PLAYER_HEAD,
				BlockTypeNames::CREEPER_HEAD,
				BlockTypeNames::DRAGON_HEAD,
				BlockTypeNames::PIGLIN_HEAD
			] as $skull){
				if(!isset($stateDataToStateIdLookup[$skull])){
					$stateDataToStateIdLookup[$skull] = $standardSkull;
				}
			}
		}

		$this->states[$protocol] = $sortedStates;
		$dictionary = $this->translator[$protocol]->getBlockStateDictionary();
		$this->bedrockKnownStates[$protocol]->setValue($dictionary, $sortedStates);
		$this->stateDataToStateIdLookup[$protocol]->setValue($dictionary, $stateDataToStateIdLookup);
		$this->idMetaToStateIdLookupCache[$protocol]->setValue($dictionary, null);
		$fallback = $stateDataToStateIdLookup[BlockTypeNames::INFO_UPDATE] ??
			throw new AssumptionFailedError(BlockTypeNames::INFO_UPDATE . " should always exist");
		// The lookup may be a plain int (single state) or an array (multiple states) — unwrap if needed.
		if(is_array($fallback)){
			$fallback = reset($fallback);
		}
		$this->fallbackStateId[$protocol]->setValue($this->translator[$protocol], $fallback);
	}

	/**
	 * On these protocols the client derives a state's network id from a hash of that state's own NBT, so the
	 * palette has no order to stay in sync with. The custom states are added under their own hash and every
	 * vanilla state keeps the id it already had.
	 */
	private function insertHashedStates(int $protocol): void {
		$dictionary = $this->translator[$protocol]->getBlockStateDictionary();
		$states = $this->states[$protocol];
		/** @var array<string, array<string, int>|int> $lookup */
		$lookup = $this->stateDataToStateIdLookup[$protocol]->getValue($dictionary);
		foreach($this->pendingInsert as $name => $entries){
			foreach($entries as $entry){
				$stateId = self::getHashStateId($entry->generateStateData());
				$states[$stateId] = $entry;
				if(count($entries) === 1){
					$lookup[$name] = $stateId;
				}else{
					$lookup[$name][$entry->getRawStateProperties()] = $stateId;
				}
			}
		}
		$this->states[$protocol] = $states;
		$this->bedrockKnownStates[$protocol]->setValue($dictionary, $states);
		$this->stateDataToStateIdLookup[$protocol]->setValue($dictionary, $lookup);
		$this->idMetaToStateIdLookupCache[$protocol]->setValue($dictionary, null);
	}

	/**
	 * Mirrors BlockStateDictionary::getHashStateId(), which is private.
	 */
	private static function getHashStateId(BlockStateData $data): int {
		$states = new CompoundTag();
		$blockStates = $data->getStates();
		ksort($blockStates);
		foreach($blockStates as $key => $state){
			$states->setTag($key, $state);
		}
		$compound = CompoundTag::create()
			->setString("name", $data->getName())
			->setTag("states", $states);
		return (int) hexdec(hash("fnv1a32", (new LittleEndianNbtSerializer())->write(new TreeRoot($compound))));
	}
}
