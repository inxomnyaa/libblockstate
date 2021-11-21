<?php

declare(strict_types=1);

namespace xenialdan\libblockstate;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\utils\InvalidBlockStateException;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use function count;
use function get_class;
use function implode;

class BlockState
{
	public int $fullId;
	public R12ToCurrentBlockMapEntry $state;

	public function __construct(int $fullId, R12ToCurrentBlockMapEntry $state)
	{
		$this->fullId = $fullId;
		$this->state = $state;
	}

	public function equals(BlockState $state): bool
	{
		return $state->fullId === $this->fullId;//TODO maybe check compound
	}

	public function getBlock(): Block
	{
		return BlockFactory::getInstance()->fromFullBlock($this->fullId);
	}

	public function getFullId(): int
	{
		return $this->fullId;
	}

	public function getId():int{
		return $this->fullId >> Block::INTERNAL_METADATA_BITS;
	}

	public function __toString(): string
	{
		$r = $this->state->getId();
		$s = [];
		foreach ($this->state->getBlockState()->getCompoundTag("states") as $tagName => $tag) {
			if ($tag instanceof StringTag || $tag instanceof IntTag) {
				$s[] = "$tagName=" . $tag->getValue();
			} else if ($tag instanceof ByteTag) {
				$s[] = "$tagName=" . ($tag->getValue() === 1 ? "true" : "false");
			} else {
				throw new InvalidBlockStateException("Unknown tag of type " . get_class($tag) . " detected");
			}
		}
		if (count($s) > 0) $r .= '[' . implode(',', $s) . ']';
		return $r;
	}
}