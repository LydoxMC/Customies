<?php
declare(strict_types=1);

namespace customiesdevs\customies\task;

use customiesdevs\customies\block\BlockPalette;
use customiesdevs\customies\block\CustomiesBlockFactory;
use pmmp\thread\ThreadSafeArray;
use pocketmine\block\Block;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

final class AsyncRegisterBlocksTask extends AsyncTask {

	private ThreadSafeArray $blockFuncs;
	private ThreadSafeArray $serializer;
	private ThreadSafeArray $deserializer;

	/**
	 * @param Closure[] $blockFuncs
	 * @phpstan-param array<string, array{(Closure(int): Block), (Closure(BlockStateWriter): Block), (Closure(Block): BlockStateReader)}> $blockFuncs
	 */
	public function __construct(private string $cachePath, array $blockFuncs) {
		$this->blockFuncs = new ThreadSafeArray();
		$this->serializer = new ThreadSafeArray();
		$this->deserializer = new ThreadSafeArray();

		foreach($blockFuncs as $identifier => [$blockFunc, $serializer, $deserializer]){
			$this->blockFuncs[$identifier] = $blockFunc;
			$this->serializer[$identifier] = $serializer;
			$this->deserializer[$identifier] = $deserializer;
		}
	}

	public function onRun(): void {
		// Block type IDs are handed out by a per-thread counter, so every thread has to allocate them in the
		// same order or the IDs mean different things on each one. On the main thread the core block
		// bootstrap (and any IDs it hands out) has long since run by the time a plugin registers a custom
		// block; here it has not, and $blockFunc() below allocates an ID as its very first act. Forcing the
		// bootstrap now puts the counter where the main thread had it.
		GlobalBlockStateHandlers::getSerializer();
		foreach($this->blockFuncs as $identifier => $blockFunc){
			// We do not care about the model or creative inventory data in other threads since it is unused outside of
			// the main thread.
			CustomiesBlockFactory::getInstance()->registerBlock($blockFunc, $identifier, serializer: $this->serializer[$identifier], deserializer: $this->deserializer[$identifier]);
		}
		BlockPalette::getInstance()->sortStates();
	}
}
