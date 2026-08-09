<?php

declare(strict_types=1);

namespace Quiote\Docs\Emitter;

use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\TypeRef;

/**
 * Renders a type as Markdown, linking the parts that have somewhere to point.
 *
 * Only the class names inside a type are linked, never the whole thing, so
 * `list<Route>|null` keeps `list` and `null` as plain text while `Route` reaches its
 * page. A type used in a signature line is rendered plain instead: a link inside a code
 * span does not render, so signatures stay code and the tables beside them carry the links.
 */
final class TypeLinker
{
    /**
     * Types outside the framework that have a stable page worth pointing at.
     *
     * PSR interfaces and PHP's own classes are the two a reader most often wants to follow.
     * Vendor types are deliberately absent: their documentation has no addressing scheme
     * that stays valid, and a link that rots is worse than a name.
     *
     * @var array<string, string>
     */
    private const EXTERNAL = [
        'Psr\Http\Message\ServerRequestInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Message\RequestInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Message\ResponseInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Message\StreamInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Message\UriInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Message\UploadedFileInterface' => 'https://www.php-fig.org/psr/psr-7/',
        'Psr\Http\Server\MiddlewareInterface' => 'https://www.php-fig.org/psr/psr-15/',
        'Psr\Http\Server\RequestHandlerInterface' => 'https://www.php-fig.org/psr/psr-15/',
        'Psr\Http\Client\ClientInterface' => 'https://www.php-fig.org/psr/psr-18/',
        'Psr\Container\ContainerInterface' => 'https://www.php-fig.org/psr/psr-11/',
        'Psr\Log\LoggerInterface' => 'https://www.php-fig.org/psr/psr-3/',
        'Psr\SimpleCache\CacheInterface' => 'https://www.php-fig.org/psr/psr-16/',
        'Psr\Cache\CacheItemPoolInterface' => 'https://www.php-fig.org/psr/psr-6/',
        'Psr\EventDispatcher\EventDispatcherInterface' => 'https://www.php-fig.org/psr/psr-14/',
        'Throwable' => 'https://www.php.net/manual/en/class.throwable.php',
        'Stringable' => 'https://www.php.net/manual/en/class.stringable.php',
        'Traversable' => 'https://www.php.net/manual/en/class.traversable.php',
        'IteratorAggregate' => 'https://www.php.net/manual/en/class.iteratoraggregate.php',
        'ArrayAccess' => 'https://www.php.net/manual/en/class.arrayaccess.php',
        'Countable' => 'https://www.php.net/manual/en/class.countable.php',
        'JsonSerializable' => 'https://www.php.net/manual/en/class.jsonserializable.php',
        'DateTimeImmutable' => 'https://www.php.net/manual/en/class.datetimeimmutable.php',
        'DateTimeInterface' => 'https://www.php.net/manual/en/class.datetimeinterface.php',
        'PDO' => 'https://www.php.net/manual/en/class.pdo.php',
        'Closure' => 'https://www.php.net/manual/en/class.closure.php',
        'UnitEnum' => 'https://www.php.net/manual/en/class.unitenum.php',
        'BackedEnum' => 'https://www.php.net/manual/en/class.backedenum.php',
    ];

    public function __construct(
        private readonly ApiIndex $index,
        private readonly string $basePath = '/api',
    ) {
    }

    /** The type as Markdown, with every documented class inside it linked. */
    public function render(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeRef::KIND_NAMED => $this->named($type),
            TypeRef::KIND_LITERAL => '`' . $type->display . '`',
            TypeRef::KIND_NULLABLE => '`?`' . $this->render($type->args[0]),
            TypeRef::KIND_UNION => $this->joined($type, '`|`'),
            TypeRef::KIND_INTERSECTION => $this->joined($type, '`&`'),
            TypeRef::KIND_GENERIC => $this->generic($type),
            default => '`' . $type->display . '`',
        };
    }

    /** The link for one class, or null when it is not part of the reference. */
    public function link(string $fqcn): ?string
    {
        $slug = $this->index->slugFor($fqcn);

        return $slug !== null ? $this->basePath . '/' . $slug . '/' : null;
    }

    /**
     * Turns the `{@link …}` markers the reference resolver left in prose into real links.
     *
     * A marker naming a member becomes a link to that member's anchor on its class's page.
     * A marker naming something the reference does not document degrades to inline code,
     * which still reads correctly.
     */
    public function prose(string $text): string
    {
        if ($text === '' || !str_contains($text, '{@link ')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{@link\s+([^}]+)\}/',
            function (array $matches): string {
                $target = trim($matches[1]);
                $member = null;

                if (str_contains($target, '::')) {
                    [$target, $member] = explode('::', $target, 2);
                }

                $href = $this->link($target);
                $short = str_contains($target, '\\')
                    ? substr($target, (int) strrpos($target, '\\') + 1)
                    : $target;

                $label = $member !== null ? $short . '::' . $member : $short;

                if ($href === null) {
                    return '`' . $label . '`';
                }

                if ($member !== null) {
                    $anchor = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $member));
                    $href .= '#' . $anchor;
                }

                return '[`' . $label . '`](' . $href . ')';
            },
            $text,
        );
    }

    /** The plain-text form of prose, for a frontmatter description where a link cannot go. */
    public function plain(string $text): string
    {
        if ($text === '' || !str_contains($text, '{@link ')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{@link\s+([^}]+)\}/',
            static function (array $matches): string {
                $target = trim($matches[1]);
                $short = str_contains($target, '\\')
                    ? substr($target, (int) strrpos($target, '\\') + 1)
                    : $target;

                return str_contains($target, '::')
                    ? substr($short, 0, (int) strpos($short, '::')) . substr($target, (int) strpos($target, '::'))
                    : $short;
            },
            $text,
        );
    }

    private function named(TypeRef $type): string
    {
        $fqcn = $type->fqcn;
        if ($fqcn === null) {
            return '`' . $type->display . '`';
        }

        $internal = $this->link($fqcn);
        if ($internal !== null) {
            return '[`' . $type->display . '`](' . $internal . ')';
        }

        $external = self::EXTERNAL[$fqcn] ?? null;
        if ($external !== null) {
            return '[`' . $type->display . '`](' . $external . ')';
        }

        return '`' . $type->display . '`';
    }

    private function joined(TypeRef $type, string $glue): string
    {
        return implode($glue, array_map($this->render(...), $type->args));
    }

    private function generic(TypeRef $type): string
    {
        $arguments = $type->args;
        $base = array_shift($arguments);

        if ($base === null) {
            return '`' . $type->display . '`';
        }

        $rendered = array_map($this->render(...), $arguments);

        return $this->render($base) . '`<`' . implode('`, `', $rendered) . '`>`';
    }
}
