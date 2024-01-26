<?php
namespace Time2Split\PCP\C\Element;

use Time2Split\Config\Configuration;
use Time2Split\Config\Configurations;
use Time2Split\Help\FIO;
use Time2Split\Help\Traversables;
use Time2Split\PCP\Expression\Expressions;

final class PCPPragma extends CPPDirective
{

    private function __construct( //
    string $directive, string $text, array $cursors, //
    private string $cmd, //
    private string $textArgs, //
    private Configuration $arguments)
    {
        parent::__construct($directive, $text, $cursors);
    }

    public static function createPCPPragma( //
    Configuration $pcpConfig, //
    string $directive, string $text, array $cursors, //
    $subTextStream): //
    PCPPragma
    {
        FIO::streamSkipChars($subTextStream, \ctype_space(...));
        $cmd = FIO::streamGetCharsUntil($subTextStream, \ctype_space(...));
        $textArgs = \stream_get_contents($subTextStream);

        $args = Configurations::emptyOf($pcpConfig);

        try {
            Expressions::arguments()->tryString($textArgs)
                ->output()
                ->get($args);
        } catch (\Exception $e) {
            throw new \Exception("Unable to parse the pragma arguments '$text' {$cursors[0]}) ; {$e->getMessage()}");
        }
        return new self($directive, $text, $cursors, $cmd, $textArgs, $args);
    }

    public function getCommand(): string
    {
        return $this->cmd;
    }

    public function getArguments(): Configuration
    {
        return $this->arguments;
    }

    public function shiftArguments(): self
    {
        list ($cmd,) = Traversables::firstKeyValue($this->arguments);
        $args = Configurations::of($this->arguments);
        unset($args[$cmd]);

        return new self($this->getDirective(), $this->getText(), $this->getFileCursors(), $cmd, $this->textArgs, $args);
    }
}
