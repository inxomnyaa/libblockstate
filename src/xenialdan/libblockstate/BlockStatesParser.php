<?php

declare(strict_types=1);

namespace xenialdan\libblockstate;

use Closure;
use Exception;
use GlobalLogger;
use InvalidArgumentException;
use JsonException;
use pocketmine\block\Block;
use pocketmine\block\Door;
use pocketmine\block\utils\BlockDataSerializer;
use pocketmine\block\utils\InvalidBlockStateException;
use pocketmine\data\bedrock\LegacyBlockIdToStringIdMap;
use pocketmine\math\Facing;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\plugin\PluginException;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\Position;
use RuntimeException;
use Webmozart\PathUtil\Path;
use xenialdan\libblockstate\exception\BlockQueryParsingException;
use xenialdan\libblockstate\exception\BlockQueryParsingFailedException;
use xenialdan\libblockstate\exception\BlockStateNotFoundException;
use function array_key_exists;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function strtolower;
use const pocketmine\BEDROCK_DATA_PATH;

final class BlockStatesParser
{
	use SingletonTrait;

	/** @var string */
	public static string $rotPath = "";
	/** @var string */
	public static string $doorRotPath = "";

	/** @var array<int,BlockState[]> *///TODO check type correct? phpstan!
	private static array $legacyStateMap = [];

	/** @var array */
	private static array $aliasMap = [];
	/** @var array */
	private static array $rotationFlipMap = [];
	/** @var array */
	private static array $doorRotationFlipMap = [];

	private function __construct()
	{
		/*$this->loadRotationAndFlipData(Loader::getRotFlipPath());
		$this->loadDoorRotationAndFlipData(Loader::getDoorRotFlipPath());*/
		//$this->loadRotationAndFlipData(self::$rotPath);
		//$this->loadDoorRotationAndFlipData(self::$doorRotPath);
		$this->loadLegacyMappings();
	}

	private function loadLegacyMappings(): void
	{
		$legacyIdMap = LegacyBlockIdToStringIdMap::getInstance();
		$legacyStateMapReader = PacketSerializer::decoder(file_get_contents(Path::join(BEDROCK_DATA_PATH, "r12_to_current_block_map.bin")), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
		$nbtReader = new NetworkNbtSerializer();
		while (!$legacyStateMapReader->feof()) {
			$blockId = $legacyStateMapReader->getString();
			$meta = $legacyStateMapReader->getLShort();

			$id = $legacyIdMap->stringToLegacy($blockId);
			if ($id === null) {
				throw new RuntimeException("No legacy ID matches " . $blockId);
			}
			$fullId = ($id << Block::INTERNAL_METADATA_BITS) | $meta;

			$offset = $legacyStateMapReader->getOffset();
			$stateTag = $nbtReader->read($legacyStateMapReader->getBuffer(), $offset)->mustGetCompoundTag();
			$legacyStateMapReader->setOffset($offset);
			if($meta > 15){
				//we can't handle metadata with more than 4 bits
				continue;
			}
			self::$legacyStateMap[$id][$meta] = new BlockState($fullId, new R12ToCurrentBlockMapEntry($blockId, $meta, $stateTag));
		}
		ksort(self::$legacyStateMap, SORT_NUMERIC);
	}

	public function runTest(): void
	{
		foreach (
			[
//				"minecraft:tnt",
//				#"minecraft:wood",
//				#"minecraft:log",
//				"minecraft:wooden_slab",
//				"minecraft:wooden_slab_wrongname",
//				"minecraft:wooden_slab[foo=bar]",
//				"minecraft:wooden_slab[top_slot_bit=]",
//				"minecraft:wooden_slab[top_slot_bit=true]",
//				"minecraft:wooden_slab[top_slot_bit=false]",
//				"minecraft:wooden_slab[wood_type=oak]",
//				#"minecraft:wooden_slab[wood_type=spruce]",
//				"minecraft:wooden_slab[wood_type=spruce,top_slot_bit=false]",
//				"minecraft:wooden_slab[wood_type=spruce,top_slot_bit=true]",
//				"minecraft:end_rod[]",
//				"minecraft:end_rod[facing_direction=1]",
//				"minecraft:end_rod[block_light_level=14]",
//				"minecraft:end_rod[block_light_level=13]",
//				"minecraft:light_block[block_light_level=14]",
//				"minecraft:stone[]",
//				"minecraft:stone[stone_type=granite]",
//				"minecraft:stone[stone_type=andesite]",
//				"minecraft:stone[stone_type=wrongtag]",//seems to just not find a block at all. neat!
//				#//alias testing
//				"minecraft:wooden_slab[top=true]",
//				"minecraft:wooden_slab[top=true,type=spruce]",
//				"minecraft:stone[type=granite]",
//				"minecraft:bedrock[burn=true]",
//				"minecraft:lever[direction=1]",
//				"minecraft:wheat[growth=3]",
//				"minecraft:stone_button[direction=1,pressed=true]",
//				"minecraft:stone_button[direction=0]",
//				"minecraft:stone_brick_stairs[direction=0]",
//				"minecraft:trapdoor[direction=0,open_bit=true,upside_down_bit=false]",
//				"minecraft:birch_door",
//				"minecraft:iron_door[direction=1]",
//				"minecraft:birch_door[upper_block_bit=true]",
//				"minecraft:birch_door[direction=1,door_hinge_bit=false,open_bit=false,upper_block_bit=true]",
//				"minecraft:birch_door[door_hinge_bit=false,open_bit=true,upper_block_bit=true]",
//				"minecraft:birch_door[direction=3,door_hinge_bit=false,open_bit=true,upper_block_bit=true]",
//				"minecraft:campfire",
				"minecraft:ladder[facing_direction=0]",
				"minecraft:wooden_door[direction=0,door_hinge_bit=false,open_bit=false,upper_block_bit=false]",
			] as $test)
			try {
				$state = $this->parseQuery(BlockQuery::fromString($test));
				Server::getInstance()->getLogger()->debug("State found for query $test ".$state->getBlock());
				Server::getInstance()->getLogger()->debug($this->prettyPrintStates($state, true));
				Server::getInstance()->getLogger()->debug($this->prettyPrintStates($state, false));
			} catch (InvalidBlockStateException | BlockQueryParsingException $e) {
				Server::getInstance()->getLogger()->debug("Issue whilst parsing query $test");
				Server::getInstance()->getLogger()->debug($e->getMessage());
			}
		try {
			$this->prettyPrintAllStates();
		} catch (InvalidBlockStateException | RuntimeException $e) {
			Server::getInstance()->getLogger()->debug("Issue whilst parsing query $test");
			Server::getInstance()->getLogger()->debug($e->getMessage());
		}
	}

	public function parseQuery(BlockQuery $query): BlockState
	{
		/** @var LegacyBlockIdToStringIdMap $legacyIdMap */
		$legacyIdMap = LegacyBlockIdToStringIdMap::getInstance();
		$id = $legacyIdMap->stringToLegacy($query->blockId);
		if ($id === null) throw new BlockQueryParsingFailedException("No matching block found for $query->query");
		$stateDefault = $this->getDefault($id);
		if ($stateDefault === null) throw new BlockStateNotFoundException("$query->blockId has no known blockstates");
		$stateCompound = clone $stateDefault->state->getBlockState()->getCompoundTag("states");
		if ($query->blockStatesQuery === null) {
			return $stateDefault;
		}
		//alias preparing
		$aliases = [];
		foreach ($stateCompound->getValue() as $k => $v) {
			if (array_key_exists($k, self::$aliasMap)) {
				foreach (self::$aliasMap[$k]["alias"] as $alias) {
					$aliases[$alias] = $k;
				}
			}
		}
		//modifying the compound tag
		foreach (explode(',', $query->blockStatesQuery) as $stateValuePair) {
			[$state, $value] = explode('=', $stateValuePair);
			if ($state === "") throw new InvalidBlockStateException("Missing state name for \"$stateValuePair\"");
			if ($value === "") throw new InvalidBlockStateException("Missing value for \"$stateValuePair\"");
			$state = strtolower($state);
			$state = strtolower($aliases[$state]??$state);
			$value = strtolower($value);
			if (($tag = $stateCompound->getTag($state)) !== null) {
				match ($tag->getType()) {
					NBT::TAG_String => $stateCompound->setString($state, $value),
					NBT::TAG_Int => $stateCompound->setInt($state, (int)$value),
					NBT::TAG_Byte => $stateCompound->setByte($state, ($value === "true" ? 1 : 0)),
					default => throw new InvalidBlockStateException("Wrong type for state $state")
				};
			} else {
				throw new InvalidBlockStateException("Unknown state $state");
			}
		}

		//finding valid states
		if ($stateCompound->equals($stateDefault->state->getBlockState()->getTag("states"))) {
			return $stateDefault;
		}
		/**
		 * @var BlockState $blockState
		 */
		foreach (self::$legacyStateMap[$id] as $blockState) {
			if ($stateCompound->equals($blockState->state->getBlockState()->getCompoundTag("states"))) return $blockState;
		}
		throw new BlockQueryParsingFailedException("No matching block found");
	}

	public function get(int $id, int $meta = 0): BlockState
	{
		return clone self::$legacyStateMap[$id][$meta];
	}

	public function getFullId(int $fullState): BlockState
	{
		return $this->get($fullState >> Block::INTERNAL_METADATA_BITS, $fullState & Block::INTERNAL_METADATA_MASK);
	}

	public function getDefault(int $id): ?BlockState
	{
		if (!array_key_exists($id, self::$legacyStateMap)) {
			return null;
		}
		//return clone reset(self::$legacyStateMap[$id]);
		return $this->get($id);
	}

	public function isDefault(BlockState $state): bool
	{
		$default = $this->getDefault($state->getId());
		if ($default === null) return false;
		return $state->equals($default);
	}

	public function getFromBlock(Block $block): BlockState
	{
		return $this->get($block->getId(), $block->getMeta());
	}

	/**
	 * @param string|null $path
	 * @throws JsonException
	 * @throws PluginException
	 */
	protected function loadRotationAndFlipData(?string $path = null): void
	{
		if ($path !== null) {
			$fileGetContents = file_get_contents($path);
			if ($fileGetContents === false) {
				throw new PluginException("rotation_flip_data.json could not be loaded! Rotation and flip support has been disabled!");
			}

			self::$rotationFlipMap = json_decode($fileGetContents, true, 512, JSON_THROW_ON_ERROR);
			GlobalLogger::get()->debug("Successfully loaded rotation_flip_data.json");
		}
	}

	/**
	 * @param string|null $path
	 * @throws JsonException
	 * @throws PluginException
	 */
	protected function loadDoorRotationAndFlipData(?string $path = null): void
	{
		if ($path !== null) {
			$fileGetContents = file_get_contents($path);
			if ($fileGetContents === false) {
				throw new PluginException("door_data.json could not be loaded! Door rotation and flip support has been disabled!");
			}

			self::$doorRotationFlipMap = json_decode($fileGetContents, true, 512, JSON_THROW_ON_ERROR);
			GlobalLogger::get()->debug("Successfully loaded door_data.json");
		}
	}

	/**
	 * @return array
	 */
	public static function getRotationFlipMap(): array
	{
		return self::$rotationFlipMap;
	}

	/**
	 * @return array
	 */
	public static function getDoorRotationFlipMap(): array
	{
		return self::$doorRotationFlipMap;
	}

	/**
	 * @param BlockQuery $query
	 * @param CompoundTag $states
	 * @return Door
	 * @throws InvalidArgumentException
	 * @throws InvalidBlockStateException
	 * @throws InvalidBlockStateException
	 */
	private static function buildDoor(BlockQuery $query, CompoundTag $states): Door
	{
		$query = clone $query;//TODO test, i don't want the original $query to be modified
		$query->fullExtraQuery = null;
		$query->fullBlockQuery = $query->blockId;
		$query->blockStatesQuery = null;
		/** @var Door $door */
		$door = self::getInstance()->parseQuery($query)->getBlock();
		$door->setOpen($states->getByte("open_bit") === 1);
		$door->setTop($states->getByte("upper_block_bit") === 1);
		$door->setHingeRight($states->getByte("door_hinge_bit") === 1);
		$direction = $states->getInt("direction");
		$door->setFacing(Facing::rotateY(BlockDataSerializer::readLegacyHorizontalFacing($direction & 0x03), false));
		return $door;
	}

	/**
	 * @param array $aliasMap
	 */
	public function setAliasMap(array $aliasMap): void
	{
		self::$aliasMap = $aliasMap;
	}

	/**
	 * @param Block $block
	 * @return string|null
	 */
	public function getBlockIdMapName(Block $block): ?string
	{
		return LegacyBlockIdToStringIdMap::getInstance()->legacyToString($block->getId());
	}

	/**
	 * Pretty-prints blockstates with colors and optionally skips over default values
	 * @param BlockState $entry
	 * @param bool $skipDefaults
	 * @return string
	 * @throws UnexpectedTagTypeException
	 * @throws UnexpectedTagTypeException
	 */
	public function prettyPrintStates(BlockState $entry, bool $skipDefaults): string
	{
		$printedCompound = $entry->state->getBlockState();
		$blockIdentifier = $entry->state->getId();
		$r = $blockIdentifier;
		if ($skipDefaults && $this->isDefault($entry)) return $r;
		$blockId = $entry->getId();
		$defaultStates = $this->getDefault($blockId);
		if ($defaultStates === null) return $r;
		//if($defaultStates->state->getId() !== $blockIdentifier) throw new InvalidBlockStateException("Got incorrect default state $defaultStates for $entry");
		$defaultStatesNamedTag = $defaultStates->state->getBlockState()->getCompoundTag("states");

		$s = [];
		foreach ($printedCompound->getCompoundTag("states") as $tagName => $tag) {
			//TODO use TextFormat::POP once added
			if ($skipDefaults && $defaultStatesNamedTag->getTag($tagName) === null) Server::getInstance()->getLogger()->error("Tag $tagName not found in $entry $defaultStates");
			if ($skipDefaults && $defaultStatesNamedTag->getTag($tagName)?->equals($tag)) continue;
			if ($tag instanceof StringTag) {
				$s[] = TF::LIGHT_PURPLE . "$tagName=" . $tag->getValue() . TF::RESET;
			} else if ($tag instanceof IntTag) {
				$s[] = TF::AQUA . "$tagName=" . $tag->getValue() . TF::RESET;
			} else if ($tag instanceof ByteTag) {
				$s[] = TF::RED . "$tagName=" . ($tag->getValue() === 1 ? TF::GREEN . "true" : TF::RED . "false") . TF::RESET;
			}
		}
		if (count($s) > 0) $r .= '[' . implode(',', $s) . ']';
		return $r;
	}

	/**
	 * Prints all blocknames with states (without default states)
	 * @throws RuntimeException
	 */
	public function prettyPrintAllStates(): void
	{
		foreach (self::$legacyStateMap as $states) {
			foreach ($states as $blockState) {
				try {
					Server::getInstance()->getLogger()->debug($this->prettyPrintStates($blockState, true));
					Server::getInstance()->getLogger()->debug($this->prettyPrintStates($blockState, false));
					Server::getInstance()->getLogger()->debug((string)$blockState);
				} catch (RuntimeException $e) {
					Server::getInstance()->getLogger()->logException($e);
				}
			}
		}
	}

	public function placeAllBlockstates(Position $position): void
	{
		//TODO add session for undo
		$pasteY = $position->getFloorY();
		$pasteX = $position->getFloorX();
		$pasteZ = $position->getFloorZ();
		$world = $position->getWorld();
		$i = 0;
		$limit = 50;
		foreach (self::$legacyStateMap as $states) {
			foreach ($states as $blockState) {
				$x = ($i % $limit) * 2;
				$z = ($i - ($i % $limit)) / $limit * 2;
				try {
					$block = $blockState->getBlock();
					#if($block->getId() !== $id || $block->getMeta() !== $meta) var_dump("error, $id:$meta does not match {$block->getId()}:{$block->getMeta()}");
					#$world->setBlock(new Vector3($pasteX + $x, $pasteY, $pasteZ + $z), $block);
					$world->setBlockAt($pasteX + $x, $pasteY, $pasteZ + $z, $block, false);
				} catch (Exception $e) {
					$i++;
					continue;
				}
				$i++;
			}
		}
		$position->getWorld()->getServer()->broadcastMessage("Placing of all blockstates completed", $position->getWorld()->getViewersForPosition($position->asVector3()));
	}

	/** @noinspection PhpUnusedPrivateMethodInspection */
	private static function doorEquals(int $currentoldDamage, CompoundTag $defaultStatesNamedTag, CompoundTag $clonedPrintedCompound, CompoundTag $finalStatesList): bool
	{
		if (
			/*(
				$isUp &&
				$currentoldDamage === 8 &&
				$finalStatesList->getByte("door_hinge_bit") === $defaultStatesNamedTag->getByte("door_hinge_bit") &&
				$finalStatesList->getByte("open_bit") === $defaultStatesNamedTag->getByte("open_bit") &&
				$finalStatesList->getInt("direction") === $defaultStatesNamedTag->getInt("direction")
			)
			xor*/
		(
			#$finalStatesList->getByte("door_hinge_bit") === $clonedPrintedCompound->getByte("door_hinge_bit") &&
			$finalStatesList->getByte("open_bit") === $clonedPrintedCompound->getByte("open_bit") &&
			$finalStatesList->getInt("direction") === $clonedPrintedCompound->getInt("direction")
		)
		) return true;
		return false;
	}

	/**
	 * Generates an alias map for blockstates
	 * Only call from main thread!
	 * @internal
	 * @noinspection PhpUnusedPrivateMethodInspection
	 */
	private static function generateBlockStateAliasMapJson(): void
	{
//		Loader::getInstance()->saveResource("blockstate_alias_map.json");
//		$config = new Config(Loader::getInstance()->getDataFolder() . "blockstate_alias_map.json");
//		$config->setAll([]);
//		$config->save();
//		foreach (self::$legacyStateMap as $blockName => $v) {
//			foreach ($v as $meta => $legacyMapEntry) {
//				$states = clone $legacyMapEntry->getBlockState()->getCompoundTag('states');
//				foreach ($states as $stateName => $state) {
//					if (!$config->exists($stateName)) {
//						$alias = $stateName;
//						$fullReplace = [
//							"top" => "top",
//							"type" => "type",
//							"_age" => "age",
//							"age_" => "age",
//							"directions" => "vine_b",//hack for vine_directions => directions
//							"direction" => "direction",
//							"vine_b" => "directions",//hack for vine_directions => directions
//							"axis" => "axis",
//							"delay" => "delay",
//							"bite_counter" => "bites",
//							"count" => "count",
//							"pressed" => "pressed",
//							"upper_block" => "top",
//							"data" => "data",
//							"extinguished" => "off",
//							"color" => "color",
//							"block_light" => "light",
//							#"_lit"=>"lit",
//							#"lit_"=>"lit",
//							"liquid_depth" => "depth",
//							"upside_down" => "flipped",
//							"infiniburn" => "burn",
//						];
//						$partReplace = [
//							"_bit",
//							"piece",
//							"output_",
//							"level",
//							"amount",
//							"cauldron",
//							"allow",
//							"state",
//							"door",
//							"redstone",
//							"bamboo",
//							#"head",
//							"brewing_stand",
//							"item_frame",
//							"mushrooms",
//							"composter",
//							"coral",
//							"_2",
//							"_3",
//							"_4",
//							"end_portal",
//						];
//						foreach ($fullReplace as $stateAlias => $setTo)
//							if (str_contains($alias, $stateAlias)) {
//								$alias = $setTo;
//							}
//						foreach ($partReplace as $replace)
//							$alias = trim(trim(str_replace($replace, "", $alias), "_"));
//						$config->set($stateName, [
//							"alias" => [$alias],
//						]);
//					}
//				}
//			}
//		}
//		$all = $config->getAll();
//		/** @var array<string, mixed> $all */
//		ksort($all);
//		$config->setAll($all);
//		$config->save();
//		unset($config);
	}

	/**
	 * Generates an alias map for blockstates
	 * Only call from main thread!
	 * @internal
	 */
	public static function generatePossibleStatesJson(): void
	{
//		$config = new Config(Loader::getInstance()->getDataFolder() . "possible_blockstates.json");
//		$config->setAll([]);
//		$config->save();
//		$all = [];
//		foreach (self::$legacyStateMap as $blockName => $v) {
//			foreach ($v as $meta => $legacyMapEntry) {
//				$states = clone $legacyMapEntry->getBlockState()->getCompoundTag('states');
//				foreach ($states as $stateName => $state) {
//					if (!array_key_exists($stateName, $all)) {
//						$all[(string)$stateName] = [];
//					}
//					if (!in_array($state->getValue(), $all[$stateName], true)) {
//						$all[(string)$stateName][] = $state->getValue();
//						if (str_contains($stateName, "_bit")) {
//							var_dump("_bit");
//						} else {
//							var_dump("no _bit");
//						}
//					}
//				}
//			}
//		}
//		ksort($all);
//		$config->setAll($all);
//		$config->save();
//		unset($config);
	}

	/**
	 * Reads a value of an object, regardless of access modifiers
	 * @param object $object
	 * @param string $property
	 * @return mixed
	 */
	public static function &readAnyValue(object $object, string $property): mixed
	{
		$invoke = Closure::bind(function & () use ($property) {
			return $this->$property;
		}, $object, $object)->__invoke();
		/** @noinspection PhpUnnecessaryLocalVariableInspection */
		$value = &$invoke;

		return $value;
	}

}