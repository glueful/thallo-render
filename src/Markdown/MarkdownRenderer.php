<?php

declare(strict_types=1);

namespace Thallo\Render\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code as InlineCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * A docs page's body: Markdown rendered at delivery.
 *
 * Documentation lives in git as Markdown, so the entry stores the Markdown itself (a plain text
 * field) and this turns it into the page. The rich-text sanitizer could not carry it — it knows no
 * tables and drops a code fence's language and a heading's id — and sanitising is the wrong tool
 * here anyway: the HTML below is GENERATED from the source by this converter, with raw HTML
 * stripped and unsafe link schemes refused, so nothing a source file says becomes markup of its
 * own. What comes out is GitHub-flavoured Markdown; a stable id and a copyable anchor on every
 * heading; the h2/h3 outline as a table of contents; and fenced code handed to a callback, which
 * the theme answers with its own code block (language label, copy button).
 */
final class MarkdownRenderer
{
    /**
     * @param callable(string $code, string $language): string $code renders one code listing
     * @return array{html:string,toc:list<array{id:string,text:string,level:int}>}
     */
    public function render(string $markdown, callable $code): array
    {
        if (trim($markdown) === '') {
            return ['html' => '', 'toc' => []];
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'symbol' => '#',
                'html_class' => 'heading-anchor',
                'title' => 'Link to this section',
                'aria_hidden' => true,
                'min_heading_level' => 2,
                'max_heading_level' => 4,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $listing = new class ($code) implements NodeRendererInterface {
            /** @param callable(string,string):string $code */
            public function __construct(private $code)
            {
            }

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                $language = 'text';
                if ($node instanceof FencedCode) {
                    // The info string's first word, held to a word: it becomes a class and a label.
                    $word = strtolower((string) ($node->getInfoWords()[0] ?? ''));
                    if (preg_match('/\A[a-z0-9][a-z0-9+#-]{0,30}\z/', $word) === 1) {
                        $language = $word;
                    } elseif (preg_match('/\A[a-z0-9]+/', $word, $m) === 1) {
                        $language = $m[0];
                    }
                }
                $text = $node instanceof StringContainerInterface ? $node->getLiteral() : '';
                return ($this->code)(rtrim($text, "\n"), $language);
            }
        };
        $environment->addRenderer(FencedCode::class, $listing, 10);
        $environment->addRenderer(IndentedCode::class, $listing, 10);

        $rendered = (new MarkdownConverter($environment))->convert($markdown);

        $toc = [];
        foreach ($rendered->getDocument()->iterator() as $node) {
            if (!$node instanceof Heading || $node->getLevel() < 2 || $node->getLevel() > 3) {
                continue;
            }
            $id = null;
            $text = '';
            foreach ($node->iterator() as $inner) {
                if ($inner instanceof HeadingPermalink) {
                    $id = $inner->getSlug();
                } elseif ($inner instanceof Text || $inner instanceof InlineCode) {
                    $text .= $inner->getLiteral();
                }
            }
            if ($id !== null && trim($text) !== '') {
                $toc[] = ['id' => $id, 'text' => trim($text), 'level' => $node->getLevel()];
            }
        }

        return ['html' => trim($rendered->getContent()), 'toc' => $toc];
    }
}
