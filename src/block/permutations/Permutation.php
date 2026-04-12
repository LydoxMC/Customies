<?php
declare(strict_types=1);

namespace customiesdevs\customies\block\permutations;

use customiesdevs\customies\util\NBT;
use pocketmine\nbt\tag\CompoundTag;
use function array_keys;

final class Permutation {

	private CompoundTag $components;
	/** @var array<int, array<string, array{string, mixed}>> */
	private array $downgradeComponents = [];

	public function __construct(private readonly string $condition) {
		$this->components = CompoundTag::create();
	}

	/**
	 * Returns the permutation with the provided component added to the current list of components.
	 */
	public function withComponent(string $component, mixed $value) : self {
		$this->components->setTag($component, NBT::getTagType($value));
		return $this;
	}

	/**
	 * Register a component replacement for a specific protocol version.
	 * When generating NBT for that protocol (or older), $replaced will be removed and $component will be added.
	 */
	public function downgradeComponent(int $protocolId, string $replaced, string $component, mixed $value) : self {
		$this->downgradeComponents[$protocolId][$replaced] = [$component, $value];
		return $this;
	}

	/**
	 * Returns the protocol IDs that have downgrade components registered.
	 * @return int[]
	 */
	public function getProtocolIds() : array {
		return array_keys($this->downgradeComponents);
	}

	/**
	 * Returns the permutation in the correct NBT format supported by the client.
	 */
	public function toNBT(int $protocolId = 0): CompoundTag {
		$components = clone $this->components;
		foreach($this->downgradeComponents as $componentProtocol => $downgrade){
			if($protocolId > $componentProtocol){
				continue;
			}
			foreach($downgrade as $replaced => [$component, $value]){
				$components->removeTag($replaced);
				$components->setTag($component, NBT::getTagType($value));
			}
		}
		return CompoundTag::create()
			->setString("condition", $this->condition)
			->setTag("components", $components);
	}
}
