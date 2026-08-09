<?php

declare(strict_types=1);

namespace Quiote\Docs\Docblock;

use PHPStan\PhpDocParser\Ast\PhpDoc\DeprecatedTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Quiote\Docs\Ir\DocBlock;

/**
 * Turns a raw docblock into a {@see DocBlock}.
 *
 * A real parser rather than the line-stripping regexes the framework uses elsewhere,
 * because a reference page needs the tags those throw away: `@param` and `@return` carry
 * the narrow types (`list<Route>` where the signature only says `array`), and `@throws`
 * is a section of its own.
 */
final class DocblockParser
{
    private readonly PhpDocParser $parser;
    private readonly Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constantExpressions = new ConstExprParser($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constantExpressions), $constantExpressions);
    }

    /** Parses a docblock, treating a missing or unparsable one as simply empty. */
    public function parse(?string $docComment): DocBlock
    {
        if ($docComment === null || trim($docComment) === '') {
            return DocBlock::empty();
        }

        try {
            $node = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (\Throwable) {
            // A docblock the parser cannot read still usually has readable prose in it, and
            // losing the page over a malformed tag would be the wrong trade.
            return $this->fallback($docComment);
        }

        $paragraphs = [];
        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $text = trim($child->text);
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        $prose = implode("\n\n", $paragraphs);
        $inheritsDoc = $prose !== '' && preg_match('/\{@inheritdoc\}/i', $prose) === 1;
        if ($inheritsDoc) {
            $prose = trim((string) preg_replace('/\{@inheritdoc\}/i', '', $prose));
        }

        [$summary, $description] = $this->splitSummary($prose);

        $paramDescriptions = [];
        $paramTypes = [];
        foreach ($node->getParamTagValues() as $param) {
            $name = ltrim($param->parameterName, '$');
            $paramTypes[$name] = (string) $param->type;
            $description = trim($param->description);
            if ($description !== '') {
                $paramDescriptions[$name] = $this->unwrap($description);
            }
        }

        $returnType = null;
        $returnDescription = '';
        foreach ($node->getReturnTagValues() as $return) {
            $returnType = (string) $return->type;
            $returnDescription = $this->unwrap(trim($return->description));
            break;
        }

        $throws = [];
        foreach ($node->getThrowsTagValues() as $throw) {
            $throws[] = [
                'type' => (string) $throw->type,
                'description' => $this->unwrap(trim($throw->description)),
            ];
        }

        return new DocBlock(
            summary: $summary,
            description: $description,
            paramDescriptions: $paramDescriptions,
            paramTypes: $paramTypes,
            returnType: $returnType,
            returnDescription: $returnDescription,
            throws: $throws,
            deprecated: $this->deprecation(array_values($node->getDeprecatedTagValues())),
            internal: $node->getTagsByName('@internal') !== [],
            since: $this->firstTagText(array_values($node->getTagsByName('@since'))),
            see: $this->tagTexts(array_values($node->getTagsByName('@see'))),
            inheritsDoc: $inheritsDoc,
        );
    }

    /**
     * @param list<DeprecatedTagValueNode> $tags
     */
    private function deprecation(array $tags): ?string
    {
        foreach ($tags as $tag) {
            return $this->unwrap(trim($tag->description));
        }

        return null;
    }

    /**
     * @param list<PhpDocTagNode> $tags
     */
    private function firstTagText(array $tags): ?string
    {
        foreach ($this->tagTexts($tags) as $text) {
            return $text;
        }

        return null;
    }

    /**
     * @param list<PhpDocTagNode> $tags
     * @return list<string>
     */
    private function tagTexts(array $tags): array
    {
        $texts = [];

        foreach ($tags as $tag) {
            $value = $tag->value;
            $text = $value instanceof GenericTagValueNode ? trim($value->value) : trim((string) $value);
            if ($text !== '') {
                $texts[] = $this->unwrap($text);
            }
        }

        return $texts;
    }

    /**
     * Splits prose into its first sentence and the rest.
     *
     * The first sentence becomes the page description and the table summary, so it is taken
     * at a sentence boundary rather than a line break. A boundary needs whitespace after the
     * period, which keeps `Psr7.php` or `v1.2` from ending the sentence early.
     *
     * @return array{string, string}
     */
    private function splitSummary(string $prose): array
    {
        $prose = trim($prose);
        if ($prose === '') {
            return ['', ''];
        }

        $paragraphs = preg_split('/\n\s*\n/', $prose) ?: [$prose];
        $first = $this->unwrap(trim($paragraphs[0]));
        $rest = array_slice($paragraphs, 1);

        if (preg_match('/^(.+?[.!?])(\s|$)/s', $first, $matches) === 1) {
            $summary = trim($matches[1]);
            $remainder = trim(substr($first, strlen($matches[1])));
            if ($remainder !== '') {
                array_unshift($rest, $remainder);
            }
        } else {
            $summary = $first;
        }

        $description = implode(
            "\n\n",
            array_map(fn(string $p): string => $this->unwrap(trim($p)), $rest),
        );

        return [$summary, trim($description)];
    }

    /** Rejoins a paragraph that was wrapped across source lines. */
    private function unwrap(string $text): string
    {
        return trim((string) preg_replace('/\s*\n\s*/', ' ', $text));
    }

    /**
     * Recovers the prose of a docblock the parser rejected, by stripping the comment
     * markers and stopping at the first tag.
     */
    private function fallback(string $docComment): DocBlock
    {
        $lines = preg_split('/\r?\n/', $docComment) ?: [];
        $collected = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('#^\s*/?\*+/?#', '', trim($line)));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '' && $collected !== []) {
                break;
            }
            if ($line !== '') {
                $collected[] = $line;
            }
        }

        [$summary, $description] = $this->splitSummary(implode(' ', $collected));

        return new DocBlock(summary: $summary, description: $description);
    }
}
