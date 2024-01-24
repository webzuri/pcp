<?php
namespace Time2Split\PCP\C;

use Time2Split\Help\FIO;
use Time2Split\Help\IO;
use Time2Split\PCP\C\Element\CDeclaration;
use Time2Split\PCP\C\Element\CMacro;
use Time2Split\PCP\File\Navigator;

class CReader
{

    private const debug = false;

    private ?Navigator $fnav;

    private function __construct($stream, bool $closeStream = true)
    {
        $mdata = \stream_get_meta_data($stream);

        if (! $mdata['seekable'])
            throw new \Exception(__class__ . " The stream must be readable mode, the mode is {$mdata['mode']}");

        $this->fnav = Navigator::fromStream($stream, $closeStream);
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function from($source, bool $closeStream = true): self
    {
        if (\is_resource($source))
            return self::fromStream($source, $closeStream);

        return self::fromFile($source, $closeStream);
    }

    public static function fromStream($stream, bool $closeStream = true): self
    {
        return new self($stream, $closeStream);
    }

    public static function fromFile($filePath, bool $closeStream = true): self
    {
        return new self(\fopen((string) $filePath, 'r'), $closeStream);
    }

    public function close(): void
    {
        $this->fnav->close();
    }

    public function getStream()
    {
        return $this->fnav->getStream();
    }

    // ========================================================================
    public function fgetc()
    {
        return $this->fnav->getc();
    }

    public function fungetc(int $nb = 1)
    {
        return $this->fnav->ungetc($nb);
    }

    // ========================================================================
    private function parseException(?string $msg): void
    {
        throw new \RuntimeException("$this->filePath: line($this->line_n) char($this->char_n) $msg");
    }

    private const C_DELIMITERS = '""\'\'(){}[]';

    /*
     * A '/' as been read
     */
    private function skipComment(): bool
    {
        $state = 0;
        while (true) {
            $c = $this->fgetc(); // Why ?

            if ($c === false)
                goto failure;

            switch ($state) {

                // Comment ?
                case 0:
                    if ('/' === $c)
                        $state = 1;
                    elseif ('*' === $c)
                        $state = 100;
                    else
                        goto failure;
                    break;
                case 1:
                    if ("\n" === $c)
                        return true;
                    break;
                // Multiline comment
                case 100:
                    if ('*' === $c)
                        $state ++;
                    break;
                case 101:

                    if ('/' === $c)
                        return true;
                    elseif ('*' === $c);
                    else
                        $state --;
                    break;
            }
        }
        failure:
        $this->fungetc();
        return false;
    }

    private function skipSpaces(): void
    {
        $this->fnav->skipChars('\ctype_space');
    }

    private function skipUselessText(): void
    {
        do {
            $skipped = $this->skipSpaces();
            $c = $this->fgetc();

            if ($c === false)
                return;

            if ($c === '/') {

                if (! $this->skipComment())
                    $this->fungetc();
                else
                    $skipped = true;
            } else
                $this->fungetc();
        } while ($skipped);
    }

    private function skipSimpleDelimitedText(string $endDelimiter): void
    {
        $this->nav->getCharsUntil($endDelimiter);
    }

    private function getDelimitedText(string $delimiters = self::C_DELIMITERS): string
    {
        $buff = "";
        $skip = false;
        $endDelimiters = [];
        $endDelimiter = null;

        while (true) {
            $c = $this->fgetc();
            $buff .= $c;

            if ($c === false)
                return false;
            if ($c === '\\')
                $skip = true;
            elseif ($c == '/') {
                if ($this->skipComment())
                    $buff = \substr($buff, 0, - 1);
            } elseif ($c === $endDelimiter && ! $skip) {
                $endDelimiter = \array_pop($endDelimiters);

                if (! isset($endDelimiter))
                    return $buff;
            } else {

                if ($skip)
                    $skip = false;

                $end = FIO::isDelimitation($c, $delimiters);
                if (null !== $end) {
                    \array_push($endDelimiters, $endDelimiter);
                    $endDelimiter = $end;
                }
            }
        }
    }

    // ========================================================================
    private function nextWord(?\Closure $pred = null): ?string
    {
        if (! isset($pred)) {
            $pred = fn ($c) => //
            ctype_alnum($c) || $c === '_';
        }
        $this->skipUselessText();
        return $this->fnav->getChars($pred);
    }

    private function nextChar()
    {
        $this->skipUselessText();
        return $this->fgetc();
    }

    private function silentChar()
    {
        $c = $this->nextChar();
        $this->fungetc();
        return $c;
    }

    private function nextIdentifier(): ?string
    {
        $c = $this->nextChar();

        if (! ($c === '_' || \ctype_alpha($c))) {
            $this->fungetc();
            return null;
        }
        return $c . $this->nextWord();
    }

    private function getPossibleSpecifiers(): array
    {
        $ret = [];

        while (true) {
            $this->skipUselessText();
            $text = $this->nextWord();

            if ($text === null) {
                return $ret;
            } else {
                $ret[] = $text;
            }
        }
    }

    private function getPointers(): array
    {
        $ret = [];

        while (true) {

            while ($this->nextChar() === '*')
                $ret[] = '*';

            $this->fungetc();

            while (true) {
                $text = $this->nextWord();

                if (null === $text)
                    return $ret;

                $ret[] = $text;
            }
        }
    }

    // ========================================================================
    private array $states;

    private array $fileStates;

    private const zeroData = [
        'e' => []
    ];

    private function clearStates(): void
    {
        $this->states = [];
        $this->pushState(CReaderState::start, self::zeroData);
    }

    private function pushFileState(): void
    {
        $this->fileStates[] = \ftell($this->fp);
    }

    private function popFileState(): void
    {
        \fseek(SEEK_SET, \array_pop($this->fileStates));
    }

    private function pushState(CReaderState $s, $data = null): void
    {
        $this->states[] = [
            $s,
            $data
        ];
    }

    private function popState(): array
    {
        if (empty($this->states))
            return [
                CReaderState::invalid,
                null
            ];

        list ($state, $data) = \array_pop($this->states);

        return [
            $state,
            $data
        ];
    }

    private function forgetState(CReaderState $s): void
    {
        list ($state, $data, $fileState) = \array_pop($this->states);

        if ($state !== $s)
            throw new \Exception(__class__ . " waiting to forget $s->name but have $state->name");
    }

    // ========================================================================
    private function newElement(): array
    {
        return [
            'group' => CDeclarationGroup::declaration,
            'type' => CDeclarationType::tvariable,
            'cursor' => $this->fnav->getCursorPosition(),
            'items' => [],
            'infos' => []
        ];
    }

    private static function elementAddItems(array &$element, array $items, ?string $name = null): void
    {
        $element['items'] = \array_merge($element['items'], $items);

        if ($name !== null) {
            $element[$name][] = $items;
        }
    }

    private static function elementSet(array &$element, string $name, $v): void
    {
        if (! isset($element[$name]) && isset($v))
            $element[$name] = $v;
    }

    private static function mergeElements(array &$element, array $toMerge): void
    {
        $prevNbItems = \count($element['items']);
        self::elementAddItems($element, $toMerge['items']);

        if (isset($toMerge['identifier']))
            self::setElementIdentifier($element, $toMerge['identifier']['pos'] + $prevNbItems);
    }

    private static function setElementIdentifier(array &$element, int $pos): void
    {
        $element['identifier'] = [
            'pos' => $pos,
            'name' => &$element['items'][$pos]
        ];
    }

    private static function makeElementIdentifier(array &$element): void
    {
        if (! array_key_exists('identifier', $element)) {
            $i = \count($element['items']) - 1;

            if ($i == - 1)
                return;
            $id = $element['items'][$i];

            // Abstract declarator
            if ((\strlen($id) > 0 && ! \ctype_alpha($id[0])) || CMatching::isSpecifier($id)) {
                $element['items'][] = null;
                $i ++;
            }
            self::setElementIdentifier($element, $i);
        }
    }

    /**
     * Hypothesis: no macro are present in the associated element
     */
    private static function elementIsParameter(array $unknownInfos): bool
    {
        if ($unknownInfos['specifiers']['type.nb'] > 0)
            return true;

        // If more than 2 specifiers then it cannot be just an identifier
        if ($unknownInfos['specifiers']['nb'] > 1)
            return true;
        // If only 1 specifier but some unknown pointers
        if ($unknownInfos['specifiers']['nb'] == 1 && $unknownInfos['unknown']['nb'] > 1)
            return true;

        return false;
    }

    private static function elementIsNotParameter(array $unknownInfos): bool
    {
        if ($unknownInfos['specifiers']['type.nb'] == 0 && $unknownInfos['pointers']['nb'] > 0)
            return true;

        return false;
    }

    private static function elementIsEmpty(array $e): bool
    {
        return empty($e['items']);
    }

    // ========================================================================
    private function parseDefine(string $text): array
    {
        $stream = IO::stringToStream($text);
        return self::fromStream($stream)->_parseDefine();
    }

    private function _parseDefine(): ?array
    {
        $state = CReaderState::start;
        $element = [];

        while (true) {

            switch ($state) {

                case CReaderState::start:
                    $name = $this->nextWord();

                    if ($name === false)
                        return $element;

                    $c = $this->nextChar();

                    if ($c !== '(')
                        $state = 10;
                    $args = [];

                    while (true) {
                        $w = $this->nextWord();

                        if (null === $w)
                            return null;

                        $args[] = $w;
                        $c = $this->nextChar();
                        if ($c === ',')
                            continue;
                        if ($c === ')')
                            break;
                    }
                    return [
                        'type' => empty($args) ? 'object' : 'function',
                        'elements' => [
                            'name' => $name,
                            'args' => $args,
                            'tokens' => \stream_get_contents($this->fnav->getStream())
                        ]
                    ];
                    break;

                // ======================================================

                // Argument List
                case 10:
                    $element['directive'] = $this->nextWord();
                    $this->skipSpaces();

                    $skipNext = false;
                    $buff = '';

                    while (true) {
                        $c = $this->fgetc();
                        $buff .= $c;

                        if (($c === "\n" && ! $skipNext) || $c === false) {
                            $element['text'] = \rtrim($buff);
                            $element['cursor2'] = $this->fnav->getCursorPosition();
                            return $element;
                        } elseif ($c === '\\')
                            $skipNext = true;
                        elseif ($skipNext)
                            $skipNext = false;
                    }
                    break;
            }
        }
    }

    public function nextMacro(): ?CMacro
    {
        while (true) {
            $c = $this->nextChar();

            if ($c === false)
                return null;
            if ($c === '#') {
                $this->fungetc();
                return $this->getMacro();
            }
            $this->fnav->skipChars(fn ($c) => $c !== "\n");
        }
    }

    public function getMacro(): ?CMacro
    {
        $state = CReaderState::start;

        while (true) {

            switch ($state) {

                case CReaderState::start:
                    $c = $this->nextChar();

                    if ($c === false)
                        return null;

                    if ($c === '#') {
                        $element = [
                            'group' => CDeclarationGroup::cpp,
                            'cursor' => $this->fnav->getCursorPosition()->decrement()
                        ];
                        $state = CReaderState::cpp_directive;
                    } else
                        return null;
                    break;

                // ======================================================

                case CReaderState::cpp_directive:
                    $element += [
                        'directive' => $d = $this->nextWord()
                    ];
                    $this->skipSpaces();

                    $skipNext = false;
                    $buff = '';

                    while (true) {
                        $c = $this->fgetc();
                        $buff .= $c;

                        if (($c === "\n" && ! $skipNext) || $c === false) {
                            $element['text'] = \rtrim($buff);
                            $element['cursor2'] = $this->fnav->getCursorPosition();

                            if ($d === 'define')
                                $element = \array_merge($element, $this->parseDefine($element['text']));

                            return CMacro::fromReaderElements($element);
                        } elseif ($c === '\\')
                            $skipNext = true;
                        elseif ($skipNext)
                            $skipNext = false;
                    }
                    break;
            }
        }
    }

    public function next(): ?CReaderElement
    {
        $this->clearStates();
        $declarator_level = 0;
        $retElements = [];

        while (true) {
            list ($state, $data) = $this->popState();
            $element = &$data['e'];

            if (self::debug) {
                $cursor = $this->fnav->getCursorPosition();
                $c = $this->fnav->getc();
                $this->fnav->ungetc();
                echo "$state->name[ $c ]", " $cursor\n";
            }

            switch ($state) {

                case CReaderState::start:

                    // Skip useless code
                    while (true) {
                        $c = $this->nextChar();
                        if ($c === ';')
                            continue;
                        if ($c === '{') {
                            $c = $this->fungetc();
                            $this->getDelimitedText(self::C_DELIMITERS);
                            continue;
                        }
                        break;
                    }

                    if ($c === false)
                        return null;

                    if ($c === '#') {
                        $this->fungetc();
                        return $this->getMacro();
                    } else {
                        $this->fungetc();
                        $element = $this->newElement();
                        $this->pushState(CReaderState::returnElement, $data);
                        $this->pushState(CReaderState::declaration_end, $data);
                        $this->pushState(CReaderState::declaration, $data);
                    }
                    break;

                case CReaderState::returnElement:

                    if (empty($retElements)) {
                        $this->clearStates();
                        break;
                    }
                    return CDeclaration::fromReaderElements($retElements[0]);

                // ======================================================

                case CReaderState::declaration:
                    $this->pushState(CReaderState::declarator, $data);
                    $this->pushState(CReaderState::declaration_specifiers, $data);
                    break;

                // specifiers declarator
                case CReaderState::declaration_specifiers:
                    $specifiers = $this->getPossibleSpecifiers();
                    $element['infos']['specifiers.nb'] = \count($specifiers);

                    if (empty($specifiers))
                        break;

                    $element['items'] = $specifiers;
                    break;

                case CReaderState::declaration_end:

                    if ($element['group'] === CDeclarationGroup::definition && $element['type'] == CDeclarationType::tfunction)
                        $retElements[] = $element;
                    else {
                        $c = $this->nextChar();

                        if ($c === ';') {
                            assert($declarator_level == 0, "Into a recursive declarator: level $declarator_level");
                            $retElements[] = $element;
                        } else
                            $this->pushState(CReaderState::wait_end_declaration);
                    }
                    break;

                // Well state for an unrecognized declaration
                case CReaderState::wait_end_declaration:

                    while (true) {
                        $c = $this->nextChar();

                        if ($c === false)
                            return null;
                        if ($c === ';' || $c === '{') {
                            $this->fungetc();
                            $this->clearStates();
                            break;
                        }
                    }
                    break;

                // ======================================================

                // pointer direct_declarator
                case CReaderState::declarator:
                    // Pointer
                    $pointers = $this->getPointers();
                    self::elementAddItems($element, $pointers);

                    $this->pushState(CReaderState::declarator_end, $data);
                    $this->pushState(CReaderState::direct_declarator, $data);
                    break;

                case CReaderState::declarator_end:
                    self::makeElementIdentifier($element);
                    break;

                case CReaderState::direct_declarator:
                    $c = $this->nextChar();

                    // It may be a recursive declarator or a function declaration
                    if ($c === '(') {
                        $newElement = $this->newElement();
                        $this->pushState(CReaderState::subdeclarator, [
                            'e' => &$element,
                            'n' => &$newElement
                        ]);
                        $this->pushState(CReaderState::declaration, [
                            'e' => &$newElement
                        ]);
                        unset($newElement);
                    } else {
                        $this->fungetc();
                    }
                    break;

                /*
                 * data['n']: the sub declaration
                 */
                case CReaderState::subdeclarator:
                    $c = $this->silentChar();

                    // List of parameters
                    if ($c === ',') {
                        $element['type'] = CDeclarationType::tfunction;
                        $this->pushState(CReaderState::opt_function_definition, $data);
                        $this->pushState(CReaderState::parameter_list, $data);
                    } elseif ($c === ')') {
                        $subDeclaration = $data['n'];
                        $uinfos = CDeclaration::makeUnknownInfos($subDeclaration);

                        if (self::elementIsEmpty($subDeclaration) || self::elementIsParameter($uinfos)) {
                            $element['type'] = CDeclarationType::tfunction;
                            $this->pushState(CReaderState::opt_function_definition, $data);
                            $this->pushState(CReaderState::parameter_list, $data);
                        } elseif (self::elementIsNotParameter($uinfos)) {
                            $element['items'][] = '(';
                            $this->pushState(CReaderState::subdeclarator_end, $data);
                        } else {
                            // Unknown parenthesis type
                            // The following part will determine the type
                            $this->fgetc();

                            $newElement = $this->newElement();
                            $this->pushState(CReaderState::subdeclarator_after, $data + [
                                'a' => &$newElement
                            ]);
                            $this->pushState(CReaderState::opt_array_or_function, [
                                'e' => &$newElement
                            ]);
                            unset($newElement);
                        }
                    } else
                        $this->pushState(CReaderState::wait_end_declaration);
                    break;

                case CReaderState::subdeclarator_after:
                    $after = $data['a'];
                    $type2 = $after['type'];

                    $c = $this->silentChar();

                    // The subdeclarator is followed by a function|array declarator
                    if (($isfun = ($type2 === CDeclarationType::tfunction)) || $type2 === CDeclarationType::tarray) {

                        // Merge the sub declarator with the main declarator
                        $element['items'][] = '(';
                        self::mergeElements($element, $data['n']);
                        $element['items'][] = ')';

                        // Merge the function|array declarator
                        $element['type'] = $type2;

                        if ($isfun) {
                            $element['group'] = $after['group'];

                            // Add the parameters to the current element
                            {
                                $element['items'][] = '(';
                                $poffset = \count($element['items']);

                                foreach ($after['parameters'] as $ppos)
                                    $element['items'][] = $after['items'][$ppos];

                                $element['items'][] = ')';
                                $element['parameters'] = \range($poffset, $poffset + \count($after['parameters']) - 1);
                            }

                            if (isset($after['cstatement'])) {
                                self::elementSet($element, 'cstatement', $after['cstatement']);
                            }
                        } else {
                            self::elementAddItems($element, $after['items']);
                        }
                    } elseif ($c === '{') {
                        $element['group'] = CDeclarationGroup::definition;
                        $element['type'] = CDeclarationType::tfunction;
                        self::elementSet($element, 'parameters', [
                            $data['n']
                        ]);
                        $this->pushState(CReaderState::opt_function_definition, [
                            'e' => &$element
                        ]);
                    } else {
                        $n = $data['n'];

                        $element['items'][] = '(';
                        self::mergeElements($element, $data['n']);
                        $element['items'][] = ')';

                        if ($n['type'] == CDeclarationType::tfunction) {
                            self::elementSet($element, 'parameters', $n['parameters']);
                        } else {
                            // Arbitrary set the element to be a recursive declaration
                            self::elementAddItems($element, $after['items']);
                        }
                    }
                    break;

                /*
                 * The element is a recursive declarator
                 */
                case CReaderState::subdeclarator_end:
                    $c = $this->fgetc();

                    if ($c === ')') {
                        $declarator_level --;
                        $subDeclaration = $data['n'];

                        self::mergeElements($element, $subDeclaration);
                        self::makeElementIdentifier($element);
                        $element['items'][] = ')';
                        $this->pushState(CReaderState::opt_array_or_function, $data);
                    } else {
                        $this->clearStates();
                        $this->fungetc();
                    }
                    break;

                case CReaderState::opt_array_or_function:
                    $c = $this->silentChar();

                    if ($c === '[') {
                        // Arrays may repeat
                        $this->pushState(CReaderState::opt_array, $data);
                        $this->pushState(CReaderState::direct_declarator_array, $data);
                    } elseif ($c === '(') {
                        $this->fgetc();
                        $this->pushState(CReaderState::direct_declarator_function, $data);
                    }
                    break;

                case CReaderState::opt_array:
                    $c = $this->silentChar();

                    if ($c === '[')
                        $this->pushState(CReaderState::direct_declarator_array, $data);
                    break;

                case CReaderState::opt_cstatement:
                    $c = $this->silentChar();

                    if ($c === '{') {
                        $cstatement = $this->getDelimitedText();
                        $element['group'] = CDeclarationGroup::definition;
                        $element['cstatement'] = $cstatement;
                    }
                    break;

                case CReaderState::opt_function_definition:

                    if ($declarator_level === 0)
                        $this->pushState(CReaderState::opt_cstatement, $data);
                    break;

                case CReaderState::direct_declarator_array:
                    $element['type'] = CDeclarationType::tarray;
                    self::makeElementIdentifier($element);
                    $content = $this->getDelimitedText(self::C_DELIMITERS);
                    $element['items'][] = $content;
                    break;

                case CReaderState::direct_declarator_function:
                    $element['type'] = CDeclarationType::tfunction;
                    $this->pushState(CReaderState::opt_function_definition, $data);
                    $this->pushState(CReaderState::parameter, $data);
                    break;

                // ======================================================

                case CReaderState::parameter:
                    $newElement = $this->newElement();
                    $this->pushState(CReaderState::parameter_list, [
                        'e' => &$element,
                        'n' => &$newElement
                    ]);
                    $data = [
                        'e' => &$newElement
                    ];
                    $this->pushState(CReaderState::declarator, $data);
                    $this->pushState(CReaderState::declaration_specifiers, $data);
                    unset($newElement);
                    break;

                case CReaderState::parameter_list:
                    $c = $this->nextChar();
                    $element['_parameters'][] = $data['n'];

                    if ($c === ',') {
                        $this->pushState(CReaderState::parameter, $data);
                    } elseif ($c === ')') {
                        $this->makeElementIdentifier($element);
                        $params = $element['_parameters'];
                        unset($element['_parameters']);

                        if (\count($params) === 1 && self::elementIsEmpty($params[0]))
                            $element['parameters'] = [];
                        else {
                            $empty = \array_filter($params, 'self::elementIsEmpty');

                            if (! empty($empty)) {
                                $this->pushState(CReaderState::wait_end_declaration);
                                break;
                            }
                            $nbItems = \count($element['items']) + 1;
                            $element['parameters'] = \range($nbItems, $nbItems + \count($params) - 1);

                            $element['items'][] = '(';
                            foreach ($params as $p)
                                $element['items'][] = CDeclaration::fromReaderElements($p);
                            $element['items'][] = ')';
                        }
                    } else
                        $this->fungetc();
                    break;

                default:
                    $this->parseException("Invalid state: $state->name");
            }
        }
        return $element;
    }
}