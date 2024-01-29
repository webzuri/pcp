<?php
namespace Time2Split\PCP\Action\PCP;

use Time2Split\Config\Configuration;
use Time2Split\Config\Configurations;
use Time2Split\Help\Arrays;
use Time2Split\Help\IO;
use Time2Split\PCP\App;
use Time2Split\PCP\Action\BaseAction;
use Time2Split\PCP\Action\Phase;
use Time2Split\PCP\Action\PhaseName;
use Time2Split\PCP\Action\PhaseState;
use Time2Split\PCP\Action\PhaseData\ReadingOneFile;
use Time2Split\PCP\C\CDeclarationGroup;
use Time2Split\PCP\C\CDeclarationType;
use Time2Split\PCP\C\CReader;
use Time2Split\PCP\C\Element\CContainer;
use Time2Split\PCP\C\Element\CDeclaration;
use Time2Split\PCP\C\Element\CPPDirective;
use Time2Split\PCP\C\Element\CPPDirectives;
use Time2Split\PCP\C\Element\PCPPragma;

final class Generate extends BaseAction
{

    private const wd = 'generate';

    private const DefaultConfig = [
        'generate' => [
            'tags' => null,
            'targets' => '.',
            'targets.prototype' => '${targets}',

            'name.base' => null,
            'name.prefix' => null,
            'name.suffix' => null,
            'name.format' => '${name.prefix}${name.base}${name.suffix}'
        ]
    ];

    private Generate\Instruction\Factory $ifactory;

    private Generate\Instruction\Storage $istorage;

    private array $area;

    private ?CContainer $currentCContainer = null;

    private array $instructions;

    private ReadingOneFile $oneFileData;

    public function __construct(Configuration $config)
    {
        parent::__construct($config);
        $this->config = Configurations::emptyChild($this->config);
        $this->ifactory = new Generate\Instruction\Factory();
        $this->istorage = new Generate\Instruction\Storage();
        $this->area = [];
    }

    public function onMessage(CContainer $ccontainer): array
    {
        if ($ccontainer->isPCPPragma()) {
            $pragma = $ccontainer->getPCPPragma();

            if ($pragma->getCommand() === 'generate') {
                $this->doInstruction($pragma);
            }
        } elseif ($ccontainer->isDeclaration()) {
            $this->currentCContainer = $ccontainer;
            $this->processCContainer($ccontainer);
        }
        return [];
    }

    private function processCContainer(CContainer $ccontainer)
    {
        if ($ccontainer->isDeclaration())
            $this->processCDeclaration($ccontainer->getDeclaration());
    }

    private function processCDeclaration(CDeclaration $declaration)
    {
        if (! $declaration->getType() === CDeclarationType::tfunction)
            return;

        if ( //
        $declaration->getGroup() === CDeclarationGroup::definition || //
        ($declaration->getGroup() === CDeclarationGroup::declaration && //
        $declaration->getType() === CDeclarationType::tfunction)) {

            foreach ($this->instructions as $instruction) {
                // The order of the $instruction arguments is important
                $first = $instruction->getArguments();
                $secnd = $this->config->subConfig('generate');

                $i = Configurations::hierarchy($secnd, $first);
                $i = $this->decorateConfig($i);

                $this->istorage->add($this->ifactory->create($declaration, $i));
            }
            $this->instructions = [];
        }
    }

    public function onPhase(Phase $phase, $data = null): void
    {
        switch ($phase->name) {

            case PhaseName::ReadingCElement:

                if (PhaseState::Start == $phase->state) {

                    if ($this->currentCContainer) {
                        $this->processCContainer($this->currentCContainer);
                        $this->currentCContainer = null;
                    }
                }
                break;

            case PhaseName::ProcessingFiles:

                if (PhaseState::Start == $phase->state) {
                    $this->goWorkingDir();

                    if (! \is_dir(self::wd))
                        mkdir(self::wd);

                    $this->outWorkingDir();
                } elseif (PhaseState::Stop == $phase->state) {
                    $this->generate();
                }
                break;

            case PhaseName::ReadingOneFile:

                if (PhaseState::Start == $phase->state) {
                    $this->oneFileData = $data;

                    // Reset the config for the file
                    $this->config->clear();

                    $this->instructions = [];
                    Configurations::mergeArrayRecursive($this->config, self::DefaultConfig);
                } elseif (PhaseState::Stop == $phase->state) {
                    $this->flushFileInfos();
                }
                break;
        }
    }

    // ========================================================================
    private function doInstruction(PCPPragma $inst): void
    {
        $args = $inst->getArguments();

        if (isset($args['area'])) {
            $this->area[] = [
                'tags' => $args['tags'] ?? null,
                'pos' => $inst->getFileCursors()[1]->getPos(),
                'date' => $this->config['dateTime']
            ];
        } elseif (isset($args['function']) || isset($args['prototype'])) {
            $this->instructions[] = $inst;
        } else {
            // Update the configuration
            $args = Arrays::map_key(fn ($k) => "generate.$k", $args->toArray());
            Configurations::mergeTraversable($this->config, $args);
        }
    }

    // ========================================================================
    private function flushStorage(): void
    {
        $this->istorage->flushOnFile($this->oneFileData);
    }

    private function flushFileInfos(): void
    {
        $this->goWorkingDir(self::wd);
        $this->flushStorage();
        $this->flushArea();
        $this->outWorkingDir();
    }

    private function flushArea(): void
    {
        $areas = \array_filter((array) $this->area);

        $finfo = $this->oneFileData->fileInfo;
        $fileDir = "{$finfo->getPathInfo()}/";
        $fname = "$fileDir/{$finfo->getFileName()}.area.php";

        if (empty($areas)) {

            if (\is_file($fname))
                \unlink($fname);

            return;
        }

        if (! is_dir($fileDir))
            mkdir($fileDir, 0777, true);

        IO::printPHPFile($fname, $areas);
        $this->area = [];
    }

    private function generateName(string $baseName): string
    {
        $conf = $this->config;
        $conf['generate.name.base'] = $baseName;
        return $conf['generate.name.format'];
    }

    private function generateMacro(array $i, CPPDirective $macro)
    {
        $macroTokens = $i['function'];
        $macroTokens .= ';';

        $macroFun = CReader::fromStream(IO::stringToStream($macroTokens))->next();
        $macroFun['identifier']['name'] = $this->generateName($macro['name']);

        $macroFunParameters = $macroFun->getParameters();

        foreach ($macro['args'] as $k => $name)
            $macroFunParameters[$k]['identifier']['name'] = $name;

        $code = $macro['tokens'] . ';';

        if (false === \array_search('void', $macroFun['items']))
            $code = "return $code";

        $ret = $this->prototypeToString($macroFun);
        $ret .= "\n{ $code }";
        return "\n$ret";
    }

    // ========================================================================
    private function skipGenerated($stream): int
    {
        $pos = \ftell($stream);
        $reader = CReader::fromStream($stream, false);
        $reader->setCPPDirectiveFactory(CPPDirectives::factory($this->config));

        $cppDirective = $reader->nextCPPDirective();

        if (! isset($cppDirective) || ! CContainer::of($cppDirective)->isPCPPragma() || $cppDirective->getCommand() !== 'begin')
            goto noBegin;

        while (true) {
            $cppDirective = $reader->nextCPPDirective();

            if (isset($cppDirective) && CContainer::of($cppDirective)->isPCPPragma() && $cppDirective->getCommand() === 'end')
                break;
        }
        return \ftell($stream) - $pos;
        noBegin:
        $reader->close();
        return 0;
    }

    private static function includeSource(string $file): array
    {
        $ret = include "$file.php";

        foreach ($ret as &$sub)
            foreach ($sub as &$item)
                $item['tags'] = \array_flip([
                    ...$item['tags'],
                    'remaining'
                ]);

        return $ret;
    }

    private function generate(): void
    {
        $filesDir = \getcwd();
        $this->goWorkingDir(self::wd);

        $cppNames = (array) $this->config['pcp.name'];
        $cppName = Arrays::first($cppNames);
        $sourceCache = [];

        $dirIterator = new \RecursiveDirectoryIterator('.', \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::KEY_AS_PATHNAME);
        $dirIterator = new \RecursiveIteratorIterator($dirIterator);
        $dirIterator = new \RegexIterator($dirIterator, "/^.+\.target\.php$/");

        foreach ($dirIterator as $baseTargetFile) {
            $fileSource = \substr((string) $baseTargetFile, 0, - \strlen('.target.php'));
            $targets = include $baseTargetFile;

            $sourceInfos = $sourceCache[$fileSource] ??= self::includeSource($fileSource);

            foreach ($targets as $targetFile => $targetGIDs) {
                $targetFileDir = "$filesDir/$targetFile";

                if (! \is_file($targetFileDir))
                    continue;

                $writer = App::fileInsertion($targetFileDir, 'tmp');
                $targetAreas = @include "$targetFile.area.php";

                if (false === $targetAreas)
                    continue;

                foreach ($targetAreas as $area) {
                    $pos = $area['pos'];
                    $writer->seekSet($pos);
                    $areaTags = \array_flip((array) $area['tags']);

                    if (empty($areaTags))
                        $cond = fn () => true;
                    else
                        $cond = function ($tags) use ($areaTags) {
                            $a = \array_intersect_key($areaTags, $tags);
                            return \count($a) === \count($areaTags);
                        };

                    // Test if the generation is already present
                    $rstream = $writer->getSourceStream();
                    $skipped = $this->skipGenerated($rstream);

                    if ($skipped > 0) {
                        $writer->seekMore($skipped);
                        $write = function () {};
                    } else
                        $write = function ($text) use ($writer) {
                            $writer->write($text);
                        };

                    $write("#pragma $cppName begin\n");

                    foreach ($targetGIDs as $gid) {

                        foreach ($sourceInfos[$gid] as &$sourceInfo) {

                            if (! $cond($sourceInfo['tags']))
                                continue;

                            unset($sourceInfo['tags']['remaining']);
                            $write($sourceInfo['text']);
                            $write("\n");
                        }
                    }
                    $write("#pragma $cppName end\n");
                }
                $writer->close();
            }
        }
        $this->outWorkingDir();
        return;
        unset($sourceCache);
    }
}