<?php

declare(strict_types=1);

namespace xenialdan\libblockstate;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\utils\InvalidBlockStateException;
use pocketmine\nbt\NoSuchTagException;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use xenialdan\libblockstate\exception\BlockQueryParsingFailedException;
use function count;
use function get_class;
use function implode;

class BlockState{
	public int $fullId;
	public R12ToCurrentBlockMapEntry $state;

	public function __construct(int $fullId, R12ToCurrentBlockMapEntry $state){
		$this->fullId = $fullId;
		$this->state = $state;
	}

	public function equals(BlockState $state) : bool{
		return $state->fullId === $this->fullId;//TODO maybe check compound
	}

	public function getBlock() : Block{
		return BlockFactory::getInstance()->fromFullBlock($this->fullId);
	}

	public function getFullId() : int{
		return $this->fullId;
	}

	public function getId() : int{
		return $this->fullId >> Block::INTERNAL_METADATA_BITS;
	}

	/**
	 * @param array                        $states Keys are state names, values are new values
	 * @param bool                         $strict Whether to throw an exception if a state is not found
	 *
	 * @phpstan-param array<string, mixed> $states
	 *
	 * @throws BlockQueryParsingFailedException|UnexpectedTagTypeException|NoSuchTagException
	 */
	public function replaceBlockStateValues(array $states, bool $strict = true) : BlockState{
		/** @var BlockStatesParser $blockStatesParser */
		$blockStatesParser = BlockStatesParser::getInstance();
		$newBlockStateCompound = clone $this->state->getBlockState();
		foreach($states as $stateName => $newValue){
			$blockStateTag = $newBlockStateCompound->getCompoundTag("states")->getTag($stateName);
			match (true) {
				$blockStateTag instanceof StringTag => $newBlockStateCompound->getCompoundTag("states")->setString($stateName, (string) $newValue),
				$blockStateTag instanceof IntTag => $newBlockStateCompound->getCompoundTag("states")->setInt($stateName, (int) $newValue),
				$blockStateTag instanceof ByteTag => $newBlockStateCompound->getCompoundTag("states")->setByte($stateName, (int) $newValue),
				$blockStateTag === null && $strict => throw new NoSuchTagException("Tag $stateName not found"),
				default => throw new UnexpectedTagTypeException("Unexpected tag type")
			};
		}
		return $blockStatesParser->getFromCompound($newBlockStateCompound);
	}

	/**
	 * @throws InvalidBlockStateException|UnexpectedTagTypeException
	 */
	public function __toString() : string{
		$r = $this->state->getId();
		$s = [];
		foreach($this->state->getBlockState()->getCompoundTag("states") as $tagName => $tag){
			$s[] = match (true) {
				$tag instanceof StringTag, $tag instanceof IntTag => $tagName . "=" . $tag->getValue(),
				$tag instanceof ByteTag => $tagName . "=" . ($tag->getValue() === 1 ? "true" : "false"),
				default => throw new InvalidBlockStateException("Invalid block state detected for block " . $this->state->getId() . " with tag " . $tagName . " of type " . get_class($tag) . " only StringTag, IntTag and ByteTag are allowed")
			};
		}
		if(count($s) > 0) $r .= '[' . implode(',', $s) . ']';
		return $r;
	}
}