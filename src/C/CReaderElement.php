<?php
namespace Time2Split\PCP\C;

abstract class CReaderElement extends \ArrayObject implements CElement
{

    protected function __construct(array $elements)
    {
        parent::__construct($elements);
    }

    public function getParameters(): ?array
    {
        if (! isset($this['parameters']))
            return null;

        $params = [];
        foreach ($this['parameters'] as $p)
            $params[] = $this['items'][$p];

        return $params;
    }
}