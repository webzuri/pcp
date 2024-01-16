<?php
namespace Time2Split\PCP\Action\PCP\Generate;

use Time2Split\Config\IConfig;
use Time2Split\PCP\C\Element;

abstract class Instruction
{

    public abstract function generate(): string;

    public abstract function getTargets(): array;

    // ========================================================================
    private Element $subject;

    private IConfig $instruction;

    private array $tags;

    protected function __construct(Element $subject, IConfig $instruction)
    {
        $this->subject = $subject;

        $i = clone $instruction;
        $stype = $subject->getElementType();

        $tags = (array) ($instruction['tags'] ?? null);
        $tags[] = $stype->value;
        \sort($tags);
        $this->tags = $tags;
        unset($i['tags']);

        $this->instruction = $i;
    }

    public function getSubject(): Element
    {
        return $this->subject;
    }

    public function getInstruction(): IConfig
    {
        return $this->instruction;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}